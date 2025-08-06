<?php

use Automattic\WooCommerce\Admin\Features\Features as WooAdminFeatures;
use Automattic\WooCommerce\Admin\Features\Navigation\Menu as WooAdminMenu;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Admin {
	/**
	 * The base page settings id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const PAGE_ID = 'wc-kledo';

	/**
	 * The settings screen array.
	 *
	 * @var \WC_Kledo_Settings_Screen[]
	 * @since 1.0.0
	 */
	private array $screens;

	/**
	 * Whether the new Woo nav should be used.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public bool $use_woo_nav;

	/**
	 * Settings constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->screens = array(
			WC_Kledo_Configure_Screen::ID => new WC_Kledo_Configure_Screen,
			WC_Kledo_Invoice_Screen::ID   => new WC_Kledo_Invoice_Screen,
			WC_Kledo_Order_Screen::ID     => new WC_Kledo_Order_Screen,
			WC_Kledo_Support_Screen::ID   => new WC_Kledo_Support_Screen,
		);

		$this->init_hooks();

		$this->use_woo_nav = class_exists( WooAdminFeatures::class ) && class_exists( WooAdminMenu::class ) && WooAdminFeatures::is_enabled( 'navigation' );
	}

	/**
	 * Init hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_js' ) );
		add_action( 'admin_menu', array( $this, 'add_menu_item' ) );
		add_action( 'wp_loaded', array( $this, 'save' ) );
	}

	/**
	 * Enqueue styles.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_styles(): void {
		if ( wc_kledo()->is_plugin_settings() ) {
			$version = WC_KLEDO_VERSION;

			wp_enqueue_style(
				'wc_kledo_admin_style',
				wc_kledo()->asset_dir_url() . '/css/style.css',
				array(),
				$version
			);

			wp_enqueue_style(
				'woocommerce_admin_styles',
				WC()->plugin_url() . '/assets/css/admin.css',
				array(),
				$version
			);

			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	/**
	 * Saves the settings page.
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @noinspection ForgottenDebugOutputInspection
	 */
	public function save(): void {
		if ( ! is_admin() || wc_kledo_get_requested_value( 'page' ) !== self::PAGE_ID ) {
			return;
		}

		$screen = $this->get_screen( wc_kledo_get_posted_value( 'screen_id' ) );

		if ( ! $screen ) {
			return;
		}

		if ( ! wc_kledo_get_posted_value( 'save_' . $screen->get_id() . '_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission to save these settings.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'wc_kledo_admin_save_' . $screen->get_id() . '_settings' );

		try {
			$screen->save();

			wc_kledo()->get_message_handler()->add_message(
				__( 'Your settings have been saved.', WC_KLEDO_TEXT_DOMAIN )
			);
		} catch ( WC_Kledo_Exception $exception ) {
			wc_kledo()->get_message_handler()->add_error(
				sprintf(
					__( 'Your settings could not be saved. %s', WC_KLEDO_TEXT_DOMAIN ),
					$exception->getMessage()
				)
			);
		}
	}

	/**
	 * Adds the Kledo menu item.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_item(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Kledo', WC_KLEDO_TEXT_DOMAIN ),
			__( 'Kledo', WC_KLEDO_TEXT_DOMAIN ),
			'manage_woocommerce', self::PAGE_ID,
			array( $this, 'render' ),
			5
		);
	}

	/**
	 * Gets the available screens.
	 *
	 * @return \WC_Kledo_Settings_Screen[]
	 * @since 1.0.0
	 */
	public function get_screens(): array {
		/**
		 * Filters the admin settings screens.
		 *
		 * @param  array  $screens  available screen objects
		 *
		 * @since 1.0.0
		 */
		$screens = (array) apply_filters( 'wc_kledo_admin_settings_screens', $this->screens, $this );

		// Ensure no bugs values are added via filter
		return array_filter(
			$screens,
			static function( $value ) {
				return $value instanceof WC_Kledo_Settings_Screen;
			}
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render(): void {
		$tabs        = $this->get_tabs();
		$current_tab = wc_kledo_get_requested_value( 'tab' );

		if ( ! $current_tab ) {
			$current_tab = current( array_keys( $tabs ) );
		}

		$screen = $this->get_screen( $current_tab );

		?>

		<div class="wrap woocommerce">
			<?php if ( ! $this->use_woo_nav ) : ?>
				<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
					<?php foreach ( $tabs as $id => $label ) : ?>
						<a href="<?php echo esc_html( admin_url( 'admin.php?page=' . self::PAGE_ID . '&tab=' . esc_attr( $id ) ) ); ?>" class="nav-tab <?php echo $current_tab === $id ? 'nav-tab-active' : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php wc_kledo()->get_message_handler()->show_messages(); ?>

			<?php if ( $screen ) : ?>
				<h1 class="screen-reader-text">
					<?php echo esc_html( $screen->get_title() ); ?>
				</h1>

				<p>
					<?php echo wp_kses_post( $screen->get_description() ); ?>
				</p>

				<?php $screen->render(); ?>

			<?php endif; ?>
		</div>

		<?php
	}

	/**
	 * Gets the tabs.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_tabs(): array {
		$tabs = array_map( static function ( $screen ) {
			return $screen->get_label();
		}, $this->get_screens() );

		/**
		 * Filters the admin settings tabs.
		 *
		 * @param  array  $tabs  tab data, as $id => $label
		 *
		 * @since 1.0.0
		 */
		return (array) apply_filters( 'wc_kledo_admin_settings_tabs', $tabs, $this );
	}

	/**
	 * Gets a settings screen object based on ID.
	 *
	 * @param  string  $screen_id  desired screen ID
	 *
	 * @return \WC_Kledo_Settings_Screen|null
	 * @since 1.0.0
	 */
	public function get_screen( string $screen_id ): ?WC_Kledo_Settings_Screen {
		$screens = $this->get_screens();

		return ! empty( $screens[ $screen_id ] ) && $screens[ $screen_id ] instanceof WC_Kledo_Settings_Screen ? $screens[ $screen_id ] : null;
	}

	/**
	 * Enqueues the javascript.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_js(): void {
		if ( ! $this->is_current_page_on( 'invoice', 'order' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-kledo',
			wc_kledo()->asset_dir_url() . '/js/kledo.js',
			array( 'jquery', 'selectWoo' ),
			WC_KLEDO_VERSION
		);

		wp_localize_script(
			'wc-kledo',
			'wc_kledo',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'i18n'     => array(
					'payment_account_placeholder' => esc_html__( 'Select Account', WC_KLEDO_TEXT_DOMAIN ),
					'warehouse_placeholder'       => esc_html__( 'Select Warehouse', WC_KLEDO_TEXT_DOMAIN ),

					'error_loading' => esc_html__( 'The results could not be loaded.', WC_KLEDO_TEXT_DOMAIN ),
					'loading_more'  => esc_html__( 'Loading more results...', WC_KLEDO_TEXT_DOMAIN ),
					'no_result'     => esc_html__( 'No results found', WC_KLEDO_TEXT_DOMAIN ),
					'searching'     => esc_html__( 'Loading...', WC_KLEDO_TEXT_DOMAIN ),
					'search'        => esc_html__( 'Search', WC_KLEDO_TEXT_DOMAIN ),
				),
			)
		);
	}

	/**
	 * Determines whether the current screen is the same as identified by the tab.
	 *
	 * @param  string  ...$tabs
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	protected function is_current_page_on(string ...$tabs): bool {
		if ( self::PAGE_ID !== wc_kledo_get_requested_value( 'page' ) ) {
			return false;
		}

		// Assume we are on configure tab by default
		// because the link under menu doesn't include the tab query arg.
		$currentTab = wc_kledo_get_requested_value( 'tab', 'configure' );

		return ! empty( $currentTab ) && in_array( $currentTab, $tabs, true );
	}
}
