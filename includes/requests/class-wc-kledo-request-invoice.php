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
		$this->set_endpoint( 'finance/invoices' );
	}

	/**
	 * Create new product.
	 *
	 * @param  \WC_Order  $order
	 * @param  array  $items
	 *
	 * @return bool|\WC_Kledo_Invoice
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function create_invoice( WC_Order $order, $items ) {
		$this->set_method( 'POST' );
		$this->set_body( array(
			'trans_date'                 => $order->get_date_created()->format( 'Y-m-d' ),
			'due_date'                   => $order->get_date_completed()->format( 'Y-m-d' ),
			'contact_id'                 => wc_kledo_contact()->get_contact_id( $order ),
			'include_tax'                => 0,
			'ref_number'                 => 'WC/' . $order->get_id(),
			'memo'                       => $order->get_customer_note(),
			'status_id'                  => 3,
			'additional_discount_amount' => $order->get_discount_total(),
			'items'                      => $items,
		) );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $this->set_object(
			$response['data']['id'],
			$response['data']['ref_number'],
			$response['data']['trans_date']
		);
	}

	/**
	 * Set the invoice object.
	 *
	 * @param  int  $id
	 * @param  string  $number
	 * @param  string  $transaction_date
	 *
	 * @return \WC_Kledo_Invoice
	 */
	private function set_object( $id, $number, $transaction_date ) {
		$invoice = new WC_Kledo_Invoice();

		$invoice->set_id( $id );
		$invoice->set_number( $number );
		$invoice->set_transaction_date( $transaction_date );

		return $invoice;
	}
}
