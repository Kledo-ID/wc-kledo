<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Warehouse extends WC_Kledo_Request {
	/**
	 * Get warehouse.
	 *
	 * @return bool|array
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function get_warehouse() {
		$this->set_endpoint( 'finance/warehouses' );
		$this->set_method( 'GET' );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $response;
	}
}
