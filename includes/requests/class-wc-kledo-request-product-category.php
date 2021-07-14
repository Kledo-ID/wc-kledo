<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Product_Category extends WC_Kledo_Request {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Set API endpoint.
		$this->set_endpoint( 'finance/productCategories' );
	}

	/**
	 * Get the product category.
	 *
	 * @param  string  $category
	 *
	 * @return bool|\WC_Kledo_Product_Category
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function get_product_category( $category ) {
		$this->set_method( 'GET' );
		$this->set_query( array(
			'name' => $category,
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) || 404 === $this->get_response_code() ) {
			return false;
		}

		return $this->set_object( $response['data']['id'], $response['data']['name'] );
	}

	/**
	 * Create new product category.
	 *
	 * @param  string  $category
	 *
	 * @return bool|\WC_Kledo_Product_Category
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_product_category( $category ) {
		$this->set_method( 'POST' );
		$this->set_body( array(
			'name' => $category,
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $this->set_object( $response['data']['id'], $response['data']['name'] );
	}

	/**
	 * Set the product category object.
	 *
	 * @param  int  $id
	 * @param  string  $name
	 *
	 * @return \WC_Kledo_Product_Category
	 */
	private function set_object( $id, $name ) {
		$product_category = new WC_Kledo_Product_Category();

		$product_category->set_id( $id );
		$product_category->set_name( $name );

		return $product_category;
	}
}
