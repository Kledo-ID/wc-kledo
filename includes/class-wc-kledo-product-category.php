<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Product_Category implements WC_Kledo_Request_Interface {
	/**
	 * The product category id.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The prdocut category name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Get the product category id.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Set the product category id.
	 *
	 * @param  int  $id
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_id( $id ) {
		$this->id = $id;
	}

	/**
	 * Get the product category name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Set the product category name.
	 *
	 * @param  string  $name
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_name( $name ) {
		$this->name = $name;
	}
}
