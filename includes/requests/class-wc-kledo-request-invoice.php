<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Invoice extends WC_Kledo_Request {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Set API endpoint.
		$this->set_endpoint( 'woocommerce/invoice' );
	}

	/**
	 * Create new product.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return bool|array
	 * @throws \Exception
	 * @since 1.0.0
	 * @since 1.1.0 Add `has_tax` field.
	 */
	public function create_invoice( WC_Order $order ) {
		$this->set_method( 'POST' );
		$this->set_body( array(
			'contact_name'               => $this->get_customer_name( $order ),
			'contact_email'              => $order->get_billing_email(),
			'contact_address'            => $order->get_billing_address_1(),
			'contact_phone'              => $order->get_billing_phone(),
			'ref_number_prefix'          => wc_kledo_get_invoice_prefix(),
			'ref_number'                 => $order->get_id(),
			'trans_date'                 => $order->get_date_created()->format( 'Y-m-d' ),
			'due_date'                   => $order->get_date_completed()->format( 'Y-m-d' ),
			'memo'                       => $order->get_customer_note(),
			'has_tax'                    => wc_kledo_include_tax_or_not( $order ),
			'items'                      => $this->get_items( $order ),
			'warehouse'                  => wc_kledo_get_warehouse(),
			'shipping_cost'              => $order->get_shipping_total(),
			'additional_discount_amount' => $order->get_total_discount(),
			'paid'                       => wc_kledo_paid_status(),
			'paid_to_account_code'       => wc_kledo_get_payment_account(),
			'tags'                       => wc_kledo_get_tags(),
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $response;
	}

	/**
	 * Get customer name.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_customer_name( WC_Order $order ) {
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/**
	 * Get the product items from order.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return array
	 * @throws \Exception
	 * @since 1.0.0
	 *
	 * @noinspection PhpPossiblePolymorphicInvocationInspection
	 */
	public function get_items( WC_Order $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Product $product */
			$product = $item->get_product();

			$items[] = array(
				'name'          => $product->get_name(),
				'code'          => $product->get_sku(),
				'desc'          => $product->get_short_description(),
				'qty'           => $item->get_quantity(),
				'regular_price' => $product->get_regular_price(),
				'sale_price'    => $product->get_sale_price(),
				'photo'         => wp_get_attachment_url( $product->get_image_id() ) ?: null,
				'category_name' => 'WooCommerce',
			);
		}

		return $items;
	}
}
