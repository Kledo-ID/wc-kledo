<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sends orders that are missing from Kledo, a few at a time, waiting for each batch to land.
 *
 * One job walks the candidates of a date range (see `WC_Kledo_Sync_Candidates`). Every minute it
 * either starts a batch of 5–10 orders or checks the batch it already started; the next batch only
 * starts once every transaction of the current one is in Kledo or has clearly failed. Kledo answers
 * a send before it has created anything, so pacing on the HTTP answer alone would let a slow Kledo
 * pile up hundreds of queued writes; pacing on confirmation does not.
 *
 * Runs on Action Scheduler, which WooCommerce ships, falling back to WP-Cron. Only one job exists
 * at a time; it is started by an admin or, when the shop switched it on, by the daily schedule.
 *
 * @since 1.8.0
 */
class WC_Kledo_Sync_Job {
	/**
	 * Option holding the current (or last) job.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const OPTION = 'wc_kledo_sync_job';

	/**
	 * Order meta: id of the job that handled the order.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ORDER_JOB_META = '_wc_kledo_sync_job';

	/**
	 * Order meta: what that job did with it.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ORDER_RESULT_META = '_wc_kledo_sync_result';

	/**
	 * Hook of one step of the job.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const TICK_HOOK = 'wc_kledo_sync_tick';

	/**
	 * Hook of the daily automatic run.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const DAILY_HOOK = 'wc_kledo_sync_daily';

	/**
	 * Action Scheduler group of both hooks.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const GROUP = 'wc-kledo';

	/**
	 * Seconds between steps.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const TICK_INTERVAL = MINUTE_IN_SECONDS;

	/**
	 * How long a batch may wait for Kledo before the job pauses itself.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const BATCH_TIMEOUT = 30 * MINUTE_IN_SECONDS;

	/**
	 * Pause after Kledo answers 429 (too many requests). Kledo sends no Retry-After.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const RATE_LIMIT_BACKOFF = 5 * MINUTE_IN_SECONDS;

	/**
	 * Smallest and largest batch.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const MIN_BATCH = 5;

	/**
	 * Largest batch.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const MAX_BATCH = 10;

	/**
	 * States after which a transaction no longer holds up its batch.
	 *
	 * `retrying` is in the list on purpose: the retry queue owns that transaction now, and waiting
	 * on it would stall the job for as long as its backoff runs.
	 *
	 * @var string[]
	 * @since 1.8.0
	 */
	private const SETTLED_STATES = array( 'confirmed', 'rejected', 'failed', 'missing', 'retrying' );

