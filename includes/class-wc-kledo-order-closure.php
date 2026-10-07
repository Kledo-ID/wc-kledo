<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Confirms with Kledo that a synced order has been closed, then reflects that in WooCommerce.
 *
 * The Kledo `/woocommerce/*` write endpoints are asynchronous: they dispatch a queue job and answer
 * immediately, so a successful HTTP 200 only means "accepted", never "processed". Once Kledo has
 * created the invoice and linked it to the order, it closes the order itself. This class polls the
 * read endpoint until that happens (or until the budget runs out) and records the outcome.
 *
 * @since 1.7.4
 */
class WC_Kledo_Order_Closure {
	/**
	 * Cron hook name for the confirmation loop.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	public const CRON_HOOK = 'wc_kledo_check_order_closure';

	/**
	 * Maximum confirmation attempts per order.
	 *
	 * Deliberately shorter than the delivery retry budget: this is not a failure being retried,
	 * it is a success waiting to be confirmed.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * Maximum age of a pending confirmation before it is abandoned.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const MAX_LIFETIME = 12 * HOUR_IN_SECONDS;

	/**
	 * How long an abandoned row is kept for audit before it is pruned.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const GAVE_UP_RETENTION = 30 * DAY_IN_SECONDS;

	/**
	 * Lock transient guarding a confirmation run.
	 *
	 * Separate from `wc_kledo_retry_lock` on purpose — sharing one lock would let the delivery
	 * retry loop and this loop block each other.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	private const LOCK_KEY = 'wc_kledo_closure_lock';

	/**
	 * Register the cron handler and the admin fallback.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, array( $this, 'process_due_checks' ) );

		// WP-Cron spawns an async HTTP request to wp-cron.php, which silently fails wherever the
		// server cannot reach itself (localhost, strict firewall, DISABLE_WP_CRON). Without this
		// fallback the confirmation would simply never run in those shops.
		add_action( 'admin_init', array( $this, 'maybe_process_due_checks' ) );
	}

	/**
	 * Admin-side fallback: run the loop when at least one row is overdue.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function maybe_process_due_checks(): void {
		// Skip AJAX and WP-Cron contexts — they have their own execution paths.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$queue = get_option( wc_kledo_get_closure_queue_option_name(), array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		$now = time();

		foreach ( $queue as $item ) {
			if ( 'pending' === ( $item['status'] ?? '' )
				&& isset( $item['next_run_at'] )
				&& (int) $item['next_run_at'] <= $now ) {
				$this->process_due_checks();

				return;
			}
		}
	}

	/**
	 * Walk every due confirmation row once.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function process_due_checks(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		$option_name = wc_kledo_get_closure_queue_option_name();
		$queue       = get_option( $option_name, array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 60 );

		$now                 = time();
		$updated_queue       = array();
		$next_check_required = false;

		wc_kledo_log_info(
			sprintf( 'Kledo closure run started: %d row(s) in queue.', count( $queue ) )
		);

		foreach ( $queue as $key => $item ) {
			$order_id = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;

			if ( $order_id <= 0 ) {
				continue;
			}

			$status = isset( $item['status'] ) ? (string) $item['status'] : 'pending';

			// Abandoned rows stay for audit, but only for a bounded time — otherwise the option
			// grows forever in a shop where orders regularly fail to link.
			if ( 'pending' !== $status ) {
				$created = isset( $item['created_at'] ) ? (int) $item['created_at'] : $now;

				if ( ( $now - $created ) <= self::GAVE_UP_RETENTION ) {
					$updated_queue[ $key ] = $item;
				}

				continue;
			}

			$next_run = isset( $item['next_run_at'] ) ? (int) $item['next_run_at'] : $now;

			if ( $next_run > $now ) {
				$updated_queue[ $key ] = $item;
				$next_check_required   = true;

				continue;
			}

			$order = wc_get_order( $order_id );

			// The order is gone; there is nothing left to confirm.
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			// Already confirmed by an earlier run or by the manual admin action.
			if ( wc_kledo_is_order_closed_in_kledo( $order ) ) {
				continue;
			}

			$created  = isset( $item['created_at'] ) ? (int) $item['created_at'] : $now;
			$attempts = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;

			if ( ( $now - $created ) > self::MAX_LIFETIME ) {
				$updated_queue[ $key ] = $this->give_up(
					$order,
					$item,
					__( 'Kledo: stopped waiting for the Kledo order to close — the maximum wait time was reached. Check the order in Kledo manually.', 'wc-kledo' )
				);

				continue;
			}

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				$updated_queue[ $key ] = $this->give_up(
					$order,
					$item,
					sprintf(
						/* translators: %d: number of attempts made */
						__( 'Kledo: stopped waiting for the Kledo order to close after %d checks. Check the order in Kledo manually.', 'wc-kledo' ),
						$attempts
					)
				);

				continue;
			}

			++$attempts;
			$item['attempts'] = $attempts;

			$outcome = $this->check_order( $order );

			if ( 'closed' === $outcome['result'] ) {
				// Confirmed: nothing more to poll for, so the row leaves the queue entirely.
				continue;
			}

			if ( 'gave_up' === $outcome['result'] ) {
				$updated_queue[ $key ] = $this->give_up( $order, $item, $outcome['note'] );

				continue;
			}

			// Still pending (or a transient error): advance one backoff step and come back.
			$item['next_run_at']   = $now + wc_kledo_get_retry_delay( $attempts + 1 );
			$item['status']        = 'pending';
			$updated_queue[ $key ] = $item;
			$next_check_required   = true;
		}

		update_option( $option_name, $updated_queue, false );

		if ( $next_check_required && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$min_next = PHP_INT_MAX;

			foreach ( $updated_queue as $queue_item ) {
				if ( 'pending' === ( $queue_item['status'] ?? '' ) && isset( $queue_item['next_run_at'] ) ) {
					$min_next = min( $min_next, (int) $queue_item['next_run_at'] );
				}
			}

			// Keep the event at least 30 s out so WP-Cron can record it before it fires again.
			$schedule_at = PHP_INT_MAX !== $min_next
				? max( $now + 30, $min_next )
				: $now + MINUTE_IN_SECONDS;

			wp_schedule_single_event( $schedule_at, self::CRON_HOOK );
		}

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Ask Kledo about one order and act on the answer.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array{result: string, note: string} `result` is one of closed, pending, gave_up.
	 * @since 1.7.4
	 */
	public function check_order( WC_Order $order ): array {
		$request = new WC_Kledo_Request_Transaction_Status();

		try {
			$response = $request->get_status( $order->get_id() );
		} catch ( Throwable $exception ) {
			wc_kledo_log_warning(
				sprintf(
					'Kledo closure check failed for order %d: %s',
					$order->get_id(),
					wc_kledo_sanitize_api_error_message( $exception->getMessage() )
				)
			);

			return array(
				'result' => 'pending',
				'note'   => '',
			);
		}

		$response_code = (int) $request->get_response_code();

		if ( false === $response ) {
			// A payload Kledo rejects deterministically will be rejected the same way forever, so
			// the same classifier the delivery path uses decides whether waiting is pointless.
			if ( wc_kledo_is_permanent_api_failure( $response_code ) ) {
				return array(
					'result' => 'gave_up',
					'note'   => sprintf(
						/* translators: %d: HTTP status code */
						__( 'Kledo: stopped waiting for the Kledo order to close — Kledo rejected the status request (HTTP %d). Check the order in Kledo manually.', 'wc-kledo' ),
						$response_code
					),
				);
			}

			return array(
				'result' => 'pending',
				'note'   => '',
			);
		}

		$status = wc_kledo_read_transaction_status( $response );

		$kledo_order   = $status['order'];
		$kledo_invoice = $status['invoice'];
		$linked        = $status['linked'];

		// The invoice exists but there is no Kledo sales order to close at all — normal when
		// "Enable Create Order" is off, in which case waiting is meaningless rather than broken.
		if ( null !== $kledo_invoice && null === $kledo_order ) {
			return array(
				'result' => 'gave_up',
				'note'   => __( 'Kledo: the invoice exists in Kledo but there is no matching Kledo sales order, so there is nothing to close. This is expected when "Enable Create Order" is turned off.', 'wc-kledo' ),
			);
		}

		// Both exist but the invoice was created without a link to the order — that happens when
		// the order items changed between "processing" and "completed". Kledo will never close the
		// order on its own in that case, so waiting out the whole budget is pointless.
		if ( null !== $kledo_invoice && ! $linked ) {
			return array(
				'result' => 'gave_up',
				'note'   => __( 'Kledo: the Kledo invoice was created without a link to the Kledo order, so the order will not be closed automatically. This usually means the order items changed between Processing and Completed. Close the order in Kledo manually if needed.', 'wc-kledo' ),
			);
		}

		// `is_closed` on the order is the only signal that triggers closure on the WooCommerce side.
		if ( null !== $kledo_order && ! empty( $kledo_order['is_closed'] ) ) {
			$this->apply_closure( $order, $kledo_order );

			return array(
				'result' => 'closed',
				'note'   => '',
			);
		}

		return array(
			'result' => 'pending',
			'note'   => '',
		);
	}

	/**
	 * Record a confirmed closure on the WooCommerce order.
	 *
	 * The WooCommerce order status is deliberately left alone. `post_status` is a single field
	 * owned by WooCommerce and read by every other plugin in the shop, so writing a Kledo-specific
	 * value into it changes what those plugins see; the meta and the note below carry the same
	 * information without that cost.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $kledo_order  The `data.order` payload.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	private function apply_closure( WC_Order $order, array $kledo_order ): void {
		// Guarded here rather than only at the call sites. `process_due_checks()` already drops
		// confirmed rows before reaching this, but `check_single_order()` runs `check_order()`
		// straight through — its only protection is the admin UI hiding the action once the meta
		// is set, and a hidden button is not a guarantee about the code behind it.
		if ( wc_kledo_is_order_closed_in_kledo( $order ) ) {
			return;
		}

		$reference = wc_kledo_get_kledo_reference( $kledo_order );

		wc_kledo_mark_order_closed_in_kledo( $order );

		$order->add_order_note(
			sprintf(
				/* translators: %s: Kledo sales order reference number */
				__( 'Kledo: sales order %s has been closed in Kledo (fully invoiced).', 'wc-kledo' ),
				$reference
			)
		);

		wc_kledo_log_info(
			sprintf( 'Order %d recorded as closed in Kledo.', $order->get_id() )
		);
	}

	/**
	 * Mark a row abandoned and explain why on the order.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $item  The queue row.
	 * @param  string    $note  Order note explaining the outcome.
	 *
	 * @return array The updated queue row.
	 * @since 1.7.4
	 */
	private function give_up( WC_Order $order, array $item, string $note ): array {
		$item['status'] = 'gave_up';

		unset( $item['next_run_at'] );

		if ( '' !== $note ) {
			$order->add_order_note( $note );
		}

		wc_kledo_log_warning(
			sprintf( 'Kledo closure check gave up on order %d.', $order->get_id() )
		);

		return $item;
	}

	/**
	 * Confirm a single order on demand, for the admin order action.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array{result: string, note: string}
	 * @since 1.7.4
	 */
	public function check_single_order( WC_Order $order ): array {
		$outcome = $this->check_order( $order );

		if ( 'closed' === $outcome['result'] ) {
			wc_kledo_remove_closure_check( $order->get_id() );

			return $outcome;
		}

		if ( 'gave_up' === $outcome['result'] ) {
			$option_name = wc_kledo_get_closure_queue_option_name();
			$queue       = get_option( $option_name, array() );
			$key         = (string) $order->get_id();

			if ( is_array( $queue ) && isset( $queue[ $key ] ) && is_array( $queue[ $key ] ) ) {
				$queue[ $key ] = $this->give_up( $order, $queue[ $key ], $outcome['note'] );
				update_option( $option_name, $queue, false );
			} elseif ( '' !== $outcome['note'] ) {
				$order->add_order_note( $outcome['note'] );
			}
		}

		return $outcome;
	}
}
