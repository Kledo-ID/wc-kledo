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
	public function setup_hooks() {
		$is_enable = wc_string_to_bool( get_option( WC_Kledo_Configure_Screen::SETTING_ENABLE_API_CONNECTION ) );

		if ( ! $is_enable ) {
			return;
		}

		add_action( 'woocommerce_order_status_completed', array( $this, 'create_invoice' ) );
	}

	/**
	 * Send invoice to kledo.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_invoice( $order_id ) {
		$order = wc_get_order( $order_id );

		$request = new WC_Kledo_Request_Invoice();

		$request->create_invoice( $order );
	}
}
