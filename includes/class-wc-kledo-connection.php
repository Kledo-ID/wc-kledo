<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Holds Kledo API credentials loaded from options (with filters applied).
 *
 * @since 1.0.0
 */
class WC_Kledo_Connection {
	/**
	 * The kledo api key.
	 *
	 * @var string
	 * @since 1.4.0
	 */
	private string $api_key;

	/**
	 * The kledo OAuth API endpoint.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $api_endpoint;

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'setup_credentials' ), 9998 );
	}

	/**
	 * Loads API credentials from options and applies filters.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setup_credentials(): void {
		/**
		 * Filters the api key.
		 *
		 * @param  string  $api_key
		 *
		 * @since 1.4.0
		 */
		$this->api_key = apply_filters(
			'wc_kledo_api_key',
			get_option( WC_Kledo_Configure_Screen::SETTING_API_KEY )
		);

		/**
		 * Filters the api endpoint.
		 *
		 * @param  string  $api_endpoint
		 *
		 * @since 1.0.0
		 */
		$this->api_endpoint = apply_filters(
			'wc_kledo_api_endpoint',
			get_option( WC_Kledo_Configure_Screen::SETTING_API_ENDPOINT )
		);
	}

	/**
	 * Get the api key.
	 *
	 * @return string
	 * @since 1.4.0
	 */
	public function get_api_key(): string {
		return $this->api_key;
	}

	/**
	 * Get the OAuth URL.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_api_endpoint(): string {
		return untrailingslashit( esc_url_raw( $this->api_endpoint ) );
	}

	/**
	 * Determines whether the OAuth credentials configured.
	 *
	 * Is configured if there is an client id, client secret, and api endpoint stored.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_configured(): bool {
		return $this->get_api_key() && $this->get_api_endpoint();
	}
}
