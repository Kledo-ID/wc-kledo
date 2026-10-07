<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Filters the WooCommerce order list by the Kledo state of the sales order and of the invoice,
 * and by an order date range — WooCommerce itself only filters by whole month.
 *
 * Paired with WooCommerce's own status filter it answers the question shops actually ask — "which
 * Completed orders have no invoice in Kledo yet?" — and the result can be selected wholesale and
 * sent with the Kledo bulk actions.
 *
 * Reads the same remote-state meta as the order list column and the Transactions screen, through
 * `wc_kledo_get_remote_state_meta_query()`, so the three never disagree about a count.
 *
 * @since 1.8.0
 */
class WC_Kledo_Admin_Order_Filter {
	/**
	 * Query parameter per transaction type.
	 *
	 * @var array<string, string>
	 * @since 1.8.0
	 */
	public const PARAMS = array(
		'order'   => 'wc_kledo_order_state',
		'invoice' => 'wc_kledo_invoice_state',
	);

	/**
	 * Query parameter of the first day of the order date range.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const PARAM_DATE_FROM = 'wc_kledo_date_from';

	/**
	 * Query parameter of the last day of the order date range.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const PARAM_DATE_TO = 'wc_kledo_date_to';

	/**
	 * Register the hooks for both order storage modes.
	 *
	 * HPOS and the legacy post table expose different hooks, and only one of them ever runs in a
	 * given shop, so both are registered — the same arrangement as the order list column.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function init(): void {
		// HPOS order list table (WooCommerce 7.3+).
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_hpos_filters' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_query_args' ) );

		// Legacy `shop_order` post list table.
		add_action( 'restrict_manage_posts', array( $this, 'render_legacy_filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'filter_legacy_query' ) );
	}

	/**
	 * Render the dropdowns on the HPOS order list.
	 *
	 * @param  string $order_type
	 * @param  string $which  `top` or `bottom`.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render_hpos_filters( $order_type, $which = 'top' ): void {
		if ( 'shop_order' === $order_type && 'top' === $which ) {
			$this->render_filters();
		}
	}

	/**
	 * Render the dropdowns on the legacy order list.
	 *
	 * @param  string $post_type
	 * @param  string $which  `top` or `bottom`.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render_legacy_filters( $post_type, $which = 'top' ): void {
		if ( 'shop_order' === $post_type && 'top' === $which ) {
			$this->render_filters();
		}
	}

	/**
	 * Narrow the HPOS order query.
	 *
	 * @param  array $query_args  Arguments about to be passed to `wc_get_orders()`.
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function filter_hpos_query_args( $query_args ): array {
		$query_args   = is_array( $query_args ) ? $query_args : array();
		$date_created = $this->get_date_created_arg();

		// Replaces WooCommerce's own month filter rather than narrowing it: a range the admin typed
		// is the more specific of the two, and intersecting them would silently empty the list
		// whenever the month and the range disagree.
		if ( '' !== $date_created ) {
			$query_args['date_created'] = $date_created;
		}

		$clauses = $this->get_meta_query_clauses();

		if ( empty( $clauses ) ) {
			return $query_args;
		}

		$query_args['meta_query'] = $this->merge_meta_query( $query_args['meta_query'] ?? array(), $clauses ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only while the admin has picked a Kledo filter.

		return $query_args;
	}

	/**
	 * Narrow the legacy order list query.
	 *
	 * @param  \WP_Query $query
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function filter_legacy_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$range = self::get_requested_date_range();

		if ( '' !== $range['from'] || '' !== $range['to'] ) {
			// Same precedence as on HPOS: the typed range replaces the month dropdown.
			$query->set( 'm', '' );
			$query->set( 'date_query', $this->get_date_query( $range ) );
		}

		$clauses = $this->get_meta_query_clauses();

		if ( empty( $clauses ) ) {
			return;
		}

		$query->set( 'meta_query', $this->merge_meta_query( $query->get( 'meta_query' ), $clauses ) );
	}

	/**
	 * The order date range currently requested.
	 *
	 * @return array{from: string, to: string}
	 * @since 1.8.0
	 */
	public static function get_requested_date_range(): array {
		return wc_kledo_get_date_range(
			wc_kledo_get_requested_value( self::PARAM_DATE_FROM ),
			wc_kledo_get_requested_value( self::PARAM_DATE_TO )
		);
	}

	/**
	 * The `date_created` argument for the requested range, for the HPOS order query.
	 *
	 * @return string Empty when no range is requested.
	 * @since 1.8.0
	 */
	private function get_date_created_arg(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return '';
		}

