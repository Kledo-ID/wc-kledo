<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Connection {
	/**
	 * The access token option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const OPTION_ACCESS_TOKEN = 'wc_kledo_access_token';

	/**
	 * The refresh token option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const OPTION_REFRESH_TOKEN = 'wc_kledo_refresh_token';

	/**
	 * The expires token option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const OPTION_EXPIRES_TOKEN = 'wc_kledo_expires_token';

	/**
	 * The genrated random state transient option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const OPTION_TRANSIENT_STATE = 'wc_kledo_random_state';

	/**
	 * The kledo OAuth API endpoint.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $oauth_url;

	/**
	 * The kledo OAuth Client ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $client_id;

	/**
	 * The kledo OAuth Client Secret.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $client_secret;

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->setup_oauth_credentials();
	}

	/**
	 * Setup the OAuth credentials.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function setup_oauth_credentials() {
		/**
		 * Filters the client id.
		 *
		 * @param  string  $client_id
		 *
		 * @since 1.0.0
		 */
		$this->client_id = apply_filters(
			'wc_kledo_client_id',
			get_option( WC_Kledo_Configure_Screen::SETTING_CLIENT_ID )
		);

		/**
		 * Filters the client secret.
		 *
		 * @param  string  $client_secret
		 *
		 * @since 1.0.0
		 */
		$this->client_secret = apply_filters(
			'wc_kledo_client_secret',
			get_option( WC_Kledo_Configure_Screen::SETTING_CLIENT_SECRET )
		);

		/**
		 * Filters the api endpoint.
		 *
		 * @param  string  $api_endpoint
		 *
		 * @since 1.0.0
		 */
		$this->oauth_url = apply_filters(
			'wc_kledo_api_endpoint',
			get_option( WC_Kledo_Configure_Screen::SETTING_API_ENDPOINT )
		);
	}

	/**
	 * Get the OAuth URL.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_oauth_url() {
		return untrailingslashit( esc_url_raw( $this->oauth_url ) );
	}

	/**
	 * Get the OAuth Client ID.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_client_id() {
		return $this->client_id;
	}

	/**
	 * Get the OAuth Client Secret.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_client_secret() {
		return $this->client_secret;
	}

	/**
	 * Store the API access token.
	 *
	 * @param  $token  string
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function set_access_token( $token ) {
		return update_option( self::OPTION_ACCESS_TOKEN, $token );
	}

	/**
	 * Get the access token.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_access_token() {
		$access_token = get_option( self::OPTION_ACCESS_TOKEN, '' );

		/**
		 * Filters the API access token.
		 *
		 * @param  string  $access_token  access token
		 *
		 * @since 1.0.0
		 */
		return apply_filters( 'wc_kledo_connection_access_token', $access_token );
	}

	/**
	 * Refresh the access token.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function refresh_access_token() {
		$refresh_token = $this->get_refresh_token();

		if ( empty( $refresh_token ) ) {
			return false;
		}

		$refresh_token_url = $this->get_oauth_url() . '/oauth/token';

		$request = wp_remote_post( $refresh_token_url, [
			'body' => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $this->get_client_id(),
				'client_secret' => $this->get_client_secret(),
				'scope'         => '',
			),
		] );

		if ( is_wp_error( $request ) ) {
			wc_kledo()->get_admin_notice_handler()->add_admin_notice(
				__( 'There was a problem when refreshing the access token. Please disconnect and try to request new token.', WC_KLEDO_TEXT_DOMAIN ),
				'connection_error_refresh_token',
				array(
					'dismissible'  => true,
					'notice_class' => 'notice-error',
				)
			);

			return false;
		}

		// If no error then get the body response.
		$this->store_response_request( $request );

		return true;
	}

	/**
	 * Store the successfull request result to storage.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function store_response_request( $request ) {
		$response = json_decode( wp_remote_retrieve_body( $request ), true );

		$this->set_expires_token( $response['expires_in'] );
		$this->set_access_token( $response['access_token'] );
		$this->set_refresh_token( $response['refresh_token'] );
	}

	/**
	 * Store the refresh token.
	 *
	 * @param  $token  string
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function set_refresh_token( $token ) {
		return update_option( self::OPTION_REFRESH_TOKEN, $token );
	}

	/**
	 * Get the refresh token.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_refresh_token() {
		$refresh_token = get_option( self::OPTION_REFRESH_TOKEN, '' );

		/**
		 * Filters the API access token.
		 *
		 * @param  string  $access_token  access token
		 *
		 * @since 1.0.0
		 */
		return apply_filters( 'wc_kledo_connection_refresh_token', $refresh_token );
	}

	/**
	 * Store the expires token time.
	 *
	 * @param  $time  int
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function set_expires_token( $time ) {
		$time = time() + $time;

		return update_option( self::OPTION_EXPIRES_TOKEN, $time );
	}

	/**
	 * Get the expires token time.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_expires_token() {
		$time_now   = time();
		$expires_in = get_option( self::OPTION_EXPIRES_TOKEN );

		if ( empty( $expires_in ) ) {
			return __( 'Does not expire', WC_KLEDO_TEXT_DOMAIN );
		}

		if ( $time_now > $expires_in ) {
			return __( 'Expired', WC_KLEDO_TEXT_DOMAIN );
		}

		$date            = date_i18n( get_option( 'date_format' ), $expires_in );
		$human_time_diff = human_time_diff( $time_now, $expires_in );

		return $date . ' (' . $human_time_diff . ')';
	}

	/**
	 * Determines whether the site is connected.
	 *
	 * A site is connected if there is an access token stored.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_connected() {
		return (bool) $this->get_access_token();
	}

	/**
	 * Determines whether the OAuth credentials configured.
	 *
	 * Is configured if there is an client id, client secret, and api endpoint stored.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_configured() {
		return $this->get_client_id()
		       && $this->get_client_secret()
		       && $this->get_oauth_url();
	}

	/**
	 * Get the admin url redirect uri.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_redirect_uri() {
		$page_id = WC_Kledo_Admin::PAGE_ID;

		$admin_url = add_query_arg( [
			'page'   => $page_id,
			'action' => 'callback',
		], admin_url( 'admin.php' ) );

		// If the admin_url isn't returned correctly then use a fallback.
		if ( $admin_url === '/wp-admin/admin.php?page=' . $page_id . '&action=callback' ) {
			$protocol  = wc_kledo_is_ssl() ? 'https' : 'http';
			$admin_url = "$protocol://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]&action=callback";
		}

		return $admin_url;
	}

	/**
	 * Get the redirect url for authorization.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_redirect_authorization() {
		$query = http_build_query( [
			'client_id'     => $this->get_client_id(),
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => '',
			'state'         => $this->get_state(),
		] );

		return $this->get_oauth_url() . '/oauth/authorize?' . $query;
	}

	/**
	 * Convertion authorization codes to access token.
	 *
	 * @param  $code  string The authorization code.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function converting_authorization_codes( $code ) {
		if ( empty( $code ) ) {
			return false;
		}

		$authorization_code_url = $this->get_oauth_url() . '/oauth/token';

		$request = wp_remote_post( $authorization_code_url, array(
			'body' => array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $this->get_client_id(),
				'client_secret' => $this->get_client_secret(),
				'redirect_uri'  => $this->get_redirect_uri(),
				'code'          => $code,
			),
		) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) !== 200 ) {
			wc_kledo()->get_admin_notice_handler()->add_admin_notice(
				__( 'There was a problem when converting authorization code from the server. Please try again later.', WC_KLEDO_TEXT_DOMAIN ),
				'connection_error_authorization_code',
				array(
					'dismissible'  => true,
					'notice_class' => 'notice-error',
				)
			);

			return false;
		}

		// If no error then get the body response.
		$this->store_response_request( $request );

		$this->delete_state();

		return true;
	}

	/**
	 * Get state parameter to protect against XSRF
	 * when requesting access token.
	 *
	 * If not exists generate new one.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_state() {
		$state = get_transient( self::OPTION_TRANSIENT_STATE );

		if ( empty( $state ) ) {
			$state = wp_generate_password( 40, false );

			set_transient( self::OPTION_TRANSIENT_STATE, $state, MINUTE_IN_SECONDS * 5 );
		}

		/**
		 * Filters the randomly-generated state parameter.
		 *
		 * @param  string  $state  The generated state.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( 'wc_kledo_random_state', $state );
	}

	/**
	 * Delete generated random state transient.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function delete_state() {
		return delete_transient( self::OPTION_TRANSIENT_STATE );
	}

	/**
	 * Disconnect the connection.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function disconnect() {
		return delete_option( self::OPTION_ACCESS_TOKEN )
		       && delete_option( self::OPTION_REFRESH_TOKEN )
		       && delete_option( self::OPTION_EXPIRES_TOKEN );
	}
}