	/**
	 * Lock transient guarding a step.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const LOCK_KEY = 'wc_kledo_sync_lock';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function init(): void {
		add_action( self::TICK_HOOK, array( $this, 'tick' ) );
		add_action( self::DAILY_HOOK, array( $this, 'run_daily' ) );
	}

	/**
	 * The current or last job, or null when there never was one.
	 *
	 * @return array|null
	 * @since 1.8.0
	 */
	public function get_job(): ?array {
		$job = get_option( self::OPTION );

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/**
	 * Whether a job is running or paused.
	 *
	 * @return bool
	 * @since 1.8.0
	 */
	public function is_active(): bool {
		$job = $this->get_job();

		return null !== $job && in_array( $job['status'], array( 'running', 'paused' ), true );
	}

	/**
	 * Start a job.
	 *
	 * @param  array $args {
	 *     @type string $date_from   `Y-m-d`.
	 *     @type string $date_to     `Y-m-d`.
	 *     @type int    $batch_size  5–10.
	 *     @type string $source      `manual` or `daily`.
	 * }
	 *
	 * @return array|WP_Error The job.
	 * @since 1.8.0
	 */
	public function start( array $args ) {
		if ( $this->is_active() ) {
			return new WP_Error( 'wc_kledo_sync_active', __( 'A synchronisation is already running. Pause or cancel it first.', 'wc-kledo' ) );
		}

		if ( empty( WC_Kledo_Sync_Candidates::get_enabled_types() ) ) {
			return new WP_Error( 'wc_kledo_sync_disabled', __( 'Both "Enable Create Order" and "Enable Create Invoice" are off, so there is nothing to send.', 'wc-kledo' ) );
		}

		$range    = wc_kledo_get_date_range( $args['date_from'] ?? '', $args['date_to'] ?? '' );
		$previous = $this->get_job();

		$job = array(
			'id'            => (string) ( ( $previous['number'] ?? 0 ) + 1 ) . '-' . wp_generate_password( 6, false ),
			'number'        => (int) ( $previous['number'] ?? 0 ) + 1,
			'source'        => 'daily' === ( $args['source'] ?? '' ) ? 'daily' : 'manual',
			'status'        => 'running',
			'date_from'     => $range['from'],
			'date_to'       => $range['to'],
			'batch_size'    => self::sanitize_batch_size( $args['batch_size'] ?? self::MIN_BATCH ),
			'batch'         => array(),
			'batches'       => 0,
			'total'         => ( new WC_Kledo_Sync_Candidates() )->count( $range )['orders'],
			'counts'        => array(
				'processed' => 0,
				'adopted'   => 0,
				'sent'      => 0,
				'confirmed' => 0,
				'failed'    => 0,
			),
			'backoff_until' => 0,
			'pause_reason'  => '',
			'user_id'       => get_current_user_id(),
			'started_at'    => time(),
			'finished_at'   => 0,
			'last_tick_at'  => 0,
		);

		$this->save_job( $job );
		$this->schedule_tick( time() );

		wc_kledo_log_info(
			sprintf( 'Kledo sync #%d started (%s): %s..%s, %d per batch, %d candidate order(s).', $job['number'], $job['source'], $job['date_from'], $job['date_to'], $job['batch_size'], $job['total'] )
		);

		return $job;
	}

	/**
	 * Pause the job.
	 *
	 * @param  string $reason  Empty for an admin's pause, `kledo_slow` when the batch timed out.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function pause( string $reason = '' ): void {
		$job = $this->get_job();

		if ( null === $job || 'running' !== $job['status'] ) {
			return;
		}

		$job['status']       = 'paused';
		$job['pause_reason'] = $reason;

		$this->save_job( $job );
		$this->unschedule_tick();
	}

	/**
	 * Resume a paused job. A batch that timed out gets a fresh 30 minutes.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function resume(): void {
		$job = $this->get_job();

		if ( null === $job || 'paused' !== $job['status'] ) {
			return;
		}

		$job['status']       = 'running';
		$job['pause_reason'] = '';

		if ( ! empty( $job['batch']['started_at'] ) ) {
			$job['batch']['started_at'] = time();
		}

		$this->save_job( $job );
		$this->schedule_tick( time() );
	}

	/**
	 * Cancel the job. Transactions already sent stay sent; they are still verified as usual.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function cancel(): void {
		$job = $this->get_job();

		if ( null === $job || ! in_array( $job['status'], array( 'running', 'paused' ), true ) ) {
			return;
		}

		$job['status']      = 'cancelled';
		$job['finished_at'] = time();

		$this->save_job( $job );
		$this->unschedule_tick();
	}

	/**
	 * Run a step now if the scheduled one is overdue.
	 *
	 * The Sync tab polls the job while it is open; when WP-Cron cannot fire on this server the
	 * poll is what keeps the job moving.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_run_overdue_tick(): void {
		$job = $this->get_job();

		if ( null === $job || 'running' !== $job['status'] ) {
			return;
		}

		if ( time() - (int) $job['last_tick_at'] >= 2 * self::TICK_INTERVAL ) {
			$this->tick();
		}
	}

	/**
	 * One step of the job.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function tick(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		$job = $this->get_job();

		if ( null === $job || 'running' !== $job['status'] ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$job = $this->run_step( $job );
			$this->save_job( $job );

			if ( 'running' === $job['status'] ) {
				$this->schedule_tick( max( time() + self::TICK_INTERVAL, (int) $job['backoff_until'] ) );
			}
		} catch ( Throwable $exception ) {
			wc_kledo_log_warning( sprintf( 'Kledo sync step failed: %s', wc_kledo_sanitize_api_error_message( $exception->getMessage() ) ) );
			$this->schedule_tick( time() + self::TICK_INTERVAL );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Either advance the current batch or start the next one.
	 *
	 * @param  array $job
	 *
	 * @return array The updated job.
	 * @since 1.8.0
	 */
	private function run_step( array $job ): array {
		$now                 = time();
		$job['last_tick_at'] = $now;

		if ( (int) $job['backoff_until'] > $now ) {
			return $job;
		}

		if ( ! empty( $job['batch']['orders'] ) ) {
			$job = $this->advance_batch( $job );

			if ( ! empty( $job['batch']['orders'] ) ) {
				if ( $now - (int) $job['batch']['started_at'] >= self::BATCH_TIMEOUT ) {
					$job['status']       = 'paused';
					$job['pause_reason'] = 'kledo_slow';

					wc_kledo_log_warning( sprintf( 'Kledo sync #%d paused: batch %d still waiting for Kledo after 30 minutes.', $job['number'], $job['batches'] ) );
				}

				return $job;
			}
		}

		return $this->start_batch( $job );
	}

	/**
	 * Pick the next orders and send what each of them is missing.
	 *
	 * @param  array $job
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function start_batch( array $job ): array {
		$range     = array(
			'from' => $job['date_from'],
			'to'   => $job['date_to'],
		);
		$order_ids = ( new WC_Kledo_Sync_Candidates() )->get_next_batch( $range, $job['id'], (int) $job['batch_size'] );

		if ( empty( $order_ids ) ) {
			$job['status']      = 'done';
			$job['finished_at'] = time();
			$job['batch']       = array();

			wc_kledo_log_info(
				sprintf( 'Kledo sync #%d finished: %d processed, %d confirmed, %d already in Kledo, %d failed.', $job['number'], $job['counts']['processed'], $job['counts']['confirmed'], $job['counts']['adopted'], $job['counts']['failed'] )
			);

			return $job;
		}

		$batch = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$outcome = $this->process_order( $order, $job );

			if ( $outcome['rate_limited'] ) {
				// Leave this order and the rest unstamped, so the next batch picks them up again.
				$job['backoff_until'] = time() + self::RATE_LIMIT_BACKOFF;

				wc_kledo_log_warning( sprintf( 'Kledo sync #%d: Kledo answered 429, waiting %d seconds.', $job['number'], self::RATE_LIMIT_BACKOFF ) );

				break;
			}

			++$job['counts']['processed'];

			if ( 'adopted' === $outcome['result'] ) {
				++$job['counts']['adopted'];
			}

			if ( ! empty( $outcome['types'] ) ) {
				$job['counts']['sent'] += count( $outcome['types'] );
				$batch[ $order_id ]     = array( 'types' => $outcome['types'] );
			}

			if ( 'failed' === $outcome['result'] ) {
				++$job['counts']['failed'];
			}
		}

		$job['batch'] = empty( $batch ) ? array() : array(
			'orders'     => $batch,
			'started_at' => time(),
		);

		++$job['batches'];

		return $job;
	}

	/**
	 * Send whatever one order is missing in Kledo.
	 *
	 * Kledo is asked first: a transaction it already holds is adopted rather than sent twice.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $job
	 *
	 * @return array{result: string, types: string[], rate_limited: bool} `types` are the ones sent
	 *                                                                     and now awaited.
	 * @since 1.8.0
	 */
	private function process_order( WC_Order $order, array $job ): array {
		$verifier = wc_kledo()->get_transaction_verifier();
		$read     = $verifier->refresh_order( $order );
		$order    = wc_get_order( $order->get_id() );
		$types    = WC_Kledo_Sync_Candidates::get_types_to_send( $order );
		$bridge   = wc_kledo()->get_woocommerce_bridge();
		$sent     = array();
		$failed   = false;

		foreach ( $types as $type ) {
			$previous_state = wc_kledo_get_remote_state( $order, $type );

			$result = $bridge->deliver(
				$order,
				$type,
				array( 'trigger' => 'sync_job' )
			);

			if ( 429 === (int) $result['http_code'] ) {
				$this->undo_rate_limited_send( (int) $order->get_id(), $type, $previous_state );

				return array(
					'result'       => 'rate_limited',
					'types'        => $sent,
					'rate_limited' => true,
				);
			}

			if ( ! empty( $result['success'] ) || 'awaiting_sales_order' === ( $result['reason'] ?? '' ) ) {
				$sent[] = $type;
			} elseif ( empty( $result['skipped'] ) ) {
				$failed = true;
			}

			$order = wc_get_order( $order->get_id() );
		}

		if ( ! empty( $sent ) ) {
			$result = 'sent';
		} elseif ( $failed ) {
			$result = 'failed';
		} elseif ( ! empty( $read['found'] ) ) {
			$result = 'adopted';
		} else {
			$result = 'skipped';
		}

		$order->update_meta_data( self::ORDER_JOB_META, $job['id'] );
		$order->update_meta_data( self::ORDER_RESULT_META, $result );
		$order->save();

		if ( 'sent' === $result ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: sync job number, 2: comma-separated transaction types */
					__( 'Kledo: sent by Synchronisation #%1$d (%2$s).', 'wc-kledo' ),
					$job['number'],
					implode( ', ', array_map( array( WC_Kledo_Status_Badge::class, 'type_label' ), $sent ) )
				)
			);
		}

