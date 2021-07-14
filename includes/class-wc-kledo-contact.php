<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Contact implements WC_Kledo_Request_Interface {
	/**
	 * The contact id.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The contact name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * The contact company.
	 *
	 * @var string
	 */
	private $company;

	/**
	 * The contact address.
	 *
	 * @var string
	 */
	private $address;

	/**
	 * The contact email.
	 *
	 * @var string
	 */
	private $email;

	/**
	 * The contact phone.
	 *
	 * @var string
	 */
	private $phone;

	/**
	 * The contact shipping address.
	 *
	 * @var string
	 */
	private $shipping_address;

	/**
	 * Get the contact id.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Set the contact id.
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
	 * Get the contact name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Set the contact name.
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
	 * Get the contact company.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_company() {
		return $this->company;
	}

	/**
	 * Set the contact company.
	 *
	 * @param  string  $company
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_company( $company ) {
		$this->company = $company;
	}

	/**
	 * Get the contact address.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_address() {
		return $this->address;
	}

	/**
	 * Set the contact address.
	 *
	 * @param  string  $address
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_address( $address ) {
		$this->address = $address;
	}

	/**
	 * Get the contact email.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_email() {
		return $this->email;
	}

	/**
	 * Set the contact email.
	 *
	 * @param  string  $email
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_email( $email ) {
		$this->email = $email;
	}

	/**
	 * Get the contact phone.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_phone() {
		return $this->phone;
	}

	/**
	 * Set the contact phone.
	 *
	 * @param  string  $phone
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_phone( $phone ) {
		$this->phone = $phone;
	}

	/**
	 * Get the contact shipping address.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_shipping_address() {
		return $this->shipping_address;
	}

	/**
	 * Set the contact shipping address.
	 *
	 * @param  string  $shipping_address
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_shipping_address( $shipping_address ) {
		$this->shipping_address = $shipping_address;
	}
}
