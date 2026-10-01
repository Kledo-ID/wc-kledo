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
		$this->set_endpoint( 'woocommerce/invoice' );
	}

	/**
	 * Create new invoice.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return bool|array
	 * @throws \Exception
	 * @since 1.0.0
	 * @since 1.7.4 Send the `link_order` field.
	 */
	public function create_invoice( WC_Order $order ) {
		$ref_number_prefix = wc_kledo_get_invoice_prefix();
		$warehouse         = wc_kledo_get_invoice_warehouse();
		$tags              = wc_kledo_get_tags( WC_Kledo_Invoice_Screen::INVOICE_TAG_OPTION_NAME );

		// Only this endpoint reads `link_order` and `close_order`; Kledo ignores both on
		// `woocommerce/order`. Kledo also treats each field as `yes` when it is absent, so
		// sending them explicitly changes nothing for the defaults — it only makes the "no"
		// cases expressible.
		$link_order = wc_kledo_link_invoice_to_order();

		$additional_body = array(
			'link_order' => $link_order,
		);

		// `close_order` only has meaning while the invoice is linked; sending it alongside
		// `link_order=no` would state a preference about something that cannot happen.
		if ( 'yes' === $link_order ) {
			$additional_body['close_order'] = wc_kledo_close_order_on_invoice();
		}

		return $this->create_transaction( $order, $ref_number_prefix, $warehouse, $tags, $additional_body );
	}
}
