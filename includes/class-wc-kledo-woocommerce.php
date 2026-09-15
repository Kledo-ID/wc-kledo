<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_WooCommerce {
	/**
	 * Sales order delivery outcomes already attempted in this request, keyed by WooCommerce order id.
	 *
	 * Completing an order fires two callbacks that each want the sales order to exist, and the
	 * invoice path will create a missing one itself. Without a memo a sales order that fails at the
	 * first callback is attempted again a moment later in the same request — two writes to Kledo for
	 * one transition, which is exactly how a duplicate appears when the first call in fact landed
	 * and only its response went missing.
	 *
	 * Per request by design: it is a guard against double-firing within one page load, not a cache
	 * of what Kledo holds. The retry queue re-attempts across requests as before.
	 *
	 * @var array<int, bool>
	 * @since 1.7.4
	 */
	private array $sales_order_attempted = array();

	/**
	 * Answers already received this request about whether a sales order exists in Kledo, keyed by
	 * WooCommerce order id.
	 *
	 * A Completed transition runs two callbacks that both want this answer; without a memo the
	 * second spends another API call to be told what the first already learned. False is memoised
	 * too — a queue job that had not finished microseconds ago has not finished now, and the point
	 * of re-asking is the next cron pass, not the next line of this one.
	 *
	 * @var array<int, bool>
	 * @since 1.7.4
	 */
	private array $sales_order_confirmed = array();

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
	}

	/**
	 * Set up the hooks.
	 *
	 * If API connection disabled return early.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setup_hooks(): void {
		$is_enable = wc_string_to_bool( get_option( WC_Kledo_Configure_Screen::SETTING_ENABLE_API_CONNECTION, 'yes' ) );

		if ( ! $is_enable ) {
			return;
		}

		add_action( 'woocommerce_order_status_processing', array( $this, 'create_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'create_invoice' ), 10, 2 );

		// Completed is reachable without ever passing through Processing — a shop selling downloads
		// or services routinely moves an order straight there, and so does the Orders list bulk
		// action. Only the Processing hook above created the sales order, so those orders used to
		// reach Kledo as an invoice with no sales order behind it. `deliver()` creates the missing
		// one ahead of the invoice; this hook exists so the sales order is still created when
		// invoicing itself is switched off.
		add_action( 'woocommerce_order_status_completed', array( $this, 'create_order' ), 5, 2 );
	}

	/**
	 * Send invoice to kledo (automatic: order completed).
	 *
	 * @param  int       $order_id
	 * @param  \WC_Order $order
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function create_invoice( int $order_id, WC_Order $order ): void {
		$this->deliver(
			$order,
			'invoice',
			array(
				'trigger' => 'status_transition',
			)
		);
	}

	/**
	 * Send order to kledo (automatic: processing).
	 *
	 * @param  int       $order_id
	 * @param  \WC_Order $order
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function create_order( int $order_id, WC_Order $order ): void {
		$this->deliver(
			$order,
			'order',
			array(
				'trigger' => 'status_transition',
			)
		);
	}

	/**
	 * Shared delivery pipeline for Kledo sales order or invoice.
	 *
	 * @param  \WC_Order $order
	 * @param  string    $type  `order` or `invoice`.
	 * @param  array     $context {
	 *     @type string $trigger  `status_transition` | `retry` | `manual_admin`
	 *     @type bool   $force_if_synced  When true, call the API even if the order is already marked synced (explicit admin intent; may duplicate in Kledo).
	 *     @type bool   $enqueue_on_failure  When false, do not add/update the failed-transactions queue (retry handlers update the queue themselves).
	 *     @type string $manual_mode  Optional. `first` or `resend` when trigger is `manual_admin` (for notes/logging).
	 * }
	 *
	 * @return array{
	 *     success: bool,
	 *     skipped: bool,
	 *     http_code: int,
	 *     error: ?string,
	 *     reason: ?string,
	 *     permanent: bool
	 * }
	 * @since 1.6.0
	 */
	public function deliver( WC_Order $order, string $type, array $context = array() ): array {
		$defaults = array(
			'trigger'            => 'status_transition',
			'force_if_synced'    => false,
			'enqueue_on_failure' => null,
		);

		$context = array_merge( $defaults, $context );

		if ( null === $context['enqueue_on_failure'] ) {
			$context['enqueue_on_failure'] = ( 'retry' !== $context['trigger'] );
		}

		$out = array(
			'success'   => false,
			'skipped'   => false,
			'http_code' => 0,
			'error'     => null,
			'reason'    => null,
			'permanent' => false,
		);

		if ( ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
			$out['reason'] = 'invalid_type';

			return $out;
		}

		$api_on = wc_string_to_bool( get_option( WC_Kledo_Configure_Screen::SETTING_ENABLE_API_CONNECTION, 'yes' ) );

		if ( ! $api_on ) {
			$out['skipped'] = true;
			$out['reason']  = 'api_disabled';

			if ( 'manual_admin' === $context['trigger'] ) {
				$order->add_order_note(
					__( 'Kledo: API connection is disabled in plugin settings; sync was not attempted.', 'wc-kledo' )
				);
			}

			return $out;
		}

		if ( 'invoice' === $type ) {
			$feature_on = wc_string_to_bool(
				get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME, 'yes' )
			);
		} else {
			$feature_on = wc_string_to_bool(
				get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME, 'yes' )
			);
		}

		if ( ! $feature_on ) {
			$out['skipped'] = true;
			$out['reason']  = 'feature_disabled';

			return $out;
		}

		$is_synced    = wc_kledo_is_delivery_synced( $order, $type );
		$filter_force = (bool) apply_filters( 'wc_kledo_force_resend_delivery', false, $order, $type );
		$force        = ! empty( $context['force_if_synced'] ) || $filter_force;

		if ( $is_synced && ! $force ) {
			$out['skipped'] = true;
			$out['reason']  = 'already_synced';

			if ( 'retry' === $context['trigger'] ) {
				wc_kledo_remove_failed_transaction_from_queue( $order->get_id(), $type );
				$order->add_order_note(
					sprintf(
						/* translators: %s: transaction type (order/invoice) */
						__( 'Kledo: %s was already synced; removed stale entry from failed queue.', 'wc-kledo' ),
						$type
					)
				);
			}

			return $out;
		}

		wc_kledo_log_info(
			sprintf(
				'Kledo delivery attempt type=%s order_id=%d trigger=%s force=%s',
				$type,
				$order->get_id(),
				$context['trigger'],
				$force ? '1' : '0'
			)
		);

		if ( 'order' === $type ) {
			$this->sales_order_attempted[ $order->get_id() ] = true;
		}

		// An invoice that Kledo is asked to link needs its sales order to already exist over there;
		// Kledo matches the two on `ref_number`, and nothing in the invoice payload carries a sales
		// order id for it to fall back on. Done here rather than in the status hook so every path
		// into an invoice — status transition, retry queue, manual push from the Transactions
		// screen — gets the same ordering instead of only the one that happened to be patched.
		if ( 'invoice' === $type && ! $this->ensure_linked_sales_order( $order, $context, $out ) ) {
			return $out;
		}

		try {
			// Fire integration hook inside try/catch so any hooked callback that throws
			// is caught and treated as a delivery failure instead of propagating.
			if ( 'invoice' === $type ) {
				do_action( 'wc_kledo_create_invoice', $order->get_id(), $order );
				$request = new WC_Kledo_Request_Invoice();
				$result  = $request->create_invoice( $order );
			} else {
				do_action( 'wc_kledo_create_order', $order->get_id() );
				$request = new WC_Kledo_Request_Order();
				$result  = $request->create_order( $order );
			}

			$response_code    = method_exists( $request, 'get_response_code' ) ? (int) $request->get_response_code() : 0;
			$out['http_code'] = $response_code;

			if ( false !== $result && 200 === $response_code ) {
				wc_kledo_mark_delivery_synced( $order, $type );
				$out['success'] = true;

				// Kledo closes its own sales order once the invoice is linked and every quantity is
				// billed, but the write endpoints are asynchronous — a 200 only means "accepted".
				// Queue a confirmation so the closure can be reflected here when it actually happens.
				// Invoice only: the sales order alone can never close anything.
				if ( 'invoice' === $type ) {
					$link_order  = wc_kledo_link_invoice_to_order();
					$close_order = wc_kledo_close_order_on_invoice();

					wc_kledo_record_invoice_link_settings( $order, $link_order, $close_order );

					if ( 'yes' === $link_order && 'yes' === $close_order ) {
						wc_kledo_enqueue_closure_check( $order->get_id() );
					} else {
						// No closure is coming from this invoice, so polling for one would spend
						// the whole budget only to end in a note saying it gave up. Drop any row
						// left over from an earlier send made under different settings, which
						// would otherwise keep polling for this same order.
						wc_kledo_remove_closure_check( $order->get_id() );

						wc_kledo_log_info(
							sprintf(
								'Closure check skipped for order %d: link_order=%s, close_order=%s.',
								$order->get_id(),
								$link_order,
								$close_order
							)
						);
					}
				}

				if ( 'manual_admin' === $context['trigger'] ) {
					$manual_mode = isset( $context['manual_mode'] ) ? (string) $context['manual_mode'] : '';

					if ( 'resend' === $manual_mode || ( $is_synced && ! empty( $context['force_if_synced'] ) ) ) {
						$order->add_order_note(
							sprintf(
								/* translators: %s: order or invoice */
								__( 'Kledo: admin manually re-sent %s to Kledo (record was already marked synced; possible duplicate in Kledo).', 'wc-kledo' ),
								$type
							)
						);
					} else {
						$order->add_order_note(
							sprintf(
								/* translators: %s: order or invoice */
								__( 'Kledo: admin manually sent %s to Kledo (first manual push; automatic sync still uses order status transitions).', 'wc-kledo' ),
								$type
							)
						);
					}
				}

				$log_suffix = '';

				if ( 'manual_admin' === $context['trigger'] && ! empty( $context['manual_mode'] ) ) {
					$log_suffix = ' manual_mode=' . $context['manual_mode'];
				}

				wc_kledo_log_info(
					sprintf(
						'Kledo delivery success type=%s order_id=%d trigger=%s force=%s%s',
						$type,
						$order->get_id(),
						$context['trigger'],
						$force ? '1' : '0',
						$log_suffix
					)
				);

				return $out;
			}

			$out['error'] = wc_kledo_sanitize_api_error_message(
				$request->get_api_error_label()
			);

			// A payload Kledo rejects on validation grounds will be rejected identically on every
			// retry, so it is reported once and kept out of the retry queue entirely.
			$is_permanent     = wc_kledo_is_permanent_api_failure( $response_code );
			$out['permanent'] = $is_permanent;

			if ( $is_permanent ) {
				$error_message = sprintf(
					/* translators: 1: transaction type (order/invoice), 2: HTTP status code, 3: API error message */
					__( 'Kledo: %1$s was rejected by Kledo (HTTP %2$d): %3$s. This will not be retried automatically — correct the data, then resend it from WooCommerce > Kledo > Transactions.', 'wc-kledo' ),
					$type,
					$response_code,
					$out['error']
				);

				if ( 'manual_admin' === $context['trigger'] ) {
					$error_message = sprintf(
						/* translators: 1: transaction type (order/invoice), 2: HTTP status code, 3: API error message */
						__( 'Kledo: admin manual push for %1$s was rejected by Kledo (HTTP %2$d): %3$s. Retrying will not help until the data is corrected.', 'wc-kledo' ),
						$type,
						$response_code,
						$out['error']
					);
				}
			} else {
				$error_message = sprintf(
					/* translators: 1: transaction type (order/invoice), 2: HTTP status code */
					__( 'Kledo: failed to send %1$s to Kledo (HTTP %2$d). Will retry automatically.', 'wc-kledo' ),
					$type,
					$response_code
				);

				if ( 'manual_admin' === $context['trigger'] ) {
					$error_message = sprintf(
						/* translators: 1: type, 2: HTTP code */
						__( 'Kledo: admin manual push failed for %1$s (HTTP %2$d).', 'wc-kledo' ),
						$type,
						$response_code
					);
				}
			}

			if ( 'retry' !== $context['trigger'] ) {
				$order->add_order_note( $error_message );
			}

			// When the caller is the retry loop it owns the queue row itself and reacts to
			// $out['permanent']; writing from here would be overwritten a moment later.
			if ( $context['enqueue_on_failure'] ) {
				if ( $is_permanent ) {
					wc_kledo_mark_transaction_permanently_failed( $order->get_id(), $type, $out['error'] );
				} else {
					wc_kledo_add_failed_transaction_to_queue( $order->get_id(), $type, $out['error'] );
				}
			}

			wc_kledo_log_warning(
				sprintf(
					'Kledo delivery failed type=%s order_id=%d trigger=%s http=%d permanent=%s error=%s',
					$type,
					$order->get_id(),
					$context['trigger'],
					$response_code,
					$is_permanent ? '1' : '0',
					$out['error']
				)
			);
		} catch ( Throwable $e ) {
			$safe_detail  = wc_kledo_sanitize_api_error_message( $e->getMessage() );
			$out['error'] = $safe_detail;

			$error_message = sprintf(
				/* translators: 1: type, 2: error detail */
				__( 'Kledo: error when sending %1$s to Kledo: %2$s. Will retry automatically.', 'wc-kledo' ),
				$type,
				$safe_detail
			);

			if ( 'manual_admin' === $context['trigger'] ) {
				$error_message = sprintf(
					/* translators: 1: type, 2: error */
					__( 'Kledo: admin manual push error for %1$s: %2$s', 'wc-kledo' ),
					$type,
					$safe_detail
				);
			}

			if ( 'retry' !== $context['trigger'] ) {
				$order->add_order_note( $error_message );
			}

			if ( $context['enqueue_on_failure'] ) {
				wc_kledo_add_failed_transaction_to_queue( $order->get_id(), $type, $safe_detail );
			}

			wc_kledo_log_warning(
				sprintf(
					'Kledo delivery exception type=%s order_id=%d trigger=%s message=%s',
					$type,
					$order->get_id(),
					$context['trigger'],
					$safe_detail
				)
			);
		}

		return $out;
	}

	/**
	 * Make sure the Kledo sales order exists before an invoice is asked to link to it.
	 *
	 * Sends the sales order first when this plugin is the thing that creates them and this order
	 * has not been sent yet, which is the ordinary case for an order moved straight to Completed.
	 * An order that already synced costs nothing here: `deliver()` answers `already_synced` without
	 * calling the API.
	 *
	 * When the sales order cannot be delivered the invoice is held back rather than sent anyway.
	 * Sending it would produce an invoice in Kledo that is permanently unlinked — the sales order
	 * stays open with nothing billed against it, and no later retry can repair the link, because
	 * the invoice already exists. Holding it costs a delay; sending it costs a wrong book.
	 *
	 * Only applies while linking is on. An invoice that was never going to be recorded against a
	 * sales order does not depend on one existing, so it is sent whatever happened above.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $context  The delivery context, as assembled by `deliver()`.
	 * @param  array     $out      Delivery result, updated in place when the invoice is held.
	 *
	 * @return bool False when the caller should stop and return `$out` as-is.
	 * @since 1.7.4
	 */
	private function ensure_linked_sales_order( WC_Order $order, array $context, array &$out ): bool {
		if ( 'yes' !== wc_kledo_link_invoice_to_order() ) {
			return true;
		}

		// A shop that creates its sales orders somewhere other than this plugin would otherwise
		// have every invoice held forever waiting for one this plugin is never going to send.
		$order_feature_on = wc_string_to_bool(
			get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME, 'yes' )
		);

		if ( ! $order_feature_on ) {
			return true;
		}

		if ( ! wc_kledo_is_delivery_synced( $order, 'order' ) ) {
			// Already tried in this request — by the Completed hook, most likely — and it did not
			// stick, or `wc_kledo_is_delivery_synced()` above would have answered. Trying again now
			// would be a second write for the same transition, not a second chance.
			if ( isset( $this->sales_order_attempted[ $order->get_id() ] ) ) {
				return $this->hold_invoice_for_sales_order( $order, $context, $out, null, 'send_failed' );
			}

			$order_result = $this->deliver(
				$order,
				'order',
				array(
					'trigger'            => $context['trigger'],
					// Forced on even under `retry`, where it would otherwise default off: the invoice
					// is what the retry loop pulled off the queue, so a sales order failing underneath
					// it has no row of its own yet and would be forgotten.
					'enqueue_on_failure' => true,
				)
			);

			if ( empty( $order_result['success'] ) && empty( $order_result['skipped'] ) ) {
				return $this->hold_invoice_for_sales_order( $order, $context, $out, $order_result['error'], 'send_failed' );
			}
		}

		// Marked synced only means Kledo answered 200, and 200 on these endpoints means the write
		// was queued, not performed — `WC_Kledo_Request_Transaction_Status` documents that. The
		// invoice is matched to its sales order by `ref_number` on Kledo's side, so sending it
		// while that queue job is still outstanding is the race this check exists to close: ask the
		// read endpoint whether the sales order is actually there yet.
		if ( $this->sales_order_exists_in_kledo( $order ) ) {
			return true;
		}

		return $this->hold_invoice_for_sales_order( $order, $context, $out, null, 'not_processed_yet' );
	}

	/**
	 * Ask Kledo whether the sales order for this WooCommerce order exists yet.
	 *
	 * `data.order` is null for exactly as long as Kledo's queue job has not produced the sales
	 * order, which is what separates "not yet" from "never". Anything unreadable — transport
	 * failure, a rejection, a body that is not JSON — is answered as "not yet" rather than "no":
	 * the cost of waiting another minute is a delay, while the cost of a wrong "yes" is an invoice
	 * that can never be linked afterwards.
	 *
	 * Memoised per request so the two callbacks a Completed transition fires do not each spend a
	 * call asking the same question.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return bool
	 * @since 1.7.4
	 */
	private function sales_order_exists_in_kledo( WC_Order $order ): bool {
		$order_id = $order->get_id();

		if ( isset( $this->sales_order_confirmed[ $order_id ] ) ) {
			return $this->sales_order_confirmed[ $order_id ];
		}

		$request  = new WC_Kledo_Request_Transaction_Status();
		$response = false;

		try {
			$response = $request->get_status( $order_id );
		} catch ( Throwable $exception ) {
			wc_kledo_log_warning(
				sprintf(
					'Kledo sales order check failed for order %d: %s',
					$order_id,
					wc_kledo_sanitize_api_error_message( $exception->getMessage() )
				)
			);
		}

		$exists = false;

		if ( is_array( $response ) ) {
			$data        = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			$kledo_order = isset( $data['order'] ) && is_array( $data['order'] ) ? $data['order'] : array();
			$exists      = ! empty( $kledo_order['id'] );
		}

		$this->sales_order_confirmed[ $order_id ] = $exists;

		wc_kledo_log_info(
			sprintf(
				'Kledo sales order presence for order %d: %s.',
				$order_id,
				$exists ? 'present' : 'not yet'
			)
		);

		return $exists;
	}

	/**
	 * Keep an invoice back until the sales order it is meant to link to exists.
	 *
	 * The invoice is queued rather than dropped, ahead of nothing and behind the sales order's own
	 * queue row, so the retry loop reaches them in that order — the queue is keyed `type:order_id`
	 * and walked in insertion order.
	 *
	 * The two reasons are worth telling apart in the order notes. `send_failed` is a problem the
	 * shop may have to act on; `not_processed_yet` is the ordinary case of Kledo still working
	 * through its queue, and reporting that as a failure would send someone looking for a fault
	 * that is not there.
	 *
	 * @param  \WC_Order   $order
	 * @param  array       $context  The delivery context, as assembled by `deliver()`.
	 * @param  array       $out      Delivery result, updated in place.
	 * @param  string|null $error    The sales order's own error, when there is one to carry over.
	 * @param  string      $reason   `send_failed` or `not_processed_yet`.
	 *
	 * @return bool Always false, so the caller can `return` it directly.
	 * @since 1.7.4
	 */
	private function hold_invoice_for_sales_order( WC_Order $order, array $context, array &$out, ?string $error, string $reason ): bool {
		$out['skipped'] = true;
		$out['reason']  = 'awaiting_sales_order';
		$out['error']   = $error;

		$queue_note = 'not_processed_yet' === $reason
			? __( 'Waiting for Kledo to finish creating the sales order.', 'wc-kledo' )
			: __( 'Waiting for the Kledo sales order to be created first.', 'wc-kledo' );

		if ( ! empty( $context['enqueue_on_failure'] ) ) {
			wc_kledo_add_failed_transaction_to_queue( $order->get_id(), 'invoice', $queue_note );
		}

		// The retry loop is noisy enough without a note on every pass.
		if ( 'retry' !== $context['trigger'] ) {
			$order->add_order_note(
				'not_processed_yet' === $reason
					? __( 'Kledo: the invoice is waiting for Kledo to finish creating its sales order. Kledo accepted the sales order but has not processed it yet, and an invoice sent before it exists could never be linked to it. The invoice will be sent automatically as soon as the sales order is there.', 'wc-kledo' )
					: __( 'Kledo: the invoice was held back because its sales order could not be created in Kledo yet. Sending the invoice now would leave it permanently unlinked. Both will be retried automatically, sales order first.', 'wc-kledo' )
			);
		}

		wc_kledo_log_warning(
			sprintf(
				'Kledo invoice held for order_id=%d: sales order not in Kledo yet (trigger=%s).',
				$order->get_id(),
				$context['trigger']
			)
		);

		return false;
	}
}
