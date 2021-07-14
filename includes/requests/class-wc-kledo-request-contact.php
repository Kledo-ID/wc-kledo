<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Contact extends WC_Kledo_Request {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Set API endpoint.
		$this->set_endpoint( 'finance/contacts' );
	}

	/**
	 * Get the contact.
	 *
	 * @param  string  $name
	 *
	 * @return bool|\WC_Kledo_Contact
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function get_contact( $name ) {
		$this->set_method( 'GET' );
		$this->set_query( array(
			'name' => $name,
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) || 404 === $this->get_response_code() ) {
			return false;
		}

		return $this->set_object(
			$response['data']['id'],
			$response['data']['name'],
			$response['data']['company'],
			$response['data']['address'],
			$response['data']['email'],
			$response['data']['phone'],
			$response['data']['shipping_address']
		);
	}

	/**
	 * Create new product.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return bool|\WC_Kledo_Contact
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_contact( WC_Order $order ) {
		$this->set_method( 'POST' );
		$this->set_body( array(
			'name'             => $this->get_customer_name( $order ),
			'company'          => $order->get_billing_company(),
			'address'          => $order->get_billing_address_1(),
			'phone'            => $order->get_billing_phone(),
			'email'            => $order->get_billing_email(),
			'shipping_address' => $order->get_shipping_address_1(),
			'type_id'          => 3,
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $this->set_object(
			$response['data']['id'],
			$response['data']['name'],
			$response['data']['company'],
			$response['data']['address'],
			$response['data']['email'],
			$response['data']['phone'],
			$response['data']['shipping_address']
		);
	}

	/**
	 * Get customer name.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return string
	 */
	public function get_customer_name( WC_Order $order ) {
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/**
	 * Set the contact object.
	 *
	 * @param  int  $id
	 * @param  string  $name
	 * @param  string  $company
	 * @param  string  $address
	 * @param  string  $email
	 * @param  string  $phone
	 * @param  string  $shipping_address
	 *
	 * @return \WC_Kledo_Contact
	 */
	private function set_object( $id, $name, $company, $address, $email, $phone, $shipping_address ) {
		$contact = new WC_Kledo_Contact();

		$contact->set_id( $id );
		$contact->set_name( $name );
		$contact->set_company( $company );
		$contact->set_address( $address );
		$contact->set_email( $email );
		$contact->set_phone( $phone );
		$contact->set_shipping_address( $shipping_address );

		return $contact;
	}
}
