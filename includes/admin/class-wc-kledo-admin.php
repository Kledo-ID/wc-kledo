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
			WC_Kledo_Configure_Screen::ID    => new WC_Kledo_Configure_Screen(),
			WC_Kledo_Invoice_Screen::ID      => new WC_Kledo_Invoice_Screen(),
			WC_Kledo_Order_Screen::ID        => new WC_Kledo_Order_Screen(),
			WC_Kledo_Transactions_Screen::ID => new WC_Kledo_Transactions_Screen(),
			WC_Kledo_Sync_Screen::ID         => new WC_Kledo_Sync_Screen(),
			WC_Kledo_Diagnostics_Screen::ID  => new WC_Kledo_Diagnostics_Screen(),
			WC_Kledo_Help_Screen::ID         => new WC_Kledo_Help_Screen(),
			WC_Kledo_Support_Screen::ID      => new WC_Kledo_Support_Screen(),
		);

		$this->init_hooks();

		$order_sync_admin = new WC_Kledo_Admin_Order_Sync();
		$order_sync_admin->init();

		$order_column_admin = new WC_Kledo_Admin_Order_Column();
		$order_column_admin->init();

		$order_filter_admin = new WC_Kledo_Admin_Order_Filter();
		$order_filter_admin->init();

		$order_bulk_actions_admin = new WC_Kledo_Admin_Order_Bulk_Actions();
		$order_bulk_actions_admin->init();

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_command_palette' ) );
		add_action( 'admin_menu', array( $this, 'add_menu_item' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_transactions_tab' ) );
		add_action( 'wp_loaded', array( $this, 'save' ) );

		add_filter(
			'plugin_action_links_' . WC_KLEDO_PLUGIN_BASENAME,
			array( $this, 'add_plugin_action_links' )
		);
	}

	/**
	 * Redirects legacy Failed Transactions tab URLs to the Transactions tab.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function maybe_redirect_legacy_transactions_tab(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( wc_kledo_get_requested_value( 'page' ) !== self::PAGE_ID ) {
			return;
		}

		if ( wc_kledo_get_requested_value( 'tab' ) !== 'failed_transactions' ) {
			return;
		}

		$params = array(
			'page' => self::PAGE_ID,
			'tab'  => WC_Kledo_Transactions_Screen::ID,
		);

		$passthrough = array( 'wc_kledo_tx_status', 'wc_kledo_tx_orderby', 'wc_kledo_tx_order', 'paged' );

		foreach ( $passthrough as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
			if ( isset( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.NonceVerification.Recommended
				$params[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
			}
		}

		wp_safe_redirect( add_query_arg( $params, admin_url( 'admin.php' ) ) );
		exit;
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

			wp_enqueue_style( 'dashicons' );

			wp_enqueue_style(
				'woocommerce_admin_styles',
				WC()->plugin_url() . '/assets/css/admin.css',
				array(),
				$version
			);

			wp_enqueue_style( 'woocommerce_admin_styles' );

			/* WP_List_Table-style column headers (th.sortable / th.sorted). */
			wp_enqueue_style( 'list-tables' );

			wp_enqueue_style(
				'wc_kledo_admin_style',
				wc_kledo()->asset_dir_url() . '/css/style.css',
				array( 'dashicons', 'list-tables' ),
				wc_kledo_asset_version( 'assets/css/style.css' )
			);
		}

		$this->enqueue_menu_bubble_style();
	}

	/**
	 * Keeps the menu bubble's circle visible on every admin color scheme.
	 *
	 * Admin color schemes other than Default paint every bubble under a hovered or open menu in
	 * the submenu's own background color (Modern: #0c0c0c on #0c0c0c), so only the bare number
	 * is left — `#adminmenu li:hover a .awaiting-mod` fires as soon as the pointer is anywhere
	 * over WooCommerce. The bubble therefore keeps one color in every state: the scheme's accent
	 * color, or WordPress's red on Default. The selector outranks the scheme's hover and current
	 * rules (two IDs). Printed on every admin page, since the menu shows on every page.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function enqueue_menu_bubble_style(): void {
		wp_register_style( 'wc-kledo-menu', false, array(), WC_KLEDO_VERSION );
		wp_enqueue_style( 'wc-kledo-menu' );
		wp_add_inline_style(
			'wc-kledo-menu',
			'#adminmenu #toplevel_page_woocommerce .wc-kledo-menu-count{background:var(--wp-admin-theme-color,#d63638);color:#fff}'
			. 'body.admin-color-fresh #adminmenu #toplevel_page_woocommerce .wc-kledo-menu-count{background:#d63638}'
		);
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
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'wc-kledo' ) );
		}

		check_admin_referer( 'wc_kledo_admin_save_' . $screen->get_id() . '_settings' );

		try {
			$screen->save();

			wc_kledo()->get_message_handler()->add_message(
				__( 'Your settings have been saved.', 'wc-kledo' )
			);
		} catch ( WC_Kledo_Exception $exception ) {
			wc_kledo()->get_message_handler()->add_error(
				sprintf(
					/* translators: %s: error message. */
					__( 'Your settings could not be saved. %s', 'wc-kledo' ),
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
			__( 'Kledo', 'wc-kledo' ),
			__( 'Kledo', 'wc-kledo' ) . $this->get_menu_bubble(),
			'manage_woocommerce',
			self::PAGE_ID,
			array( $this, 'render' ),
			5
		);
	}

	/**
	 * The count bubble next to "Kledo" in the WooCommerce menu.
	 *
	 * Same markup as WooCommerce's own Orders bubble, so it looks like part of the menu. Shows
	 * how many orders need checking in Kledo, "99+" beyond 99, and nothing at zero or while the
	 * plugin is switched off or not connected — there is nothing to act on then.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function get_menu_bubble(): string {
		if ( ! $this->shows_attention_counts() ) {
			return '';
		}

		$count = wc_kledo_get_attention_count();

		if ( $count <= 0 ) {
			return '';
		}

		$label = sprintf(
			/* translators: %d: number of orders */
			_n( '%d order needs checking in Kledo', '%d orders need checking in Kledo', $count, 'wc-kledo' ),
			$count
		);

		return $this->format_bubble( 'awaiting-mod update-plugins wc-kledo-menu-count count-' . min( $count, 100 ), $count, $label );
	}

	/**
	 * The count bubble on a settings tab, telling which tab the menu number comes from.
	 *
	 * The menu bubble adds up two kinds of order, each handled in its own tab: orders never sent
	 * (Sync tab) and orders whose transaction failed in Kledo (Transactions tab). Other tabs get
	 * nothing.
	 *
	 * @param  string  $tab_id  The tab ID.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function get_tab_bubble( string $tab_id ): string {
		$labels = array(
			/* translators: %d: number of orders */
			WC_Kledo_Sync_Screen::ID         => array( 'not_sent', _n_noop( '%d order not sent to Kledo yet', '%d orders not sent to Kledo yet', 'wc-kledo' ) ),
			/* translators: %d: number of orders */
			WC_Kledo_Transactions_Screen::ID => array( 'failed', _n_noop( '%d order failed in Kledo', '%d orders failed in Kledo', 'wc-kledo' ) ),
		);

		if ( ! isset( $labels[ $tab_id ] ) || ! $this->shows_attention_counts() ) {
			return '';
		}

		list( $key, $noop ) = $labels[ $tab_id ];

		$count = wc_kledo_get_attention_counts()[ $key ];

		if ( $count <= 0 ) {
			return '';
		}

		return $this->format_bubble( 'wc-kledo-tab-count', $count, sprintf( translate_nooped_plural( $noop, $count, 'wc-kledo' ), $count ) );
	}

	/**
	 * Whether the "needs checking" counts are shown at all.
	 *
	 * Not while the plugin is switched off or not connected: nothing is sent then, so every order
	 * would look unsent and there is nothing the user can act on.
	 *
	 * @return bool
	 * @since 1.8.0
	 */
	private function shows_attention_counts(): bool {
		return current_user_can( 'manage_woocommerce' )
			&& wc_string_to_bool( get_option( WC_Kledo_Configure_Screen::SETTING_ENABLE_API_CONNECTION, 'yes' ) )
			&& wc_kledo()->get_connection_handler()->is_configured();
	}

	/**
	 * Count bubble markup: "99+" beyond 99, with the full sentence as tooltip and for screen readers.
	 *
	 * @param  string  $classes  Classes of the outer span.
	 * @param  int     $count    The number.
	 * @param  string  $label    Sentence describing the number.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function format_bubble( string $classes, int $count, string $label ): string {
		return sprintf(
			' <span class="%1$s" title="%2$s"><span class="processing-count" aria-hidden="true">%3$s</span><span class="screen-reader-text">%2$s</span></span>',
			esc_attr( $classes ),
			esc_attr( $label ),
			esc_html( $count > 99 ? '99+' : (string) $count )
		);
	}

	/**
	 * Adds a "Settings" link to the plugin row on the Plugins screen.
	 *
	 * The settings live under WooCommerce > Kledo rather than under the Settings
	 * menu, which is not where someone looks after activating a plugin. This puts
	 * a direct link where they do look.
	 *
	 * The link is only added for users who can actually open that page. The
	 * Plugins screen needs `activate_plugins`, while the Kledo screen is
	 * registered with `manage_woocommerce` — without this guard a user holding
	 * only the former would be handed a link straight into a permission error.
	 *
	 * @param  string[] $links  Action links already registered for this plugin.
	 *
	 * @return string[]
	 * @since 1.7.4
	 */
	public function add_plugin_action_links( array $links ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_ID ) ),
			esc_html__( 'Settings', 'wc-kledo' )
		);

		array_unshift( $links, $settings_link );

		return $links;
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
			static function ( $value ) {
				return $value instanceof WC_Kledo_Settings_Screen;
			}
		);
	}

	/**
	 * Render the settings page.
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
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_ID . '&tab=' . rawurlencode( (string) $id ) ) ); ?>" class="nav-tab <?php echo $current_tab === $id ? 'nav-tab-active' : ''; ?>">
							<?php echo esc_html( $label ); ?><?php echo $this->get_tab_bubble( (string) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in format_bubble(). ?>
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

				<?php $this->render_guide_link( $current_tab ); ?>

				<?php $screen->render(); ?>

			<?php endif; ?>
		</div>

		<?php
	}

	/**
	 * A link from a settings tab to the part of the Guide & FAQ tab about it.
	 *
	 * @param  string $tab
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_guide_link( string $tab ): void {
		$anchors = array(
			WC_Kledo_Configure_Screen::ID    => 'guide-api-key',
			WC_Kledo_Invoice_Screen::ID      => 'guide-invoice',
			WC_Kledo_Order_Screen::ID        => 'guide-sales-order',
			WC_Kledo_Transactions_Screen::ID => 'guide-transactions',
			WC_Kledo_Sync_Screen::ID         => 'guide-sync',
			WC_Kledo_Diagnostics_Screen::ID  => 'guide-diagnostics',
		);

		if ( ! isset( $anchors[ $tab ] ) ) {
			return;
		}

		printf(
			'<p class="wc-kledo-guide-link"><span class="dashicons dashicons-book" aria-hidden="true"></span> <a href="%1$s">%2$s</a></p>',
			esc_url( WC_Kledo_Help_Screen::get_url( $anchors[ $tab ] ) ),
			esc_html__( 'Guide for this tab', 'wc-kledo' )
		);
	}

	/**
	 * Gets the tabs.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_tabs(): array {
		$tabs = array_map(
			static function ( $screen ) {
				return $screen->get_label();
			},
			$this->get_screens()
		);

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
	 * @param  string $screen_id  desired screen ID
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
	/**
	 * Minimum WordPress version whose command palette reaches ordinary admin screens.
	 *
	 * Before this, the palette existed only inside the block and site editors, so a command
	 * pointing at a classic settings page could be registered but never found.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	private const COMMAND_PALETTE_MINIMUM_WP_VERSION = '6.9';

	/**
	 * Register the plugin's screens with the WordPress command palette.
	 *
	 * Loaded on every admin screen rather than only the plugin's own: a command that can only be
	 * found from the page it opens is not worth registering.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function enqueue_command_palette(): void {
		// The palette leads to a page guarded by `manage_woocommerce`. Offering it to someone who
		// cannot open it would just be a shortcut to a permission error.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( version_compare( get_bloginfo( 'version' ), self::COMMAND_PALETTE_MINIMUM_WP_VERSION, '<' ) ) {
			return;
		}

		// Belt and braces against a build where the palette scripts were removed or renamed: the
		// script would otherwise be queued with a dependency WordPress cannot satisfy, which
		// silently drops it and everything after it in the queue.
		if ( ! wp_script_is( 'wp-commands', 'registered' ) || ! wp_script_is( 'wp-data', 'registered' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-kledo-command-palette',
			wc_kledo()->asset_dir_url() . '/js/command-palette.js',
			array( 'wp-data', 'wp-commands' ),
			wc_kledo_asset_version( 'assets/js/command-palette.js' ),
			true
		);

		wp_localize_script(
			'wc-kledo-command-palette',
			'wcKledoCommands',
			array(
				'commands' => $this->get_palette_commands(),
			)
		);
	}

	/**
	 * The commands the palette should offer, one per settings screen.
	 *
	 * Every label is prefixed with the plugin name so that typing "kledo" surfaces all of them at
	 * once, which is how someone looks for a plugin they cannot find in the menu.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.7.4
	 */
	private function get_palette_commands(): array {
		$screens = array(
			WC_Kledo_Configure_Screen::ID    => __( 'Kledo: Configure', 'wc-kledo' ),
			WC_Kledo_Invoice_Screen::ID      => __( 'Kledo: Invoice settings', 'wc-kledo' ),
			WC_Kledo_Order_Screen::ID        => __( 'Kledo: Order settings', 'wc-kledo' ),
			WC_Kledo_Transactions_Screen::ID => __( 'Kledo: Kledo Status', 'wc-kledo' ),
			WC_Kledo_Sync_Screen::ID         => __( 'Kledo: Sync', 'wc-kledo' ),
			WC_Kledo_Diagnostics_Screen::ID  => __( 'Kledo: Diagnostics', 'wc-kledo' ),
			WC_Kledo_Help_Screen::ID         => __( 'Kledo: Guide & FAQ', 'wc-kledo' ),
			WC_Kledo_Support_Screen::ID      => __( 'Kledo: Support', 'wc-kledo' ),
		);

		$commands = array();

		foreach ( $screens as $screen_id => $label ) {
			$commands[] = array(
				'name'  => 'wc-kledo/' . $screen_id,
				'label' => $label,
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_ID . '&tab=' . rawurlencode( (string) $screen_id ) ),
			);
		}

		return $commands;
	}

	public function enqueue_js(): void {
		$this->enqueue_transactions_js();
		$this->enqueue_sync_js();
		$this->enqueue_diagnostics_js();

		if ( $this->is_current_page_on( WC_Kledo_Help_Screen::ID ) ) {
			wp_enqueue_script( 'wc-kledo-help', wc_kledo()->asset_dir_url() . '/js/help.js', array(), wc_kledo_asset_version( 'assets/js/help.js' ), true );
		}

		if ( ! $this->is_current_page_on( 'invoice', 'order' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-kledo',
			wc_kledo()->asset_dir_url() . '/js/kledo.js',
			array( 'jquery', 'selectWoo' ),
			wc_kledo_asset_version( 'assets/js/kledo.js' )
		);

		wp_localize_script(
			'wc-kledo',
			'wc_kledo',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'security' => wp_create_nonce( 'wc_kledo_admin' ),
				'i18n'     => array(
					'payment_account_placeholder' => esc_html__( 'Select Account', 'wc-kledo' ),
					'warehouse_placeholder'       => esc_html__( 'Select Warehouse', 'wc-kledo' ),

					'error_loading'               => esc_html__( 'The results could not be loaded.', 'wc-kledo' ),
					'loading_more'                => esc_html__( 'Loading more results...', 'wc-kledo' ),
					'no_result'                   => esc_html__( 'No results found', 'wc-kledo' ),
					'searching'                   => esc_html__( 'Loading...', 'wc-kledo' ),
					'search'                      => esc_html__( 'Search', 'wc-kledo' ),
				),
			)
		);
	}

	/**
	 * Enqueue the row actions of the Transactions tab.
	 *
	 * Plain JavaScript with no dependency: the tab only needs to post a form and swap some HTML.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function enqueue_transactions_js(): void {
		if ( ! $this->is_current_page_on( WC_Kledo_Transactions_Screen::ID ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-kledo-transactions',
			wc_kledo()->asset_dir_url() . '/js/transactions.js',
			array(),
			wc_kledo_asset_version( 'assets/js/transactions.js' ),
			true
		);

		wp_localize_script(
			'wc-kledo-transactions',
			'wc_kledo_transactions',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'security' => wp_create_nonce( WC_Kledo_Transactions_Screen::AJAX_NONCE_ACTION ),
				'i18n'     => array(
					'check'   => __( 'Checking…', 'wc-kledo' ),
					'resend'  => __( 'Resending…', 'wc-kledo' ),
					'failed'  => __( 'Could not reach the server. Try again.', 'wc-kledo' ),
					'updated' => __( 'Updated.', 'wc-kledo' ),
				),
			)
		);
	}

	/**
	 * Enqueue the Diagnostics tab's step runner.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function enqueue_diagnostics_js(): void {
		if ( ! $this->is_current_page_on( WC_Kledo_Diagnostics_Screen::ID ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-kledo-diagnostics',
			wc_kledo()->asset_dir_url() . '/js/diagnostics.js',
			array( 'jquery', 'selectWoo' ),
			wc_kledo_asset_version( 'assets/js/diagnostics.js' ),
			true
		);

		wp_localize_script(
			'wc-kledo-diagnostics',
			'wc_kledo_diagnostics',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'security' => wp_create_nonce( WC_Kledo_Diagnostics_Screen::NONCE ),
				'steps'    => WC_Kledo_Diagnostics::get_steps(),
				'i18n'     => array(
					'running'       => __( 'Checking…', 'wc-kledo' ),
					/* translators: 1: attempt number, 2: number of attempts */
					'waiting'       => __( 'Waiting 20 seconds, then asking Kledo (%1$d of %2$d)…', 'wc-kledo' ),
					'details'       => __( 'Details', 'wc-kledo' ),
					'failed'        => __( 'Could not reach the server. Try again.', 'wc-kledo' ),
					'likely'        => __( 'Likely cause', 'wc-kledo' ),
					'todo'          => __( 'What to do:', 'wc-kledo' ),
					/* translators: %s: report code */
					'code'          => __( 'Report code: %s — mention it when you contact Kledo.', 'wc-kledo' ),
					'download_text' => __( 'Download report', 'wc-kledo' ),
					'download_json' => __( 'Download for developers (JSON)', 'wc-kledo' ),
					'pick_order'    => __( 'Choose an order or type its number…', 'wc-kledo' ),
					/* translators: %s: what the user typed */
					'typed_order'   => __( 'Use order number %s', 'wc-kledo' ),
					'no_order'      => __( 'Choose an order first.', 'wc-kledo' ),
					'searching'     => __( 'Searching…', 'wc-kledo' ),
					'no_results'    => __( 'No order found. Type the order number to use it anyway.', 'wc-kledo' ),
					'load_failed'   => __( 'The list could not be loaded. Type the order number instead.', 'wc-kledo' ),
				),
			)
		);
	}

	/**
	 * Enqueue the Sync tab's progress poll and confirmations.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function enqueue_sync_js(): void {
		if ( ! $this->is_current_page_on( WC_Kledo_Sync_Screen::ID ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-kledo-sync',
			wc_kledo()->asset_dir_url() . '/js/sync.js',
			array(),
			wc_kledo_asset_version( 'assets/js/sync.js' ),
			true
		);

		wp_localize_script(
			'wc-kledo-sync',
			'wc_kledo_sync',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'security' => wp_create_nonce( WC_Kledo_Sync_Screen::POLL_NONCE ),
				'interval' => 10000,
			)
		);
	}

	/**
	 * Determines whether the current screen is the same as identified by the tab.
	 *
	 * @param  string ...$tabs
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	protected function is_current_page_on( string ...$tabs ): bool {
		if ( self::PAGE_ID !== wc_kledo_get_requested_value( 'page' ) ) {
			return false;
		}

		// Assume we are on configure tab by default
		// because the link under menu doesn't include the tab query arg.
		$currentTab = wc_kledo_get_requested_value( 'tab', 'configure' );

		return ! empty( $currentTab ) && in_array( $currentTab, $tabs, true );
	}
}
