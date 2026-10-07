<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads every accepted transaction back from Kledo until it is seen there, or until waiting stops
 * being a plausible explanation.
 *
 * `POST /woocommerce/order` and `POST /woocommerce/invoice` answer 200 as soon as Kledo has queued
 * the write, so until 1.8.0 a transaction whose queue job then failed inside Kledo was reported
 * as synced here forever. This loop closes that gap: a 200 now starts in `verifying`, and only a
 * read of `GET /woocommerce/transactions/{id}` moves it to `confirmed` — or, once the budget is
 * spent, to `missing`, which makes it resendable.
 *
 * Built like `WC_Kledo_Order_Closure` — option queue, transient lock, backoff, admin fallback —
 * but kept separate from it: closure waits for a sales order to close, this waits for a
 * transaction to exist, and the two have different budgets and different endings.
 *
 * @since 1.8.0
 */
class WC_Kledo_Transaction_Verifier {
	/**
	 * Cron hook name for the read-back loop.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const CRON_HOOK = 'wc_kledo_verify_transactions';

	/**
	 * Seconds before the first read-back of a freshly accepted transaction.
	 *
	 * Short, because Kledo's queue normally finishes within seconds and the order list should stop
	 * saying "waiting" soon after.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const FIRST_DELAY = 2 * MINUTE_IN_SECONDS;

	/**
	 * Read-backs made before a transaction that never appeared is recorded as missing.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * Age after which the next read-back is the last one.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const MAX_LIFETIME = 6 * HOUR_IN_SECONDS;

	/**
	 * Most orders an admin may have checked synchronously in one request.
	 *
	 * Each is one HTTP call to Kledo; past this the rest is left to the cron loop so a large
	 * selection cannot time the request out.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const MAX_IMMEDIATE_CHECKS = 20;

	/**
	 * Lock transient guarding a run. Separate from the retry and closure locks so the loops never
	 * block each other.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const LOCK_KEY = 'wc_kledo_verify_lock';

	/**
	 * Option recording that queue rows from before 1.8.0 have been given a state.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const BACKFILL_OPTION = 'wc_kledo_remote_state_backfill';

	/**
	 * Register the cron handler and the admin fallback.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, array( $this, 'process_due_checks' ) );

		// Same reason as the closure loop: WP-Cron silently never fires on a server that cannot
		// reach itself, and the order list would then say "waiting" forever.
		add_action( 'admin_init', array( $this, 'maybe_process_due_checks' ) );

		add_action( 'admin_init', array( $this, 'maybe_backfill_queue_states' ) );
	}

	/**
	 * Give a state to every failed-queue row left over from before 1.8.0.
	 *
	 * The Transactions screen and the order list filter read the state meta, and a row queued by
	 * 1.7.x has none, so without this it would vanish from both while still sitting in the retry
	 * queue. The queue is small by construction, so this runs once, in one pass. Records that were
	 * sent successfully before 1.8.0 need no backfill — they read as `legacy_synced`.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_backfill_queue_states(): void {
		if ( get_option( self::BACKFILL_OPTION ) || wp_doing_ajax() ) {
			return;
		}

		update_option( self::BACKFILL_OPTION, time(), false );

		$queue = get_option( 'wc_kledo_failed_transactions', array() );

		if ( ! is_array( $queue ) ) {
			return;
		}

		foreach ( $queue as $item ) {
			$order_id = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
			$type     = isset( $item['type'] ) ? (string) $item['type'] : '';
			$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order || ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
				continue;
			}

			if ( '' !== (string) $order->get_meta( wc_kledo_get_remote_state_meta_key( $type ) ) ) {
				continue;
			}

			// 1.7.x stored a rejection and an exhausted retry budget alike as `failed`.
			$queue_status = isset( $item['status'] ) ? (string) $item['status'] : '';

			wc_kledo_set_remote_state( $order, $type, 'failed' === $queue_status ? 'failed' : 'retrying' );
		}
	}

	/**
	 * Admin-side fallback: run the loop when at least one row is overdue.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_process_due_checks(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$queue = get_option( wc_kledo_get_verification_queue_option_name(), array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		$now = time();

		foreach ( $queue as $item ) {
			if ( isset( $item['next_run_at'] ) && (int) $item['next_run_at'] <= $now ) {
				$this->process_due_checks();

				return;
			}
		}
	}

	/**
	 * Walk every due row once, asking Kledo once per order.
	 *
	 * Rows are keyed `type:order_id`, but one response answers both types of an order, so due rows
	 * are grouped by order first and each order costs one request.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function process_due_checks(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		$option_name = wc_kledo_get_verification_queue_option_name();
		$queue       = get_option( $option_name, array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 60 );

		$now     = time();
		$due     = array();
		$waiting = array();

		foreach ( $queue as $key => $item ) {
			$order_id = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
			$type     = isset( $item['type'] ) ? (string) $item['type'] : '';

			if ( $order_id <= 0 || ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
				continue;
			}

			if ( isset( $item['next_run_at'] ) && (int) $item['next_run_at'] > $now ) {
				$waiting[ $key ] = $item;

				continue;
			}

			$due[ $order_id ][ $key ] = $item;
		}

		wc_kledo_log_info(
			sprintf( 'Kledo verification run started: %d order(s) due, %d row(s) waiting.', count( $due ), count( $waiting ) )
		);

		$updated_queue = $waiting;

		foreach ( $due as $order_id => $rows ) {
			$updated_queue = array_merge( $updated_queue, $this->check_rows( (int) $order_id, $rows, $now ) );
		}

		// Rows added while this run was talking to Kledo — a delivery finishing in another request
		// — are not in the snapshot taken above and would be lost by a plain overwrite.
		$current = get_option( $option_name, array() );

		if ( is_array( $current ) ) {
			foreach ( $current as $key => $item ) {
				if ( ! isset( $queue[ $key ] ) ) {
					$updated_queue[ $key ] = $item;
				}
			}
		}

		update_option( $option_name, $updated_queue, false );

		$this->schedule_next( $updated_queue, $now );

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Ask Kledo about one order and settle every due row it has.
	 *
	 * @param  int   $order_id
	 * @param  array $rows  Due rows of this order, keyed `type:order_id`.
	 * @param  int   $now
	 *
	 * @return array Rows that must stay queued, keyed as given.
	 * @since 1.8.0
	 */
	private function check_rows( int $order_id, array $rows, int $now ): array {
		$order = wc_get_order( $order_id );

		// The order is gone; there is nothing left to verify.
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$status = $this->fetch_status( $order );
		$keep   = array();

		foreach ( $rows as $key => $item ) {
			$type     = (string) $item['type'];
			$attempts = isset( $item['attempts'] ) ? (int) $item['attempts'] + 1 : 1;
			$created  = isset( $item['created_at'] ) ? (int) $item['created_at'] : $now;

			if ( $status['readable'] && null !== $status[ $type ] ) {
				$this->confirm( $order, $type, $status[ $type ] );

				continue;
			}

			// An unreadable answer says nothing about Kledo, only about the request, so it does
			// not count towards declaring the transaction missing.
			if ( $status['readable'] && ( $attempts >= self::MAX_ATTEMPTS || ( $now - $created ) > self::MAX_LIFETIME ) ) {
				$this->record_missing( $order, $type, $attempts );

				continue;
			}

			// Not there yet: record that it was looked for, so the screens can say when.
			if ( $status['readable'] ) {
				$current_state = wc_kledo_get_remote_state( $order, $type );

				if ( in_array( $current_state, wc_kledo_get_remote_states(), true ) ) {
					wc_kledo_set_remote_state( $order, $type, $current_state, array( 'checked' => true ) );
				}
			}

			$item['attempts']    = $status['readable'] ? $attempts : $attempts - 1;
			$item['next_run_at'] = $now + wc_kledo_get_retry_delay( max( 1, $attempts ) );
			$keep[ $key ]        = $item;
		}

		return $keep;
	}

