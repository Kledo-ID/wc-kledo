<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Manage_Product {
	/**
	 * The product ID meta key.
	 *
	 * @var string
	 */
	const PRODUCT_ID_META_KEY = 'wc_kledo_product_id';

	/**
	 * The request handler.
	 *
	 * @var \WC_Kledo_Request_Product
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
		$this->request_handler = new WC_Kledo_Request_Product();
	}

	/**
	 * Create product.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_product( WC_Product $wc_product ) {
		if ( ! wc_kledo()->get_connection_handler()->is_connected() ) {
			return;
		}

		$product = $this->request()->get_product( $wc_product->get_sku() );

		if ( false === $product ) {
			$product = $this->request()->create_product( $wc_product );
		}

		if ( $product instanceof WC_Kledo_Request_Interface ) {
			$this->update_product_id( $wc_product, $product->get_id() );
		}
	}

	/**
	 * Check if the product id exists.
	 *
	 * @param  \WC_Product  $product
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function has_product_id( WC_Product $product ) {
		return $product->meta_exists( self::PRODUCT_ID_META_KEY );
	}

	/**
	 * Get the product id.
	 *
	 * @param  \WC_Product  $product
	 *
	 * @return int
	 */
	public function get_product_id( WC_Product $product ) {
		return $product->get_meta( self::PRODUCT_ID_META_KEY );
	}

	/**
	 * Update the product id.
	 *
	 * @param  \WC_Product  $product
	 * @param  int  $product_id
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function update_product_id( WC_Product $product, $product_id ) {
		$product->update_meta_data( self::PRODUCT_ID_META_KEY, $product_id );
		$product->save_meta_data();
	}

	/**
	 * Get the request handler instance.
	 *
	 * @return \WC_Kledo_Request_Product
	 */
	public function request() {
		return $this->request_handler;
	}

	/**
	 * Rebuild request handler to avoid duplicate data on looping.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function rebuild_request() {
		$this->request_handler = new WC_Kledo_Request_Product();
	}
}
