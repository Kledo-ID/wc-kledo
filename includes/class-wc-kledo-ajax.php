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
	public static function init() {
		// Get payment account via ajax.
		add_action( 'wp_ajax_wc_kledo_payment_account', array( __CLASS__, 'get_payment_account' ) );

		// Get warehouse via ajax.
		add_action( 'wp_ajax_wc_kledo_warehouse', array( __CLASS__, 'get_warehouse' ) );
	}

	/**
	 * Get the payment account.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public static function get_payment_account() {
		$request = new WC_Kledo_Request_Account();

		$keyword = sanitize_text_field( $_POST['keyword'] ) ?? '';
		$page    = sanitize_text_field( $_POST['page'] );

		$response = $request->get_accounts_suggestion_per_page( $keyword, $page );

		$items = array();

		foreach ( $response['data']['data'] as $item ) {
			$name = $item['name'];
			$code = $item['ref_code'];

			$value = $code . ' | ' . $name;

			$items[] = array(
				'id'   => $value,
				'text' => $value,
			);
		}

		wp_send_json(
			array(
				'items'    => $items,
				'page'     => $response['data']['current_page'],
				'per_page' => $response['data']['per_page'],
				'total'    => $response['data']['total'],
			)
		);
	}

	/**
	 * Get the warehouse.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public static function get_warehouse() {
		$request = new WC_Kledo_Request_Warehouse();

		$response = $request->get_warehouse();

		$items = array();

		foreach ( $response['data']['data'] as $item ) {
			$name = $item['name'];

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
