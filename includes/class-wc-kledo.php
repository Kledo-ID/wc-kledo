<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

final class WC_Kledo {
	/**
	 * The plugin id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const PLUGIN_ID = WC_Kledo_Loader::PLUGIN_ID;

	/**
	 * The single instance of this class.
	 *
	 * @var null|self
	 * @since 1.0.0
	 */
	protected static $instance;

	/**
	 * The admin notice instance.
	 *
	 * @var \WC_Kledo_Admin_Notice_Handler
	 * @since 1.0.0
	 */
	private $admin_notice_handler;

	/**
	 * The message instance.
	 *
	 * @var \WC_Kledo_Admin_Message_Handler
	 * @since 1.0.0
	 */
	private $message_handler;

	/**
	 * The API connection instance.
	 *
	 * @var \WC_Kledo_Connection
	 * @since 1.0.0
	 */
	private $connection_handler;

	/**
	 * The admin settings instance.
	 *
	 * @var \WC_Kledo_Admin
	 * @since 1.0.0
	 */
	private $admin_settings;

	/**
	 * Gets the main class instance.
	 *
	 * Ensures only one instance can be loaded.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->setup_autoloader();
		$this->includes();
		$this->init();
		$this->add_hooks();
	}

	/**
	 * Setup the autoloader class.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function setup_autoloader() {
		// Class autoloader.
		require_once WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-autoloader.php';

		// Create autoloader instance.
		$autoloader = new WC_Kledo_Autoloader( WC_KLEDO_ABSPATH . 'includes/' );

		// Register autoloader.
		spl_autoload_register( array( $autoloader, 'load' ) );
	}

	/**
	 * Include required core files.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function includes() {
		// Function helpers.
		require_once( WC_KLEDO_ABSPATH . 'includes/helpers.php' );

		// Abstract classes.
		require_once( WC_KLEDO_ABSPATH . 'includes/abstracts/abstract-wc-kledo-settings-screen.php' );
		require_once( WC_KLEDO_ABSPATH . 'includes/abstracts/abstract-wc-kledo-request.php' );

		// Core classes.
		require_once( WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-translation.php' );
		require_once( WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-ajax.php' );
		require_once( WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-admin-message-handler.php' );
		require_once( WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-admin-notice-handler.php' );
		require_once( WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-issuing-token.php' );
		require_once( WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-woocommerce.php' );

		// Exception handler.
		require_once( WC_KLEDO_ABSPATH . 'includes/class-wc-kledo-exception.php' );
	}

	/**
	 * Initializes the plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init() {
		// Build the admin message handler instance.
		$this->message_handler = new WC_Kledo_Admin_Message_Handler( $this->get_id() );

		// Build the admin notice handler instance.
		$this->admin_notice_handler = new WC_Kledo_Admin_Notice_Handler( $this );

		// Build the connection handler instance.
		$this->connection_handler = new WC_Kledo_Connection();

		if ( is_admin() ) {
			// Build the admin settings instance.
			$this->admin_settings = new WC_Kledo_Admin();
		}

		// Setup WooCommerce.
		$wc = new WC_Kledo_WooCommerce();
		$wc->setup_hooks();
	}

	/**
	 * Adds the action & filter hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function add_hooks() {
		// Add the admin notices.
		add_action( 'admin_notices', array( $this, 'add_admin_notices' ) );
	}

	/**
	 * Add the plugin admin notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_admin_notices() {
		// Inform users who are not connected to Kledo
		if ( ! $this->is_plugin_settings() && ! $this->get_connection_handler()->is_connected() ) {
			// Direct these users to the new plugin settings page.
			$message = sprintf(
				esc_html__(
					'%1$sWooCommerce Kledo is almost ready.%2$s To complete your configuration, %3$scomplete the setup steps%4$s.',
					WC_KLEDO_TEXT_DOMAIN
				),
				'<strong>',
				'</strong>',
				'<a href="' . esc_url( $this->get_settings_url() ) . '">',
				'</a>'
			);

			$this->get_admin_notice_handler()->add_admin_notice(
				$message,
				$this->get_id() . '_get_started',
				array(
					'dismissible'  => true,
					'notice_class' => 'notice-info',
				)
			);
		}

		if ( wc_kledo_is_enhanced_admin_available() ) {
			$message = sprintf(
				__( 'For your convenience, the Kledo for WooCommerce settings are located under %1$sWooCommerce > Kledo%2$s.', WC_KLEDO_TEXT_DOMAIN ),
				'<a href="' . esc_url( $this->get_settings_url() ) . '">',
				'</a>'
			);

			$this->get_admin_notice_handler()->add_admin_notice(
				$message,
				'settings_menu',
				array(
					'dismissible'             => true,
					'always_show_on_settings' => false,
					'notice_class'            => 'notice-info',
				)
			);
		}
	}

	/**
	 * Get the admin message handler.
	 *
	 * @return \WC_Kledo_Admin_Message_Handler
	 * @since 1.0.0
	 */
	public function get_message_handler() {
		return $this->message_handler;
	}

	/**
	 * Get the admin notice handler instance.
	 *
	 * @return \WC_Kledo_Admin_Notice_Handler
	 * @since 1.0.0
	 */
	public function get_admin_notice_handler() {
		return $this->admin_notice_handler;
	}

	/**
	 * Get the connection handler.
	 *
	 * @return \WC_Kledo_Connection
	 * @since 1.0.0
	 */
	public function get_connection_handler() {
		return $this->connection_handler;
	}

	/**
	 * Return the plugin id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_id() {
		return self::PLUGIN_ID;
	}

	/**
	 * Determines if viewing the plugin settings in the admin.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_plugin_settings() {
		return is_admin() && WC_Kledo_Admin::PAGE_ID === wc_kledo_get_requested_value( 'page' );
	}

	/**
	 * Returns the plugin id with dashes in place of underscores, and
	 * appropriate for use in frontend element names, classes and ids.
	 *
	 * @return string plugin id with dashes in place of underscores
	 * @since 1.0.0
	 */
	public function get_id_dasherized() {
		return str_replace( '_', '-', $this->get_id() );
	}

	/**
	 * Gets the settings page URL.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_settings_url() {
		return admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID );
	}

	/**
	 * Gets the url for the assets directory.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function asset_dir_url() {
		return $this->plugin_url() . '/assets';
	}

	/**
	 * Gets the plugin's URL without a trailing slash.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', WC_KLEDO_PLUGIN_FILE ) );
	}
}

/**
 * Get the WooCommerce Kledo plugin instance.
 *
 * @return \WC_Kledo|null
 * @since  1.0.0
 */
function wc_kledo() {
	return WC_Kledo::instance();
}
