<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Invoice implements WC_Kledo_Request_Interface {
	/**
	 * The invoice id.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The invoice number.
	 *
	 * @var string
	 */
	private $number;

	/**
	 * The invoice transaction date.
	 *
	 * @var string
	 */
	private $transaction_date;

	/**
	 * Get the invoice id.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Set the invoice id.
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
	 * Get the invoice number.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_number() {
		return $this->number;
	}

	/**
	 * Set the invoice number.
	 *
	 * @param  string  $number
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_number( $number ) {
		$this->number = $number;
	}

	/**
	 * Get the transaction date.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_transaction_date() {
		return $this->transaction_date;
	}

	/**
	 * Set the transaction date.
	 *
	 * @param  string  $transaction_date
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_transaction_date( $transaction_date ) {
		$this->transaction_date = $transaction_date;
	}
}
