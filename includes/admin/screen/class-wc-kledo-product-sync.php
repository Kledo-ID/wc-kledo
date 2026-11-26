<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Product Sync tab: let Kledo read this store's catalogue and write stock back.
 *
 * Everything else in this plugin sends data *to* Kledo. This tab is the only place that works the
 * other way, so it is also the only place that hands Kledo a credential for this store. The
 * exchange is one button: the plugin creates a read/write WooCommerce API key, gives it to Kledo,
 * and Kledo proves it can use it before anything is saved on either side.
 *
 * Nothing here syncs on its own yet — connecting only establishes the link. Pulling the catalogue
 * and pushing stock are driven from Kledo.
 *
 * @since 1.9.0
 */
class WC_Kledo_Product_Sync_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	public const ID = 'product-sync';

	/**
	 * Nonce action for the connect/disconnect buttons.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	private const CONTROL_NONCE = 'wc_kledo_product_sync_control';

	/**
	 * Name of the hidden field carrying which button was pressed.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	private const CONTROL_FIELD = 'wc_kledo_product_sync_control';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action(
			'load-woocommerce_page_wc-kledo',
			function () {
				$this->label       = __( 'Product Sync', 'wc-kledo' );
				$this->title       = __( 'Product Sync', 'wc-kledo' );
				$this->description = __( 'Let Kledo read this store\'s products and keep stock in step. Connecting only creates the link; syncing is started from Kledo.', 'wc-kledo' );
			}
		);

		add_action( 'admin_init', array( $this, 'maybe_handle_control' ) );
	}

	/**
	 * This tab has no stored settings of its own — it is a connect/disconnect panel.
	 *
	 * Returning an empty array would make `WC_Kledo_Settings_Screen::render()` bail before
	 * printing anything, so the panel is rendered by {@see self::render()} instead and this stays
	 * empty on purpose.
	 *
	 * @return array
	 * @since 1.9.0
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * Render the connection panel.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$connection = wc_kledo()->get_connection_handler();

		if ( ! $connection->is_configured() ) {
			$this->render_prerequisite_notice();

			return;
		}

		if ( WC_Kledo_Product_Connect::is_connected() ) {
			$this->render_connected_panel();

			return;
		}

		$this->render_connect_form();
	}

	/**
	 * Shown when the store is not connected to Kledo at all; product sync cannot come first.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	private function render_prerequisite_notice(): void {
		?>
		<h2><?php esc_html_e( 'Product Sync', 'wc-kledo' ); ?></h2>
		<p>
			<?php esc_html_e( 'Connect this store to Kledo on the Configure tab first. Product sync uses that same connection.', 'wc-kledo' ); ?>
		</p>
		<?php
	}

	/**
	 * The one-button connect form.
	 *
	 * The store URL is shown but not editable: Kledo refuses a URL that redirects, and WordPress
	 * already knows the address this site answers on. Letting it be typed is how a `www` mismatch
	 * gets shipped.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	private function render_connect_form(): void {
		?>
		<h2><?php esc_html_e( 'Product Sync', 'wc-kledo' ); ?></h2>

		<p><?php esc_html_e( 'What happens when you connect:', 'wc-kledo' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'This plugin creates a WooCommerce API key with read and write permission, named "Kledo product sync".', 'wc-kledo' ); ?></li>
			<li><?php esc_html_e( 'The key is sent to Kledo, which immediately tries it against this store.', 'wc-kledo' ); ?></li>
			<li><?php esc_html_e( 'If the test fails, nothing is saved and the key is removed again. You will see why here.', 'wc-kledo' ); ?></li>
		</ol>

		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Store address', 'wc-kledo' ); ?></th>
				<td>
					<code><?php echo esc_html( get_home_url() ); ?></code>
					<p class="description">
						<?php esc_html_e( 'Taken from WordPress. Kledo must be able to reach this address over HTTPS from the internet.', 'wc-kledo' ); ?>
					</p>
				</td>
			</tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( $this->get_screen_url() ); ?>">
			<?php wp_nonce_field( self::CONTROL_NONCE ); ?>
			<input type="hidden" name="page" value="<?php echo esc_attr( WC_Kledo_Admin::PAGE_ID ); ?>"/>
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::ID ); ?>"/>
			<input type="hidden" name="<?php echo esc_attr( self::CONTROL_FIELD ); ?>" value="connect"/>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Connect Product Sync', 'wc-kledo' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * What an admin sees once product sync is connected.
	 *
	 * The registered address is shown next to the current one, because moving a site is exactly
	 * when sync stops working and exactly when nobody thinks to look here.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	private function render_connected_panel(): void {
		$registered_url = (string) get_option( WC_Kledo_Product_Connect::STORE_URL_OPTION, '' );
		$current_url    = get_home_url();
		$truncated_key  = (string) get_option( WC_Kledo_Product_Connect::TRUNCATED_KEY_OPTION, '' );

		?>
		<h2><?php esc_html_e( 'Product Sync', 'wc-kledo' ); ?></h2>

		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'wc-kledo' ); ?></th>
				<td><strong><?php esc_html_e( 'Connected', 'wc-kledo' ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection id', 'wc-kledo' ); ?></th>
				<td>
					<code><?php echo esc_html( (string) WC_Kledo_Product_Connect::get_connect_id() ); ?></code>
					<p class="description"><?php esc_html_e( 'Quote this number when asking Kledo support about product sync.', 'wc-kledo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Registered address', 'wc-kledo' ); ?></th>
				<td>
					<code><?php echo esc_html( '' !== $registered_url ? $registered_url : $current_url ); ?></code>
					<?php if ( '' !== $registered_url && untrailingslashit( $registered_url ) !== untrailingslashit( $current_url ) ) : ?>
						<p class="description">
							<strong><?php esc_html_e( 'This store\'s address has changed since it was connected.', 'wc-kledo' ); ?></strong>
							<?php
							printf(
								/* translators: %s: current store URL */
								esc_html__( 'It is now %s. Disconnect and connect again so Kledo uses the new address.', 'wc-kledo' ),
								'<code>' . esc_html( $current_url ) . '</code>'
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( '' !== $truncated_key ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'API key', 'wc-kledo' ); ?></th>
					<td>
						<code><?php echo esc_html( '…' . $truncated_key ); ?></code>
						<p class="description"><?php esc_html_e( 'Listed in WooCommerce > Settings > Advanced > REST API as "Kledo product sync".', 'wc-kledo' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( $this->get_screen_url() ); ?>">
			<?php wp_nonce_field( self::CONTROL_NONCE ); ?>
			<input type="hidden" name="page" value="<?php echo esc_attr( WC_Kledo_Admin::PAGE_ID ); ?>"/>
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::ID ); ?>"/>
			<input type="hidden" name="<?php echo esc_attr( self::CONTROL_FIELD ); ?>" value="disconnect"/>

			<p class="submit">
				<button type="submit" class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Disconnect product sync? Kledo will stop reading products and stock from this store, and the API key it uses will be deleted.', 'wc-kledo' ) ); ?>');">
					<?php esc_html_e( 'Disconnect Product Sync', 'wc-kledo' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * URL of this tab.
	 *
	 * Declared here rather than reused: the base settings screen does not provide it, and the
	 * Sync tab's copy is private to that class.
	 *
	 * @return string
	 * @since 1.9.0
	 */
	private function get_screen_url(): string {
		return admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . self::ID );
	}

	/**
	 * Handle the connect/disconnect buttons.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	public function maybe_handle_control(): void {
		$control = sanitize_key( (string) wc_kledo_get_posted_value( self::CONTROL_FIELD ) );

		if ( '' === $control ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-kledo' ) );
		}

		check_admin_referer( self::CONTROL_NONCE );

		$messages = wc_kledo()->get_message_handler();

		if ( 'disconnect' === $control ) {
			WC_Kledo_Product_Connect::disconnect();
			$messages->add_message( __( 'Product sync disconnected.', 'wc-kledo' ) );

			return;
		}

		if ( 'connect' !== $control ) {
			return;
		}

		$result = WC_Kledo_Product_Connect::connect();

		if ( $result['ok'] ) {
			$messages->add_message( $result['message'] );

			return;
		}

		$messages->add_error( $result['message'] );
	}
}