	/**
	 * Read one order's status from Kledo.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array See `wc_kledo_read_transaction_status()`.
	 * @since 1.8.0
	 */
	private function fetch_status( WC_Order $order ): array {
		$request = new WC_Kledo_Request_Transaction_Status();

		try {
			return wc_kledo_read_transaction_status( $request->get_status( $order->get_id() ) );
		} catch ( Throwable $exception ) {
			wc_kledo_log_warning(
				sprintf(
					'Kledo verification request failed for order %d: %s',
					$order->get_id(),
					wc_kledo_sanitize_api_error_message( $exception->getMessage() )
				)
			);
		}

		return wc_kledo_read_transaction_status( false );
	}

	/**
	 * Record a transaction as seen in Kledo.
	 *
	 * Also marks it synced: a transaction that exists in Kledo without this plugin having recorded
	 * the send — sent before the meta existed, or found by an admin's check — must not be sent
	 * again by the next status change.
	 *
	 * @param  \WC_Order $order
	 * @param  string    $type
	 * @param  array     $transaction  `data.order` or `data.invoice`.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function confirm( WC_Order $order, string $type, array $transaction ): void {
		$was_confirmed = 'confirmed' === wc_kledo_get_remote_state( $order, $type );
		$reference     = wc_kledo_get_kledo_reference( $transaction );

		$order->update_meta_data( wc_kledo_get_delivery_meta_key( $type ), 'yes' );

		wc_kledo_set_remote_state(
			$order,
			$type,
			'confirmed',
			array(
				'ref'     => $reference,
				'checked' => true,
			)
		);

		wc_kledo_remove_failed_transaction_from_queue( $order->get_id(), $type );

		if ( $was_confirmed ) {
			return;
		}

		if ( 'order' === $type ) {
			/* translators: %s: Kledo reference number */
			$note = sprintf( __( 'Kledo: sales order %s is confirmed in Kledo.', 'wc-kledo' ), $reference );
		} else {
			/* translators: %s: Kledo reference number */
			$note = sprintf( __( 'Kledo: invoice %s is confirmed in Kledo.', 'wc-kledo' ), $reference );
		}

		$order->add_order_note( $note );

		wc_kledo_log_info( sprintf( 'Kledo %s for order %d confirmed as %s.', $type, $order->get_id(), $reference ) );
	}

	/**
	 * Record a transaction Kledo accepted but never produced.
	 *
	 * @param  \WC_Order $order
	 * @param  string    $type
	 * @param  int       $attempts
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function record_missing( WC_Order $order, string $type, int $attempts ): void {
		wc_kledo_record_missing_transaction( $order, $type );

		// No invoice in Kledo means no sales order closure is ever coming from it.
		if ( 'invoice' === $type ) {
			wc_kledo_remove_closure_check( $order->get_id() );
		}

		if ( 'order' === $type ) {
			/* translators: %d: number of checks made */
			$note = sprintf( __( 'Kledo: the sales order was accepted by Kledo but still did not exist there after %d checks, so Kledo most likely failed to create it. It has not been resent automatically, to avoid a duplicate if Kledo is only slow. Check Kledo, then resend it from the order actions, the Orders bulk actions, or WooCommerce > Kledo > Kledo Status.', 'wc-kledo' ), $attempts );
		} else {
			/* translators: %d: number of checks made */
			$note = sprintf( __( 'Kledo: the invoice was accepted by Kledo but still did not exist there after %d checks, so Kledo most likely failed to create it. It has not been resent automatically, to avoid a duplicate if Kledo is only slow. Check Kledo, then resend it from the order actions, the Orders bulk actions, or WooCommerce > Kledo > Kledo Status.', 'wc-kledo' ), $attempts );
		}

		$order->add_order_note( $note );
	}

	/**
	 * Check orders right now, on an admin's request.
	 *
	 * Every sent type of each order is read back; a type never sent is confirmed too if Kledo
	 * already has it. Past `MAX_IMMEDIATE_CHECKS` the remaining orders are queued for the cron
	 * loop instead of being checked in this request.
	 *
	 * A sent transaction that is absent is not declared missing on the spot — a single read
	 * cannot tell "slow" from "never" — it gets a fresh read-back budget instead.
	 *
	 * @param  int[] $order_ids
	 *
	 * @return array{confirmed: int, pending: int, queued: int, not_sent: int}
	 * @since 1.8.0
	 */
	public function check_orders_now( array $order_ids ): array {
		$summary = array(
			'confirmed' => 0,
			'pending'   => 0,
			'queued'    => 0,
			'not_sent'  => 0,
		);

		foreach ( array_values( $order_ids ) as $index => $order_id ) {
			$order = wc_get_order( (int) $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$sent_types = array_filter(
				array( 'order', 'invoice' ),
				static function ( $type ) use ( $order ) {
					return 'not_sent' !== wc_kledo_get_remote_state( $order, $type );
				}
			);

			if ( $index >= self::MAX_IMMEDIATE_CHECKS ) {
				foreach ( $sent_types as $type ) {
					wc_kledo_enqueue_verification( $order->get_id(), $type, true );
				}

				++$summary[ empty( $sent_types ) ? 'not_sent' : 'queued' ];

				continue;
			}

			$status = $this->fetch_status( $order );
			$found  = false;

			foreach ( array( 'order', 'invoice' ) as $type ) {
				if ( $status['readable'] && null !== $status[ $type ] ) {
					$this->confirm( $order, $type, $status[ $type ] );
					wc_kledo_remove_verification( $order->get_id(), $type );
					$found = true;

					continue;
				}

				$state = wc_kledo_get_remote_state( $order, $type );

				if ( ! in_array( $state, array( 'verifying', 'legacy_synced', 'confirmed' ), true ) ) {
					continue;
				}

				// Stamp the check even though nothing was found, so the screens show it ran. A record
				// sent before 1.8.0 now has a read-back underway, and says so.
				if ( $status['readable'] ) {
					wc_kledo_set_remote_state( $order, $type, 'legacy_synced' === $state ? 'verifying' : $state, array( 'checked' => true ) );
				} elseif ( 'legacy_synced' === $state ) {
					wc_kledo_set_remote_state( $order, $type, 'verifying' );
				}

				wc_kledo_enqueue_verification( $order->get_id(), $type );
			}

			if ( $found ) {
				++$summary['confirmed'];
			} elseif ( empty( $sent_types ) ) {
				++$summary['not_sent'];
			} else {
				++$summary['pending'];
			}
		}

		return $summary;
	}

	/**
	 * Read one order from Kledo and record what was found, without queueing anything.
	 *
	 * For a caller that polls on its own schedule — the sync job checks its batch every minute —
	 * and must not reset the read-back budget of the transactions it is watching each time, which
	 * `check_orders_now()` would do by queueing them afresh.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array{readable: bool, found: string[]} `found` lists the types that exist in Kledo.
	 * @since 1.8.0
	 */
	public function refresh_order( WC_Order $order ): array {
		$status = $this->fetch_status( $order );
		$found  = array();

		foreach ( array( 'order', 'invoice' ) as $type ) {
			if ( $status['readable'] && null !== $status[ $type ] ) {
				$this->confirm( $order, $type, $status[ $type ] );
				wc_kledo_remove_verification( $order->get_id(), $type );
				$found[] = $type;

				continue;
			}

			$state = wc_kledo_get_remote_state( $order, $type );

			if ( $status['readable'] && in_array( $state, wc_kledo_get_remote_states(), true ) ) {
				wc_kledo_set_remote_state( $order, $type, $state, array( 'checked' => true ) );
			}
		}

		return array(
			'readable' => $status['readable'],
			'found'    => $found,
		);
	}

	/**
	 * Make sure the cron event exists for the earliest remaining row.
	 *
	 * @param  array $queue
	 * @param  int   $now
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function schedule_next( array $queue, int $now ): void {
		if ( empty( $queue ) || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$min_next = PHP_INT_MAX;

		foreach ( $queue as $item ) {
			if ( isset( $item['next_run_at'] ) ) {
				$min_next = min( $min_next, (int) $item['next_run_at'] );
			}
		}

		// At least 30 s out so WP-Cron records the event before it fires again.
		wp_schedule_single_event(
			PHP_INT_MAX !== $min_next ? max( $now + 30, $min_next ) : $now + MINUTE_IN_SECONDS,
			self::CRON_HOOK
		);
	}
}
