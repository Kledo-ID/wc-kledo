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
	 * @param  \WC_Order    $order
	 * @param  string|null $link_order  `yes`/`no` to override the setting for this invoice only.
	 *
	 * @return bool|array
	 * @throws \Exception
	 * @since 1.0.0
	 * @since 1.7.4 Send the `link_order` field.
	 * @since 1.8.0 Accept a per-invoice `link_order` override.
	 */
	public function create_invoice( WC_Order $order, ?string $link_order = null ) {
		$this->set_method( 'POST' );
		$this->set_body( $this->build_body( $order, $link_order ) );

		$this->do_request();

		$response = $this->get_response();

		if ( isset( $response['success'] ) && false === $response['success'] ) {
			return false;
		}

		return $response;
	}

	/**
	 * The body `create_invoice()` would send, without sending it.
	 *
	 * @param  \WC_Order    $order
	 * @param  string|null  $link_order  `yes`/`no` to override the setting for this invoice only.
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function build_body( WC_Order $order, ?string $link_order = null ): array {
		$ref_number_prefix = wc_kledo_get_invoice_prefix();
		$warehouse         = wc_kledo_get_invoice_warehouse();
		$tags              = wc_kledo_get_tags( WC_Kledo_Invoice_Screen::INVOICE_TAG_OPTION_NAME );

		// Only this endpoint reads `link_order` and `close_order`; Kledo ignores both on
		// `woocommerce/order`. Kledo also treats each field as `yes` when it is absent, so
		// sending them explicitly changes nothing for the defaults — it only makes the "no"
		// cases expressible.
		$link_order = in_array( $link_order, array( 'yes', 'no' ), true ) ? $link_order : wc_kledo_link_invoice_to_order();

		$additional_body = array(
			'link_order' => $link_order,
		);

		// `close_order` only has meaning while the invoice is linked; sending it alongside
		// `link_order=no` would state a preference about something that cannot happen.
		if ( 'yes' === $link_order ) {
			$additional_body['close_order'] = wc_kledo_close_order_on_invoice();
		}

		return $this->build_transaction_body( $order, $ref_number_prefix, $warehouse, $tags, $additional_body );
	}
}
