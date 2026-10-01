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
	 * The Kledo API endpoint URL, as entered on the Configure screen.
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
	 * Replace the stored api key with one Kledo issued in place of it.
	 *
	 * Kledo rotates a token that is close to expiring: it revokes the old one, issues a
	 * replacement, and returns that replacement in the body of whatever request triggered the
	 * rotation. The old key stops working the moment that happens, so persisting the replacement
	 * is not an optimisation — it is the difference between the connection surviving and the shop
	 * waking up to a dead API key.
	 *
	 * The in-memory value is updated alongside the option because credentials are loaded once on
	 * `wp_loaded`; without this, every later request in the same PHP process would keep sending
	 * the key that was just revoked.
	 *
	 * @param  string $api_key  The replacement key.
	 *
	 * @return bool Whether anything was stored.
	 * @since 1.7.4
	 */
	public function update_api_key( string $api_key ): bool {
		$api_key = trim( $api_key );

		if ( '' === $api_key || $api_key === $this->api_key ) {
			return false;
		}

		update_option( WC_Kledo_Configure_Screen::SETTING_API_KEY, $api_key );

		$this->api_key = $api_key;

		return true;
	}

	/**
	 * Get the API endpoint URL, without a trailing slash.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_api_endpoint(): string {
		return untrailingslashit( esc_url_raw( $this->api_endpoint ) );
	}

	/**
	 * Whether this store has credentials to talk to Kledo with.
	 *
	 * Configured means the API key AND the API endpoint URL are both stored — one without the
	 * other is not enough, which is what decides whether the setup notice keeps appearing.
	 *
	 * Says nothing about "Enable Integration" on the same screen: that setting gates whether
	 * syncing runs (`WC_Kledo_WooCommerce::setup_hooks()` and `::deliver()`), not whether the
	 * store is connected, and the two are deliberately separate.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_configured(): bool {
		return $this->get_api_key() && $this->get_api_endpoint();
	}
}
