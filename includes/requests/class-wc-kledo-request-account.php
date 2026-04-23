<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Account extends WC_Kledo_Request {
	/**
	 * Requests a paginated list of finance accounts for SelectWoo suggestions.
	 *
	 * @param string $search   Search keyword.
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Results per page.
	 *
	 * @return array|false Response payload, or false when the API reports failure.
	 * @throws \Exception When the HTTP layer fails before a parseable response exists.
	 * @since 1.0.0
	 */
	public function get_accounts_suggestion_per_page( string $search, int $page = 1, int $per_page = 10 ) {
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
