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
	public const ID = 'configure';

	/**
	 * The API connection setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const SETTING_ENABLE_API_CONNECTION = 'wc_kledo_enable_api_connection';

	/**
	 * The api key setting ID.
	 *
	 * @var string
	 * @since 1.4.0
	 */
	public const SETTING_API_KEY = 'wc_kledo_api_key';

	/**
	 * The character standing in for the secret part of the API key on screen.
	 *
	 * Matches the character Kledo uses in the masked key it returns, so one tripwire covers both
	 * the mask Kledo sends and the local fallback below. Also the reason a masked value can never
	 * be saved over the real one: Kledo's keys are base62, so no legitimate key contains it.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	public const API_KEY_MASK_CHARACTER = '*';

	/**
	 * The API endpoint setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const SETTING_API_ENDPOINT = 'wc_kledo_api_endpoint';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action(
			'load-woocommerce_page_wc-kledo',
			function () {
				$this->label = __( 'Configure', 'wc-kledo' );
				$this->title = __( 'Configure', 'wc-kledo' );
			}
		);

		$this->init_hooks();
	}

	/**
	 * Init hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_hooks(): void {
		add_action( 'woocommerce_admin_field_wc_kledo_configure_title', array( $this, 'render_title' ) );
		add_action( 'woocommerce_admin_field_wc_kledo_api_key', array( $this, 'render_api_key_field' ) );
		add_action( 'woocommerce_admin_field_wc_kledo_api_key_status', array( $this, 'render_api_key_status_field' ) );

		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::SETTING_API_KEY,
			array( $this, 'sanitize_api_key' ),
			10,
			3
		);
	}

	/**
	 * Render the API key input, showing a masked value once a key is stored.
	 *
	 * @param  array $field  field data
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function render_api_key_field( array $field ): void {
		$masked = wc_kledo_get_displayed_api_key_mask();

		?>

		<tr>
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
			</th>

			<td class="forminp forminp-text">
				<input
					name="<?php echo esc_attr( $field['id'] ); ?>"
					id="<?php echo esc_attr( $field['id'] ); ?>"
					type="text"
					class="input-text regular-input wc-kledo-field"
					autocomplete="off"
					spellcheck="false"
					value="<?php echo esc_attr( $masked ); ?>"
				/>

				<?php if ( '' !== $masked ) : ?>
					<p class="description">
						<?php
						esc_html_e(
							'The saved key is hidden. Leave this as it is to keep it, or paste a different key over it to replace it.',
							'wc-kledo'
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Keep the stored API key when the form sends back the mask rather than a new key.
	 *
	 * The settings API saves whatever the field contained, and the field now contains a mask, so
	 * without this every save would quietly overwrite a working key with a row of bullets. The
	 * final guard is the mask character itself: it cannot occur in a real key, so a value carrying
	 * one is never a key worth storing, however it got there.
	 *
	 * Clearing the field on purpose still clears the key — an empty box is unambiguous, because
	 * the box is never rendered empty while a key exists.
	 *
	 * @param  mixed $value      The sanitized value about to be stored.
	 * @param  array $option     The field definition.
	 * @param  mixed $raw_value  The value as submitted.
	 *
	 * @return mixed
	 * @since 1.7.4
	 */
	public function sanitize_api_key( $value, $option, $raw_value ) {
		$stored = (string) get_option( self::SETTING_API_KEY, '' );

		if ( '' === $stored ) {
			return $value;
		}

		// Compare against what was actually rendered, which is Kledo's own mask once one has been
		// received and the local fallback before that.
		$masked    = wc_kledo_get_displayed_api_key_mask();
		$submitted = is_string( $raw_value ) ? trim( $raw_value ) : '';

		if ( $submitted === $masked || trim( (string) $value ) === $masked ) {
			return $stored;
		}

		// Sanitizing could in principle reshape the mask into something that no longer matches it
		// exactly; anything still carrying the mask character is the mask, not a key.
		if ( false !== strpos( (string) $value, self::API_KEY_MASK_CHARACTER )
			|| false !== strpos( $submitted, self::API_KEY_MASK_CHARACTER ) ) {
			return $stored;
		}

		return $value;
	}

	/**
	 * Render the read-only API key state read from Kledo.
	 *
	 * Not a setting — nothing here is stored or editable. It answers the one question the API key
	 * field above cannot: how much longer the key that is already saved will work.
	 *
	 * @param  array $field  field data
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function render_api_key_status_field( array $field ): void {
		?>

		<tr>
			<th scope="row" class="titledesc">
				<?php echo esc_html( $field['title'] ); ?>
			</th>

			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $field['type'] ) ); ?> wc-kledo-api-key-status">
				<?php $this->render_api_key_status_body(); ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * The body of the API key state cell.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	private function render_api_key_status_body(): void {
		if ( ! wc_kledo()->get_connection_handler()->is_configured() ) {
			echo '<p class="description">'
				. esc_html__( 'Save an API key and endpoint first, then this will show when that key expires.', 'wc-kledo' )
				. '</p>';

			return;
		}

		$connection_status = wc_kledo()->get_connection_status();

		// A refusal is its own answer, not a missing one: Kledo was reached and said no. Which of
		// the two refusals it is decides what the shop should do about it.
		if ( $connection_status->is_unauthenticated() ) {
			if ( $connection_status->is_missing_website_access() ) {
				echo '<p><strong>'
					. esc_html__( 'This API key works, but the account has no access to that company — nothing is syncing.', 'wc-kledo' )
					. '</strong></p>';

				echo '<p class="description">'
					. esc_html__( 'Kledo accepted the key and then refused the request. Pasting a new key from the same account will not help. Check that the account still belongs to the company, or save a key made for the right company.', 'wc-kledo' )
					. '</p>';

				return;
			}

			echo '<p><strong>'
				. esc_html__( 'This API key is no longer valid — nothing is syncing.', 'wc-kledo' )
				. '</strong></p>';

			echo '<p class="description">'
				. esc_html__( 'Kledo refused it. It has either expired or been replaced by a newer login. Paste a current API key above and save.', 'wc-kledo' )
				. '</p>';

			return;
		}

		$status = $connection_status->get();

		if ( null === $status ) {
			$message = $connection_status->last_read_failed()
				? __( 'Kledo could not be reached for this, so the expiry date is unknown right now. It will be retried automatically.', 'wc-kledo' )
				: __( 'Not checked yet. Reload this page in a moment.', 'wc-kledo' );

			echo '<p class="description">' . esc_html( $message ) . '</p>';

			return;
		}

		$rows = array();

		$rows[] = array( __( 'State', 'wc-kledo' ), __( 'Active', 'wc-kledo' ) );

		// Syncing still works here, so this is not an error — but the key Kledo lists is no
		// longer the key this store sends, and nothing else on either side says so.
		if ( wc_kledo_is_api_key_detached() ) {
			echo '<p class="description"><strong>'
				. esc_html__( 'Kledo renewed this key automatically, and the replacement is no longer the key listed in Kledo.', 'wc-kledo' )
				. '</strong> '
				. esc_html__( 'Syncing is unaffected, but managing the key from Kledo will not change what this store uses. Create a new API key in Kledo and save it here if you want the two to match again.', 'wc-kledo' )
				. '</p>';
		}

		// Only present for a key Kledo manages as a named token. An older key that is a raw
		// Passport token carries none of these, so each is shown only when it is there.
		if ( '' !== (string) $status['name'] ) {
			$rows[] = array( __( 'Name in Kledo', 'wc-kledo' ), (string) $status['name'] );
		}

		$timezone = (string) $status['timezone'];

		if ( '' !== (string) $status['created_at'] ) {
			$rows[] = array(
				__( 'Created', 'wc-kledo' ),
				$this->describe_moment( (string) $status['created_at'], $timezone ),
			);
		}

		if ( '' !== (string) $status['last_used_at'] ) {
			$rows[] = array(
				__( 'Last used', 'wc-kledo' ),
				$this->describe_moment( (string) $status['last_used_at'], $timezone ),
			);
		}

		if ( '' !== (string) $status['expires_at'] ) {
			$rows[] = array(
				__( 'Expires', 'wc-kledo' ),
				$this->describe_moment( (string) $status['expires_at'], $timezone ),
			);
		}

		if ( null !== $status['days_remaining'] ) {
			$days = (int) $status['days_remaining'];

			$rows[] = array(
				__( 'Days remaining', 'wc-kledo' ),
				$days < 0
					/* translators: %d: number of days the key expired ago */
					? sprintf( _n( 'Expired %d day ago', 'Expired %d days ago', abs( $days ), 'wc-kledo' ), abs( $days ) )
					/* translators: %d: number of days left */
					: sprintf( _n( '%d day', '%d days', $days, 'wc-kledo' ), $days ),
			);
		}

		// "Renews automatically" is deliberately not a row. For a managed key it is always "no",
		// so it never told anyone anything; when it does matter, the expiry notices say so at the
		// point where acting on it is the next thing to do.

		echo '<ul>';

		foreach ( $rows as $row ) {
			printf(
				'<li><strong>%1$s:</strong> %2$s</li>',
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}

		echo '</ul>';

		if ( ! empty( $status['checked_at'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human readable time difference, e.g. "5 mins" */
						__( 'Last checked %s ago.', 'wc-kledo' ),
						human_time_diff( (int) $status['checked_at'] )
					)
				)
			);
		}
	}

	/**
	 * One moment, as an absolute date in the store's own timezone plus how far away it is.
	 *
	 * Both halves earn their place: the absolute date is what someone compares against the other
	 * dates on their screen, and the relative part is what answers "is that soon?" without
	 * arithmetic.
	 *
	 * No timezone name is printed. The value has been converted into the store's timezone, so
	 * labelling it with Kledo's would be worse than labelling it with nothing.
	 *
	 * @param  string $raw       A timestamp as Kledo returned it.
	 * @param  string $timezone  The timezone Kledo reported it in.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	private function describe_moment( string $raw, string $timezone ): string {
		$absolute = wc_kledo_format_kledo_datetime( $raw, $timezone );
		$relative = wc_kledo_format_kledo_datetime_relative( $raw, $timezone );

		if ( '' === $relative ) {
			return $absolute;
		}

		return sprintf(
			/* translators: 1: a date and time, 2: how far away it is, e.g. "26 days from now" */
			__( '%1$s (%2$s)', 'wc-kledo' ),
			$absolute,
			$relative
		);
	}

	/**
	 * Render the tab, with the API key banner above the settings when the key needs replacing.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render(): void {
		$this->render_api_key_banner();

		parent::render();
	}

	/**
	 * Tell the admin, where the key is pasted, that it is expiring or no longer works — and how to
	 * get a new one.
	 *
	 * Shown instead of the admin notice on this tab, so the same warning does not appear twice.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_api_key_banner(): void {
		$banner = wc_kledo()->get_connection_status()->get_banner();

		if ( null === $banner ) {
			return;
		}

		?>
		<div class="notice notice-<?php echo esc_attr( $banner['level'] ); ?> inline wc-kledo-key-banner">
			<h3><?php echo esc_html( $banner['title'] ); ?></h3>
			<p><?php echo esc_html( $banner['message'] ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Open the API key page in Kledo with the button below (in Kledo: Settings › Integrations › Developer & Security › API Key), and create a new API key for WooCommerce.', 'wc-kledo' ); ?></li>
				<li><?php esc_html_e( 'Copy the new key.', 'wc-kledo' ); ?></li>
				<li><?php esc_html_e( 'Paste it into the API Key field below and click "Save changes". Orders keep syncing automatically from then on.', 'wc-kledo' ); ?></li>
			</ol>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wc_kledo_get_api_key_management_url() ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Create a new API key in Kledo', 'wc-kledo' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'wc-kledo' ); ?></span>
				</a>
				<a class="button" href="#<?php echo esc_attr( self::SETTING_API_KEY ); ?>"><?php esc_html_e( 'Go to the API Key field', 'wc-kledo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render configure admin settings title.
	 *
	 * @param  array $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_title( array $field ): void {
		?>

		<h2><?php echo esc_html( $field['title'] ); ?></h2>

		<table class="form-table">

		<?php
	}

	/**
	 * Gets the screen settings.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_settings(): array {
		return array(
			'title'              => array(
				'type'  => 'wc_kledo_configure_title',
				'title' => __( 'Configure', 'wc-kledo' ),
			),

			'enable_integration' => array(
				'id'      => self::SETTING_ENABLE_API_CONNECTION,
				'title'   => __( 'Enable Integration', 'wc-kledo' ),
				'type'    => 'checkbox',
				'label'   => ' ',
				'default' => 'yes',
			),

			'api_key'            => array(
				'id'    => self::SETTING_API_KEY,
				'title' => __( 'API Key', 'wc-kledo' ),
				'type'  => 'wc_kledo_api_key',
			),

			'api_endpoint'       => array(
				'id'    => self::SETTING_API_ENDPOINT,
				'title' => __( 'API Endpoint URL', 'wc-kledo' ),
				'type'  => 'text',
			),

			'api_key_status'     => array(
				'title' => __( 'API Key Status', 'wc-kledo' ),
				'type'  => 'wc_kledo_api_key_status',
			),

			'section_end'        => array(
				'type' => 'sectionend',
			),
		);
	}
}
