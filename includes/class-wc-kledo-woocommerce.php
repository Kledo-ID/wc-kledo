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
		$is_enable = wc_string_to_bool( get_option( WC_Kledo_Configure_Screen::SETTING_ENABLE_API_CONNECTION ) );

		if ( ! $is_enable ) {
			return;
		}

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
		$is_enable = wc_string_to_bool( get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME ) );

		if ( ! $is_enable ) {
			return;
		}

		do_action( 'wc_kledo_create_invoice', $order_id, $order );

		$request = new WC_Kledo_Request_Invoice();

		$request->create_invoice( $order );
	}
}
