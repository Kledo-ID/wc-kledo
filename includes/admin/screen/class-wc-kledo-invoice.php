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
	 * The link invoice to sales order option name.
	 *
	 * Sent to Kledo as the `link_order` field on `POST /woocommerce/invoice`. Kledo treats an
	 * absent field as `yes`, so the default here matches that rather than introducing a second
	 * meaning for "not configured yet".
	 *
	 * @var string
	 * @since 1.7.4
	 */
	public const LINK_ORDER_OPTION_NAME = 'wc_kledo_link_order';

	/**
	 * The close sales order option name.
	 *
	 * Sent to Kledo as the `close_order` field on `POST /woocommerce/invoice`, and only
	 * meaningful while `link_order` is on. Kledo treats an absent field as `yes`, so the default
	 * here matches that.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	public const CLOSE_ORDER_OPTION_NAME = 'wc_kledo_close_order';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 * @since 1.3.0 Sanitize tags update value.
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action(
			'load-woocommerce_page_wc-kledo',
			function () {
				$this->label = __( 'Invoice', 'wc-kledo' );
				$this->title = __( 'Invoice', 'wc-kledo' );
			}
		);

		add_action( 'woocommerce_admin_field_payment_account', array( $this, 'render_payment_account_field' ) );
		add_action( 'woocommerce_admin_field_invoice_warehouse', array( $this, 'render_invoice_warehouse_field' ) );
		add_action( 'woocommerce_admin_field_invoice_tags', array( $this, 'render_invoice_tags_field' ) );

		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::INVOICE_TAG_OPTION_NAME,
			array(
				$this,
				'sanitize_tags',
			),
			10,
			3
		);
	}

	/**
	 * Render the payment account field.
	 *
	 * @param  array $field  field data
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
					<?php if ( $payment_account ) : ?>
						<option value="<?php echo esc_attr( $payment_account ); ?>" selected="selected"><?php echo esc_html( $payment_account ); ?></option>
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
	public function get_settings(): array {
		return array(
			'title'                 => array(
				'title' => __( 'Invoice', 'wc-kledo' ),
				'type'  => 'title',
			),

			'enable_create_invoice' => array(
				'id'      => self::ENABLE_INVOICE_OPTION_NAME,
				'title'   => __( 'Enable Create Invoice', 'wc-kledo' ),
				'type'    => 'checkbox',
				'class'   => 'wc-kledo-field',
				'default' => 'yes',
				'desc'    => sprintf(
					/* translators: %s: "Completed" order status label (may include <strong> markup). */
					__( 'Create new invoice on Kledo when order status is %s.', 'wc-kledo' ),
					'<strong>Completed</strong>'
				),
			),

			'invoice_prefix'        => array(
				'id'      => self::INVOICE_PREFIX_OPTION_NAME,
				'title'   => __( 'Invoice Prefix', 'wc-kledo' ),
				'type'    => 'text',
				'class'   => 'wc-kledo-field',
				'default' => 'WC/INV/',
			),

			'invoice_status'        => array(
				'id'      => self::INVOICE_STATUS_OPTION_NAME,
				'title'   => __( 'Invoice Status on Created', 'wc-kledo' ),
				'type'    => 'select',
				'class'   => 'wc-kledo-field wc-kledo-invoice-status-field',
				'default' => 'unpaid',
				'options' => array(
					'paid'   => __( 'Paid', 'wc-kledo' ),
					'unpaid' => __( 'Unpaid', 'wc-kledo' ),
				),
			),

			'payment_account'       => array(
				'id'    => self::INVOICE_PAYMENT_ACCOUNT_OPTION_NAME,
				'title' => __( 'Payment Account', 'wc-kledo' ),
				'type'  => 'payment_account',
				'class' => 'wc-kledo-field wc-kledo-payment-account-field',
			),

			'warehouse'             => array(
				'id'    => self::INVOICE_WAREHOUSE_OPTION_NAME,
				'title' => __( 'Warehouse', 'wc-kledo' ),
				'type'  => 'invoice_warehouse',
				'class' => 'wc-kledo-field wc-kledo-warehouse-field',
			),

			'tags'                  => array(
				'id'    => self::INVOICE_TAG_OPTION_NAME,
				'title' => __( 'Tags', 'wc-kledo' ),
				'type'  => 'invoice_tags',
				'class' => 'wc-kledo-field wc-kledo-tags-field',
			),

			'section_end'           => array(
				'type' => 'sectionend',
			),

			// Split into a section of its own because these two are not independent: the second
			// only does anything while the first is on. Sitting in the same undifferentiated list
			// as Prefix and Warehouse, that dependency was invisible until you read the small
			// print — which is how a shop ends up with "Close Sales Order When Invoiced" ticked,
			// linking off, and no idea why nothing ever closes.
			'link_title'            => array(
				'title' => __( 'Sales Order Link', 'wc-kledo' ),
				'type'  => 'title',
				'desc'  => __( 'How an invoice relates back to the Kledo sales order the same WooCommerce order already created. Both settings below are inert unless linking is on — closing is something Kledo does to a sales order the invoice is attached to, so there is nothing to close without the attachment.', 'wc-kledo' ),
			),

			'link_order'            => array(
				'id'      => self::LINK_ORDER_OPTION_NAME,
				'title'   => __( 'Link Invoice to Sales Order', 'wc-kledo' ),
				'type'    => 'checkbox',
				'class'   => 'wc-kledo-field wc-kledo-link-order-field',
				'default' => 'yes',
				'desc'    => __( 'Record each invoice against the Kledo sales order it came from, so the sales order counts those quantities as billed. Turn it off and the invoice stands alone, leaving the sales order untouched — and the setting below stops applying.', 'wc-kledo' ),
			),

			'close_order'           => array(
				'id'      => self::CLOSE_ORDER_OPTION_NAME,
				'title'   => __( 'Close Sales Order When Invoiced', 'wc-kledo' ),
				'type'    => 'checkbox',
				'class'   => 'wc-kledo-field wc-kledo-close-order-field',
				'default' => 'yes',
				'desc'    => sprintf(
					/* translators: %s: the name of the setting this one depends on, wrapped in <strong>. */
					__( 'Requires %s above to be on; without it this setting does nothing. Kledo closes a sales order once every quantity is billed — the same as invoicing from the sales order in Kledo. Turn this off to close it yourself, though Kledo closes it anyway the next time it recalculates that order.', 'wc-kledo' ),
					'<strong>' . esc_html__( 'Link Invoice to Sales Order', 'wc-kledo' ) . '</strong>'
				),
			),

			'link_section_end'      => array(
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Render the warehouse field.
	 *
	 * @param  array $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_invoice_warehouse_field( array $field ): void {
		$value = get_option( self::INVOICE_WAREHOUSE_OPTION_NAME );

		$this->render_warehouse_field( $field, $value );
	}

	/**
	 * Render the tags field.
	 *
	 * @param  array $field
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function render_invoice_tags_field( array $field ): void {
		$tags = wc_kledo_get_tags( self::INVOICE_TAG_OPTION_NAME );

		$this->render_tags_field( $field, $tags );
	}
}
