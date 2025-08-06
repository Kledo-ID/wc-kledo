<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Order_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	public const ID = 'order';

	/**
	 * The enable order option name.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	public const ENABLE_ORDER_OPTION_NAME = 'wc_kledo_enable_order';

	/**
	 * The order prefix option name.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	public const ORDER_PREFIX_OPTION_NAME = 'wc_kledo_order_prefix';

	/**
	 * The order payment warehouse option name.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	public const ORDER_WAREHOUSE_OPTION_NAME = 'wc_kledo_order_warehouse';

	/**
	 * The order tag option name.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	public const ORDER_TAG_OPTION_NAME = 'wc_kledo_order_tags';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action( 'load-woocommerce_page_wc-kledo', function () {
			$this->label = __( 'Order', WC_KLEDO_TEXT_DOMAIN );
			$this->title = __( 'Order', WC_KLEDO_TEXT_DOMAIN );
		} );

		add_action( 'woocommerce_admin_field_order_warehouse', array( $this, 'render_order_warehouse_field' ) );
	}

	public function get_settings(): array {
		return array(
			'title' => array(
				'title' => __( 'Order', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'title',
			),

			'enable_create_order' => array(
				'id'      => self::ENABLE_ORDER_OPTION_NAME,
				'title'   => __( 'Enable Create Order', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'checkbox',
				'class'   => 'wc-kledo-field',
				'default' => 'yes',
				'desc'    => __( 'Create new order on Kledo when order status is <strong>Processing</strong>.', WC_KLEDO_TEXT_DOMAIN ),
			),

			'order_prefix' => array(
				'id'      => self::ORDER_PREFIX_OPTION_NAME,
				'title'   => __( 'Order Prefix', WC_KLEDO_TEXT_DOMAIN ),
				'type'    => 'text',
				'class'   => 'wc-kledo-field',
				'default' => 'WC/SO/',
			),

			'warehouse' => array(
				'id'    => self::ORDER_WAREHOUSE_OPTION_NAME,
				'title' => __( 'Warehouse', WC_KLEDO_TEXT_DOMAIN ),
				'type'  => 'order_warehouse',
				'class' => 'wc-kledo-field wc-kledo-warehouse-field',
			),

			'tags' => array(
				'id'      => self::ORDER_TAG_OPTION_NAME,
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

	/**
	 * Renders the warehouse field.
	 *
	 * @param  array  $field  field data
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function render_order_warehouse_field( array $field ): void {
		$value = get_option( self::ORDER_WAREHOUSE_OPTION_NAME );

		$this->render_warehouse_field( $field, $value );
	}
}
