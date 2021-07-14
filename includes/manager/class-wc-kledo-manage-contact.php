<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Manage_Contact {
	/**
	 * The request handler.
	 *
	 * @var \WC_Kledo_Request_Contact
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
		$this->request_handler = new WC_Kledo_Request_Contact();
	}

	/**
	 * Get the contact id.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return int
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function get_contact_id( WC_Order $order ) {
		$name = $this->request()->get_customer_name( $order );

		$contact = $this->request()->get_contact( $name );

		if ( false === $contact ) {
			$contact = $this->request()->create_contact( $order );
		}

		return $contact->get_id();
	}

	/**
	 * Get the request handler instance.
	 *
	 * @return \WC_Kledo_Request_Contact
	 */
	public function request() {
		return $this->request_handler;
	}
}
