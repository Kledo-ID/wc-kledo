<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Manage_Invoice {
	/**
	 * The request handler.
	 *
	 * @var \WC_Kledo_Request_Invoice
	 */
	private $request_handler;

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		// Init request handler.
		$this->request_handler = new WC_Kledo_Request_Invoice();
	}

	/**
	 * Create invoice.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_invoice( WC_Order $order ) {
		$items = $this->get_items( $order );

		$this->request()->create_invoice( $order, $items);
	}

	/**
	 * Get the product items from order.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return array
	 * @throws \Exception
	 * @since 1.0.0
	 * @noinspection PhpPossiblePolymorphicInvocationInspection
	 */
	public function get_items( WC_Order $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Product $product */
			$product = $item->get_product();

			if ( ! wc_kledo_product()->has_product_id( $product ) ) {
				wc_kledo_product()->create_product( $product );
			}

			$product_id = wc_kledo_product()->get_product_id( $product );

			$items[] = array(
				'finance_account_id' => $product_id,
				'desc'               => $product->get_short_description(),
				'qty'                => $item->get_quantity(),
				'price'              => $product->get_price(),
				'amount'             => $item->get_subtotal(),
			);

			wc_kledo_product()->rebuild_request();
		}

		return $items;
	}

	/**
	 * Get the request handler instance.
	 *
	 * @return \WC_Kledo_Request_Invoice
	 */
	public function request() {
		return $this->request_handler;
	}
}
