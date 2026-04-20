<?php

// Exit if accessed directly.
use Automattic\WooCommerce\Utilities\FeaturesUtil as WC_FeatureUtil;

defined( 'ABSPATH' ) || exit;

final class WC_Kledo {
	/**
	 * The plugin id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const PLUGIN_ID = WC_Kledo_Loader::PLUGIN_ID;

	/**
	 * The single instance of this class.
	 *
	 * @var null|self
	 * @since 1.0.0
	 */
	protected static ?WC_Kledo $instance = null;

	/**
	 * The admin notice instance.
	 *
	 * @var \WC_Kledo_Admin_Notice_Handler
	 * @since 1.0.0
	 */
	private WC_Kledo_Admin_Notice_Handler $admin_notice_handler;

	/**
	 * The message instance.
	 *
	 * @var \WC_Kledo_Admin_Message_Handler
	 * @since 1.0.0
	 */
	private WC_Kledo_Admin_Message_Handler $message_handler;

	/**
	 * The API connection instance.
	 *
	 * @var \WC_Kledo_Connection
	 * @since 1.0.0
	 */
	private WC_Kledo_Connection $connection_handler;

	/**
	 * The admin settings instance.
	 *
	 * @var \WC_Kledo_Admin|null
	 * @since 1.0.0
	 */
	private ?WC_Kledo_Admin $admin_settings = null;

	/**
	 * WooCommerce ↔ Kledo bridge (status hooks + shared delivery pipeline).
	 *
	 * @var \WC_Kledo_WooCommerce
	 * @since 1.6.0
	 */
	private WC_Kledo_WooCommerce $woocommerce_bridge;

	/**
	 * Gets the main class instance.
	 *
	 * Ensures only one instance can be loaded.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function instance(): WC_Kledo {
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
	 * Set up the autoloader class.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function setup_autoloader(): void {
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
	private function includes(): void {
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
	private function init(): void {
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
		$this->woocommerce_bridge = new WC_Kledo_WooCommerce();
		$this->woocommerce_bridge->setup_hooks();
	}

	/**
	 * Shared WooCommerce order/invoice delivery logic (used by status hooks, retries, and manual admin).
	 *
	 * @return \WC_Kledo_WooCommerce
	 * @since 1.6.0
	 */
	public function get_woocommerce_bridge(): WC_Kledo_WooCommerce {
		return $this->woocommerce_bridge;
	}

