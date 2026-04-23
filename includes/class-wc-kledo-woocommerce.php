<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_WooCommerce {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		//
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
	}

	/**
	 * Send invoice to kledo (automatic: order completed).
	 *
	 * @param  int  $order_id
	 * @param  \WC_Order  $order
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
	 * @param  int  $order_id
	 * @param  \WC_Order  $order
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
	 * @param  \WC_Order  $order
	 * @param  string  $type  `order` or `invoice`.
	 * @param  array  $context {
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
	 *     reason: ?string
	 * }
	 * @since 1.6.0
	 */
	public function deliver( WC_Order $order, string $type, array $context = array() ): array {
		$defaults = array(
			'trigger'             => 'status_transition',
			'force_if_synced'     => false,
			'enqueue_on_failure'  => null,
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
					__( 'Kledo: API connection is disabled in plugin settings; sync was not attempted.', WC_KLEDO_TEXT_DOMAIN )
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
						__( 'Kledo: %s was already synced; removed stale entry from failed queue.', WC_KLEDO_TEXT_DOMAIN ),
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

			$response_code = method_exists( $request, 'get_response_code' ) ? (int) $request->get_response_code() : 0;
			$out['http_code'] = $response_code;

			if ( false !== $result && 200 === $response_code ) {
				wc_kledo_mark_delivery_synced( $order, $type );
				$out['success'] = true;

				if ( 'manual_admin' === $context['trigger'] ) {
					$manual_mode = isset( $context['manual_mode'] ) ? (string) $context['manual_mode'] : '';

					if ( 'resend' === $manual_mode || ( $is_synced && ! empty( $context['force_if_synced'] ) ) ) {
						$order->add_order_note(
							sprintf(
								/* translators: %s: order or invoice */
								__( 'Kledo: admin manually re-sent %s to Kledo (record was already marked synced; possible duplicate in Kledo).', WC_KLEDO_TEXT_DOMAIN ),
								$type
							)
						);
					} else {
						$order->add_order_note(
							sprintf(
								/* translators: %s: order or invoice */
								__( 'Kledo: admin manually sent %s to Kledo (first manual push; automatic sync still uses order status transitions).', WC_KLEDO_TEXT_DOMAIN ),
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

			$error_message = sprintf(
				/* translators: 1: HTTP status code */
				__( 'Kledo: failed to send %1$s to Kledo (HTTP %2$d). Will retry automatically.', WC_KLEDO_TEXT_DOMAIN ),
				$type,
				$response_code
			);

			if ( 'manual_admin' === $context['trigger'] ) {
				$error_message = sprintf(
					/* translators: 1: type, 2: HTTP code */
					__( 'Kledo: admin manual push failed for %1$s (HTTP %2$d).', WC_KLEDO_TEXT_DOMAIN ),
					$type,
					$response_code
				);
			}

			if ( 'retry' !== $context['trigger'] ) {
				$order->add_order_note( $error_message );
			}
			$out['error'] = wc_kledo_sanitize_api_error_message(
				$request->get_api_error_label()
			);

			if ( $context['enqueue_on_failure'] ) {
				wc_kledo_add_failed_transaction_to_queue( $order->get_id(), $type, $out['error'] );
			}

			wc_kledo_log_warning(
				sprintf(
					'Kledo delivery failed type=%s order_id=%d trigger=%s http=%d error=%s',
					$type,
					$order->get_id(),
					$context['trigger'],
					$response_code,
					$out['error']
				)
			);
		} catch ( Throwable $e ) {
			$safe_detail = wc_kledo_sanitize_api_error_message( $e->getMessage() );
			$out['error']  = $safe_detail;

			$error_message = sprintf(
				/* translators: 1: type, 2: error detail */
				__( 'Kledo: error when sending %1$s to Kledo: %2$s. Will retry automatically.', WC_KLEDO_TEXT_DOMAIN ),
				$type,
				$safe_detail
			);

			if ( 'manual_admin' === $context['trigger'] ) {
				$error_message = sprintf(
					/* translators: 1: type, 2: error */
					__( 'Kledo: admin manual push error for %1$s: %2$s', WC_KLEDO_TEXT_DOMAIN ),
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
}
