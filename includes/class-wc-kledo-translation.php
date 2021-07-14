<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Translation {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'load_translations' ) );
	}

	/**
	 * Load plugin translations.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function load_translations() {
		// If this plugin passes along its text domain, load its translation files
		if ( defined( WC_KLEDO_TEXT_DOMAIN ) ) {
			$this->load_textdomain( WC_KLEDO_TEXT_DOMAIN, dirname( KLEDO_PLUGIN_BASENAME ) );
		}
	}

	/**
	 * Loads the plugin text domain.
	 *
	 * @param  string  $text_domain
	 * @param  string  $path
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function load_textdomain( $text_domain, $path ) {
		// User's locale if in the admin for WP 4.7+, or the site locale otherwise
		$locale = is_admin() && is_callable( 'get_user_locale' ) ? get_user_locale() : get_locale();

		$locale = apply_filters( 'plugin_locale', $locale, $text_domain );

		load_textdomain( $text_domain, WP_LANG_DIR . '/' . $text_domain . '/' . $text_domain . '-' . $locale . '.mo' );

		load_plugin_textdomain( $text_domain, false, untrailingslashit( $path ) . '/languages' );
	}
}

new WC_Kledo_Translation();
