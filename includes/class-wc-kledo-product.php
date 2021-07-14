<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Product implements WC_Kledo_Request_Interface {
	/**
	 * The product id.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The product name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * The product sku/code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * The product description.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * The product price.
	 *
	 * @var int
	 */
	private $price;

	/**
	 * The product photo.
	 *
	 * @var string
	 */
	private $photo;

	/**
	 * Get the product id.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Set the product id.
	 *
	 * @param  int  $id
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_id( int $id ) {
		$this->id = $id;
	}

	/**
	 * Get the product name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Set the product name.
	 *
	 * @param  string  $name
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_name( $name ) {
		$this->name = $name;
	}

	/**
	 * Get the product code.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_code() {
		return $this->code;
	}

	/**
	 * Set the product code.
	 *
	 * @param  string  $code
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_code( string $code ) {
		$this->code = $code;
	}

	/**
	 * Get the product description.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Set the product description.
	 *
	 * @param  string  $description
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_description( $description ) {
		$this->description = $description;
	}

	/**
	 * Get the product price.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function get_price() {
		return $this->price;
	}

	/**
	 * Set the product price.
	 *
	 * @param  int  $price
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_price( $price ) {
		$this->price = $price;
	}

	/**
	 * Get the product photo.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_photo() {
		return $this->photo;
	}

	/**
	 * Set the product photo.
	 *
	 * @param  string  $photo
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_photo( $photo ) {
		$this->photo = $photo;
	}
}
