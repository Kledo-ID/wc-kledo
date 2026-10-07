<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Order extends WC_Kledo_Request {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Set API endpoint.
		$this->set_endpoint( 'woocommerce/order' );
	}

	/**
	 * Create new order.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return bool|array
	 * @throws \Exception
	 * @since 1.3.0
	 */
	public function create_order( WC_Order $order ) {
		$ref_number_prefix = wc_kledo_get_order_prefix();
		$warehouse         = wc_kledo_get_order_warehouse();
		$tags              = wc_kledo_get_tags( WC_Kledo_Order_Screen::ORDER_TAG_OPTION_NAME );

		return $this->create_transaction( $order, $ref_number_prefix, $warehouse, $tags );
	}

	/**
	 * The body `create_order()` would send, without sending it.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function build_body( WC_Order $order ): array {
		return $this->build_transaction_body(
			$order,
			wc_kledo_get_order_prefix(),
			wc_kledo_get_order_warehouse(),
			wc_kledo_get_tags( WC_Kledo_Order_Screen::ORDER_TAG_OPTION_NAME )
		);
	}
}
