<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads the rows and counts of the Kledo > Kledo Status tab from the order tables.
 *
 * Until 1.8.0 the screen built its "success" rows by loading the 2,500 most recently modified
 * synced orders into memory and paginating them in PHP, so anything older silently fell off the
 * list. Every transaction now carries its state in order meta, which lets the database do the
 * filtering, counting and paginating instead.
 *
 * One row is one transaction — the sales order or the invoice of one order — so a page of N
 * orders can hold up to 2N rows when both types are shown.
 *
 * @since 1.8.0
 */
class WC_Kledo_Admin_Transactions_Query {
	/**
	 * The tabs of the screen and the stored states behind each.
	 *
	 * `legacy` marks the tabs that also hold records sent before 1.8.0, which carry no state.
	 *
	 * @return array<string, array{states: string[], legacy: bool}>
	 * @since 1.8.0
	 */
	public static function get_tabs(): array {
		return array(
			'all'         => array(
				'states' => wc_kledo_get_remote_states(),
				'legacy' => true,
			),
			'pending'     => array(
				'states' => array( 'verifying', 'waiting_sales_order' ),
				'legacy' => true,
			),
			'confirmed'   => array(
				'states' => array( 'confirmed' ),
				'legacy' => false,
			),
			'send_failed' => array(
				'states' => array( 'retrying', 'failed' ),
				'legacy' => false,
			),
			'rejected'    => array(
				'states' => array( 'rejected' ),
				'legacy' => false,
			),
			'missing'     => array(
				'states' => array( 'missing' ),
				'legacy' => false,
			),
		);
	}

