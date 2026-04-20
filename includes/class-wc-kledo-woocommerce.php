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
	 * Send invoice to kledo.
	 *
	 * @param  int  $order_id
	 * @param  \WC_Order  $order
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_invoice( int $order_id, WC_Order $order): void {
		$is_enable = wc_string_to_bool(
			get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME, 'yes' )
		);

		if ( ! $is_enable ) {
			return;
		}

		if (
			wc_kledo_is_delivery_synced( $order, 'invoice' )
			&& ! apply_filters( 'wc_kledo_force_resend_delivery', false, $order, 'invoice' )
		) {
			return;
		}

		do_action( 'wc_kledo_create_invoice', $order_id, $order );

		try {
			$request = new WC_Kledo_Request_Invoice();
			$result  = $request->create_invoice( $order );

			$response_code = method_exists( $request, 'get_response_code' ) ? (int) $request->get_response_code() : 0;

			if ( false !== $result && 200 === $response_code ) {
				wc_kledo_mark_delivery_synced( $order, 'invoice' );

				return;
			}

			$error_message = sprintf(
				/* translators: 1: HTTP status code */
				__( 'Kledo: failed to send invoice to Kledo (HTTP %d). Will retry automatically.', WC_KLEDO_TEXT_DOMAIN ),
				$response_code
			);

			$order->add_order_note( $error_message );

			wc_kledo_add_failed_transaction_to_queue( $order_id, 'invoice', $error_message );
		} catch ( Throwable $e ) {
			$safe_detail = wc_kledo_sanitize_api_error_message( $e->getMessage() );
			$error_message = sprintf(
				__( 'Kledo: error when sending invoice to Kledo: %s. Will retry automatically.', WC_KLEDO_TEXT_DOMAIN ),
				$safe_detail
			);

			$order->add_order_note( $error_message );

			wc_kledo_add_failed_transaction_to_queue( $order_id, 'invoice', $safe_detail );
		}
	}

	/**
	 * Send order to kledo.
	 *
	 * @param  int  $order_id
	 * @param  \WC_Order  $order
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.3.0
	 */
	public function create_order( int $order_id, WC_Order $order ): void {
		$is_enable = wc_string_to_bool(
			get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME , 'yes')
		);

		if ( ! $is_enable ) {
			return;
		}

		if (
			wc_kledo_is_delivery_synced( $order, 'order' )
			&& ! apply_filters( 'wc_kledo_force_resend_delivery', false, $order, 'order' )
		) {
			return;
		}

		do_action( 'wc_kledo_create_order', $order_id );

		try {
			$request = new WC_Kledo_Request_Order();
			$result  = $request->create_order( $order );

			$response_code = method_exists( $request, 'get_response_code' ) ? (int) $request->get_response_code() : 0;

			if ( false !== $result && 200 === $response_code ) {
				wc_kledo_mark_delivery_synced( $order, 'order' );

				return;
			}

			$error_message = sprintf(
				/* translators: 1: HTTP status code */
				__( 'Kledo: failed to send order to Kledo (HTTP %d). Will retry automatically.', WC_KLEDO_TEXT_DOMAIN ),
				$response_code
			);

			$order->add_order_note( $error_message );

			wc_kledo_add_failed_transaction_to_queue( $order_id, 'order', $error_message );
		} catch ( Throwable $e ) {
			$safe_detail = wc_kledo_sanitize_api_error_message( $e->getMessage() );
			$error_message = sprintf(
				__( 'Kledo: error when sending order to Kledo: %s. Will retry automatically.', WC_KLEDO_TEXT_DOMAIN ),
				$safe_detail
			);

			$order->add_order_note( $error_message );

			wc_kledo_add_failed_transaction_to_queue( $order_id, 'order', $safe_detail );
		}
	}
}
