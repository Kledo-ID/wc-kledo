<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Manage_Product_Category {
	/**
	 * The product category option ID.
	 *
	 * @var string
	 */
	const SETTING_PRODUCT_CATEGORY_OPTION = 'wc_kledo_product_category_ids';

	/**
	 * The request handler.
	 *
	 * @var \WC_Kledo_Request_Product_Category
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
		$this->request_handler = new WC_Kledo_Request_Product_Category();
	}

	/**
	 * Create default product category.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_default_category() {
		if ( ! wc_kledo()->get_connection_handler()->is_connected() ) {
			return;
		}

		if ( ! $this->get_option() ) {
			$product_category_name = 'WooCommerce';

			$product_category = $this->request()->get_product_category( $product_category_name );

			if ( ! $product_category ) {
				$product_category = $this->request()->create_product_category( $product_category_name );
			}

			if ( $product_category instanceof WC_Kledo_Request_Interface ) {
				$this->set_option( 'default', array(
					'id'   => $product_category->get_id(),
					'name' => $product_category->get_name(),
				) );
			}
		}
	}

	/**
	 * Get the product category id.
	 *
	 * @param  string|int  $category_id
	 *
	 * @return bool|int
	 */
	public function get_option( $category_id = 'default' ) {
		$product_category_options = get_option( self::SETTING_PRODUCT_CATEGORY_OPTION );

		if ( ! empty( $product_category_options[ $category_id ] ) ) {
			return $product_category_options[ $category_id ];
		}

		return false;
	}

	/**
	 * Save the product category id.
	 *
	 * @param  string|int  $category_id
	 * @param  array  $value
	 *
	 * @return void
	 */
	public function set_option( $category_id, $value ) {
		$product_category_options = get_option( self::SETTING_PRODUCT_CATEGORY_OPTION );

		$product_category_options[ $category_id ] = array(
			'id'   => $value['id'],
			'name' => $value['name'],
		);

		update_option( self::SETTING_PRODUCT_CATEGORY_OPTION, $product_category_options, 'yes' );
	}

	/**
	 * Get the request handler instance.
	 *
	 * @return \WC_Kledo_Request_Product_Category
	 */
	public function request() {
		return $this->request_handler;
	}
}
