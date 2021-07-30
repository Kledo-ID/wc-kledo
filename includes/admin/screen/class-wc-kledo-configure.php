<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Configure_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const ID = 'configure';

	/**
	 * The API connection setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const SETTING_ENABLE_API_CONNECTION = 'wc_kledo_enable_api_connection';

	/**
	 * The client id setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const SETTING_CLIENT_ID = 'wc_kledo_client_id';

	/**
	 * The client secret setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const SETTING_CLIENT_SECRET = 'wc_kledo_client_secret';

	/**
	 * The API endpoint setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const SETTING_API_ENDPOINT = 'wc_kledo_api_endpoint';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id    = self::ID;
		$this->label = __( 'Configure', WC_KLEDO_TEXT_DOMAIN );
		$this->title = __( 'Configure', WC_KLEDO_TEXT_DOMAIN );

		$this->init_hooks();
	}

	/**
	 * Init hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'woocommerce_admin_field_wc_kledo_configure_title', array( $this, 'render_title' ) );
		add_action( 'woocommerce_admin_field_wc_kledo_redirect_uri', array( $this, 'redirect_uri' ) );
		add_action( 'woocommerce_admin_field_wc_kledo_manage_connection', array( $this, 'manage_connection' ) );
		add_action( 'woocommerce_admin_field_wc_kledo_token_expires_in', array( $this, 'token_expires_in' ) );
    }

	/**
	 * Display the token expiration status.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function token_expires_in( $field ) {
		$is_connected = wc_kledo()->get_connection_handler()->is_connected();

		if ( ! $is_connected ) {
			return;
		}

		?>

		<tr>
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( 'Token Expires In', WC_KLEDO_TEXT_DOMAIN ); ?></label>
			</th>

			<td class="forminp forminp-text">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php esc_html_e( 'Token Expires In', WC_KLEDO_TEXT_DOMAIN ); ?></span>
					</legend>

					<code><?php echo wc_kledo()->get_connection_handler()->get_expires_token(); ?></code>

				</fieldset>
			</td>
		</tr>

		<?php
	}

	/**
	 * Render configure admin settings title.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_title( $field ) {
		?>

		<h2><?php echo $field['title']; ?></h2>

		<table class="form-table">

		<?php
	}

	/**
	 * Render the redirect uri field.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_uri( $field ) {
		?>

		<tr>
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( 'Redirect URI', WC_KLEDO_TEXT_DOMAIN ); ?></label>
			</th>

			<td class="forminp forminp-text">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php esc_html_e( 'Redirect URI', WC_KLEDO_TEXT_DOMAIN ); ?></span>
					</legend>

					<input class="input-text regular-input" type="text" value="<?php echo esc_url( wc_kledo()->get_connection_handler()->get_redirect_uri() ); ?>" readonly />

					<p class="description">
						<?php esc_html_e( 'The redirect URI that should enter when create new OAuth App.', WC_KLEDO_TEXT_DOMAIN ); ?>
					</p>
				</fieldset>
			</td>
		</tr>

		<?php
	}

	/**
	 * Render the manage connection field.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function manage_connection( array $field ) {
		$is_connected = wc_kledo()->get_connection_handler()->is_connected();

		?>

		<tr>
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( 'Manage Connection', WC_KLEDO_TEXT_DOMAIN ); ?></label>
			</th>

			<td class="forminp forminp-text">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php esc_html_e( 'Manage Connection', WC_KLEDO_TEXT_DOMAIN ); ?></span>
					</legend>

					<?php if ( ! wc_kledo()->get_connection_handler()->is_configured() ): ?>
						<span>
							<b> <?php esc_html_e( __( 'Please fill in the Client ID, Client Secret and API Endpoint fields first and save before continuing.', WC_KLEDO_TEXT_DOMAIN ) ); ?></b>
						</span>
					<?php else: ?>
						<?php if ( ! $is_connected ): ?>
							<a href="<?php echo esc_url( add_query_arg('action', 'redirect', wc_kledo()->get_settings_url() ) ); ?>" class="button button-info"><?php _e( 'Request Token', WC_KLEDO_TEXT_DOMAIN ); ?></a>

						<?php else: ?>
							<a href="<?php echo esc_url( add_query_arg('action', 'disconnect', wc_kledo()->get_settings_url() ) ); ?>" class="button button-danger"><?php _e( 'Disconnect', WC_KLEDO_TEXT_DOMAIN ); ?></a>

							<a href="<?php echo esc_url( add_query_arg('action', 'refresh', wc_kledo()->get_settings_url() ) ); ?>" class="button button-success"><?php _e( 'Refresh Token', WC_KLEDO_TEXT_DOMAIN ); ?></a>
						<?php endif; ?>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>

		<?php
	}

	/**
	 * Gets the screen settings.
	 *
	 * @return array
     * @since 1.0.0
	 */
	public function get_settings() {
		return array(
			array(
				'type'  => 'wc_kledo_configure_title',
				'title' => __( 'Configure', WC_KLEDO_TEXT_DOMAIN ),
			),

			array(
				'id'      => self::SETTING_ENABLE_API_CONNECTION,
				'title'   => __( 'Enable Integration', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'checkbox',
				'label'   => ' ',
				'default' => 'yes',
			),

			array(
				'id'       => self::SETTING_CLIENT_ID,
				'title'    => __( 'Client ID', WC_KLEDO_TEXT_DOMAIN ),
				'type'     => 'text',
			),

			array(
				'id'       => self::SETTING_CLIENT_SECRET,
				'title'    => __( 'Client Secret', WC_KLEDO_TEXT_DOMAIN ),
				'type'     => 'text',
			),

			array(
				'id'       => self::SETTING_API_ENDPOINT,
				'title'    => __( 'API Endpoint', WC_KLEDO_TEXT_DOMAIN ),
				'type'     => 'text',
			),

			array(
				'type' => 'wc_kledo_redirect_uri',
			),

			array(
				'type' => 'wc_kledo_manage_connection',
			),

			array(
				'type' => 'wc_kledo_token_expires_in',
			),

			array(
				'type' => 'sectionend',
			),
		);
	}
}
