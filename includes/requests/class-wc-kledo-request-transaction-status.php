<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Transaction_Status extends WC_Kledo_Request {
	/**
	 * Read the Kledo sync status of one WooCommerce order.
	 *
	 * `POST /woocommerce/order` and `POST /woocommerce/invoice` only dispatch a queue job before
	 * answering, so the plugin never learns the final outcome from those responses. This endpoint
	 * answers it afterwards: whether the Kledo order and invoice exist yet, whether the invoice is
	 * linked to the order, and whether the order has been closed.
	 *
	 * Response payload (`data`), as verified against the Kledo API:
	 *
	 *     wc_order_id  string  the id that was asked for, echoed back
	 *     order        array|null
	 *     invoice      array|null
	 *     linked       bool
	 *
	 * Both `order` and `invoice` carry `id`, `ref_number` (nullable), `status_id` and `is_closed`,
	 * and both are `null` while the transaction does not exist yet — that null is what separates
	 * "the queue job has not finished" from "this will never exist". `is_closed` is only ever true
	 * for `order`; on `invoice` it is always false, because only an order can be closed.
	 *
	 * @param  int|string $wc_order_id  WooCommerce order id. Kledo stores it as a string.
	 *
	 * @return array|false Decoded response, or false when the API reports failure or sends a body
	 *                     that is not JSON.
	 * @throws \RuntimeException When the HTTP layer fails before any response exists.
	 * @since 1.7.4
	 */
	public function get_status( $wc_order_id ) {
		$this->set_endpoint( 'woocommerce/transactions/' . rawurlencode( (string) $wc_order_id ) );
		$this->set_method( 'GET' );

		$this->do_request();

		// get_response() decodes with JSON_THROW_ON_ERROR, so an HTML error page or a truncated
		// body raises JsonException. That is a malformed response, not a transport failure, so it
		// is reported as false here rather than thrown at the cron handler.
		try {
			$response = $this->get_response();
		} catch ( JsonException $exception ) {
			wc_kledo_log_warning(
				sprintf(
					'Kledo closure check: response body for order %s was not valid JSON: %s',
					(string) $wc_order_id,
					$exception->getMessage()
				)
			);

			return false;
		}

		if ( ! is_array( $response ) ) {
			return false;
		}

		if ( isset( $response['success'] ) && false === $response['success'] ) {
			return false;
		}

		return $response;
	}
}