	/**
	 * One page of transaction rows.
	 *
	 * @param  array $args {
	 *     @type string $tab           Key of `get_tabs()`.
	 *     @type string $type          `order`, `invoice`, or empty for both.
	 *     @type string $date_from     First order day, `Y-m-d`, site timezone. Optional.
	 *     @type string $date_to       Last order day, `Y-m-d`, site timezone. Optional.
	 *     @type int    $order_id      Exact order id. Optional.
	 *     @type string $orderby       `created` or `order`.
	 *     @type string $order         `asc` or `desc`.
	 *     @type int    $paged
	 *     @type int    $per_page      Orders per page.
	 * }
	 *
	 * @return array{rows: array<int, array>, total_orders: int, total_pages: int}
	 * @since 1.8.0
	 */
	public function get_page( array $args ): array {
		$tabs  = self::get_tabs();
		$tab   = isset( $tabs[ $args['tab'] ?? '' ] ) ? (string) $args['tab'] : 'all';
		$types = $this->resolve_types( (string) ( $args['type'] ?? '' ) );

		$query_args = array_merge(
			$this->get_base_query_args( $args ),
			array(
				'limit'      => max( 1, (int) ( $args['per_page'] ?? 25 ) ),
				'paged'      => max( 1, (int) ( $args['paged'] ?? 1 ) ),
				'paginate'   => true,
				'orderby'    => 'order' === ( $args['orderby'] ?? '' ) ? 'ID' : 'date',
				'order'      => 'asc' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC',
				'meta_query' => $this->get_tab_meta_query( $tab, $types ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The screen exists to list orders by this meta.
			)
		);

		$result = wc_kledo_get_orders( $query_args );
		$rows   = array();

		if ( ! is_object( $result ) || ! isset( $result->orders ) ) {
			return array(
				'rows'         => array(),
				'total_orders' => 0,
				'total_pages'  => 0,
			);
		}

		$queue = get_option( 'wc_kledo_failed_transactions', array() );
		$queue = is_array( $queue ) ? $queue : array();

		foreach ( $result->orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( $types as $type ) {
				$state = wc_kledo_get_remote_state( $order, $type );

				if ( ! $this->state_in_tab( $state, $tab ) ) {
					continue;
				}

				$rows[] = self::build_row( $order, $type, $queue );
			}
		}

		return array(
			'rows'         => $rows,
			'total_orders' => (int) $result->total,
			'total_pages'  => (int) $result->max_num_pages,
		);
	}

	/**
	 * One row of the screen: everything shown about one transaction.
	 *
	 * @param  \WC_Order $order
	 * @param  string    $type
	 * @param  array     $queue  The failed-delivery queue, read once by the caller.
	 *
	 * @return array{key: string, order: WC_Order, type: string, state: string, reference: string, checked_at: int, queue_item: ?array}
	 * @since 1.8.0
	 */
	public static function build_row( WC_Order $order, string $type, array $queue ): array {
		$key = $type . ':' . $order->get_id();

		return array(
			'key'        => $key,
			'order'      => $order,
			'type'       => $type,
			'state'      => wc_kledo_get_remote_state( $order, $type ),
			'reference'  => wc_kledo_get_remote_ref( $order, $type ),
			'checked_at' => wc_kledo_get_remote_checked_at( $order, $type ),
			'queue_item' => isset( $queue[ $key ] ) && is_array( $queue[ $key ] ) ? $queue[ $key ] : null,
		);
	}

	/**
	 * Number of transactions in each tab, honouring the type and date filters.
	 *
	 * Counted per type and summed, because one order can hold a transaction in two tabs at once —
	 * a confirmed sales order with a rejected invoice.
	 *
	 * @param  array $args  Same filters as `get_page()`; paging and sorting are ignored.
	 *
	 * @return array<string, int>
	 * @since 1.8.0
	 */
	public function get_tab_counts( array $args ): array {
		$counts = array();
		$types  = $this->resolve_types( (string) ( $args['type'] ?? '' ) );

		foreach ( array_keys( self::get_tabs() ) as $tab ) {
			$counts[ $tab ] = 0;

			foreach ( $types as $type ) {
				$counts[ $tab ] += $this->count_orders(
					array_merge(
						$this->get_base_query_args( $args ),
						array( 'meta_query' => $this->get_tab_meta_query( $tab, array( $type ) ) ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Counting by this meta is the point.
					)
				);
			}
		}

		return $counts;
	}

	/**
	 * Transactions that are due but were never sent, per type.
	 *
	 * Due means the status that triggers the automatic send: Processing or Completed for a sales
	 * order, Completed for an invoice.
	 *
	 * @return array{order: int, invoice: int}
	 * @since 1.8.0
	 */
	public function get_not_sent_counts(): array {
		return array(
			'order'   => $this->count_orders(
				array(
					'status'     => array( 'wc-processing', 'wc-completed' ),
					'meta_query' => wc_kledo_get_remote_state_meta_query( 'order', 'not_sent' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			),
			'invoice' => $this->count_orders(
				array(
					'status'     => array( 'wc-completed' ),
					'meta_query' => wc_kledo_get_remote_state_meta_query( 'invoice', 'not_sent' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			),
		);
	}

	/**
	 * Whether a row in `$state` belongs on `$tab`.
	 *
	 * @param  string $state
	 * @param  string $tab
	 *
	 * @return bool
	 * @since 1.8.0
	 */
	private function state_in_tab( string $state, string $tab ): bool {
		$definition = self::get_tabs()[ $tab ];

		if ( 'legacy_synced' === $state ) {
			return $definition['legacy'];
		}

		return in_array( $state, $definition['states'], true );
	}

	/**
	 * The `meta_query` selecting orders with at least one transaction of `$types` on `$tab`.
	 *
	 * @param  string   $tab
	 * @param  string[] $types
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function get_tab_meta_query( string $tab, array $types ): array {
		$definition = self::get_tabs()[ $tab ];
		$clauses    = array( 'relation' => 'OR' );

		foreach ( $types as $type ) {
			$clauses[] = array(
				'key'     => wc_kledo_get_remote_state_meta_key( $type ),
				'value'   => $definition['states'],
				'compare' => 'IN',
			);

			if ( $definition['legacy'] ) {
				$clauses[] = array(
					'relation' => 'AND',
					array(
						'key'   => wc_kledo_get_delivery_meta_key( $type ),
						'value' => 'yes',
					),
					array(
						'key'     => wc_kledo_get_remote_state_meta_key( $type ),
						'compare' => 'NOT EXISTS',
					),
				);
			}
		}

		return $clauses;
	}

	/**
	 * Query arguments shared by the page and the counts: every order status, plus the filters.
	 *
	 * @param  array $args
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function get_base_query_args( array $args ): array {
		$query_args = array(
			'status' => array_keys( wc_get_order_statuses() ),
			'type'   => 'shop_order',
		);

		$date_created = wc_kledo_get_date_created_query_arg(
			wc_kledo_get_date_range( $args['date_from'] ?? '', $args['date_to'] ?? '' )
		);

		if ( '' !== $date_created ) {
			$query_args['date_created'] = $date_created;
		}

		if ( ! empty( $args['order_id'] ) ) {
			$query_args['include'] = array( (int) $args['order_id'] );
		}

		return $query_args;
	}

	/**
	 * Number of orders matching a query, without loading them.
	 *
	 * @param  array $query_args
	 *
	 * @return int
	 * @since 1.8.0
	 */
	private function count_orders( array $query_args ): int {
		$result = wc_kledo_get_orders(
			array_merge(
				$query_args,
				array(
					'limit'    => 1,
					'paginate' => true,
					'return'   => 'ids',
				)
			)
		);

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * The transaction types a filter value stands for.
	 *
	 * @param  string $type
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	private function resolve_types( string $type ): array {
		return in_array( $type, array( 'order', 'invoice' ), true ) ? array( $type ) : array( 'order', 'invoice' );
	}
}
