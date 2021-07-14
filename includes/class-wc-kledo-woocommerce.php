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
	 * Setup the hooks.
	 *
	 * If API connection disabled return early.
	 *
	 * @return void
	 */
	public function setup_hooks() {
		$is_enable = wc_string_to_bool( get_option( WC_Kledo_Configure_Screen::SETTING_ENABLE_API_CONNECTION ) );

		if ( ! $is_enable ) {
			return;
		}

		add_action( 'woocommerce_order_status_completed', array( $this, 'send_invoice' ) );
	}

	/**
	 * Send invoice to kledo.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function send_invoice( $order_id ) {
		$order = wc_get_order( $order_id );

		wc_kledo_invoice()->create_invoice( $order );
	}
}
