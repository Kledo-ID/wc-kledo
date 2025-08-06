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
	public const ID = 'invoice';

	/**
	 * The enable invoice option name.
     *
     * @var string
     * @since 1.3.0
	 */
    public const ENABLE_INVOICE_OPTION_NAME = 'wc_kledo_enable_invoice';

	/**
	 * The invoice prefix option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const INVOICE_PREFIX_OPTION_NAME = 'wc_kledo_invoice_prefix';

	/**
	 * The invoice status option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const INVOICE_STATUS_OPTION_NAME = 'wc_kledo_invoice_status';

	/**
	 * The invoice payment account code option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const INVOICE_PAYMENT_ACCOUNT_OPTION_NAME = 'wc_kledo_invoice_payment_account';

	/**
	 * The invoice warehouse option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const INVOICE_WAREHOUSE_OPTION_NAME = 'wc_kledo_warehouse';

	/**
	 * The invoice tag option name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const INVOICE_TAG_OPTION_NAME = 'wc_kledo_tags';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action( 'load-woocommerce_page_wc-kledo', function () {
			$this->label = __( 'Invoice', WC_KLEDO_TEXT_DOMAIN );
			$this->title = __( 'Invoice', WC_KLEDO_TEXT_DOMAIN );
		});

		add_action( 'woocommerce_admin_field_payment_account', array( $this, 'render_payment_account_field' ) );
		add_action( 'woocommerce_admin_field_invoice_warehouse', array( $this, 'render_invoice_warehouse_field' ) );
	}

	/**
	 * Renders the payment account field.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_payment_account_field( array $field ): void {
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
	public function render_invoice_warehouse_field( array $field ): void {
		$value = get_option( self::INVOICE_WAREHOUSE_OPTION_NAME );

		$this->render_warehouse_field( $field, $value );
	}

	/**
	 * Gets the screen settings.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_settings(): array {
		return array(
			'title' => array(
				'title' => __( 'Invoice', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'title',
			),

			'enable_create_invoice' => array(
				'id'       => self::ENABLE_INVOICE_OPTION_NAME,
				'title'    => __( 'Enable Create Invoice', WC_KLEDO_TEXT_DOMAIN ),
				'type'     => 'checkbox',
				'class'    => 'wc-kledo-field',
				'default'  => 'yes',
				'desc'     => __( 'Create new invoice on Kledo when order status is <strong>Completed</strong>.', WC_KLEDO_TEXT_DOMAIN ),
			),

			'invoice_prefix' => array(
				'id'      => self::INVOICE_PREFIX_OPTION_NAME,
				'title'   => __( 'Invoice Prefix', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'text',
				'class'   => 'wc-kledo-field',
				'default' => 'WC/INV/',
			),

			'invoice_status' => array(
				'id'      => self::INVOICE_STATUS_OPTION_NAME,
				'title'   => __( 'Invoice Status on Created', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'select',
				'class'   => 'wc-kledo-field wc-kledo-invoice-status-field',
				'default' => 'unpaid',
				'options' => array(
					'paid'   => __( 'Paid', WC_KLEDO_TEXT_DOMAIN ),
					'unpaid' => __( 'Unpaid', WC_KLEDO_TEXT_DOMAIN ),
				),
			),

			'payment_account' => array(
				'id'    => self::INVOICE_PAYMENT_ACCOUNT_OPTION_NAME,
				'title' => __( 'Payment Account', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'payment_account',
				'class' => 'wc-kledo-field wc-kledo-payment-account-field',
			),

			'warehouse' => array(
				'id'    => self::INVOICE_WAREHOUSE_OPTION_NAME,
				'title' => __( 'Warehouse', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'invoice_warehouse',
				'class' => 'wc-kledo-field wc-kledo-warehouse-field',
			),

			'tags' => array(
				'id'      => self::INVOICE_TAG_OPTION_NAME,
				'title'   => __( 'Tags (Multiple tag separated by comma)', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'text',
				'class'   => 'wc-kledo-field',
				'default' => 'WooCommerce',
			),

			'section_end' => array(
				'type' => 'sectionend',
			),
		);
	}
}
