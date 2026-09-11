<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Ajax {
	/**
	 * Hook in ajax handlers.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function init(): void {
		// Get payment account via ajax.
		add_action( 'wp_ajax_wc_kledo_payment_account', array( __CLASS__, 'get_payment_account' ) );

		// Get warehouse via ajax.
		add_action( 'wp_ajax_wc_kledo_warehouse', array( __CLASS__, 'get_warehouse' ) );
	}

	/**
	 * AJAX handler: returns finance accounts for the payment account SelectWoo field.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function get_payment_account(): void {
		check_ajax_referer( 'wc_kledo_admin', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wc-kledo' ) ), 403 );
		}

		$request = new WC_Kledo_Request_Account();

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$page    = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;

		try {
			$response = $request->get_accounts_suggestion_per_page( $keyword, $page );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => __( 'Request failed.', 'wc-kledo' ) ), 500 );
		}

		if (
			isset( $response ) &&
			( false === $response
				|| ! is_array( $response )
				|| empty( $response['data']['data'] )
				|| ! is_array( $response['data']['data'] ) )
		) {
			wp_send_json_error( array( 'message' => __( 'Invalid response from Kledo.', 'wc-kledo' ) ), 502 );
		}

		$items = array();

		foreach ( $response['data']['data'] as $item ) {
			$name = isset( $item['name'] ) ? (string) $item['name'] : '';
			$code = isset( $item['ref_code'] ) ? (string) $item['ref_code'] : '';

			$value = $code . ' | ' . $name;

			$items[] = array(
				'id'   => $value,
				'text' => $value,
			);
		}

		wp_send_json(
			array(
				'items'    => $items,
				'page'     => isset( $response['data']['current_page'] ) ? (int) $response['data']['current_page'] : 1,
				'per_page' => isset( $response['data']['per_page'] ) ? (int) $response['data']['per_page'] : 10,
				'total'    => isset( $response['data']['total'] ) ? (int) $response['data']['total'] : 0,
			)
		);
	}

	/**
	 * AJAX handler: returns warehouses for the warehouse SelectWoo field.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function get_warehouse(): void {
		check_ajax_referer( 'wc_kledo_admin', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wc-kledo' ) ), 403 );
		}

		$request = new WC_Kledo_Request_Warehouse();

		try {
			$response = $request->get_warehouse();
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => __( 'Request failed.', 'wc-kledo' ) ), 500 );
		}

		if (
			false === $response
			|| ! is_array( $response )
			|| empty( $response['data']['data'] )
			|| ! is_array( $response['data']['data'] )
		) {
			wp_send_json_error( array( 'message' => __( 'Invalid response from Kledo.', 'wc-kledo' ) ), 502 );
		}

		$items = array();

		foreach ( $response['data']['data'] as $item ) {
			$name = isset( $item['name'] ) ? (string) $item['name'] : '';

			$items[] = array(
				'id'   => $name,
				'text' => $name,
			);
		}

		wp_send_json(
			array(
				'items' => $items,
			)
		);
	}
}

// Fire it!
WC_Kledo_Ajax::init();
