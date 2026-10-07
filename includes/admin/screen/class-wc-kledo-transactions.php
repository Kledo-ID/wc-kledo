<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen listing every sales order and invoice the plugin has sent to Kledo, with where each
 * one stands in Kledo.
 *
 * Rows and counts come from {@see WC_Kledo_Admin_Transactions_Query}, which reads the remote-state
 * order meta, so the list is paginated by the database and covers every order. The failed-delivery
 * queue (`wc_kledo_failed_transactions`) is still where retry details — attempts, next run, last
 * error — are read from.
 *
 * Shown to users as the "Kledo Status" tab. The code keeps the `transactions` name — tab ID,
 * options, user meta, AJAX action and assets — so saved URLs and per-user preferences keep working.
 *
 * @since 1.5.0
 * @since 1.8.0 Rebuilt around the Kledo-side state of each transaction, and renamed "Kledo Status" in the UI.
 */
class WC_Kledo_Transactions_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * Manual retry outcome: delivery succeeded and queue cleared by sync.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_SUCCESS = 'success';

	/**
	 * Manual retry outcome: skipped because the record was already synced.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_SKIPPED_SYNCED = 'skipped_synced';

	/**
	 * Manual retry outcome: delivery failed (non-200 / API error).
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_FAILED = 'failed';

	/**
	 * Manual retry outcome: Kledo rejected the payload itself (HTTP 400 validation), so a retry
	 * cannot succeed until the data is corrected.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_REJECTED = 'rejected';

	/**
	 * Manual retry outcome: an invoice held back until its sales order exists in Kledo.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_WAITING = 'waiting_sales_order';

	/**
	 * Manual retry outcome: empty or malformed queue key.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_INVALID_KEY = 'invalid_key';

	/**
	 * Manual retry outcome: key not present in the queue option.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_NOT_IN_QUEUE = 'not_in_queue';

	/**
	 * Manual retry outcome: queue item missing order_id or valid type.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_BAD_ITEM = 'bad_item';

	/**
	 * Manual retry outcome: WooCommerce order no longer exists.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_ORDER_MISSING = 'order_missing';

	/**
	 * Query arg: tab (Kledo state group).
	 *
	 * @var string
	 */
	private const QUERY_STATUS = 'wc_kledo_tx_status';

	/**
	 * Query arg: sort column.
	 *
	 * @var string
	 */
	private const QUERY_ORDERBY = 'wc_kledo_tx_orderby';

	/**
	 * Query arg: sort direction.
	 *
	 * @var string
	 */
	private const QUERY_ORDER = 'wc_kledo_tx_order';

	/**
	 * Query arg: current page (1-based).
	 *
	 * @var string
	 */
	private const QUERY_PAGED = 'paged';

	/**
	 * Default orders per page when the user has not saved a Screen Options preference.
	 *
	 * @var int
	 */
	private const PER_PAGE_DEFAULT = 25;

	/**
	 * The screen id.
	 *
	 * @var string
	 */
	public const ID = 'transactions';

	/**
	 * Transient prefix for the one-shot result notice of any row or bulk action (PRG pattern).
	 *
	 * @var string
	 */
	private const NOTICE_TRANSIENT_PREFIX = 'wc_kledo_retry_notice_';

	/**
	 * Nonce action of the AJAX row actions.
	 *
	 * @var string
	 */
	public const AJAX_NONCE_ACTION = 'wc_kledo_tx_row_action';

	/**
	 * Nonce action shared by every POST on this screen.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'wc_kledo_retry_failed_transaction';

	/**
	 * States a transaction can be resent from by hand.
	 *
	 * @var string[]
	 */
	private const RESENDABLE_STATES = array( 'retrying', 'failed', 'rejected', 'missing' );

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->id = self::ID;

		// Per-page persistence: register the save-validation filter immediately — no hook
		// wrapper. WordPress's set_screen_options() applies this filter to decide whether
		// to persist the submitted per-page value; it can run as early as admin_init at
		// priority 0 (depending on WP version), so registering here (class instantiation,
		// during plugins_loaded) guarantees the filter is always present in time.
		add_filter(
			'set_screen_option_wc_kledo_transactions_per_page',
			static function ( $screen_option, string $option, $value ): int {
				return max( 1, min( 999, (int) $value ) );
			},
			10,
			3
		);

		add_action(
			'load-woocommerce_page_wc-kledo',
			function () {
				$this->label = __( 'Kledo Status', 'wc-kledo' );
				$this->title = __( 'Kledo Status', 'wc-kledo' );
				$this->register_screen_columns();
			}
		);

		// Every POST on this tab is handled before headers are sent so it can redirect (PRG).
		add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );

		// Column visibility persistence: WordPress's native wp_ajax_hidden_columns() requires
		// get_current_screen() to return a non-null WP_Screen. In admin-ajax.php the screen
		// object is never initialized automatically, so the native handler unreliably calls
		// wp_die(0) — silently discarding the user's column preference. Intercept at priority 1.
		add_action( 'wp_ajax_hidden-columns', array( $this, 'ajax_save_hidden_columns' ), 1 );

		// "Check status" and "Resend" on a row, without reloading the page. The same buttons
		// still submit the form when JavaScript is unavailable.
		add_action( 'wp_ajax_wc_kledo_tx_row_action', array( $this, 'ajax_row_action' ) );
	}

	/**
	 * Run a row action and send back the refreshed rows and summary.
	 *
	 * Both rows of the order are sent back, not just the clicked one: one read from Kledo answers
	 * for the sales order and the invoice, and resending a sales order can release a held
	 * invoice. The page replaces whichever of them it is showing.
	 *
	 * The page's own query parameters arrive with the request, so the summary counts are taken
	 * under the same filters the page shows and a row can be labelled when it has left the tab.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function ajax_row_action(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'wc-kledo' ) ), 403 );
		}

		$key        = sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) );
		$row_action = sanitize_key( wp_unslash( (string) ( $_POST['row_action'] ?? '' ) ) );
		$order_ids  = $this->get_order_ids_from_keys( array( $key ) );

		if ( empty( $order_ids ) || ! in_array( $row_action, array( 'check', 'resend' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Not checked: the selected row is invalid.', 'wc-kledo' ) ), 400 );
		}

		$notice = 'resend' === $row_action ? $this->handle_single_resend( $key ) : $this->handle_single_check( $key );
		$order  = wc_get_order( $order_ids[0] );

		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Not resent: the WooCommerce order no longer exists. The queue row has been removed.', 'wc-kledo' ) ), 404 );
		}

		$request = $this->get_transaction_request_args();
		$queue   = get_option( 'wc_kledo_failed_transactions', array() );
		$queue   = is_array( $queue ) ? $queue : array();
		$hidden  = $this->get_hidden_column_keys();
		$rows    = array();

		foreach ( array( 'order', 'invoice' ) as $type ) {
			$row                 = WC_Kledo_Admin_Transactions_Query::build_row( $order, $type, $queue );
			$rows[ $row['key'] ] = $this->get_row_html( $row, $request['status'], $hidden );
		}

		$query = new WC_Kledo_Admin_Transactions_Query();

		ob_start();
		$this->render_summary( $query, $this->get_query_args( $request ), $request );
		$summary = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'rows'    => $rows,
				'summary' => $summary,
				'message' => $notice['text'],
				'level'   => str_replace( 'notice-', '', $notice['class'] ),
			)
		);
	}

	/**
	 * Gets the screen settings.
	 *
	 * This screen does not use the standard WooCommerce settings API fields.
	 *
	 * @return array
	 * @since 1.5.0
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * Route a POST on this tab to the matching action, then redirect back.
	 *
	 * One form carries every action: a row's "Resend" button (`wc_kledo_failed_key`), a row's
	 * "Check status" button (`wc_kledo_check_key`), and the bulk Apply button
	 * (`wc_kledo_bulk_retry_failed`) with its selected rows.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_handle_post(): void {
		if ( ! is_admin() || ! $this->is_this_tab() ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';

		if ( 'post' !== $request_method ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below before anything is acted on.
		$is_bulk   = ! empty( $_POST['wc_kledo_bulk_retry_failed'] );
		$is_resend = isset( $_POST['wc_kledo_failed_key'] );
		$is_check  = isset( $_POST['wc_kledo_check_key'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $is_bulk && ! $is_resend && ! $is_check ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-kledo' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( $is_bulk ) {
			$notice = $this->handle_bulk_action();
		} elseif ( $is_resend ) {
			$notice = $this->handle_single_resend( sanitize_text_field( wp_unslash( (string) $_POST['wc_kledo_failed_key'] ) ) );
		} else {
			$notice = $this->handle_single_check( sanitize_text_field( wp_unslash( (string) $_POST['wc_kledo_check_key'] ) ) );
		}

		set_transient( self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), $notice, 2 * MINUTE_IN_SECONDS );

		wp_safe_redirect( $this->get_transactions_screen_url( $this->get_redirect_args_from_post() ) );
		exit;
	}

	/**
	 * Resend one transaction from its row.
	 *
	 * @param  string $queue_key  `type:order_id`.
	 *
	 * @return array{class: string, text: string}
	 * @since 1.8.0
	 */
	private function handle_single_resend( string $queue_key ): array {
		wc_kledo_log_info(
			sprintf( 'Kledo Transactions single manual retry start: user_id=%d key=%s', get_current_user_id(), $queue_key )
		);

		try {
			$outcome = $this->execute_manual_retry_for_key( $queue_key );
		} catch ( Throwable $exception ) {
			$outcome = 'exception';

			wc_kledo_log_warning(
				sprintf( 'Kledo Transactions single retry exception: key=%s message=%s', $queue_key, $exception->getMessage() )
			);
		}

		wc_kledo_log_info( sprintf( 'Kledo Transactions single manual retry end: key=%s outcome=%s', $queue_key, $outcome ) );

		switch ( $outcome ) {
			case self::RETRY_OUTCOME_SUCCESS:
				return $this->notice( 'notice-success', __( 'Resent. Kledo accepted the transaction; the plugin now checks that it appears in Kledo.', 'wc-kledo' ) );
			case self::RETRY_OUTCOME_SKIPPED_SYNCED:
				return $this->notice( 'notice-success', __( 'Nothing to resend: the transaction was already sent. The stale queue row has been removed.', 'wc-kledo' ) );
			case self::RETRY_OUTCOME_ORDER_MISSING:
				return $this->notice( 'notice-warning', __( 'Not resent: the WooCommerce order no longer exists. The queue row has been removed.', 'wc-kledo' ) );
			case self::RETRY_OUTCOME_WAITING:
				return $this->notice( 'notice-info', __( 'The invoice is waiting for its sales order to exist in Kledo, and will be sent automatically once it does.', 'wc-kledo' ) );
			case self::RETRY_OUTCOME_FAILED:
				return $this->notice( 'notice-error', __( 'Resend failed: the request to Kledo did not succeed. See the Notes column for the error.', 'wc-kledo' ) );
			case self::RETRY_OUTCOME_REJECTED:
				return $this->notice( 'notice-error', __( 'Kledo rejected the data again. Correct what the error names, then resend. The order notes hold the full validation message.', 'wc-kledo' ) );
			case self::RETRY_OUTCOME_INVALID_KEY:
			case self::RETRY_OUTCOME_NOT_IN_QUEUE:
			case self::RETRY_OUTCOME_BAD_ITEM:
				return $this->notice( 'notice-warning', __( 'Not resent: this transaction is not waiting to be resent. Refresh the page to see its current state.', 'wc-kledo' ) );
		}

		return $this->notice( 'notice-error', __( 'An unexpected error occurred during the resend. Please check the WooCommerce logs for details.', 'wc-kledo' ) );
	}

	/**
	 * Read one order back from Kledo from its row.
	 *
	 * @param  string $queue_key  `type:order_id`.
	 *
	 * @return array{class: string, text: string}
	 * @since 1.8.0
	 */
	private function handle_single_check( string $queue_key ): array {
		$order_ids = $this->get_order_ids_from_keys( array( $queue_key ) );

		if ( empty( $order_ids ) ) {
			return $this->notice( 'notice-warning', __( 'Not checked: the selected row is invalid.', 'wc-kledo' ) );
		}

		return $this->notice( 'notice-info', $this->format_check_summary( wc_kledo()->get_transaction_verifier()->check_orders_now( $order_ids ) ) );
	}

	/**
	 * Run the bulk action chosen in the dropdown on the selected rows.
	 *
	 * @return array{class: string, text: string}
	 * @since 1.8.0
	 */
	private function handle_bulk_action(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in maybe_handle_post().
		$bulk_action = isset( $_POST['wc_kledo_failed_bulk_action'] )
			? sanitize_key( wp_unslash( $_POST['wc_kledo_failed_bulk_action'] ) )
			: '';

		$posted_keys = array();

		if ( isset( $_POST['wc_kledo_failed_keys'] ) && is_array( $_POST['wc_kledo_failed_keys'] ) ) {
			$posted_keys = array_map( 'sanitize_text_field', wp_unslash( $_POST['wc_kledo_failed_keys'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$posted_keys = array_values( array_unique( array_filter( $posted_keys, 'strlen' ) ) );

		if ( ! in_array( $bulk_action, array( 'retry_selected', 'check_selected' ), true ) ) {
			return $this->notice( 'notice-warning', __( 'Choose a bulk action before applying.', 'wc-kledo' ) );
		}

		if ( empty( $posted_keys ) ) {
			return $this->notice( 'notice-warning', __( 'No transactions were selected. Choose one or more rows and try again.', 'wc-kledo' ) );
		}

		if ( 'check_selected' === $bulk_action ) {
			$order_ids = $this->get_order_ids_from_keys( $posted_keys );

			return $this->notice( 'notice-info', $this->format_check_summary( wc_kledo()->get_transaction_verifier()->check_orders_now( $order_ids ) ) );
		}

		return $this->bulk_resend( $posted_keys );
	}

	/**
	 * Resend every selected row that is waiting to be resent.
	 *
	 * @param  string[] $posted_keys
	 *
	 * @return array{class: string, text: string}
	 * @since 1.7.0
	 * @since 1.8.0 Rows that are not resendable are counted as skipped instead of invalid.
	 */
	private function bulk_resend( array $posted_keys ): array {
		wc_kledo_log_info(
			sprintf( 'Kledo Transactions bulk manual retry start: user_id=%d count=%d', get_current_user_id(), count( $posted_keys ) )
		);

		$counts = array(
			'ok'      => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		foreach ( $posted_keys as $queue_key ) {
			try {
				$outcome = $this->execute_manual_retry_for_key( $queue_key );
			} catch ( Throwable $exception ) {
				wc_kledo_log_warning(
					sprintf( 'Kledo Transactions bulk retry exception: key=%s message=%s', $queue_key, $exception->getMessage() )
				);

				$outcome = 'exception';
			}

			if ( in_array( $outcome, array( self::RETRY_OUTCOME_SUCCESS, self::RETRY_OUTCOME_WAITING ), true ) ) {
				++$counts['ok'];
			} elseif ( in_array( $outcome, array( self::RETRY_OUTCOME_FAILED, self::RETRY_OUTCOME_REJECTED, 'exception' ), true ) ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		wc_kledo_log_info(
			sprintf(
				'Kledo Transactions bulk manual retry end: user_id=%d ok=%d failed=%d skipped=%d',
				get_current_user_id(),
				$counts['ok'],
				$counts['failed'],
				$counts['skipped']
			)
		);

		return $this->notice(
			$counts['failed'] > 0 ? 'notice-warning' : 'notice-success',
			sprintf(
				/* translators: 1: resent count, 2: failed count, 3: skipped count */
				__( 'Resend finished. Accepted by Kledo: %1$d. Failed: %2$d. Skipped because they were not waiting to be resent: %3$d.', 'wc-kledo' ),
				$counts['ok'],
				$counts['failed'],
				$counts['skipped']
			)
		);
	}

	/**
	 * Notice text for a status check.
	 *
	 * @param  array $summary  From `WC_Kledo_Transaction_Verifier::check_orders_now()`.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function format_check_summary( array $summary ): string {
		$parts = array(
			/* translators: %d: number of orders */
			sprintf( __( 'Kledo status checked: %d order(s) found in Kledo.', 'wc-kledo' ), $summary['confirmed'] ),
		);

		if ( $summary['pending'] > 0 ) {
			/* translators: %d: number of orders */
			$parts[] = sprintf( __( '%d not found yet; the plugin keeps checking and marks them "Failed in Kledo" if they never appear.', 'wc-kledo' ), $summary['pending'] );
		}

		if ( $summary['queued'] > 0 ) {
			/* translators: %d: number of orders */
			$parts[] = sprintf( __( '%d queued to be checked in the background.', 'wc-kledo' ), $summary['queued'] );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Distinct order ids from `type:order_id` keys.
	 *
	 * @param  string[] $keys
	 *
	 * @return int[]
	 * @since 1.8.0
	 */
	private function get_order_ids_from_keys( array $keys ): array {
		$order_ids = array();

		foreach ( $keys as $key ) {
			$parts = explode( ':', (string) $key );

			if ( 2 === count( $parts ) && in_array( $parts[0], array( 'order', 'invoice' ), true ) && absint( $parts[1] ) > 0 ) {
				$order_ids[ absint( $parts[1] ) ] = absint( $parts[1] );
			}
		}

		return array_values( $order_ids );
	}

	/**
	 * Build redirect query args from the current POST state (filter/sort/page).
	 *
	 * @return array<string, scalar>
	 * @since 1.7.0
	 */
	private function get_redirect_args_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- `check_admin_referer()` in maybe_handle_post(); POST is only for redirect state preservation.
		$out = array(
			self::QUERY_STATUS  => sanitize_key( (string) wp_unslash( $_POST[ self::QUERY_STATUS ] ?? 'all' ) ),
			self::QUERY_ORDERBY => sanitize_key( (string) wp_unslash( $_POST[ self::QUERY_ORDERBY ] ?? 'created' ) ),
			self::QUERY_ORDER   => sanitize_key( (string) wp_unslash( $_POST[ self::QUERY_ORDER ] ?? 'desc' ) ),
			self::QUERY_PAGED   => max( 1, absint( wp_unslash( $_POST[ self::QUERY_PAGED ] ?? '1' ) ) ),
		);

		foreach ( $this->get_filter_param_keys() as $param_key ) {
			if ( isset( $_POST[ $param_key ] ) && '' !== $_POST[ $param_key ] ) {
				$out[ $param_key ] = sanitize_text_field( (string) wp_unslash( $_POST[ $param_key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification

		return $out;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 * @since 1.5.0
	 * @since 1.8.0 Summary cards, Kledo state tabs and columns, paginated by the database.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wc-kledo' ) );
		}

		$filter_handler = new WC_Kledo_Admin_Table_Filter_Handler( array( 'screen' => 'wc-kledo-transactions' ) );
		$filter_handler->fire_before_display();

		$this->render_pending_notice();

		$request    = $this->get_transaction_request_args();
		$query      = new WC_Kledo_Admin_Transactions_Query();
		$query_args = $this->get_query_args( $request );
		$page       = $query->get_page( $query_args );

		?>
		<div id="wc-kledo-tx-summary">
			<?php $this->render_summary( $query, $query_args, $request ); ?>
		</div>

		<?php $this->render_status_help(); ?>

		<div class="wc-kledo-tx-toolbar">
			<div class="wc-kledo-tx-bulkactions alignleft actions bulkactions">
				<label for="wc-kledo-tx-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk actions', 'wc-kledo' ); ?></label>
				<select name="wc_kledo_failed_bulk_action" id="wc-kledo-tx-bulk-action" form="wc-kledo-transactions-form">
					<option value=""><?php esc_html_e( 'Bulk actions', 'wc-kledo' ); ?></option>
					<option value="retry_selected"><?php esc_html_e( 'Resend selected (failed only)', 'wc-kledo' ); ?></option>
					<option value="check_selected"><?php esc_html_e( 'Check status in Kledo', 'wc-kledo' ); ?></option>
				</select>
				<button type="submit" form="wc-kledo-transactions-form" name="wc_kledo_bulk_retry_failed" value="1" class="button action"><?php esc_html_e( 'Apply', 'wc-kledo' ); ?></button>
			</div>
			<?php $this->render_filter_form( $request ); ?>
		</div>

		<form method="post" class="wc-kledo-transactions-form" id="wc-kledo-transactions-form" action="<?php echo esc_url( $this->get_transactions_screen_url() ); ?>">
			<?php
			wp_nonce_field( self::NONCE_ACTION );

			foreach ( $this->get_list_url_args( $request ) as $hidden_key => $hidden_value ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s"/>', esc_attr( $hidden_key ), esc_attr( (string) $hidden_value ) );
			}

			$this->render_table( $page['rows'], $request );
			?>

			<div class="tablenav bottom">
				<?php $this->render_pagination_controls( $page['total_orders'], $page['total_pages'], $request ); ?>
				<br class="clear"/>
			</div>
		</form>
		<?php
	}

	/**
	 * The arguments of `WC_Kledo_Admin_Transactions_Query` for a request bundle.
	 *
	 * @param  array $request  From `get_transaction_request_args()`.
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function get_query_args( array $request ): array {
		return array(
			'tab'       => $request['status'],
			'type'      => $request['filters']['type'] ?? '',
			'date_from' => $request['filters']['date_from'] ?? '',
			'date_to'   => $request['filters']['date_to'] ?? '',
			'order_id'  => $request['filters']['order_id'] ?? 0,
			'orderby'   => $request['orderby'],
			'order'     => $request['order'],
			'paged'     => $request['paged'],
			'per_page'  => $this->get_items_per_page(),
		);
	}

	/**
	 * Summary cards and tab links — the part of the screen a row action can change.
	 *
	 * Rendered on its own so the AJAX row actions can send it back and replace it in place.
	 *
	 * @param  \WC_Kledo_Admin_Transactions_Query $query
	 * @param  array                              $query_args
	 * @param  array                              $request
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_summary( WC_Kledo_Admin_Transactions_Query $query, array $query_args, array $request ): void {
		$tab_counts = $query->get_tab_counts( $query_args );

		$this->render_summary_cards( $tab_counts, $query->get_not_sent_counts(), $request );

		echo '<ul class="subsubsub wc-kledo-tx-tabs">' . wp_kses_post( $this->get_status_subsubsub_html( $tab_counts, $request ) ) . '</ul>';
	}

	/**
	 * Show the result of the last action on this screen, once.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_pending_notice(): void {
		$transient_key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice        = get_transient( $transient_key );

		if ( ! is_array( $notice ) || empty( $notice['class'] ) || empty( $notice['text'] ) ) {
			return;
		}

		delete_transient( $transient_key );

		printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $notice['class'] ), esc_html( $notice['text'] ) );
	}

	/**
	 * The four summary cards above the table.
	 *
	 * Three link to their tab; "Not sent" links to the WooCommerce order list with the Kledo filter
	 * applied, because a transaction that was never sent has no row here.
	 *
	 * @param  array<string, int>              $tab_counts
	 * @param  array{order: int, invoice: int} $not_sent
	 * @param  array                           $request
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_summary_cards( array $tab_counts, array $not_sent, array $request ): void {
		$cards = array(
			array(
				'tab'         => 'confirmed',
				'count'       => $tab_counts['confirmed'] ?? 0,
				'label'       => __( 'In Kledo', 'wc-kledo' ),
				'description' => __( 'Confirmed to exist in Kledo.', 'wc-kledo' ),
				'modifier'    => 'ok',
			),
			array(
				'tab'         => 'pending',
				'count'       => $tab_counts['pending'] ?? 0,
				'label'       => __( 'Waiting for Kledo', 'wc-kledo' ),
				'description' => __( 'Sent, not confirmed yet. Checked automatically.', 'wc-kledo' ),
				'modifier'    => 'pending',
			),
			array(
				'tab'         => 'failed',
				'count'       => ( $tab_counts['send_failed'] ?? 0 ) + ( $tab_counts['rejected'] ?? 0 ) + ( $tab_counts['missing'] ?? 0 ),
				'label'       => __( 'Needs attention', 'wc-kledo' ),
				'description' => __( 'Send failed, rejected, or failed in Kledo.', 'wc-kledo' ),
				'modifier'    => 'failed',
			),
		);

		echo '<div class="wc-kledo-tx-cards">';

		foreach ( $cards as $card ) {
			// "Needs attention" spans three tabs; it opens the one with rows in it first.
			$target_tab = $card['tab'];

			if ( 'failed' === $target_tab ) {
				$target_tab = 'send_failed';

				foreach ( array( 'missing', 'rejected', 'send_failed' ) as $failed_tab ) {
					if ( ( $tab_counts[ $failed_tab ] ?? 0 ) > 0 ) {
						$target_tab = $failed_tab;
					}
				}
			}

			printf(
				'<a class="wc-kledo-tx-card wc-kledo-tx-card--%1$s" href="%2$s"><span class="wc-kledo-tx-card-count">%3$s</span><span class="wc-kledo-tx-card-label">%4$s</span><span class="wc-kledo-tx-card-description">%5$s</span></a>',
				esc_attr( $card['modifier'] ),
				esc_url(
					$this->get_transactions_screen_url(
						array_merge(
							$this->get_filter_url_args( $request ),
							array( self::QUERY_STATUS => $target_tab )
						)
					)
				),
				esc_html( number_format_i18n( (int) $card['count'] ) ),
				esc_html( $card['label'] ),
				esc_html( $card['description'] )
			);
		}

		printf(
			'<div class="wc-kledo-tx-card wc-kledo-tx-card--none"><span class="wc-kledo-tx-card-count">%1$s</span><span class="wc-kledo-tx-card-label">%2$s</span><span class="wc-kledo-tx-card-description"><a href="%3$s">%4$s</a> &middot; <a href="%5$s">%6$s</a></span></div>',
			esc_html( number_format_i18n( $not_sent['order'] + $not_sent['invoice'] ) ),
			esc_html__( 'Not sent', 'wc-kledo' ),
			esc_url( $this->get_orders_list_url( 'order', array( 'wc-processing', 'wc-completed' ) ) ),
			/* translators: %s: number of orders */
			esc_html( sprintf( __( '%s sales orders', 'wc-kledo' ), number_format_i18n( $not_sent['order'] ) ) ),
			esc_url( $this->get_orders_list_url( 'invoice', array( 'wc-completed' ) ) ),
			/* translators: %s: number of orders */
			esc_html( sprintf( __( '%s invoices', 'wc-kledo' ), number_format_i18n( $not_sent['invoice'] ) ) )
		);

		echo '</div>';
	}

	/**
	 * URL of the WooCommerce order list, filtered to orders whose `$type` was never sent.
	 *
	 * Links to the first status only: the order list filters by one status at a time.
	 *
	 * @param  string   $type
	 * @param  string[] $statuses
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function get_orders_list_url( string $type, array $statuses ): string {
		$uses_hpos = wc_kledo_uses_hpos();

		$args = array( WC_Kledo_Admin_Order_Filter::PARAMS[ $type ] => 'not_sent' );

		if ( 'invoice' === $type ) {
			$args[ $uses_hpos ? 'status' : 'post_status' ] = $statuses[0];
		}

		return $uses_hpos
			? add_query_arg( array_merge( array( 'page' => 'wc-orders' ), $args ), admin_url( 'admin.php' ) )
			: add_query_arg( array_merge( array( 'post_type' => 'shop_order' ), $args ), admin_url( 'edit.php' ) );
	}

	/**
	 * The collapsible "What do the statuses mean?" panel.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_status_help(): void {
		$states = array( 'confirmed', 'verifying', 'waiting_sales_order', 'legacy_synced', 'retrying', 'failed', 'rejected', 'missing' );

		echo '<details class="wc-kledo-tx-help"><summary>' . esc_html__( 'What do the statuses mean?', 'wc-kledo' ) . '</summary>';
		echo '<p>' . esc_html__( 'Kledo answers a send as soon as it has queued the transaction, before creating it. The plugin then reads every transaction back from Kledo, so "In Kledo" means it was actually found there.', 'wc-kledo' ) . '</p>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( $states as $state ) {
			$badge = WC_Kledo_Status_Badge::describe( $state, 'order' );

			printf(
				'<tr><td class="wc-kledo-tx-help-badge">%1$s</td><td>%2$s%3$s</td></tr>',
				WC_Kledo_Status_Badge::render( $state, 'order' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render().
				esc_html( $badge['description'] ),
				'' !== $badge['action'] ? ' <em>' . esc_html( $badge['action'] ) . '</em>' : ''
			);
		}

		echo '</tbody></table></details>';
	}

	/**
	 * The transactions table.
	 *
	 * @param  array $rows
	 * @param  array $request
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_table( array $rows, array $request ): void {
		$columns       = $this->get_columns();
		$hidden        = $this->get_hidden_column_keys();
		$visible_count = 1 + count( $columns ) - count( array_intersect( array_keys( $this->get_hideable_columns() ), $hidden ) );

		?>
		<table class="wp-list-table widefat fixed striped wc-kledo-transactions-table" data-tab="<?php echo esc_attr( $request['status'] ); ?>">
			<thead>
				<tr>
					<td id="cb" class="manage-column column-cb check-column">
						<label class="screen-reader-text" for="wc-kledo-tx-select-all"><?php esc_html_e( 'Select all rows', 'wc-kledo' ); ?></label>
						<input type="checkbox" id="wc-kledo-tx-select-all"/>
					</td>
					<?php
					foreach ( $columns as $key => $label ) {
						$classes = 'manage-column ' . $this->get_column_classes( $key, $hidden );

						if ( 'order' === $key ) {
							$classes .= ' ' . $this->get_sortable_th_class( 'order', $request );
						}

						printf(
							'<th id="%1$s" scope="col" class="%2$s">%3$s</th>',
							esc_attr( $key ),
							esc_attr( $classes ),
							'order' === $key
								? $this->get_sortable_column_header( $label, 'order', $request ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
								: esc_html( $label )
						);
					}
					?>
				</tr>
			</thead>
			<tbody id="the-list">
				<?php if ( empty( $rows ) ) : ?>
					<tr class="no-items">
						<td class="colspanchange" colspan="<?php echo esc_attr( (string) $visible_count ); ?>"><?php echo esc_html( $this->get_empty_message( $request['status'] ) ); ?></td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $rows as $row ) {
						echo $this->get_row_html( $row, $request['status'], $hidden ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
					}
					?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The table columns after the checkbox, in order.
	 *
	 * Five instead of the nine 1.8.0 started with: everything about the order — its status, its
	 * date and the actions — sits in the first column, which is also the one WordPress keeps on a
	 * narrow screen, so the actions are never the column that scrolls out of view.
	 *
	 * @return array<string, string>
	 * @since 1.8.0
	 */
	private function get_columns(): array {
		return array(
			'order'      => __( 'Order', 'wc-kledo' ),
			'type'       => __( 'Transaction', 'wc-kledo' ),
			'status'     => __( 'Status in Kledo', 'wc-kledo' ),
			'attempts'   => __( 'Attempts', 'wc-kledo' ),
			'last_error' => __( 'Notes', 'wc-kledo' ),
		);
	}

	/**
	 * CSS classes of a column's header and cells.
	 *
	 * @param  string   $key
	 * @param  string[] $hidden
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function get_column_classes( string $key, array $hidden ): string {
		$classes = 'column-' . $key;

		if ( 'order' === $key ) {
			$classes .= ' column-primary';
		}

		if ( isset( $this->get_hideable_columns()[ $key ] ) && in_array( $key, $hidden, true ) ) {
			$classes .= ' hidden';
		}

		return $classes;
	}

	/**
	 * Columns the current user has hidden through Screen Options.
	 *
	 * Read from the same user option `get_hidden_columns()` uses, so it also works in an AJAX
	 * request, where there is no current screen to ask.
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	private function get_hidden_column_keys(): array {
		$hidden = get_user_option( sprintf( 'managewoocommerce_page_%scolumnshidden', WC_Kledo_Admin::PAGE_ID ) );

		return is_array( $hidden ) ? $hidden : $this->get_default_hidden_columns();
	}

	/**
	 * Columns hidden until the user chooses otherwise.
	 *
	 * Attempts only matter for a failed send, and the Notes column already says when the next
	 * retry is due for those, so it starts out hidden to give the other columns room.
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	private function get_default_hidden_columns(): array {
		return array( 'attempts' );
	}

	/**
	 * Apply the default hidden columns to the Screen Options checkboxes.
	 *
	 * @param  array      $hidden
	 * @param  \WP_Screen $screen
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function filter_default_hidden_columns( $hidden, $screen ): array {
		$hidden = is_array( $hidden ) ? $hidden : array();

		if ( $screen instanceof WP_Screen && 'woocommerce_page_' . WC_Kledo_Admin::PAGE_ID === $screen->id ) {
			return array_merge( $hidden, $this->get_default_hidden_columns() );
		}

		return $hidden;
	}

	/**
	 * One transaction row, as HTML.
	 *
	 * Used both for the page and for the AJAX row actions, which replace the row in place.
	 *
	 * @param  array    $row     From `WC_Kledo_Admin_Transactions_Query`.
	 * @param  string   $tab     The tab being shown.
	 * @param  string[] $hidden  Hidden column keys.
	 *
	 * @return string Escaped HTML.
	 * @since 1.8.0
	 */
	public function get_row_html( array $row, string $tab, array $hidden ): string {
		/** @var WC_Order $order */
		$order      = $row['order'];
		$type       = $row['type'];
		$state      = $row['state'];
		$queue_item = $row['queue_item'];
		$badge      = WC_Kledo_Status_Badge::describe( $state, $type );
		$date       = $order->get_date_created();
		$columns    = $this->get_columns();

		$cells = array();

		// Order: identity, WooCommerce status and date, then the actions — kept in this column so
		// they stay next to the order number and survive the narrow-screen collapse.
		$actions = array(
			'check' => sprintf(
				'<button type="submit" class="button-link wc-kledo-tx-action" name="wc_kledo_check_key" value="%1$s" data-action="check">%2$s</button>',
				esc_attr( $row['key'] ),
				esc_html__( 'Check status', 'wc-kledo' )
			),
		);

		if ( in_array( $state, self::RESENDABLE_STATES, true ) ) {
			$actions['resend'] = sprintf(
				'<button type="submit" class="button-link wc-kledo-tx-action" name="wc_kledo_failed_key" value="%1$s" data-action="resend">%2$s</button>',
				esc_attr( $row['key'] ),
				esc_html__( 'Resend', 'wc-kledo' )
			);
		}

		$actions['view'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html__( 'Open order', 'wc-kledo' ) );

		if ( ! in_array( $state, array( 'confirmed', 'verifying', 'waiting_sales_order' ), true ) ) {
			$actions['diagnose'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( WC_Kledo_Diagnostics_Screen::get_url_for_order( $order->get_id() ) ),
				esc_html__( 'Diagnose', 'wc-kledo' )
			);
		}

		$action_html = array();

		foreach ( $actions as $action_key => $html ) {
			$action_html[] = sprintf( '<span class="%1$s">%2$s</span>', esc_attr( $action_key ), $html );
		}

		$cells['order'] = $this->format_order_identifier_cell( $order )
			. sprintf(
				'<div class="wc-kledo-tx-order-meta">%1$s &middot; %2$s</div>',
				esc_html( wc_get_order_status_name( $order->get_status() ) ),
				esc_html( $date ? wc_kledo_format_admin_timestamp( $date->getTimestamp() ) : '—' )
			)
			. '<div class="row-actions visible wc-kledo-tx-actions">' . implode( ' | ', $action_html ) . '</div>'
			. '<div class="wc-kledo-tx-row-message" role="status" aria-live="polite"></div>'
			. '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'wc-kledo' ) . '</span></button>';

		$cells['type'] = esc_html( WC_Kledo_Status_Badge::type_label( $type ) );

		// Status: the badge, then what identifies it in Kledo and when that was last confirmed.
		$status_html = WC_Kledo_Status_Badge::render( $state, $type, $row['reference'] );

		if ( '' !== $row['reference'] ) {
			/* translators: %s: Kledo reference number */
			$status_html .= '<span class="wc-kledo-tx-detail">' . esc_html( sprintf( __( 'Kledo: %s', 'wc-kledo' ), $row['reference'] ) ) . '</span>';
		}

		if ( $row['checked_at'] > 0 ) {
			/* translators: %s: date and time */
			$status_html .= '<span class="wc-kledo-tx-detail">' . esc_html( sprintf( __( 'Checked: %s', 'wc-kledo' ), wc_kledo_format_admin_timestamp( $row['checked_at'], 'past' ) ) ) . '</span>';
		}

		$moved_to = $this->get_moved_tab_label( $state, $tab );

		if ( '' !== $moved_to ) {
			/* translators: %s: name of the tab the row now belongs to */
			$status_html .= '<span class="wc-kledo-tx-moved">' . esc_html( sprintf( __( 'Moved to: %s', 'wc-kledo' ), $moved_to ) ) . '</span>';
		}

		$cells['status'] = $status_html;

		// Attempts: only meaningful for a send that failed, and only while it sits in the queue.
		$attempts_html = '&mdash;';

		if ( null !== $queue_item ) {
			/* translators: %d: number of attempts */
			$attempts_html = esc_html( sprintf( _n( '%d attempt', '%d attempts', (int) ( $queue_item['attempts'] ?? 0 ), 'wc-kledo' ), (int) ( $queue_item['attempts'] ?? 0 ) ) );
			$next_run      = (int) ( $queue_item['next_run_at'] ?? 0 );

			if ( $next_run > 0 && in_array( $state, array( 'retrying', 'waiting_sales_order' ), true ) ) {
				/* translators: %s: date and time */
				$attempts_html .= '<span class="wc-kledo-tx-detail">' . esc_html( sprintf( __( 'Next retry: %s', 'wc-kledo' ), wc_kledo_format_admin_timestamp( $next_run, 'future' ) ) ) . '</span>';
			}
		}

		$cells['attempts'] = $attempts_html;

		// Notes: what went wrong, then what to do about it.
		$last_error = null !== $queue_item ? (string) ( $queue_item['last_error'] ?? '' ) : '';
		$notes_html = '' !== $last_error ? $this->format_last_error_cell( $last_error ) : '';

		if ( '' !== $badge['action'] ) {
			$notes_html .= '<span class="wc-kledo-tx-next-step">' . esc_html( $badge['action'] ) . '</span>';
		}

		$cells['last_error'] = '' !== $notes_html ? $notes_html : '&mdash;';

		$html = sprintf(
			'<tr class="wc-kledo-tx-row" data-key="%1$s"><th scope="row" class="check-column"><label class="screen-reader-text" for="wc-kledo-tx-cb-%2$s">%3$s</label><input type="checkbox" id="wc-kledo-tx-cb-%2$s" name="wc_kledo_failed_keys[]" value="%1$s"/></th>',
			esc_attr( $row['key'] ),
			esc_attr( str_replace( ':', '-', $row['key'] ) ),
			esc_html__( 'Select row', 'wc-kledo' )
		);

		foreach ( array_keys( $columns ) as $key ) {
			$html .= sprintf(
				'<td class="%1$s" data-colname="%2$s">%3$s</td>',
				esc_attr( $this->get_column_classes( $key, $hidden ) . ( 'order' === $key ? ' has-row-actions' : '' ) ),
				esc_attr( $columns[ $key ] ),
				$cells[ $key ]
			);
		}

		return $html . '</tr>';
	}

	/**
	 * Name of the tab a row now belongs to, when it no longer matches the one being shown.
	 *
	 * A row checked from the "Waiting for Kledo" tab that turns out to be in Kledo is left where
	 * it is — removing it under the admin's cursor reads as if it vanished — and labelled instead.
	 *
	 * @param  string $state
	 * @param  string $tab
	 *
	 * @return string Empty when the row still belongs on `$tab`.
	 * @since 1.8.0
	 */
	private function get_moved_tab_label( string $state, string $tab ): string {
		$tabs = WC_Kledo_Admin_Transactions_Query::get_tabs();

		if ( 'all' === $tab || ! isset( $tabs[ $tab ] ) ) {
			return '';
		}

		$belongs = 'legacy_synced' === $state ? $tabs[ $tab ]['legacy'] : in_array( $state, $tabs[ $tab ]['states'], true );

		if ( $belongs ) {
			return '';
		}

		foreach ( $tabs as $tab_key => $definition ) {
			if ( 'all' !== $tab_key && ( in_array( $state, $definition['states'], true ) || ( 'legacy_synced' === $state && $definition['legacy'] ) ) ) {
				return $this->get_tab_labels()[ $tab_key ];
			}
		}

		return '';
	}

	/**
	 * Message for a tab with no rows.
	 *
	 * @param  string $tab
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function get_empty_message( string $tab ): string {
		switch ( $tab ) {
			case 'pending':
				return __( 'Nothing is waiting for Kledo.', 'wc-kledo' );
			case 'confirmed':
				return __( 'No transaction has been confirmed in Kledo yet for the current filter.', 'wc-kledo' );
			case 'send_failed':
				return __( 'No failed sends. Every request reached Kledo.', 'wc-kledo' );
			case 'rejected':
				return __( 'Kledo has not rejected any transaction.', 'wc-kledo' );
			case 'missing':
				return __( 'No transaction went missing in Kledo.', 'wc-kledo' );
		}

		return __( 'No transactions match the current filter.', 'wc-kledo' );
	}

	/**
	 * GET args driving the list (tab, filters, sort, pagination).
	 *
	 * @return array{status: string, orderby: string, order: string, paged: int, filters: array<string, mixed>}
	 * @since 1.5.0
	 */
	private function get_transaction_request_args(): array {
		$status = sanitize_key( (string) wc_kledo_get_requested_value( self::QUERY_STATUS, 'all' ) );

		// Values used by 1.7.x links and bookmarks.
		$legacy_tabs = array(
			'success'  => 'all',
			'failed'   => 'send_failed',
			'retrying' => 'send_failed',
		);

		if ( isset( $legacy_tabs[ $status ] ) ) {
			$status = $legacy_tabs[ $status ];
		}

		if ( ! array_key_exists( $status, WC_Kledo_Admin_Transactions_Query::get_tabs() ) ) {
			$status = 'all';
		}

		$orderby = sanitize_key( (string) wc_kledo_get_requested_value( self::QUERY_ORDERBY, 'created' ) );
		$order   = strtolower( (string) wc_kledo_get_requested_value( self::QUERY_ORDER, 'desc' ) );

		return array(
			'status'  => $status,
			'orderby' => in_array( $orderby, array( 'created', 'order' ), true ) ? $orderby : 'created',
			'order'   => in_array( $order, array( 'asc', 'desc' ), true ) ? $order : 'desc',
			'paged'   => max( 1, absint( wc_kledo_get_requested_value( self::QUERY_PAGED, 1 ) ) ),
			'filters' => $this->parse_filters_from_request(),
		);
	}

	/**
	 * Filter query parameters understood by this screen.
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	private function get_filter_param_keys(): array {
		return array(
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_FROM,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_TO,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_ORDER_ID,
		);
	}

	/**
	 * Parse the filters through the shared sanitizer.
	 *
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function parse_filters_from_request(): array {
		$subset = array();

		foreach ( $this->get_filter_param_keys() as $param_key ) {
			$value = (string) wc_kledo_get_requested_value( $param_key );

			if ( '' !== $value ) {
				$subset[ $param_key ] = $value;
			}
		}

		// A single-day link from 1.8.0 development builds still opens that day.
		$single_day = (string) wc_kledo_get_requested_value( WC_Kledo_Admin_Table_Filter_Handler::PARAM_CREATED_DATE );

		if ( '' !== $single_day && ! isset( $subset[ WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_FROM ] ) && ! isset( $subset[ WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_TO ] ) ) {
			$subset[ WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_FROM ] = $single_day;
			$subset[ WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_TO ]   = $single_day;
		}

		$handler = new WC_Kledo_Admin_Table_Filter_Handler( array( 'screen' => 'wc-kledo-transactions' ) );
		$filters = $handler->parse_from_array( $subset );

		if ( isset( $filters['type'] ) && ! in_array( $filters['type'], array( 'order', 'invoice' ), true ) ) {
			unset( $filters['type'] );
		}

		return $filters;
	}

	/**
	 * Filter values as query parameters, for links and hidden fields.
	 *
	 * @param  array $request
	 *
	 * @return array<string, scalar>
	 * @since 1.8.0
	 */
	private function get_filter_url_args( array $request ): array {
		$map = array(
			'type'      => WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE,
			'date_from' => WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_FROM,
			'date_to'   => WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_TO,
			'order_id'  => WC_Kledo_Admin_Table_Filter_Handler::PARAM_ORDER_ID,
		);

		$out = array();

		foreach ( $map as $filter_key => $param_key ) {
			if ( ! empty( $request['filters'][ $filter_key ] ) ) {
				$out[ $param_key ] = $request['filters'][ $filter_key ];
			}
		}

		return $out;
	}

	/**
	 * Every query parameter of the current list view.
	 *
	 * @param  array $request
	 *
	 * @return array<string, scalar>
	 * @since 1.8.0
	 */
	private function get_list_url_args( array $request ): array {
		return array_merge(
			array(
				self::QUERY_STATUS  => $request['status'],
				self::QUERY_ORDERBY => $request['orderby'],
				self::QUERY_ORDER   => $request['order'],
				self::QUERY_PAGED   => $request['paged'],
			),
			$this->get_filter_url_args( $request )
		);
	}

	/**
	 * The GET filter bar (separate from the POST action form).
	 *
	 * @param  array $request
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_filter_form( array $request ): void {
		$filters = $request['filters'];
		$type    = (string) ( $filters['type'] ?? '' );

		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wc-kledo-tx-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( WC_Kledo_Admin::PAGE_ID ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::ID ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_STATUS ); ?>" value="<?php echo esc_attr( $request['status'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_ORDERBY ); ?>" value="<?php echo esc_attr( $request['orderby'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_ORDER ); ?>" value="<?php echo esc_attr( $request['order'] ); ?>" />

			<label for="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE ); ?>" class="screen-reader-text"><?php esc_html_e( 'Transaction type', 'wc-kledo' ); ?></label>
			<select name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE ); ?>" id="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE ); ?>">
				<option value=""><?php esc_html_e( 'Sales orders and invoices', 'wc-kledo' ); ?></option>
				<option value="order" <?php selected( $type, 'order' ); ?>><?php esc_html_e( 'Sales orders only', 'wc-kledo' ); ?></option>
				<option value="invoice" <?php selected( $type, 'invoice' ); ?>><?php esc_html_e( 'Invoices only', 'wc-kledo' ); ?></option>
			</select>

			<span class="wc-kledo-date-range">
				<label for="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_FROM ); ?>"><?php esc_html_e( 'Order date from', 'wc-kledo' ); ?></label>
				<input type="date" id="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_FROM ); ?>" name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_FROM ); ?>" value="<?php echo esc_attr( (string) ( $filters['date_from'] ?? '' ) ); ?>" />
				<label for="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_TO ); ?>"><?php esc_html_e( 'to', 'wc-kledo' ); ?></label>
				<input type="date" id="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_TO ); ?>" name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_DATE_TO ); ?>" value="<?php echo esc_attr( (string) ( $filters['date_to'] ?? '' ) ); ?>" />
			</span>

			<label for="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_ORDER_ID ); ?>" class="screen-reader-text"><?php esc_html_e( 'Order number', 'wc-kledo' ); ?></label>
			<input type="number" min="1" step="1" class="wc-kledo-tx-order-id" id="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_ORDER_ID ); ?>" name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_ORDER_ID ); ?>" value="<?php echo esc_attr( ! empty( $filters['order_id'] ) ? (string) $filters['order_id'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Order #', 'wc-kledo' ); ?>" />

			<?php submit_button( __( 'Filter', 'wc-kledo' ), 'secondary', 'wc_kledo_tx_adv_filter', false ); ?>
		</form>
		<?php
	}

	/**
	 * Admin URL for this screen preserving standard Kledo query args.
	 *
	 * @param  array<string, scalar> $args
	 *
	 * @return string
	 */
	private function get_transactions_screen_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => WC_Kledo_Admin::PAGE_ID,
					'tab'  => self::ID,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Label of each tab.
	 *
	 * @return array<string, string>
	 * @since 1.8.0
	 */
	private function get_tab_labels(): array {
		return array(
			'all'         => __( 'All', 'wc-kledo' ),
			'pending'     => __( 'Waiting for Kledo', 'wc-kledo' ),
			'confirmed'   => __( 'In Kledo', 'wc-kledo' ),
			'send_failed' => __( 'Send failed', 'wc-kledo' ),
			'rejected'    => __( 'Rejected by Kledo', 'wc-kledo' ),
			'missing'     => __( 'Failed in Kledo', 'wc-kledo' ),
		);
	}

	/**
	 * HTML for the tab links (subsubsub).
	 *
	 * @param  array<string, int> $counts
	 * @param  array              $request
	 *
	 * @return string
	 */
	private function get_status_subsubsub_html( array $counts, array $request ): string {
		$labels = $this->get_tab_labels();

		$base  = array_merge(
			$this->get_filter_url_args( $request ),
			array(
				self::QUERY_ORDERBY => $request['orderby'],
				self::QUERY_ORDER   => $request['order'],
				self::QUERY_PAGED   => 1,
			)
		);
		$parts = array();

		foreach ( $labels as $tab => $label ) {
			$parts[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a></li>',
				esc_url( $this->get_transactions_screen_url( array_merge( $base, array( self::QUERY_STATUS => $tab ) ) ) ),
				$request['status'] === $tab ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( (int) ( $counts[ $tab ] ?? 0 ) ) )
			);
		}

		return implode( ' | ', $parts );
	}

	/**
	 * Classes for sortable &lt;th&gt; (matches WP_List_Table so core list-table.css shows arrows).
	 *
	 * @param  string $column_key
	 * @param  array  $request
	 *
	 * @return string
	 */
	private function get_sortable_th_class( string $column_key, array $request ): string {
		if ( $request['orderby'] === $column_key ) {
			return 'sorted ' . ( 'asc' === $request['order'] ? 'asc' : 'desc' );
		}

		return 'sortable desc';
	}

	/**
	 * Sortable column link markup.
	 *
	 * @param  string $label
	 * @param  string $column_key
	 * @param  array  $request
	 *
	 * @return string HTML (already escaped).
	 */
	private function get_sortable_column_header( string $label, string $column_key, array $request ): string {
		$is_current = $request['orderby'] === $column_key;
		$next_order = ( $is_current && 'desc' === $request['order'] ) ? 'asc' : 'desc';

		$url = $this->get_transactions_screen_url(
			array_merge(
				$this->get_filter_url_args( $request ),
				array(
					self::QUERY_STATUS  => $request['status'],
					self::QUERY_ORDERBY => $column_key,
					self::QUERY_ORDER   => $next_order,
					self::QUERY_PAGED   => 1,
				)
			)
		);

		$title = $is_current
			? sprintf(
				/* translators: %s: sort direction, ascending or descending */
				__( 'Sorted %s.', 'wc-kledo' ),
				'asc' === $request['order'] ? __( 'ascending', 'wc-kledo' ) : __( 'descending', 'wc-kledo' )
			)
			: __( 'Sort by this column.', 'wc-kledo' );

		$icon = $is_current
			? ( 'asc' === $request['order'] ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' )
			: 'dashicons-sort';

		return sprintf(
			'<a href="%1$s" class="wc-kledo-tx-sort-link" title="%2$s"><span class="wc-kledo-tx-col-label">%3$s</span><span class="wc-kledo-tx-sort-icon dashicons %4$s" aria-hidden="true"></span></a>',
			esc_url( $url ),
			esc_attr( $title ),
			esc_html( $label ),
			esc_attr( $icon )
		);
	}

	/**
	 * Pagination links.
	 *
	 * @param  int   $total_orders
	 * @param  int   $total_pages
	 * @param  array $request
	 *
	 * @return void
	 */
	private function render_pagination_controls( int $total_orders, int $total_pages, array $request ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_url = $this->get_transactions_screen_url(
			array_merge(
				$this->get_filter_url_args( $request ),
				array(
					self::QUERY_STATUS  => $request['status'],
					self::QUERY_ORDERBY => $request['orderby'],
					self::QUERY_ORDER   => $request['order'],
				)
			)
		);

		$links = paginate_links(
			array(
				'base'      => esc_url_raw( $base_url . '&' . self::QUERY_PAGED . '=%#%' ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => max( 1, (int) $request['paged'] ),
				'type'      => 'plain',
			)
		);

		if ( ! is_string( $links ) || '' === $links ) {
			return;
		}

		printf(
			'<div class="tablenav-pages"><span class="displaying-num">%1$s</span><span class="pagination-links">%2$s</span></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of orders */
					_n( '%s order', '%s orders', $total_orders, 'wc-kledo' ),
					number_format_i18n( $total_orders )
				)
			),
			wp_kses_post( $links )
		);
	}

	/**
	 * Order column: #ID and billing name, linked to the order.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return string HTML, already escaped.
	 */
	private function format_order_identifier_cell( WC_Order $order ): string {
		$label = '#' . $order->get_order_number();
		$name  = trim( $order->get_formatted_billing_full_name() );

		if ( '' === $name ) {
			$name = trim( $order->get_formatted_shipping_full_name() );
		}

		if ( '' !== $name ) {
			$label .= ' ' . $name;
		}

		return sprintf( '<a href="%1$s"><strong>%2$s</strong></a>', esc_url( $order->get_edit_order_url() ), esc_html( $label ) );
	}

	/**
	 * Format the last error for admin display, truncated with the full text in a tooltip.
	 *
	 * @param  string $last_error
	 * @param  int    $max_length
	 *
	 * @return string HTML, already escaped.
	 * @since 1.7.0
	 */
	private function format_last_error_cell( string $last_error, int $max_length = 120 ): string {
		if ( mb_strlen( $last_error ) <= $max_length ) {
			return '<span class="wc-kledo-tx-error">' . esc_html( $last_error ) . '</span>';
		}

		return sprintf(
			'<span class="wc-kledo-tx-error wc-kledo-tx-error--truncated" title="%1$s">%2$s</span>',
			esc_attr( $last_error ),
			esc_html( mb_substr( $last_error, 0, $max_length - 3 ) . '...' )
		);
	}

	/**
	 * Register Screen Options for the Transactions tab: hideable columns and items per page.
	 *
	 * Scoped to the transactions tab only — other tabs share the same WP screen ID
	 * (woocommerce_page_wc-kledo) and must not inherit these options.
	 *
	 * @return void
	 */
	private function register_screen_columns(): void {
		if ( ! $this->is_this_tab() ) {
			return;
		}

		add_filter( 'manage_woocommerce_page_wc-kledo_columns', array( $this, 'get_hideable_columns' ) );
		add_filter( 'default_hidden_columns', array( $this, 'filter_default_hidden_columns' ), 10, 2 );

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Orders per page', 'wc-kledo' ),
				'default' => self::PER_PAGE_DEFAULT,
				'option'  => 'wc_kledo_transactions_per_page',
			)
		);
	}

	/**
	 * Intercept the hidden-columns AJAX request for the Transactions table.
	 *
	 * See the constructor for why WordPress's own handler cannot be relied on here. The hidden
	 * columns are saved to the same user meta key `get_hidden_columns()` reads.
	 *
	 * @return void
	 */
	public function ajax_save_hidden_columns(): void {
		$page = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only routes; check_ajax_referer() follows.

		if ( ( 'woocommerce_page_' . WC_Kledo_Admin::PAGE_ID ) !== $page ) {
			return; // Not our page; let WP core's handler run.
		}

		check_ajax_referer( 'screen-options-nonce', 'screenoptionnonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 0 );
		}

		$hidden_raw = isset( $_POST['hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['hidden'] ) ) : '';
		$hidden     = ( '' !== $hidden_raw ) ? explode( ',', $hidden_raw ) : array();
		$hidden     = array_values( array_intersect( array_map( 'sanitize_key', $hidden ), array_keys( $this->get_hideable_columns() ) ) );

		update_user_meta(
			get_current_user_id(),
			sprintf( 'managewoocommerce_page_%scolumnshidden', WC_Kledo_Admin::PAGE_ID ),
			$hidden
		);

		wp_die( 1 );
	}

	/**
	 * Columns eligible for show/hide via Screen Options.
	 *
	 * Order and Status in Kledo are always visible. Keys that also existed in 1.7.x keep their
	 * names so saved preferences carry over; keys of removed columns are simply ignored.
	 *
	 * @return array<string, string>
	 */
	public function get_hideable_columns(): array {
		return array(
			'type'       => __( 'Transaction', 'wc-kledo' ),
			'attempts'   => __( 'Attempts', 'wc-kledo' ),
			'last_error' => __( 'Notes', 'wc-kledo' ),
		);
	}

	/**
	 * Orders per page for the current user.
	 *
	 * @return int
	 */
	private function get_items_per_page(): int {
		$saved = (int) get_user_option( 'wc_kledo_transactions_per_page' );

		return ( $saved >= 1 ) ? min( $saved, 999 ) : self::PER_PAGE_DEFAULT;
	}

	/**
	 * Whether the current request is this tab.
	 *
	 * @return bool
	 * @since 1.8.0
	 */
	private function is_this_tab(): bool {
		return WC_Kledo_Admin::PAGE_ID === wc_kledo_get_requested_value( 'page' )
			&& self::ID === wc_kledo_get_requested_value( 'tab' );
	}

	/**
	 * Notice payload for the PRG transient.
	 *
	 * @param  string $class
	 * @param  string $text
	 *
	 * @return array{class: string, text: string}
	 * @since 1.8.0
	 */
	private function notice( string $class, string $text ): array {
		return array(
			'class' => $class,
			'text'  => $text,
		);
	}

	/**
	 * A stand-in queue row for a transaction whose state says it failed but whose row is gone.
	 *
	 * The state meta outlives the queue row in a few ways — the option cleared by hand, a
	 * migration, an order restored from a backup — and a row without a Resend button would leave
	 * the admin no way to send it from here.
	 *
	 * @param  string $key  `type:order_id`.
	 *
	 * @return array|null Null when the key is malformed or the transaction is not resendable.
	 * @since 1.8.0
	 */
	private function get_queue_item_from_state( string $key ): ?array {
		$parts = explode( ':', $key );

		if ( 2 !== count( $parts ) || ! in_array( $parts[0], array( 'order', 'invoice' ), true ) ) {
			return null;
		}

		$order = wc_get_order( absint( $parts[1] ) );

		if ( ! $order instanceof WC_Order || ! in_array( wc_kledo_get_remote_state( $order, $parts[0] ), self::RESENDABLE_STATES, true ) ) {
			return null;
		}

		return array(
			'order_id'   => $order->get_id(),
			'type'       => $parts[0],
			'attempts'   => 0,
			'created_at' => time(),
			'status'     => 'failed',
			'last_error' => '',
		);
	}

	/**
	 * Run the manual resend pipeline for one queue key.
	 *
	 * @param  string $key  Queue option key (e.g. order:62).
	 *
	 * @return string One of the RETRY_OUTCOME_* constants.
	 * @since 1.5.0
	 */
	private function execute_manual_retry_for_key( string $key ): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return self::RETRY_OUTCOME_INVALID_KEY;
		}

		if ( '' === $key ) {
			return self::RETRY_OUTCOME_INVALID_KEY;
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );

		$queue = is_array( $queue ) ? $queue : array();
		$item  = isset( $queue[ $key ] ) && is_array( $queue[ $key ] ) ? $queue[ $key ] : $this->get_queue_item_from_state( $key );

		if ( null === $item ) {
			return self::RETRY_OUTCOME_NOT_IN_QUEUE;
		}

		$order_id = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
		$type     = $item['type'] ?? '';

		if ( ! $order_id || ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
			return self::RETRY_OUTCOME_BAD_ITEM;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			unset( $queue[ $key ] );
			update_option( $option_name, $queue, false );

			return self::RETRY_OUTCOME_ORDER_MISSING;
		}

		$result = wc_kledo()->get_woocommerce_bridge()->deliver(
			$order,
			$type,
			array(
				'trigger'            => 'retry',
				'enqueue_on_failure' => false,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: "order" or "invoice" */
					__( 'Kledo: manually resent %s to Kledo from the Kledo Status tab.', 'wc-kledo' ),
					$type
				)
			);

			return self::RETRY_OUTCOME_SUCCESS;
		}

		if ( ! empty( $result['skipped'] ) && 'already_synced' === ( $result['reason'] ?? '' ) ) {
			return self::RETRY_OUTCOME_SKIPPED_SYNCED;
		}

		// Re-read: deliver() may have rewritten the row (an invoice held for its sales order).
		$queue = get_option( $option_name, array() );
		$queue = is_array( $queue ) ? $queue : array();
		$item  = isset( $queue[ $key ] ) && is_array( $queue[ $key ] ) ? $queue[ $key ] : $item;

		if ( ! empty( $result['error'] ) ) {
			$item['last_error'] = $result['error'];
		} elseif ( 'awaiting_sales_order' !== ( $result['reason'] ?? '' ) ) {
			$http_code          = (int) ( $result['http_code'] ?? 0 );
			$item['last_error'] = wc_kledo_sanitize_api_error_message(
				$http_code > 0
					? sprintf( 'HTTP %d', $http_code )
					: __( 'There was a problem when connecting to the API.', 'wc-kledo' )
			);
		}

		// Kledo rejected the payload rather than failing to process it, so there is nothing to
		// schedule: the row stays terminal until an admin fixes the data and retries by hand.
		if ( ! empty( $result['permanent'] ) ) {
			$item['status'] = 'failed';

			unset( $item['next_run_at'], $item['reason'] );

			$queue[ $key ] = $item;
			update_option( $option_name, $queue, false );

			wc_kledo_set_remote_state( $order, $type, 'rejected' );

			return self::RETRY_OUTCOME_REJECTED;
		}

		// Handed back to the automatic retry loop, with a fresh budget.
		$item['status']      = 'retrying';
		$item['attempts']    = 0;
		$item['created_at']  = time();
		$item['next_run_at'] = time() + HOUR_IN_SECONDS;

		unset( $item['reason'] );

		$queue[ $key ] = $item;
		update_option( $option_name, $queue, false );

		if ( 'awaiting_sales_order' === ( $result['reason'] ?? '' ) ) {
			return self::RETRY_OUTCOME_WAITING;
		}

		wc_kledo_set_remote_state( $order, $type, 'retrying' );

		return self::RETRY_OUTCOME_FAILED;
	}
}