	/**
	 * Adds the action & filter hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function add_hooks(): void {
		// Add the admin notices.
		add_action( 'admin_notices', array( $this, 'add_admin_notices' ) );

		// Declare the compatibility with WooCommerce plugin HPOS.
		add_action( 'before_woocommerce_init', array( $this, 'add_woocommerce_hpos_compatibility' ) );

		// Retry cron for failed transactions.
		add_action( 'wc_kledo_retry_failed_transactions', array( $this, 'retry_failed_transactions' ) );

		// Admin-side fallback: process overdue retries synchronously on admin page
		// loads.  WP-Cron spawns an async HTTP request to wp-cron.php that silently
		// fails in development environments and some production servers that cannot
		// reach themselves (localhost, strict firewall, DISABLE_WP_CRON, etc.).
		// This hook guarantees retries execute whenever an admin user refreshes any
		// admin page after the scheduled time has passed.
		add_action( 'admin_init', array( $this, 'maybe_process_due_retries' ) );
	}

	/**
	 * Retry failed order / invoice transactions via WP-Cron.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function retry_failed_transactions(): void {
		// Single-execution lock prevents concurrent runs when both the admin
		// fallback and a WP-Cron spawn fire at the same time.  The 60 s TTL
		// ensures a stale lock (e.g. from an unexpected PHP fatal) never blocks
		// future runs permanently.
		$lock_key = 'wc_kledo_retry_lock';

		if ( get_transient( $lock_key ) ) {
			return;
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		set_transient( $lock_key, 1, 60 );

		$context = wp_doing_cron() ? 'wp-cron' : 'other';

		if ( is_admin() ) {
			$context = 'admin';
		}

		wc_kledo_log_info( sprintf(
			'Retry run started: %d item(s) in queue, context: %s.',
			count( $queue ),
			$context
		) );

		$max_attempts        = 20;
		$max_lifetime        = 2 * DAY_IN_SECONDS;
		$next_retry_required = false;
		$updated_queue       = array();
		$now                 = time();

		foreach ( $queue as $key => $item ) {
			$order_id    = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
			$type        = $item['type'] ?? '';
			$attempts    = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;
			$created     = isset( $item['created_at'] ) ? (int) $item['created_at'] : $now;
			$next_run    = isset( $item['next_run_at'] ) ? (int) $item['next_run_at'] : $now;
			$item_status = isset( $item['status'] ) ? (string) $item['status'] : 'retrying';

			if ( ! $order_id || ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
				continue;
			}

			// Terminal failures remain in the queue for audit / manual retry; skip auto-retry only.
			if ( 'failed' === $item_status ) {
				$updated_queue[ $key ] = $item;
				continue;
			}

			if ( $next_run > $now ) {
				$updated_queue[ $key ] = $item;
				$next_retry_required   = true;
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( ( $now - $created ) > $max_lifetime ) {
				if ( $order instanceof WC_Order ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: transaction type (order/invoice) */
							__( 'Kledo: automatic retry for %s has stopped: maximum queue lifetime reached. Manual retry is still available from the Transactions screen.', WC_KLEDO_TEXT_DOMAIN ),
							$type
						)
					);
				}

				// Keep as terminal failure so it is visible in the Transactions screen.
				$item['status']      = 'failed';
				$updated_queue[ $key ] = $item;
				continue;
			}

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( wc_kledo_is_delivery_synced( $order, $type ) ) {
				wc_kledo_remove_failed_transaction_from_queue( $order_id, $type );
				continue;
			}

			if ( $attempts >= $max_attempts ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: transaction type (order/invoice), 2: attempts count */
						__( 'Kledo: automatic retry for %1$s has stopped after %2$d failed attempts. Manual retry is still available from the Transactions screen.', WC_KLEDO_TEXT_DOMAIN ),
						$type,
						$attempts
					)
				);

				// Keep as terminal failure so it is visible in the Transactions screen.
				$item['status']        = 'failed';
				$updated_queue[ $key ] = $item;
				continue;
			}

			$attempts++;

			$result = $this->get_woocommerce_bridge()->deliver(
				$order,
				$type,
				array(
					'trigger'            => 'retry',
					'enqueue_on_failure'  => false,
				)
			);

			if ( ! empty( $result['success'] ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: transaction type (order/invoice), 2: attempts count */
						__( 'Kledo: successfully resent %1$s to Kledo after %2$d attempt(s).', WC_KLEDO_TEXT_DOMAIN ),
						$type,
						$attempts
					)
				);

				continue;
			}

			if ( ! empty( $result['skipped'] ) && 'already_synced' === ( $result['reason'] ?? '' ) ) {
				continue;
			}

			if ( ! empty( $result['error'] ) ) {
				$last_error = $result['error'];
			} else {
				$last_error = wc_kledo_sanitize_api_error_message(
					sprintf(
						'HTTP %d',
						(int) $result['http_code']
					)
				);
			}

			// $attempts was pre-incremented before deliver (0→1 for the first cron
			// retry). Use ($attempts + 1) so each cron retry consumes the NEXT
			// backoff step rather than repeating the delay used for initial enqueue.
			// Progression: enqueue→5 min, retry1→10 min, retry2→30 min, …
			$updated_queue[ $key ] = array(
				'order_id'    => $order_id,
				'type'        => $type,
				'attempts'    => $attempts,
				'last_error'  => $last_error,
				'created_at'  => $created,
				'next_run_at' => $now + $this->get_retry_delay( $attempts + 1 ),
				'status'      => 'retrying',
			);

			$next_retry_required = true;
		}

		update_option( $option_name, $updated_queue, false );

		if ( $next_retry_required && ! wp_next_scheduled( 'wc_kledo_retry_failed_transactions' ) ) {
			// Schedule the cron at the earliest next_run_at across all retrying
			// items rather than a fixed 60-second heartbeat.  This avoids firing
			// the hook multiple times before any item is actually due.
			$min_next = PHP_INT_MAX;

			foreach ( $updated_queue as $q_item ) {
				if ( 'failed' !== ( $q_item['status'] ?? '' ) && isset( $q_item['next_run_at'] ) ) {
					$min_next = min( $min_next, (int) $q_item['next_run_at'] );
				}
			}

			// Ensure the event is always at least 30 s in the future to give
			// WP-Cron time to record the scheduled event before it fires again.
			$schedule_at = PHP_INT_MAX !== $min_next
				? max( $now + 30, $min_next )
				: $now + MINUTE_IN_SECONDS;

			wp_schedule_single_event( $schedule_at, 'wc_kledo_retry_failed_transactions' );
		}

		delete_transient( $lock_key );
	}

	/**
	 * Admin-side synchronous fallback for overdue retry items.
	 *
	 * WP-Cron spawns an async HTTP request to wp-cron.php to execute scheduled
	 * events.  That spawn silently fails whenever the server cannot reach itself
	 * (localhost, Docker, strict firewall, DISABLE_WP_CRON = true, etc.).  In
	 * those cases the retry queue grows stale and no retries ever execute.
	 *
	 * By hooking into admin_init this method ensures that any overdue item is
	 * processed synchronously on the next admin page load, providing a reliable
	 * execution path independently of WP-Cron spawn health.
	 *
	 * @return void
	 * @since 1.7.3
	 */
	public function maybe_process_due_retries(): void {
		// Skip AJAX and WP-Cron contexts — they have their own execution paths.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$queue = get_option( 'wc_kledo_failed_transactions', array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		$now = time();

		foreach ( $queue as $item ) {
			if ( 'failed' !== ( $item['status'] ?? '' )
				&& isset( $item['next_run_at'] )
				&& (int) $item['next_run_at'] <= $now ) {
				// At least one item is due — delegate to the main retry runner.
				// The lock inside retry_failed_transactions() prevents concurrent
				// double-processing if WP-Cron spawns at the same moment.
				$this->retry_failed_transactions();

				return;
			}
		}
	}

	/**
	 * Seconds to wait before the nth retry attempt. Delegates to the global
	 * helper so the backoff table is defined in one place.
	 *
	 * @param  int  $attempt  1-based attempt index.
	 *
	 * @return int Delay in seconds.
	 * @since 1.5.0
	 */
	private function get_retry_delay( int $attempt ): int {
		return wc_kledo_get_retry_delay( $attempt );
	}

	/**
	 * Add the plugin admin notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_admin_notices(): void {
		// Inform users who are not connected to Kledo
		if ( ! $this->is_plugin_settings() && ! $this->get_connection_handler()->is_configured() ) {
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
	 * Add WooCommerce HPOS Compatibility.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function add_woocommerce_hpos_compatibility(): void {
		if ( class_exists( WC_FeatureUtil::class ) ) {
			WC_FeatureUtil::declare_compatibility( 'custom_order_tables', WC_KLEDO_PLUGIN_FILE );
		}
	}

	/**
	 * Get the admin message handler.
	 *
	 * @return \WC_Kledo_Admin_Message_Handler
	 * @since 1.0.0
	 */
	public function get_message_handler(): WC_Kledo_Admin_Message_Handler {
		return $this->message_handler;
	}

	/**
	 * Get the admin notice handler instance.
	 *
	 * @return \WC_Kledo_Admin_Notice_Handler
	 * @since 1.0.0
	 */
	public function get_admin_notice_handler(): WC_Kledo_Admin_Notice_Handler {
		return $this->admin_notice_handler;
	}

	/**
	 * Get the connection handler.
	 *
	 * @return \WC_Kledo_Connection
	 * @since 1.0.0
	 */
	public function get_connection_handler(): WC_Kledo_Connection {
		return $this->connection_handler;
	}

	/**
	 * Return the plugin id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_id(): string {
		return self::PLUGIN_ID;
	}

	/**
	 * Determines if viewing the plugin settings in the admin.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_plugin_settings(): bool {
		return is_admin() && WC_Kledo_Admin::PAGE_ID === wc_kledo_get_requested_value( 'page' );
	}

	/**
	 * Returns the plugin id with dashes in place of underscores, and
	 * appropriate for use in frontend element names, classes and ids.
	 *
	 * @return string plugin id with dashes in place of underscores
	 * @since 1.0.0
	 */
	public function get_id_dasherized(): string {
		return str_replace( '_', '-', $this->get_id() );
	}

	/**
	 * Gets the settings page URL.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_settings_url(): string {
		return admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID );
	}

	/**
	 * Gets the url for the assets directory.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function asset_dir_url(): string {
		return $this->plugin_url() . '/assets';
	}

	/**
	 * Gets the plugin's URL without a trailing slash.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function plugin_url(): string {
		return untrailingslashit( plugins_url( '/', WC_KLEDO_PLUGIN_FILE ) );
	}
}

/**
 * Get the WooCommerce Kledo plugin instance.
 *
 * @return \WC_Kledo|null
 * @since  1.0.0
 */
function wc_kledo(): ?WC_Kledo {
	return WC_Kledo::instance();
}