		return array(
			'result'       => $result,
			'types'        => $sent,
			'rate_limited' => false,
		);
	}

	/**
	 * Put a send that Kledo answered with 429 back the way it was.
	 *
	 * `deliver()` treats 429 like any temporary failure and hands the transaction to the retry
	 * queue. Here that is wrong: the job backs off and picks the order up again itself, and a
	 * transaction marked `retrying` is no longer a candidate, so the job would skip it for good.
	 *
	 * @param  int    $order_id
	 * @param  string $type
	 * @param  string $previous_state
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function undo_rate_limited_send( int $order_id, string $type, string $previous_state ): void {
		wc_kledo_remove_failed_transaction_from_queue( $order_id, $type );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( in_array( $previous_state, wc_kledo_get_remote_states(), true ) ) {
			wc_kledo_set_remote_state( $order, $type, $previous_state );

			return;
		}

		$order->delete_meta_data( wc_kledo_get_remote_state_meta_key( $type ) );
		$order->save();
	}

	/**
	 * Read the batch back from Kledo and drop the orders that are settled.
	 *
	 * Also sends an invoice that was held for its sales order as soon as that sales order is in
	 * Kledo, rather than leaving it to the retry queue's backoff — which would hold the batch for
	 * no reason.
	 *
	 * @param  array $job
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function advance_batch( array $job ): array {
		$verifier = wc_kledo()->get_transaction_verifier();
		$bridge   = wc_kledo()->get_woocommerce_bridge();

		foreach ( $job['batch']['orders'] as $order_id => $entry ) {
			$order = wc_get_order( (int) $order_id );

			if ( ! $order instanceof WC_Order ) {
				unset( $job['batch']['orders'][ $order_id ] );

				continue;
			}

			$verifier->refresh_order( $order );
			$order = wc_get_order( (int) $order_id );

			if ( in_array( 'invoice', $entry['types'], true )
				&& 'waiting_sales_order' === wc_kledo_get_remote_state( $order, 'invoice' )
				&& 'confirmed' === wc_kledo_get_remote_state( $order, 'order' ) ) {
				$bridge->deliver( $order, 'invoice', array( 'trigger' => 'sync_job' ) );
				$order = wc_get_order( (int) $order_id );
			}

			$settled = true;
			$failed  = false;

			foreach ( $entry['types'] as $type ) {
				$state = wc_kledo_get_remote_state( $order, $type );

				if ( ! in_array( $state, self::SETTLED_STATES, true ) ) {
					$settled = false;
				} elseif ( 'confirmed' !== $state ) {
					$failed = true;
				}
			}

			if ( ! $settled ) {
				continue;
			}

			$order->update_meta_data( self::ORDER_RESULT_META, $failed ? 'failed' : 'confirmed' );
			$order->save();

			++$job['counts'][ $failed ? 'failed' : 'confirmed' ];
			unset( $job['batch']['orders'][ $order_id ] );
		}

		return $job;
	}

	/**
	 * Daily run: start a job over the last N days, when the shop switched it on.
	 *
	 * Skipped while another job is running or paused, so runs never stack.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function run_daily(): void {
		if ( 'yes' !== get_option( WC_Kledo_Sync_Screen::DAILY_ENABLED_OPTION, 'no' ) ) {
			return;
		}

		if ( $this->is_active() ) {
			wc_kledo_log_info( 'Kledo daily sync skipped: another synchronisation is still running or paused.' );

			return;
		}

		$range = WC_Kledo_Sync_Candidates::get_recent_range( WC_Kledo_Sync_Screen::get_daily_days() );

		$result = $this->start(
			array(
				'date_from'  => $range['from'],
				'date_to'    => $range['to'],
				'batch_size' => WC_Kledo_Sync_Screen::get_batch_size(),
				'source'     => 'daily',
			)
		);

		if ( is_wp_error( $result ) ) {
			wc_kledo_log_warning( 'Kledo daily sync not started: ' . $result->get_error_message() );
		}
	}

	/**
	 * Match the daily schedule to the settings: present only while switched on.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function sync_daily_schedule(): void {
		self::unschedule( self::DAILY_HOOK );

		if ( 'yes' !== get_option( WC_Kledo_Sync_Screen::DAILY_ENABLED_OPTION, 'no' ) ) {
			return;
		}

		$hour = WC_Kledo_Sync_Screen::get_daily_hour();
		$next = current_datetime()->setTime( $hour, 0 );

		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::DAILY_HOOK, array(), self::GROUP );

			return;
		}

		wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY_HOOK );
	}

	/**
	 * Clamp a batch size to 5–10.
	 *
	 * @param  mixed $value
	 *
	 * @return int
	 * @since 1.8.0
	 */
	public static function sanitize_batch_size( $value ): int {
		return max( self::MIN_BATCH, min( self::MAX_BATCH, (int) $value ) );
	}

	/**
	 * Drop every scheduled action of a hook, in whichever scheduler holds it.
	 *
	 * @param  string $hook
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public static function unschedule( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Persist the job.
	 *
	 * @param  array $job
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function save_job( array $job ): void {
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Schedule the next step, replacing any already scheduled.
	 *
	 * @param  int $timestamp
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function schedule_tick( int $timestamp ): void {
		$this->unschedule_tick();

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::TICK_HOOK, array(), self::GROUP );

			return;
		}

		wp_schedule_single_event( $timestamp, self::TICK_HOOK );
	}

	/**
	 * Drop the scheduled step.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function unschedule_tick(): void {
		self::unschedule( self::TICK_HOOK );
	}
}
