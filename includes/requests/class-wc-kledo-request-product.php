<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Product extends WC_Kledo_Request {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Set API endpoint.
		$this->set_endpoint( 'finance/products' );
	}

	/**
	 * Get the product.
	 *
	 * @param  string  $code
	 *
	 * @return bool|\WC_Kledo_Product
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function get_product( $code ) {
		$this->set_method( 'GET' );
		$this->set_query( array(
			'code' => $code,
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) || 404 === $this->get_response_code() ) {
			return false;
		}

		return $this->set_object(
			$response['data']['id'],
			$response['data']['name'],
			$response['data']['code'],
			$response['data']['description'],
			$response['data']['price'],
			$response['data']['photo']
		);
	}

	/**
	 * Create new product.
	 *
	 * @param  \WC_Product  $product
	 *
	 * @return bool|\WC_Kledo_Product
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_product( WC_Product $product ) {
		$product_category = wc_kledo_product_category()->get_option();

		$this->set_method( 'POST' );
		$this->set_body( array(
			'name'                    => $product->get_name(),
			'code'                    => $product->get_sku(),
			'description'             => $product->get_short_description(),
			'price'                   => $product->get_price(),
			'photo'                   => wp_get_attachment_url( $product->get_image_id() ) ?: '',
			'pos_product_category_id' => $product_category['id'],
			'is_purchase'             => 0,
			'is_sell'                 => 1,
			'sell_account_id'         => 121,
			'is_track'                => 0,
			'unit_id'                 => 1,
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $this->set_object(
			$response['data']['id'],
			$response['data']['name'],
			$response['data']['code'],
			$response['data']['description'],
			$response['data']['price'],
			$response['data']['photo']
		);
	}

	/**
	 * Set the product object.
	 *
	 * @param  int  $id
	 * @param  string  $name
	 * @param  string  $code
	 * @param  string  $description
	 * @param  int  $price
	 * @param  string  $photo
	 *
	 * @return \WC_Kledo_Product
	 */
	private function set_object( $id, $name, $code, $description, $price, $photo ) {
		$product = new WC_Kledo_Product();

		$product->set_id( $id );
		$product->set_name( $name );
		$product->set_code( $code );
		$product->set_description( $description );
		$product->set_price( $price );
		$product->set_photo( $photo );

		return $product;
	}
}
