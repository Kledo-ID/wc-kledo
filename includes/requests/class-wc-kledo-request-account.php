<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Account extends WC_Kledo_Request {
	/**
	 * Get accounts suggestion per page.
	 *
	 * @param  int  $page
	 * @param  string  $search
	 * @param  int  $per_page
	 *
	 * @return bool|array
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function get_accounts_suggestion_per_page( $search, $page = 1, $per_page = 10 ) {
		$this->set_endpoint( 'finance/accounts/suggestionPerPage' );
		$this->set_method( 'GET' );

		$query = array(
			'finance_account_category_ids' => urlencode_deep( '1,17' ),
			'page'                         => $page,
			'per_page'                     => $per_page,
		);

		if ( '' !== trim( $search ) ) {
			$query['search'] = $search;
		}

		$this->set_query( $query );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $response;
	}
}
