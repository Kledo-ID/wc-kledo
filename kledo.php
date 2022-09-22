<?php
/**
 * Plugin Name: Kledo
 * Plugin URI: https://github.com/Kledo-ID/wc-kledo
 * Description: Integrates <a href="https://woocommerce.com/" target="_blank" >WooCommerce</a> with the <a href="https://kledo.com" target="_blank">Kledo</a> accounting software.
 * Author: Kledo
 * Author URI: https://kledo.com
 * Version: 1.1.5
 * Text Domain: wc-kledo
 * WC requires at least: 3.5.0
 * WC tested up to: 5.3.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Define the main kledo plugin file.
if ( ! defined( 'WC_KLEDO_PLUGIN_FILE' ) ) {
	define( 'WC_KLEDO_PLUGIN_FILE', __FILE__ );
}

class WC_Kledo_Loader {
	/**
	 * The plugin version.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const VERSION = '1.1.4';

	/**
	 * Minimum PHP version.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const PHP_VERSION = '7.0.0';

	/**
	 * Minimum WordPress version.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const WP_VERSION = '4.4';

	/**
	 * Minimum WooCommerce version.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const WC_VERSION = '3.5.0';

	/**
	 * The plugin name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const PLUGIN_NAME = 'Kledo';

	/**
	 * The plugin id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const PLUGIN_ID = 'wc_kledo';

	/**
	 * The plugin text domain.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const TEXT_DOMAIN = 'wc-kledo';

	/**
	 * The admin notices to add.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $notices = array();

	/**
	 * The single instance of this class.
	 *
	 * @var null|self
	 * @since 1.0.0
	 */
	private static $instance;

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
		$this->define_constant();

		if ( $this->is_wc_active() ) {
			$this->setup();
		} else {
			$this->add_admin_notice( 'wc_required', 'error', $this->get_wc_required_message() );
		}

		add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
	}

	/**
	 * Displays admin notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function admin_notices() {
		foreach ( $this->notices as $notice ) {
			?>
			<div class="<?php
			echo esc_attr( $notice['class'] ); ?>">
				<p><?php
					echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Init plugin hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function setup() {
		register_activation_hook( WC_KLEDO_PLUGIN_FILE, array( $this, 'activation_check' ) );

		add_action( 'admin_init', array( $this, 'check_environment' ) );
		add_action( 'admin_init', array( $this, 'plugin_notices' ) );

		// If the environment check fails, initialize the plugin.
		if ( $this->is_environment_compatible() ) {
			add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
		}
	}

	/**
	 * Checks the server environment and other factors and deactivates plugins as necessary.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function activation_check() {
		if ( ! $this->is_environment_compatible() ) {
			$this->deactivate_plugin();

			$this->add_admin_notice( 'bad_environment', 'error', WC_KLEDO_PLUGIN_NAME . ' could not be activated. ' . $this->get_environment_message() );
		}
	}

	/**
	 * Initializes the plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init_plugin() {
		if ( ! $this->plugins_compatible() ) {
			return;
		}

		require_once plugin_dir_path( WC_KLEDO_PLUGIN_FILE ) . 'includes/class-wc-kledo.php';

		// Fire it up!
		if ( function_exists( 'wc_kledo' ) ) {
			wc_kledo();
		}
	}

	/**
	 * Define plugin constant.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function define_constant() {
		$this->define( 'WC_KLEDO_ABSPATH', plugin_dir_path( WC_KLEDO_PLUGIN_FILE ) );
		$this->define( 'WC_KLEDO_PLUGIN_BASENAME', plugin_basename( WC_KLEDO_PLUGIN_FILE ) );
		$this->define( 'WC_KLEDO_PLUGIN_NAME', self::PLUGIN_NAME );
		$this->define( 'WC_KLEDO_PLUGIN_URL', untrailingslashit( plugins_url( '/', WC_KLEDO_PLUGIN_FILE ) ) );
		$this->define( 'WC_KLEDO_VERSION', self::VERSION );
		$this->define( 'WC_KLEDO_MIN_PHP_VERSION', self::PHP_VERSION );
		$this->define( 'WC_KLEDO_MIN_WP_VERSION', self::WP_VERSION );
		$this->define( 'WC_KLEDO_MIN_WC_VERSION', self::WC_VERSION );
		$this->define( 'WC_KLEDO_TEXT_DOMAIN', self::TEXT_DOMAIN );
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param  string  $name  Constant name.
	 * @param  string|bool  $value  Constant value.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Determine if the WooCommerce plugin active.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_wc_active(): bool {
		return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true );
	}

	/**
	 * Determines if the server environment is compatible with this plugin.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_environment_compatible() {
		return version_compare( PHP_VERSION, WC_KLEDO_MIN_PHP_VERSION, '>=' );
	}

	/**
	 * Determines if the WordPress compatible.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_wp_compatible() {
		if ( ! WC_KLEDO_MIN_WP_VERSION ) {
			return true;
		}

		return version_compare( get_bloginfo( 'version' ), WC_KLEDO_MIN_WP_VERSION, '>=' );
	}

	/**
	 * Determines if the WooCommerce compatible.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_wc_compatible() {
		if ( ! WC_KLEDO_MIN_WC_VERSION ) {
			return true;
		}

		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WC_KLEDO_MIN_WC_VERSION, '>=' );
	}

	/**
	 * Get the message for display when the environment is incompatible with this plugin.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_environment_message() {
		return sprintf( 'The minimum PHP version required for this plugin is %1$s. You are running %2$s.', WC_KLEDO_MIN_PHP_VERSION, PHP_VERSION );
	}

	/**
	 * Get the message to notifying user if WC is required.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_wc_required_message() {
		return sprintf( esc_html__( 'WooCommerce Kledo requires %s to be installed and active.', WC_KLEDO_TEXT_DOMAIN ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' );
	}

	/**
	 * Checks the environment on loading WordPress, just in case the environment changes after activation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function check_environment() {
		if ( ! $this->is_environment_compatible()
		     && is_plugin_active( plugin_basename( WC_KLEDO_PLUGIN_FILE ) )
		) {
			$this->deactivate_plugin();

			$this->add_admin_notice( 'bad_environment', 'error', WC_KLEDO_PLUGIN_NAME . ' has been deactivated. ' . $this->get_environment_message() );
		}
	}

	/**
	 * Adds notices for out-of-date WordPress and/or WooCommerce versions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function plugin_notices() {
		if ( ! $this->is_wp_compatible() ) {
			$this->add_admin_notice( 'update_wordpress', 'error', sprintf(
				'%s requires WordPress version %s or higher. Please %supdate WordPress &raquo;%s',
				'<strong>' . WC_KLEDO_PLUGIN_NAME . '</strong>',
				WC_KLEDO_MIN_WP_VERSION,
				'<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '</a>'
			) );
		}

		if ( ! $this->is_wc_compatible() ) {
			$this->add_admin_notice( 'update_woocommerce', 'error', sprintf(
				'%1$s requires WooCommerce version %2$s or higher. Please %3$supdate WooCommerce%4$s to the latest version, or %5$sdownload the minimum required version &raquo;%6$s',
				'<strong>' . WC_KLEDO_PLUGIN_NAME . '</strong>',
				WC_KLEDO_MIN_WC_VERSION,
				'<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '</a>',
				'<a href="' . esc_url( 'https://downloads.wordpress.org/plugin/woocommerce.' . WC_KLEDO_MIN_WC_VERSION . '.zip' ) . '">', '</a>'
			) );
		}
	}

	/**
	 * Determines if the required plugins are compatible.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function plugins_compatible() {
		return $this->is_wp_compatible() && $this->is_wc_compatible();
	}

	/**
	 * Adds an admin notice to be displayed.
	 *
	 * @param  string  $slug  the slug for the notice
	 * @param  string  $class  the css class for the notice
	 * @param  string  $message  the notice message
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function add_admin_notice( $slug, $class, $message ) {
		$this->notices[ $slug ] = [
			'class'   => $class,
			'message' => $message,
		];
	}

	/**
	 * Deactivates the plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function deactivate_plugin() {
		deactivate_plugins( plugin_basename( WC_KLEDO_PLUGIN_FILE ) );

		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}
}

// Fire it up!
WC_Kledo_Loader::instance();
