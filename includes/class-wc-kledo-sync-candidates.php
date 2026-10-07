<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Finds the orders that should be in Kledo and are not.
 *
 * A candidate is a Processing or Completed order whose sales order or invoice — whichever the shop
 * creates — was never sent, or was sent and ended badly enough that sending again is the fix:
 * automatic retries ran out (`failed`) or Kledo never produced it (`missing`). Everything else is
 * left alone: a transaction on its way (`verifying`, `waiting_sales_order`, `retrying`) has its own
 * loop, and one Kledo rejected (`rejected`) will be rejected again until someone fixes the data.
 *
 * Only order meta is read, never the Kledo API, so counting thousands of orders is cheap and safe
 * to do on an admin page load.
 *
 * @since 1.8.0
 */
class WC_Kledo_Sync_Candidates {
	/**
	 * Option caching the latest count, for the admin notice.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const COUNT_OPTION = 'wc_kledo_sync_candidates';

	/**
	 * Days covered by the default range.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * States from which a transaction is sent again.
	 *
	 * @var string[]
	 * @since 1.8.0
	 */
	public const RESEND_STATES = array( 'failed', 'missing' );

	/**
	 * The last `$days` days up to today, in the store's timezone.
	 *
	 * @param  int $days
	 *
	 * @return array{from: string, to: string}
	 * @since 1.8.0
	 */
	public static function get_recent_range( int $days = self::DEFAULT_DAYS ): array {
		$today = current_datetime();

		return array(
			'from' => $today->modify( '-' . max( 0, $days ) . ' days' )->format( 'Y-m-d' ),
			'to'   => $today->format( 'Y-m-d' ),
		);
	}

	/**
	 * Transaction types the shop has switched on.
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	public static function get_enabled_types(): array {
		$types = array();

		if ( wc_string_to_bool( get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME, 'yes' ) ) ) {
			$types[] = 'order';
		}

		if ( wc_string_to_bool( get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME, 'yes' ) ) ) {
			$types[] = 'invoice';
		}

		return $types;
	}

	/**
	 * Types this order still needs sent, given its status and the shop's settings.
	 *
	 * A Completed order skips its sales order when the shop chose "invoice only" for orders that
	 * jump straight to Completed — the same rule the status hook follows.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return string[] In sending order: the sales order before the invoice.
	 * @since 1.8.0
	 */
	public static function get_types_to_send( WC_Order $order ): array {
		$enabled = self::get_enabled_types();
		$types   = array();

		if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			return $types;
		}

		if ( in_array( 'order', $enabled, true )
			&& self::needs_sending( $order, 'order' )
			&& ! ( $order->has_status( 'completed' ) && 'no' === wc_kledo_create_order_on_completed() && 'not_sent' === wc_kledo_get_remote_state( $order, 'order' ) ) ) {
			$types[] = 'order';
		}

		if ( in_array( 'invoice', $enabled, true ) && $order->has_status( 'completed' ) && self::needs_sending( $order, 'invoice' ) ) {
			$types[] = 'invoice';
		}

