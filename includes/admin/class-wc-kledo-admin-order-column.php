<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Shows the Kledo state of each order as a column on the WooCommerce order list.
 *
 * This is what the "Kledo closed" order status used to be for, without hijacking `post_status` to
 * do it. The state it renders is read from the order meta and the confirmation queue, so it also
 * surfaces the two states that were previously invisible outside an order note: an order still
 * waiting for Kledo, and one the confirmation loop gave up on.
 *
 * @since 1.7.4
 */
class WC_Kledo_Admin_Order_Column {
	/**
	 * The column key, shared by both storage modes.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	public const COLUMN_KEY = 'wc_kledo_state';

	/**
	 * Register the hooks for both order storage modes.
	 *
	 * WooCommerce has two order list tables and they do not share hooks: the HPOS table fires
	 * `woocommerce_shop_order_list_table_*`, while the legacy custom-post-type table fires the
	 * WordPress `manage_*_posts_custom_column` pair. Only one of them ever runs in a given shop,
	 * so registering both is how the column appears either way.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function init(): void {
		// HPOS order list table.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Legacy `shop_order` post list table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'add_inline_styles' ), 20 );
	}

	/**
	 * Lay out the two state lines of the cell.
	 *
	 * Attached to WooCommerce's admin stylesheet rather than the plugin's own, which is only
	 * enqueued on the plugin's settings page; WooCommerce's is already present on the order list.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function add_inline_styles(): void {
		if ( ! wp_style_is( 'woocommerce_admin_styles', 'enqueued' ) ) {
			return;
		}

		wp_add_inline_style(
			'woocommerce_admin_styles',
			'.column-' . self::COLUMN_KEY . ' { width: 13em; }'
			. '.wc-kledo-state-line { display: flex; align-items: center; gap: 4px; margin: 0 0 4px; white-space: nowrap; }'
			. '.wc-kledo-state-type { min-width: 6.5em; color: #50575e; }'
			. '.wc-kledo-state-line .order-status { margin: 0; }'
			. '.wc-kledo-state-closure { color: #50575e; font-style: italic; }'
			. '.wc-kledo-date-range { display: inline-flex; align-items: center; gap: 4px; margin: 0 6px 0 0; }'
		);
	}

	/**
	 * Add the column, directly after the order status column.
	 *
	 * @param  array<string, string> $columns
	 *
	 * @return array<string, string>
	 * @since 1.7.4
	 */
	public function add_column( $columns ): array {
		// Cast rather than bail: the declared return type would turn a non-array coming from
		// another filter into a TypeError, which is a worse outcome than an empty column set.
		$columns = is_array( $columns ) ? $columns : array();

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $columns;
		}

		$label     = __( 'Kledo', 'wc-kledo' );
		$reordered = array();

		foreach ( $columns as $key => $value ) {
			$reordered[ $key ] = $value;

			if ( 'order_status' === $key ) {
				$reordered[ self::COLUMN_KEY ] = $label;
			}
		}

		// Order status missing entirely (hidden by another plugin) — append rather than drop.
		if ( ! isset( $reordered[ self::COLUMN_KEY ] ) ) {
			$reordered[ self::COLUMN_KEY ] = $label;
		}

		return $reordered;
	}

	/**
	 * Render one cell.
	 *
	 * Serves both hooks: HPOS passes the order object as the second argument, the legacy table
	 * passes a post id.
	 *
	 * @param  string             $column
	 * @param  \WC_Order|int|null $order_or_post_id
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function render_column( $column, $order_or_post_id = null ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = $order_or_post_id instanceof WC_Order
			? $order_or_post_id
			: wc_get_order( $order_or_post_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( array( 'order', 'invoice' ) as $type ) {
			$state = wc_kledo_get_remote_state( $order, $type );

			printf(
				'<div class="wc-kledo-state-line"><span class="wc-kledo-state-type">%1$s:</span> %2$s</div>',
				esc_html( WC_Kledo_Status_Badge::type_label( $type ) ),
				WC_Kledo_Status_Badge::render( $state, $type, wc_kledo_get_remote_ref( $order, $type ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render().
			);
		}

		$closure = $this->get_state( $order );

		if ( null !== $closure ) {
			printf(
				'<div class="wc-kledo-state-line wc-kledo-state-closure" title="%1$s">%2$s</div>',
				esc_attr( $closure['description'] ),
				esc_html( $closure['label'] )
			);
		}
	}

	/**
	 * Resolve the sales order closure note shown under the two state lines.
	 *
	 * Order matters: the closed meta is checked first because a confirmed closure removes the
	 * queue row, and a pruned queue row must not read as "never sent".
	 *
	 * The CSS classes are WooCommerce's own order-status pills. The plugin stylesheet is only
	 * enqueued on its settings page, while these styles are already present wherever the order
	 * list renders.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array{label: string, description: string, class: string}|null Null when there is
	 *                                                                      nothing to report.
	 * @since 1.7.4
	 */
	private function get_state( WC_Order $order ): ?array {
		if ( wc_kledo_is_order_closed_in_kledo( $order ) ) {
			return array(
				'label'       => __( 'Sales order closed', 'wc-kledo' ),
				'description' => __( 'Kledo has closed its sales order for this order; every quantity has been invoiced.', 'wc-kledo' ),
				'class'       => 'status-completed',
			);
		}

		$queue_state = wc_kledo_get_closure_check_state( $order->get_id() );

		if ( 'pending' === $queue_state ) {
			return array(
				'label'       => __( 'Waiting for closure', 'wc-kledo' ),
				'description' => __( 'The invoice reached Kledo and the plugin is waiting for Kledo to finish processing it and close the sales order.', 'wc-kledo' ),
				'class'       => 'status-processing',
			);
		}

		if ( 'gave_up' === $queue_state ) {
			return array(
				'label'       => __( 'Closure: check Kledo', 'wc-kledo' ),
				'description' => __( 'The plugin stopped waiting for Kledo to close the sales order. Open the order to read why, then check it in Kledo.', 'wc-kledo' ),
				'class'       => 'status-failed',
			);
		}

		if ( 'no' === wc_kledo_get_invoice_link_mode( $order ) ) {
			return array(
				'label'       => __( 'Not linked', 'wc-kledo' ),
				'description' => __( 'The invoice was sent without a link to the sales order. The sales order never registered it, its quantities do not count as billed, and it stays open in Kledo until you close it there.', 'wc-kledo' ),
				'class'       => 'status-on-hold',
			);
		}

		if ( 'no' === wc_kledo_get_invoice_close_mode( $order ) ) {
			return array(
				'label'       => __( 'Left open', 'wc-kledo' ),
				'description' => __( 'The invoice is linked to the sales order and its quantities count as billed, but Kledo was asked not to close the sales order at the time. Kledo closes it anyway once anything makes it recalculate that sales order.', 'wc-kledo' ),
				'class'       => 'status-on-hold',
			);
		}

		return null;
	}
}
