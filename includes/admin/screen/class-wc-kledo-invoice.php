<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Invoice_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const ID = 'invoice';

	/**
	 * The invoice prefix option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const INVOICE_PREFIX_OPTION_NAME = 'wc_kledo_invoice_prefix';

	/**
	 * The invoice status option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const INVOICE_STATUS_OPTION_NAME = 'wc_kledo_invoice_status';

	/**
	 * The invoice payment account code option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const INVOICE_PAYMENT_ACCOUNT_OPTION_NAME = 'wc_kledo_invoice_payment_account';

	/**
	 * The invoice payment warehouse option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const INVOICE_WAREHOUSE_OPTION_NAME = 'wc_kledo_warehouse';

	/**
	 * The invoice payment tag option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const INVOICE_TAG_OPTION_NAME = 'wc_kledo_tags';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id    = self::ID;
		$this->label = __( 'Invoice', WC_KLEDO_TEXT_DOMAIN );
		$this->title = __( 'Invoice', WC_KLEDO_TEXT_DOMAIN );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'woocommerce_admin_field_payment_account', array( $this, 'render_payment_account_field' ) );
		add_action( 'woocommerce_admin_field_warehouse', array( $this, 'render_warehouse_field' ) );
	}

	/**
	 * Renders the payment account field.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_payment_account_field( $field ) {
		$payment_account = get_option( self::INVOICE_PAYMENT_ACCOUNT_OPTION_NAME );

		?>

		<tr>
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
			</th>

			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $field['type'] ) ); ?>">
				<select name="<?php echo esc_attr( $field['id'] ); ?>" id="<?php echo esc_attr( $field['id'] ); ?>" class="<?php echo esc_attr( $field['class'] ); ?>">
					<?php if ( $payment_account ): ?>
						<option value="<?php echo $payment_account; ?>" selected="selected"><?php echo $payment_account; ?></option>
					<?php endif; ?>
				</select>
			</td>
		</tr>

		<?php
	}

	/**
	 * Renders the warehouse field.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_warehouse_field( $field ) {
		$warehouse = get_option( self::INVOICE_WAREHOUSE_OPTION_NAME );

		?>

		<tr>
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
			</th>

			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $field['type'] ) ); ?>">
				<select name="<?php echo esc_attr( $field['id'] ); ?>" id="<?php echo esc_attr( $field['id'] ); ?>" class="<?php echo esc_attr( $field['class'] ); ?>">
					<?php if ( $warehouse ): ?>
						<option value="<?php echo esc_attr( $warehouse ); ?>" selected="selected"><?php echo esc_attr( $warehouse ); ?></option>
					<?php endif; ?>
				</select>
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
				'title' => __( 'Invoice', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'title',
			),

			array(
				'id'      => self::INVOICE_PREFIX_OPTION_NAME,
				'title'   => __( 'Invoice Prefix', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'text',
				'class'   => 'invoice-field',
				'default' => 'WC/',
			),

			array(
				'id'      => self::INVOICE_STATUS_OPTION_NAME,
				'title'   => __( 'Invoice Status on Created', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'select',
				'class'   => 'invoice-field',
				'default' => 'unpaid',
				'options' => array(
					'paid'   => __( 'Paid', WC_KLEDO_TEXT_DOMAIN ),
					'unpaid' => __( 'Unpaid', WC_KLEDO_TEXT_DOMAIN ),
				),
			),

			array(
				'id'    => self::INVOICE_PAYMENT_ACCOUNT_OPTION_NAME,
				'title' => __( 'Payment Account', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'payment_account',
				'class' => 'invoice-field payment-account-field',
			),

			array(
				'id'    => self::INVOICE_WAREHOUSE_OPTION_NAME,
				'title' => __( 'Warehouse', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'warehouse',
				'class' => 'invoice-field warehouse-field',
			),

			array(
				'id'      => self::INVOICE_TAG_OPTION_NAME,
				'title'   => __( 'Tags', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'text',
				'class'   => 'invoice-field',
				'default' => 'WooCommerce',
			),

			array(
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Enqueues the assets.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets() {
		if ( ! $this->is_current_screen_page() ) {
			return;
		}

		wp_enqueue_script(
			'wc-kledo-invoice',
			wc_kledo()->asset_dir_url() . '/js/invoice.js',
			array( 'jquery', 'selectWoo' ),
			WC_KLEDO_VERSION
		);

		wp_localize_script(
			'wc-kledo-invoice',
			'wc_kledo_invoice',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'i18n'     => array(
					'payment_account_placeholder' => esc_html__( 'Select Account', WC_KLEDO_TEXT_DOMAIN ),

					'error_loading' => esc_html__( 'The results could not be loaded.', WC_KLEDO_TEXT_DOMAIN ),
					'loading_more'  => esc_html__( 'Loading more results...', WC_KLEDO_TEXT_DOMAIN ),
					'no_result'     => esc_html__( 'No results found', WC_KLEDO_TEXT_DOMAIN ),
					'searching'     => esc_html__( 'Searching...', WC_KLEDO_TEXT_DOMAIN ),
					'search'        => esc_html__( 'Search', WC_KLEDO_TEXT_DOMAIN ),
				),
			)
		);
	}
}