		return $types;
	}

	/**
	 * Whether one transaction of an order should be sent.
	 *
	 * @param  \WC_Order $order
	 * @param  string    $type
	 *
	 * @return bool
	 * @since 1.8.0
	 */
	public static function needs_sending( WC_Order $order, string $type ): bool {
		$state = wc_kledo_get_remote_state( $order, $type );

		return 'not_sent' === $state || in_array( $state, self::RESEND_STATES, true );
	}

	/**
	 * Count the candidates in a range.
	 *
	 * @param  array{from: string, to: string} $range
	 *
	 * @return array{orders: int, order: int, invoice: int}
	 * @since 1.8.0
	 */
	public function count( array $range ): array {
		$enabled = self::get_enabled_types();
		$counts  = array(
			'orders'  => 0,
			'order'   => 0,
			'invoice' => 0,
		);

		if ( empty( $enabled ) ) {
			return $counts;
		}

		foreach ( array( 'processing', 'completed' ) as $status ) {
			$meta_query = $this->get_status_meta_query( $status );

			if ( empty( $meta_query ) ) {
				continue;
			}

			$counts['orders'] += $this->count_orders( $this->get_query_args( $status, $range, $meta_query ) );
		}

		if ( in_array( 'order', $enabled, true ) ) {
			$counts['order'] += $this->count_orders( $this->get_query_args( 'processing', $range, $this->get_needs_meta_query( 'order' ) ) );

			if ( 'yes' === wc_kledo_create_order_on_completed() ) {
				$counts['order'] += $this->count_orders( $this->get_query_args( 'completed', $range, $this->get_needs_meta_query( 'order' ) ) );
			}
		}

		if ( in_array( 'invoice', $enabled, true ) ) {
			$counts['invoice'] += $this->count_orders( $this->get_query_args( 'completed', $range, $this->get_needs_meta_query( 'invoice' ) ) );
		}

		return $counts;
	}

	/**
	 * Count the default range and cache it for the admin notice.
	 *
	 * @return array{orders: int, order: int, invoice: int}
	 * @since 1.8.0
	 */
	public function refresh_cached_count(): array {
		$counts = $this->count( self::get_recent_range() );

		update_option(
			self::COUNT_OPTION,
			array_merge( $counts, array( 'counted_at' => time() ) ),
			false
		);

		return $counts;
	}

	/**
	 * The next candidates a job has not handled yet, oldest order first.
	 *
	 * Orders the job already stamped are excluded, so an order whose send failed is not picked
	 * up again by the same job — it is reported once and left for the retry loop or an admin.
	 *
	 * @param  array{from: string, to: string} $range
	 * @param  string                          $job_id
	 * @param  int                             $limit
	 *
	 * @return int[]
	 * @since 1.8.0
	 */
	public function get_next_batch( array $range, string $job_id, int $limit ): array {
		$ids = array();

		foreach ( array( 'processing', 'completed' ) as $status ) {
			$meta_query = $this->get_status_meta_query( $status );

			if ( empty( $meta_query ) ) {
				continue;
			}

			$found = wc_kledo_get_orders(
				array_merge(
					$this->get_query_args( $status, $range, $this->exclude_job( $meta_query, $job_id ) ),
					array(
						'limit'   => $limit,
						'orderby' => 'ID',
						'order'   => 'ASC',
						'return'  => 'ids',
					)
				)
			);

			$ids = array_merge( $ids, is_array( $found ) ? array_map( 'intval', $found ) : array() );
		}

		sort( $ids );

		return array_slice( array_values( array_unique( $ids ) ), 0, $limit );
	}

	/**
	 * One page of the Sync tab's order list.
	 *
	 * The "only orders that need sending" view cannot be one query: whether an order needs
	 * anything depends on its status (a Processing order never needs an invoice), and a meta
	 * clause cannot see the status. So the candidate ids of each status are collected separately
	 * — ids only, which stays cheap — merged, and paginated before any order is loaded.
	 *
	 * @param  array{from: string, to: string} $range
	 * @param  string                          $status  `processing`, `completed` or empty for both.
	 * @param  bool                            $only_candidates
	 * @param  int                             $paged
	 * @param  int                             $per_page
	 *
	 * @return array{orders: WC_Order[], total: int, pages: int}
	 * @since 1.8.0
	 */
	public function get_list_page( array $range, string $status, bool $only_candidates, int $paged, int $per_page ): array {
		$ids    = $this->get_ids( $range, $status, $only_candidates );
		$total  = count( $ids );
		$orders = array();

		foreach ( array_slice( $ids, ( max( 1, $paged ) - 1 ) * $per_page, $per_page ) as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				$orders[] = $order;
			}
		}

		return array(
			'orders' => $orders,
			'total'  => $total,
			'pages'  => (int) ceil( $total / max( 1, $per_page ) ),
		);
	}

	/**
	 * Ids of the Processing/Completed orders in a range, oldest first.
	 *
	 * @param  array{from: string, to: string} $range
	 * @param  string                          $status           `processing`, `completed` or empty for both.
	 * @param  bool                            $only_candidates  Only orders that need something sent.
	 *
	 * @return int[]
	 * @since 1.8.0
	 */
	public function get_ids( array $range, string $status = '', bool $only_candidates = true ): array {
		$statuses = in_array( $status, array( 'processing', 'completed' ), true ) ? array( $status ) : array( 'processing', 'completed' );
		$ids      = array();

		foreach ( $statuses as $status_key ) {
			if ( $only_candidates ) {
				$meta_query = $this->get_status_meta_query( $status_key );

				// Nothing of this status can be sent with the current settings.
				if ( empty( $meta_query ) ) {
					continue;
				}

				$args = $this->get_query_args( $status_key, $range, $meta_query );
			} else {
				$args = $this->get_query_args( $status_key, $range, array() );
				unset( $args['meta_query'] );
			}

			$found = wc_kledo_get_orders(
				array_merge(
					$args,
					array(
						'limit'  => -1,
						'return' => 'ids',
					)
				)
			);

			$ids = array_merge( $ids, is_array( $found ) ? array_map( 'intval', $found ) : array() );
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * The meta clause selecting orders of one status that need anything sent.
	 *
	 * @param  string $status  `processing` or `completed`.
	 *
	 * @return array Empty when nothing can be sent for that status.
	 * @since 1.8.0
	 */
	private function get_status_meta_query( string $status ): array {
		$enabled = self::get_enabled_types();
		$clauses = array( 'relation' => 'OR' );

		if ( in_array( 'order', $enabled, true ) && ( 'processing' === $status || 'yes' === wc_kledo_create_order_on_completed() ) ) {
			$clauses[] = $this->get_needs_meta_query( 'order' );
		}

		if ( 'completed' === $status && in_array( 'invoice', $enabled, true ) ) {
			$clauses[] = $this->get_needs_meta_query( 'invoice' );
		}

		return count( $clauses ) > 1 ? $clauses : array();
	}

	/**
	 * The meta clause for "this type was never sent, or should be sent again".
	 *
	 * @param  string $type
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function get_needs_meta_query( string $type ): array {
		return array(
			'relation' => 'OR',
			wc_kledo_get_remote_state_meta_query( $type, 'not_sent' ),
			array(
				'key'     => wc_kledo_get_remote_state_meta_key( $type ),
				'value'   => self::RESEND_STATES,
				'compare' => 'IN',
			),
		);
	}

	/**
	 * Narrow a meta clause to orders a job has not stamped yet.
	 *
	 * @param  array  $meta_query
	 * @param  string $job_id
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function exclude_job( array $meta_query, string $job_id ): array {
		return array(
			'relation' => 'AND',
			$meta_query,
			array(
				'relation' => 'OR',
				array(
					'key'     => WC_Kledo_Sync_Job::ORDER_JOB_META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => WC_Kledo_Sync_Job::ORDER_JOB_META,
					'value'   => $job_id,
					'compare' => '!=',
				),
			),
		);
	}

	/**
	 * Query arguments for one status in a range.
	 *
	 * @param  string $status
	 * @param  array  $range
	 * @param  array  $meta_query
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function get_query_args( string $status, array $range, array $meta_query ): array {
		$args = array(
			'status'     => array( 'wc-' . $status ),
			'type'       => 'shop_order',
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		$date_created = wc_kledo_get_date_created_query_arg( $range );

		if ( '' !== $date_created ) {
			$args['date_created'] = $date_created;
		}

		return $args;
	}

	/**
	 * Number of orders matching a query, without loading them.
	 *
	 * @param  array $args
	 *
	 * @return int
	 * @since 1.8.0
	 */
	private function count_orders( array $args ): int {
		$result = wc_kledo_get_orders(
			array_merge(
				$args,
				array(
					'limit'    => 1,
					'paginate' => true,
					'return'   => 'ids',
				)
			)
		);

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}
}