		return wc_kledo_get_date_created_query_arg( self::get_requested_date_range() );
	}

	/**
	 * A `date_query` for the legacy post query, inclusive of both days.
	 *
	 * `WP_Query` compares `post_date`, which WordPress stores in the site timezone, so the days
	 * mean the same as on HPOS and as shown in the list.
	 *
	 * @param  array{from: string, to: string} $range
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function get_date_query( array $range ): array {
		$clause = array( 'inclusive' => true );

		if ( '' !== $range['from'] ) {
			$clause['after'] = $range['from'] . ' 00:00:00';
		}

		if ( '' !== $range['to'] ) {
			$clause['before'] = $range['to'] . ' 23:59:59';
		}

		return array( $clause );
	}

	/**
	 * The filter value currently requested for a type, whitelisted.
	 *
	 * @param  string $type
	 *
	 * @return string Empty when unset or unknown.
	 * @since 1.8.0
	 */
	public static function get_requested_group( string $type ): string {
		if ( ! isset( self::PARAMS[ $type ] ) ) {
			return '';
		}

		$value = (string) wc_kledo_get_requested_value( self::PARAMS[ $type ] );

		return array_key_exists( $value, WC_Kledo_Status_Badge::filter_choices() ) ? $value : '';
	}

	/**
	 * Echo both dropdowns.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_filters(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$labels = array(
			'order'   => __( 'Any Kledo sales order', 'wc-kledo' ),
			'invoice' => __( 'Any Kledo invoice', 'wc-kledo' ),
		);

		foreach ( self::PARAMS as $type => $param ) {
			$selected = self::get_requested_group( $type );

			printf(
				'<label for="%1$s" class="screen-reader-text">%2$s</label>',
				esc_attr( $param ),
				esc_html(
					'order' === $type
						? __( 'Filter by Kledo sales order', 'wc-kledo' )
						: __( 'Filter by Kledo invoice', 'wc-kledo' )
				)
			);

			printf(
				'<select name="%1$s" id="%1$s" title="%2$s">',
				esc_attr( $param ),
				esc_attr__( '"Not sent" also lists orders that are not due yet (Pending, On hold); combine it with the order status filter.', 'wc-kledo' )
			);

			printf( '<option value="">%s</option>', esc_html( $labels[ $type ] ) );

			foreach ( WC_Kledo_Status_Badge::filter_choices() as $value => $label ) {
				printf(
					'<option value="%1$s"%2$s>%3$s: %4$s</option>',
					esc_attr( $value ),
					selected( $selected, $value, false ),
					esc_html( WC_Kledo_Status_Badge::type_label( $type ) ),
					esc_html( $label )
				);
			}

			echo '</select>';
		}

		$range = self::get_requested_date_range();

		printf(
			'<span class="wc-kledo-date-range" title="%1$s">'
			. '<label for="%2$s">%3$s</label><input type="date" id="%2$s" name="%2$s" value="%4$s" />'
			. '<label for="%5$s">%6$s</label><input type="date" id="%5$s" name="%5$s" value="%7$s" />'
			. '</span>',
			esc_attr__( 'Filling in dates here replaces the month chosen in "All dates".', 'wc-kledo' ),
			esc_attr( self::PARAM_DATE_FROM ),
			esc_html__( 'From', 'wc-kledo' ),
			esc_attr( $range['from'] ),
			esc_attr( self::PARAM_DATE_TO ),
			esc_html__( 'to', 'wc-kledo' ),
			esc_attr( $range['to'] )
		);

		$this->render_sync_link( $range );
	}

	/**
	 * Offer the Sync tab when the list is filtered down to orders missing from Kledo.
	 *
	 * Bulk actions stop at 20 orders a click, because each is a request to Kledo; the Sync tab
	 * sends any number of them, a few per minute. The link carries the date range across so the
	 * admin does not have to type it twice.
	 *
	 * @param  array{from: string, to: string} $range
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_sync_link( array $range ): void {
		$missing_filter = false;

		foreach ( array_keys( self::PARAMS ) as $type ) {
			if ( in_array( self::get_requested_group( $type ), array( 'not_sent', 'failed' ), true ) ) {
				$missing_filter = true;
			}
		}

		if ( ! $missing_filter ) {
			return;
		}

		$default = WC_Kledo_Sync_Screen::get_default_range();

		printf(
			'<a class="button wc-kledo-sync-link" href="%1$s">%2$s</a>',
			esc_url(
				add_query_arg(
					array(
						'page'                           => WC_Kledo_Admin::PAGE_ID,
						'tab'                            => WC_Kledo_Sync_Screen::ID,
						WC_Kledo_Sync_Screen::PARAM_FROM => '' !== $range['from'] ? $range['from'] : $default['from'],
						WC_Kledo_Sync_Screen::PARAM_TO   => '' !== $range['to'] ? $range['to'] : $default['to'],
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'Send all of them gradually with Sync →', 'wc-kledo' )
		);
	}

	/**
	 * The `meta_query` clauses for the requested filters, empty when none is set.
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function get_meta_query_clauses(): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array();
		}

		$clauses = array();

		foreach ( array_keys( self::PARAMS ) as $type ) {
			$group = self::get_requested_group( $type );

			if ( '' === $group ) {
				continue;
			}

			$clause = wc_kledo_get_remote_state_meta_query( $type, $group );

			if ( ! empty( $clause ) ) {
				$clauses[] = $clause;
			}
		}

		return $clauses;
	}

	/**
	 * AND the Kledo clauses onto whatever `meta_query` another filter already set.
	 *
	 * @param  mixed $existing
	 * @param  array $clauses
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function merge_meta_query( $existing, array $clauses ): array {
		$merged = array( 'relation' => 'AND' );

		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$merged[] = $existing;
		}

		foreach ( $clauses as $clause ) {
			$merged[] = $clause;
		}

		return $merged;
	}
}
