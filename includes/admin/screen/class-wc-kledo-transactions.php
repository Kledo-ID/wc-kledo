<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen listing Kledo order/invoice delivery outcomes (failed queue + synced orders).
 *
 * Successful deliveries are inferred from order meta ({@see wc_kledo_is_delivery_synced}); failed/retrying
 * rows come from the `wc_kledo_failed_transactions` option. A bounded scan of recent orders is used for
 * success rows so the screen stays performant on large catalogs (see {@see self::get_max_orders_for_success_scan()}).
 */
class WC_Kledo_Transactions_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * Manual retry outcome: delivery succeeded and queue cleared by sync.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_SUCCESS = 'success';

	/**
	 * Manual retry outcome: already synced; stale queue row removed by deliver().
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_SKIPPED_SYNCED = 'skipped_synced';

	/**
	 * Manual retry outcome: API or transport failure; queue row updated.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_FAILED = 'failed';

	/**
	 * Manual retry outcome: empty or malformed queue key.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_INVALID_KEY = 'invalid_key';

	/**
	 * Manual retry outcome: key not present in current queue.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_NOT_IN_QUEUE = 'not_in_queue';

	/**
	 * Manual retry outcome: row missing order_id or invalid type.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_BAD_ITEM = 'bad_item';

	/**
	 * Manual retry outcome: WooCommerce order missing; queue row removed.
	 *
	 * @var string
	 */
	private const RETRY_OUTCOME_ORDER_MISSING = 'order_missing';

	/**
	 * Query param: status filter.
	 *
	 * @var string
	 */
	private const QUERY_STATUS = 'wc_kledo_tx_status';

	/**
	 * Query param: list ordering field.
	 *
	 * @var string
	 */
	private const QUERY_ORDERBY = 'wc_kledo_tx_orderby';

	/**
	 * Query param: list order direction.
	 *
	 * @var string
	 */
	private const QUERY_ORDER = 'wc_kledo_tx_order';

	/**
	 * Query param: current page (pagination).
	 *
	 * @var string
	 */
	private const QUERY_PAGED = 'paged';

	/**
	 * Status filter: success + failed (default).
	 *
	 * @var string
	 */
	private const STATUS_ALL = 'all';

	/**
	 * Status filter: synced deliveries only.
	 *
	 * @var string
	 */
	private const STATUS_SUCCESS = 'success';

	/**
	 * Status filter: failed queue rows.
	 *
	 * @var string
	 */
	private const STATUS_FAILED = 'failed';

	/**
	 * Status filter: queue rows waiting for next cron/manual retry.
	 *
	 * @var string
	 */
	private const STATUS_RETRYING = 'retrying';

	/**
	 * Default rows per page — used as the Screen Options 'per_page' default and as the
	 * first-load fallback before a user saves their own preference.
	 *
	 * @var int
	 */
	private const PER_PAGE_DEFAULT = 25;

	/**
	 * The screen id (tab slug). Kept as `transactions`; legacy `failed_transactions` tab redirects in admin.
	 *
	 * @var string
	 * @since 1.5.0
	 */
	public const ID = 'transactions';

	/**
	 * Transient key prefix for one-shot admin feedback after single-row retry (PRG).
	 *
	 * @var string
	 */
	private const RETRY_NOTICE_TRANSIENT_PREFIX = 'wc_kledo_retry_notice_';

	/**
	 * Transient key prefix for one-shot admin feedback after bulk retry (PRG).
	 *
	 * @var string
	 */
	private const BULK_RETRY_NOTICE_TRANSIENT_PREFIX = 'wc_kledo_bulk_retry_notice_';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->id = self::ID;

		// Per-page persistence: register the save-validation filter immediately — no hook
		// wrapper.  WordPress's set_screen_options() applies this filter to decide whether
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

		add_action( 'load-woocommerce_page_wc-kledo', function () {
			$this->label = __( 'Transactions', WC_KLEDO_TEXT_DOMAIN );
			$this->title = __( 'Transactions', WC_KLEDO_TEXT_DOMAIN );
			$this->register_screen_columns();
		} );

		// Handle single-row retry before headers are sent so we can redirect (PRG pattern).
		add_action( 'admin_init', array( $this, 'maybe_handle_single_retry' ) );

		// Handle bulk retry before headers are sent so we can redirect (PRG pattern).
		add_action( 'admin_init', array( $this, 'maybe_handle_bulk_retry' ) );

		// Column visibility persistence: WordPress's native wp_ajax_hidden_columns() requires
		// get_current_screen() to return a non-null WP_Screen.  In admin-ajax.php the screen
		// object is never initialized automatically (admin-ajax.php does NOT go through
		// wp-admin/admin.php which calls set_current_screen()), so the native handler
		// unreliably calls wp_die(0) — silently discarding the user's column preference.
		//
		// Fix: intercept the 'hidden-columns' AJAX action at priority 1 (WP core registers
		// its handler at the default priority 10).  We save directly to user_meta using the
		// same key that get_hidden_columns($screen) reads, then wp_die(1) so the request
		// terminates before the broken core handler runs.  For every other admin page we
		// return early so core's handler is unaffected.
		add_action( 'wp_ajax_hidden-columns', array( $this, 'ajax_save_hidden_columns' ), 1 );
	}

	/**
	 * Process a single-row Retry Now form submission early (admin_init, before headers).
	 *
	 * Uses Post/Redirect/Get: validates, executes retry, stores result in a
	 * short-lived transient, then redirects back to the Transactions screen.
	 * The result notice is picked up in render() on the next GET request.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function maybe_handle_single_retry(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Only act on our admin page / tab.
		if ( wc_kledo_get_requested_value( 'page' ) !== WC_Kledo_Admin::PAGE_ID ) {
			return;
		}

		if ( wc_kledo_get_requested_value( 'tab' ) !== self::ID ) {
			return;
		}

		// Single-row retry: wc_kledo_failed_key present but bulk action button NOT submitted.
		if ( ! isset( $_POST['wc_kledo_failed_key'] ) || isset( $_POST['wc_kledo_bulk_retry_failed'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'wc_kledo_retry_failed_transaction' );

		$raw_key   = wp_unslash( $_POST['wc_kledo_failed_key'] );
		$queue_key = is_string( $raw_key ) ? sanitize_text_field( $raw_key ) : '';

		wc_kledo_log_info(
			sprintf(
				'Kledo Transactions single manual retry start: user_id=%d key=%s',
				get_current_user_id(),
				$queue_key
			)
		);

		$outcome = self::RETRY_OUTCOME_INVALID_KEY;

		try {
			if ( '' !== $queue_key ) {
				$outcome = $this->execute_manual_retry_for_key( $queue_key );
			}
		} catch ( Throwable $e ) {
			$outcome = 'exception';

			wc_kledo_log_warning(
				sprintf(
					'Kledo Transactions single retry exception: key=%s message=%s',
					$queue_key,
					$e->getMessage()
				)
			);
		}

		wc_kledo_log_info(
			sprintf(
				'Kledo Transactions single manual retry end: key=%s outcome=%s',
				$queue_key,
				$outcome
			)
		);

		switch ( $outcome ) {
			case self::RETRY_OUTCOME_SUCCESS:
				$notice_class = 'notice-success';
				$notice_text  = __( 'Retry succeeded. The transaction was sent to Kledo successfully.', WC_KLEDO_TEXT_DOMAIN );
				break;
			case self::RETRY_OUTCOME_SKIPPED_SYNCED:
				$notice_class = 'notice-success';
				$notice_text  = __( 'Retry skipped: the transaction was already synced. The stale queue row has been removed.', WC_KLEDO_TEXT_DOMAIN );
				break;
			case self::RETRY_OUTCOME_ORDER_MISSING:
				$notice_class = 'notice-warning';
				$notice_text  = __( 'Retry skipped: the associated WooCommerce order no longer exists. The queue row has been removed.', WC_KLEDO_TEXT_DOMAIN );
				break;
			case self::RETRY_OUTCOME_FAILED:
				$notice_class = 'notice-error';
				$notice_text  = __( 'Retry failed. The API returned an error. The transaction will be retried automatically by the next cron run.', WC_KLEDO_TEXT_DOMAIN );
				break;
			case self::RETRY_OUTCOME_INVALID_KEY:
			case self::RETRY_OUTCOME_NOT_IN_QUEUE:
			case self::RETRY_OUTCOME_BAD_ITEM:
				$notice_class = 'notice-warning';
				$notice_text  = __( 'Retry skipped: the selected transaction row is no longer in the queue or has an invalid format.', WC_KLEDO_TEXT_DOMAIN );
				break;
			default:
				$notice_class = 'notice-error';
				$notice_text  = __( 'An unexpected error occurred during retry. Please check the WooCommerce logs for details.', WC_KLEDO_TEXT_DOMAIN );
		}

		// Store notice in a short-lived transient for display after redirect.
		set_transient(
			self::RETRY_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'class' => $notice_class,
				'text'  => $notice_text,
			),
			2 * MINUTE_IN_SECONDS
		);

		$redirect_url = $this->get_transactions_screen_url( $this->get_redirect_args_from_post() );

		wc_kledo_log_info(
			sprintf( 'Kledo Transactions single retry redirect: %s', $redirect_url )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Process a bulk Retry Selected form submission early (admin_init, before headers).
	 *
	 * Uses Post/Redirect/Get: validates, executes retry for each selected key, stores
	 * a summary notice in a short-lived transient, then redirects back to the Transactions
	 * screen. The result notice is picked up in render() on the next GET request.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function maybe_handle_bulk_retry(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Only act on our admin page / tab.
		if ( wc_kledo_get_requested_value( 'page' ) !== WC_Kledo_Admin::PAGE_ID ) {
			return;
		}

		if ( wc_kledo_get_requested_value( 'tab' ) !== self::ID ) {
			return;
		}

		// Bulk retry: Apply button submitted with wc_kledo_bulk_retry_failed=1.
		if ( empty( $_POST['wc_kledo_bulk_retry_failed'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'wc_kledo_retry_failed_transaction' );

		$bulk_action = isset( $_POST['wc_kledo_failed_bulk_action'] )
			? sanitize_text_field( wp_unslash( $_POST['wc_kledo_failed_bulk_action'] ) )
			: '';

		// Wrong action selected: redirect with warning, do not process.
		if ( 'retry_selected' !== $bulk_action ) {
			set_transient(
				self::BULK_RETRY_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'class' => 'notice-warning',
					'text'  => __( 'Choose the bulk action "Retry selected" before applying.', WC_KLEDO_TEXT_DOMAIN ),
				),
				2 * MINUTE_IN_SECONDS
			);

			wp_safe_redirect( $this->get_transactions_screen_url( $this->get_redirect_args_from_post() ) );
			exit;
		}

		$posted_keys = array();

		if ( isset( $_POST['wc_kledo_failed_keys'] ) && is_array( $_POST['wc_kledo_failed_keys'] ) ) {
			$posted_keys = array_map( 'sanitize_text_field', wp_unslash( $_POST['wc_kledo_failed_keys'] ) );
		}

		$posted_keys = array_values( array_unique( array_filter( $posted_keys, 'strlen' ) ) );

		// Nothing selected: redirect with warning.
		if ( empty( $posted_keys ) ) {
			wc_kledo_log_info(
				sprintf(
					'Kledo Transactions bulk manual retry: no rows selected user_id=%d',
					get_current_user_id()
				)
			);

			set_transient(
				self::BULK_RETRY_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'class' => 'notice-warning',
					'text'  => __( 'No failed transactions were selected. Choose one or more rows and try again.', WC_KLEDO_TEXT_DOMAIN ),
				),
				2 * MINUTE_IN_SECONDS
			);

			wp_safe_redirect( $this->get_transactions_screen_url( $this->get_redirect_args_from_post() ) );
			exit;
		}

		wc_kledo_log_info(
			sprintf(
				'Kledo Transactions bulk manual retry start: user_id=%d count=%d',
				get_current_user_id(),
				count( $posted_keys )
			)
		);

		$counts = array(
			'selected' => count( $posted_keys ),
			'ok'       => 0,
			'failed'   => 0,
			'invalid'  => 0,
		);

		foreach ( $posted_keys as $queue_key ) {
			try {
				$outcome = $this->execute_manual_retry_for_key( $queue_key );
			} catch ( Throwable $e ) {
				wc_kledo_log_warning(
					sprintf(
						'Kledo Transactions bulk retry exception: key=%s message=%s',
						$queue_key,
						$e->getMessage()
					)
				);

				$outcome = 'exception';
			}

			if ( self::RETRY_OUTCOME_SUCCESS === $outcome || self::RETRY_OUTCOME_SKIPPED_SYNCED === $outcome || self::RETRY_OUTCOME_ORDER_MISSING === $outcome ) {
				++ $counts['ok'];
			} elseif ( self::RETRY_OUTCOME_FAILED === $outcome ) {
				++ $counts['failed'];
			} else {
				++ $counts['invalid'];
			}
		}

		wc_kledo_log_info(
			sprintf(
				'Kledo Transactions bulk manual retry end: user_id=%d selected=%d ok=%d failed=%d invalid_or_skipped=%d',
				get_current_user_id(),
				$counts['selected'],
				$counts['ok'],
				$counts['failed'],
				$counts['invalid']
			)
		);

		$notice_class = ( $counts['failed'] > 0 || $counts['invalid'] > 0 ) ? 'notice-warning' : 'notice-success';
		$notice_text  = sprintf(
		/* translators: 1: selected count, 2: succeeded count, 3: failed count, 4: invalid/skipped count */
			__( 'Bulk retry finished. Selected: %1$d. Succeeded: %2$d. Failed: %3$d. Not processed (invalid or missing row): %4$d.', WC_KLEDO_TEXT_DOMAIN ),
			$counts['selected'],
			$counts['ok'],
			$counts['failed'],
			$counts['invalid']
		);

		set_transient(
			self::BULK_RETRY_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'class' => $notice_class,
				'text'  => $notice_text,
			),
			2 * MINUTE_IN_SECONDS
		);

		$redirect_url = $this->get_transactions_screen_url( $this->get_redirect_args_from_post() );

		wc_kledo_log_info(
			sprintf( 'Kledo Transactions bulk retry redirect: %s', $redirect_url )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Build redirect query args from the current POST state (filter/sort/page).
	 *
	 * Used by both single and bulk retry handlers to preserve the admin's view state
	 * across the PRG redirect.
	 *
	 * @return array<string, scalar>
	 * @since 1.7.0
	 */
	private function get_redirect_args_from_post(): array {
		$out = array(
			self::QUERY_STATUS  => sanitize_key( wp_unslash( $_POST[ self::QUERY_STATUS ] ?? self::STATUS_ALL ) ),
			self::QUERY_ORDERBY => sanitize_key( wp_unslash( $_POST[ self::QUERY_ORDERBY ] ?? 'created' ) ),
			self::QUERY_ORDER   => sanitize_key( wp_unslash( $_POST[ self::QUERY_ORDER ] ?? 'desc' ) ),
			self::QUERY_PAGED   => max( 1, absint( $_POST[ self::QUERY_PAGED ] ?? '1' ) ),
		);

		$adv_param_keys = array(
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_ATTEMPTS,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_CREATED_DATE,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_NEXT_RETRY_DATE,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_LAST_ERROR,
		);

		foreach ( $adv_param_keys as $pk ) {
			if ( ! isset( $_POST[ $pk ] ) || '' === $_POST[ $pk ] ) {
				continue;
			}
			if ( WC_Kledo_Admin_Table_Filter_Handler::PARAM_ATTEMPTS === $pk ) {
				$out[ $pk ] = (string) max( 0, absint( wp_unslash( $_POST[ $pk ] ) ) );
				continue;
			}
			$out[ $pk ] = sanitize_text_field( wp_unslash( $_POST[ $pk ] ) );
		}

		return $out;
	}

	/**
	 * Gets the screen settings.
	 *
	 * This screen does not use the standard WooCommerce settings API fields.
	 * We only render a custom table in render().
	 *
	 * @return array
	 * @since 1.5.0
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * Render the transactions table.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission to view this page.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		$filter_handler = new WC_Kledo_Admin_Table_Filter_Handler( array( 'screen' => 'wc-kledo-transactions' ) );
		$filter_handler->fire_before_display();

		$inline_notices = array();

		// Read one-shot notice from single-row retry (PRG: set in maybe_handle_single_retry(), consumed here).
		$single_transient_key = self::RETRY_NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$single_notice        = get_transient( $single_transient_key );

		if ( is_array( $single_notice ) && ! empty( $single_notice['class'] ) && ! empty( $single_notice['text'] ) ) {
			delete_transient( $single_transient_key );
			$inline_notices[] = $single_notice;
		}

		// Read one-shot notice from bulk retry (PRG: set in maybe_handle_bulk_retry(), consumed here).
		$bulk_transient_key = self::BULK_RETRY_NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$bulk_notice        = get_transient( $bulk_transient_key );

		if ( is_array( $bulk_notice ) && ! empty( $bulk_notice['class'] ) && ! empty( $bulk_notice['text'] ) ) {
			delete_transient( $bulk_transient_key );
			$inline_notices[] = $bulk_notice;
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$req = $this->get_transaction_request_args();

		// Resolve which columns the current user has opted to hide via Screen Options.
		// get_hidden_columns() reads from user meta (managewoocommerce_page_wc-kledocolumnshidden).
		// On first visit (no saved meta), all columns are visible by default.
		// WordPress' wp_ajax_hidden-columns handler persists user changes automatically.
		//
		// IMPORTANT DOM CONTRACT: WP core's common.js saves preferences by scanning
		//   $( '.manage-column[id]' ).filter( ':hidden' ).map( function () { return this.id; } )
		// which means every hideable <th> MUST carry an `id="<column_key>"` attribute
		// (matching WP_List_Table::print_column_headers()). Without the id, WP posts an
		// empty `hidden` list and the user's choice is silently discarded on refresh.
		$wp_screen      = get_current_screen();
		$hidden_columns = $wp_screen ? get_hidden_columns( $wp_screen ) : array();

		// Returns the CSS classes for a hideable <th> or <td>.
		$col_class = static function ( string $key ) use ( $hidden_columns ): string {
			return ' column-' . $key . ( in_array( $key, $hidden_columns, true ) ? ' hidden' : '' );
		};

		// Always-visible columns (cb=1, order=1, status=1, actions=1) + up to 5 hideable ones.
		$hideable_keys = array( 'type', 'attempts', 'created', 'next_retry', 'last_error' );
		$colspan       = 9 - count( array_intersect( $hideable_keys, $hidden_columns ) );

		$failed_rows = $this->build_failed_rows_from_queue( $queue );
		$scan        = $this->build_success_rows_from_orders( array_keys( $queue ), $this->get_max_orders_for_success_scan() );

		$success_rows           = $scan['rows'];
		$success_scan_truncated = ! empty( $scan['truncated'] );

		$filtered = $this->merge_and_filter_rows( $failed_rows, $success_rows, $req['status'] );
		$filtered = $this->apply_advanced_filters( $filtered, $req['adv'] );
		$this->sort_rows( $filtered, $req['orderby'], $req['order'] );

		$per_page   = $this->get_items_per_page();
		$total_rows = count( $filtered );
		$offset     = ( max( 1, $req['paged'] ) - 1 ) * $per_page;
		$page_rows  = array_slice( $filtered, $offset, $per_page );

		$order_ids = array();

		foreach ( $page_rows as $r ) {
			if ( ! empty( $r['order_id'] ) ) {
				$order_ids[] = (int) $r['order_id'];
			}
		}

		$orders_by_id = $this->load_orders_for_queue( array_values( array_unique( $order_ids ) ) );

		$nav_counts = $this->build_nav_counts( $failed_rows, $success_rows, $queue, $success_scan_truncated );

		foreach ( $inline_notices as $notice ) {
			printf(
				'<div class="notice %1$s"><p>%2$s</p></div>',
				esc_attr( $notice['class'] ),
				esc_html( $notice['text'] )
			);
		}

		if ( $success_scan_truncated && in_array( $req['status'], array(
				self::STATUS_ALL,
				self::STATUS_SUCCESS,
			), true )
		) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
					/* translators: %d: max number of recent orders scanned for success rows */
						__( 'Success list is built from the %d most recently modified orders that have Kledo sync meta. Older successes may not appear; use filters or order search if you need a specific order.', WC_KLEDO_TEXT_DOMAIN ),
						$this->get_max_orders_for_success_scan()
					)
				)
			);
		}

		$hidden_args = array_merge(
			array(
				'page'              => WC_Kledo_Admin::PAGE_ID,
				'tab'               => self::ID,
				self::QUERY_STATUS  => $req['status'],
				self::QUERY_ORDERBY => $req['orderby'],
				self::QUERY_ORDER   => $req['order'],
				self::QUERY_PAGED   => $req['paged'],
			),
			$this->adv_filters_to_query_args( $req['adv'] )
		);

		?>
		<ul class="subsubsub" style="float:none;margin:0 0 12px;">
			<?php echo wp_kses_post( $this->get_status_subsubsub_html( $nav_counts, $req ) ); ?>
		</ul>

		<div class="wc-kledo-tx-toolbar" style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;padding:12px 16px;margin:0 0 12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-sizing:border-box;">
			<div class="wc-kledo-tx-bulkactions alignleft actions bulkactions" style="margin:0;flex:0 0 auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
				<label for="wc-kledo-tx-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk actions', WC_KLEDO_TEXT_DOMAIN ); ?></label>
				<select name="wc_kledo_failed_bulk_action" id="wc-kledo-tx-bulk-action" form="wc-kledo-transactions-form">
					<option value=""><?php esc_html_e( 'Bulk actions', WC_KLEDO_TEXT_DOMAIN ); ?></option>
					<option value="retry_selected"><?php esc_html_e( 'Retry selected', WC_KLEDO_TEXT_DOMAIN ); ?></option>
				</select>
				<button type="submit" form="wc-kledo-transactions-form" name="wc_kledo_bulk_retry_failed" value="1" class="button action"><?php esc_html_e( 'Apply', WC_KLEDO_TEXT_DOMAIN ); ?></button>
			</div>
			<?php $this->render_transactions_advanced_filter_form( $req, $failed_rows, $success_rows ); ?>
		</div>

        <form method="post" class="wc-kledo-transactions-form" id="wc-kledo-transactions-form" action="<?php
		echo esc_url( $this->get_transactions_screen_url() ); ?>">
			<?php
			wp_nonce_field( 'wc_kledo_retry_failed_transaction' ); ?>
			<?php
			foreach ( $hidden_args as $hk => $hv ) : ?>
                <input type="hidden" name="<?php
				echo esc_attr( $hk ); ?>" value="<?php
				echo esc_attr( (string) $hv ); ?>"/>
			<?php
			endforeach; ?>

            <table class="wp-list-table widefat fixed striped wc-kledo-transactions-table">
                <thead>
                    <tr>
                        <th id="cb" scope="col" class="manage-column column-cb check-column">
                            <span class="wc-kledo-tx-th-cb-inner">
                                <input type="checkbox" id="wc-kledo-tx-select-all" aria-label="<?php
								esc_attr_e( 'Select all retryable rows', WC_KLEDO_TEXT_DOMAIN ); ?>"/>
                            </span>
                        </th>
                        <th id="order" scope="col" class="manage-column column-order <?php echo esc_attr( $this->get_sortable_th_class( 'order', $req ) ); ?>"><?php
							echo $this->get_sortable_column_header( __( 'Order', WC_KLEDO_TEXT_DOMAIN ), 'order', $req ); ?></th>
                        <th id="type" scope="col" class="manage-column<?php
						echo esc_attr( $col_class( 'type' ) ); ?> <?php echo esc_attr( $this->get_sortable_th_class( 'type', $req ) ); ?>"><?php
							echo $this->get_sortable_column_header( __( 'Type', WC_KLEDO_TEXT_DOMAIN ), 'type', $req ); ?></th>
                        <th id="status" scope="col" class="manage-column column-status <?php echo esc_attr( $this->get_sortable_th_class( 'status', $req ) ); ?>"><?php
							echo $this->get_sortable_column_header( __( 'Status', WC_KLEDO_TEXT_DOMAIN ), 'status', $req ); ?></th>
                        <th id="attempts" scope="col" class="manage-column<?php
						echo esc_attr( $col_class( 'attempts' ) ); ?>"><?php
							esc_html_e( 'Attempts', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                        <th id="created" scope="col" class="manage-column<?php
						echo esc_attr( $col_class( 'created' ) ); ?> <?php echo esc_attr( $this->get_sortable_th_class( 'created', $req ) ); ?>"><?php
							echo $this->get_sortable_column_header( __( 'Created At', WC_KLEDO_TEXT_DOMAIN ), 'created', $req ); ?></th>
                        <th id="next_retry" scope="col" class="manage-column<?php
						echo esc_attr( $col_class( 'next_retry' ) ); ?> <?php echo esc_attr( $this->get_sortable_th_class( 'next_retry', $req ) ); ?>"><?php
							echo $this->get_sortable_column_header( __( 'Next Retry', WC_KLEDO_TEXT_DOMAIN ), 'next_retry', $req ); ?></th>
                        <th id="last_error" scope="col" class="manage-column<?php
						echo esc_attr( $col_class( 'last_error' ) ); ?>"><?php
							esc_html_e( 'Last Error', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                        <th id="actions" scope="col" class="manage-column column-actions"><?php
							esc_html_e( 'Actions', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                    </tr>
                </thead>
                <tbody>
					<?php
					if ( empty( $page_rows ) ) : ?>
                        <tr>
                            <td colspan="<?php
							echo esc_attr( (string) $colspan ); ?>"><?php
								esc_html_e( 'No transactions match the current filter.', WC_KLEDO_TEXT_DOMAIN ); ?></td>
                        </tr>
					<?php
					else : ?>
						<?php
						foreach ( $page_rows as $row ) : ?>
							<?php
							$order_id   = (int) ( $row['order_id'] ?? 0 );
							$type       = (string) ( $row['type'] ?? '' );
							$qkey       = isset( $row['queue_key'] ) ? (string) $row['queue_key'] : '';
							$attempts   = isset( $row['attempts'] ) ? (int) $row['attempts'] : 0;
							$created    = isset( $row['created_at'] ) ? (int) $row['created_at'] : 0;
							$next_run   = isset( $row['next_run_at'] ) ? (int) $row['next_run_at'] : 0;
							$last_error = (string) ( $row['last_error'] ?? '' );
							$dstatus    = (string) ( $row['display_status'] ?? '' );

							$order_obj  = ( $order_id && isset( $orders_by_id[ $order_id ] ) ) ? $orders_by_id[ $order_id ] : null;
							$order_cell = $this->format_order_identifier_cell( $order_id, $order_obj, $qkey ? $qkey : ( $type . ':' . $order_id ) );

							$status_label = $this->get_display_status_label( $dstatus );
							$can_retry    = ( 'queue' === ( $row['source'] ?? '' ) ) && '' !== $qkey;
							?>
                            <tr>
                                <th scope="row" class="check-column">
									<?php
									if ( $can_retry ) : ?>
                                        <input type="checkbox" name="wc_kledo_failed_keys[]" value="<?php
										echo esc_attr( $qkey ); ?>"/>
									<?php
									else : ?>
                                        <span class="wc-kledo-tx-no-cb" aria-hidden="true">&mdash;</span>
									<?php
									endif; ?>
                                </th>
                                <td class="column-order"><?php
									echo wp_kses_post( $order_cell ); ?></td>
                                <td class="<?php
								echo esc_attr( ltrim( $col_class( 'type' ) ) ); ?>"><?php
									echo esc_html( $type ); ?></td>
                                <td class="column-status"><?php
									echo esc_html( $status_label ); ?></td>
                                <td class="<?php
								echo esc_attr( ltrim( $col_class( 'attempts' ) ) ); ?>"><?php
									echo 'queue' === ( $row['source'] ?? '' ) ? esc_html( (string) $attempts ) : '&mdash;'; ?></td>
                                <td class="<?php
								echo esc_attr( ltrim( $col_class( 'created' ) ) ); ?>"><?php
									echo esc_html( wc_kledo_format_admin_timestamp( $created, 'past' ) ); ?></td>
                                <td class="<?php
								echo esc_attr( ltrim( $col_class( 'next_retry' ) ) ); ?>"><?php
									echo 'queue' === ( $row['source'] ?? '' ) ? esc_html( wc_kledo_format_admin_timestamp( $next_run, 'future' ) ) : '&mdash;'; ?></td>
                                <td class="<?php
								echo esc_attr( ltrim( $col_class( 'last_error' ) ) ); ?>"><?php
									echo wp_kses_post( $this->format_last_error_cell( $last_error ) ); ?></td>
                                <td class="column-actions">
									<?php
									if ( $can_retry ) : ?>
                                        <button type="submit" class="button" name="wc_kledo_failed_key" value="<?php
										echo esc_attr( $qkey ); ?>">
											<?php
											esc_html_e( 'Retry now', WC_KLEDO_TEXT_DOMAIN ); ?>
                                        </button>
									<?php
									else : ?>
                                        &mdash;
									<?php
									endif; ?>
                                </td>
                            </tr>
						<?php
						endforeach; ?>
					<?php
					endif; ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
				<?php
				$this->render_pagination_controls( $total_rows, $req, $per_page, 'bottom' ); ?>
                <br class="clear"/>
            </div>
        </form>
        <script>
            (function () {
                let master = document.getElementById('wc-kledo-tx-select-all');
                let form = document.getElementById('wc-kledo-transactions-form');
                if (!master || !form) {
                    return;
                }
                master.addEventListener('change', function () {
                    let boxes = form.querySelectorAll('input[name="wc_kledo_failed_keys[]"]');
                    for (let i = 0; i < boxes.length; i++) {
                        boxes[i].checked = master.checked;
                    }
                });
            })();
        </script>
		<?php
	}

	/**
	 * GET args driving the transactions list (server-side filter, sort, pagination).
	 *
	 * @return array{status:string,orderby:string,order:string,paged:int,adv:array<string, mixed>}
	 */
	private function get_transaction_request_args(): array {
		$src = $_REQUEST;

		$status = isset( $src[ self::QUERY_STATUS ] )
			? sanitize_key( wp_unslash( $src[ self::QUERY_STATUS ] ) )
			: self::STATUS_ALL;

		$allowed_status = array( self::STATUS_ALL, self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_RETRYING );

		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = self::STATUS_ALL;
		}

		$orderby = isset( $src[ self::QUERY_ORDERBY ] )
			? sanitize_key( wp_unslash( $src[ self::QUERY_ORDERBY ] ) )
			: 'created';

		$allowed_orderby = array( 'created', 'order', 'type', 'status', 'next_retry' );

		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created';
		}

		$order = isset( $src[ self::QUERY_ORDER ] )
			? strtolower( sanitize_text_field( wp_unslash( $src[ self::QUERY_ORDER ] ) ) )
			: 'desc';

		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'desc';
		}

		$paged = isset( $src[ self::QUERY_PAGED ] ) ? absint( $src[ self::QUERY_PAGED ] ) : 1;

		if ( $paged < 1 ) {
			$paged = 1;
		}

		$adv = $this->parse_advanced_filters_from_request();

		return array(
			'status'  => $status,
			'orderby' => $orderby,
			'order'   => $order,
			'paged'   => $paged,
			'adv'     => $adv,
		);
	}

	/**
	 * Parses advanced filter query args using the shared sanitizer.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_advanced_filters_from_request(): array {
		$param_keys = array(
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_ATTEMPTS,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_CREATED_DATE,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_NEXT_RETRY_DATE,
			WC_Kledo_Admin_Table_Filter_Handler::PARAM_LAST_ERROR,
		);

		$subset = array();

		foreach ( $param_keys as $pk ) {
			if ( isset( $_REQUEST[ $pk ] ) && '' !== $_REQUEST[ $pk ] ) {
				$subset[ $pk ] = wp_unslash( $_REQUEST[ $pk ] );
			}
		}

		$handler = new WC_Kledo_Admin_Table_Filter_Handler( array( 'screen' => 'wc-kledo-transactions' ) );

		return $handler->parse_from_array( $subset );
	}

	/**
	 * Maps sanitized advanced filters to public query parameter names for URLs and hidden fields.
	 *
	 * @param array<string, mixed> $adv Sanitized advanced filters.
	 *
	 * @return array<string, scalar>
	 */
	private function adv_filters_to_query_args( array $adv ): array {
		$map = array(
			'type'            => WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE,
			'attempts'        => WC_Kledo_Admin_Table_Filter_Handler::PARAM_ATTEMPTS,
			'created_date'    => WC_Kledo_Admin_Table_Filter_Handler::PARAM_CREATED_DATE,
			'next_retry_date' => WC_Kledo_Admin_Table_Filter_Handler::PARAM_NEXT_RETRY_DATE,
			'last_error'      => WC_Kledo_Admin_Table_Filter_Handler::PARAM_LAST_ERROR,
		);

		$out = array();

		foreach ( $map as $fk => $qk ) {
			if ( isset( $adv[ $fk ] ) && '' !== $adv[ $fk ] && null !== $adv[ $fk ] ) {
				$out[ $qk ] = is_scalar( $adv[ $fk ] ) ? $adv[ $fk ] : '';
			}
		}

		return $out;
	}

	/**
	 * Builds stable query args for list URLs (status, sort, pagination, advanced filters).
	 *
	 * @param array<string, mixed> $req Request bundle from {@see self::get_transaction_request_args()}.
	 *
	 * @return array<string, scalar>
	 */
	private function get_transactions_list_base_url_args( array $req ): array {
		return array_merge(
			array(
				self::QUERY_STATUS  => $req['status'],
				self::QUERY_ORDERBY => $req['orderby'],
				self::QUERY_ORDER   => $req['order'],
				self::QUERY_PAGED   => $req['paged'],
			),
			$this->adv_filters_to_query_args( $req['adv'] ?? array() )
		);
	}

	/**
	 * Converts a calendar day (Y-m-d) to a Unix range boundary in the site timezone.
	 *
	 * @param string $ymd   Date string.
	 * @param bool   $end   True for end-of-day, false for start-of-day.
	 *
	 * @return int 0 when invalid.
	 */
	private function get_day_boundary_timestamp( string $ymd, bool $end ): int {
		if ( '' === $ymd || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return 0;
		}

		$tz = wp_timezone();
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $ymd, $tz );

		if ( ! $dt instanceof \DateTimeImmutable ) {
			return 0;
		}

		if ( $end ) {
			$dt = $dt->setTime( 23, 59, 59 );
		} else {
			$dt = $dt->setTime( 0, 0, 0 );
		}

		return $dt->getTimestamp();
	}

	/**
	 * Filters merged rows by type, attempts, date windows, and last error substring.
	 *
	 * @param array<int, array<string, mixed>> $rows Merged rows after status filter.
	 * @param array<string, mixed>             $adv  Sanitized advanced filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_advanced_filters( array $rows, array $adv ): array {
		if ( empty( $adv ) ) {
			return $rows;
		}

		$type_filter = isset( $adv['type'] ) ? (string) $adv['type'] : '';

		$has_attempts = isset( $adv['attempts'] );
		$exact_attempts = $has_attempts ? (int) $adv['attempts'] : 0;

		$has_created = ! empty( $adv['created_date'] );
		$c_from      = $has_created ? $this->get_day_boundary_timestamp( (string) $adv['created_date'], false ) : 0;
		$c_to        = $has_created ? $this->get_day_boundary_timestamp( (string) $adv['created_date'], true ) : 0;

		$has_next = ! empty( $adv['next_retry_date'] );
		$n_from   = $has_next ? $this->get_day_boundary_timestamp( (string) $adv['next_retry_date'], false ) : 0;
		$n_to     = $has_next ? $this->get_day_boundary_timestamp( (string) $adv['next_retry_date'], true ) : 0;

		$err_q = isset( $adv['last_error'] ) ? (string) $adv['last_error'] : '';

		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $type_filter, $has_attempts, $exact_attempts, $has_created, $c_from, $c_to, $has_next, $n_from, $n_to, $err_q ) {
					if ( ! is_array( $row ) ) {
						return false;
					}

					if ( '' !== $type_filter && (string) ( $row['type'] ?? '' ) !== $type_filter ) {
						return false;
					}

					if ( $has_attempts ) {
						if ( 'queue' !== ( $row['source'] ?? '' ) ) {
							return false;
						}

						$a = (int) ( $row['attempts'] ?? 0 );

						if ( $a !== $exact_attempts ) {
							return false;
						}
					}

					if ( $has_created ) {
						$c = (int) ( $row['created_at'] ?? 0 );

						if ( $c <= 0 ) {
							return false;
						}

						if ( $c_from > 0 && $c < $c_from ) {
							return false;
						}

						if ( $c_to > 0 && $c > $c_to ) {
							return false;
						}
					}

					if ( $has_next ) {
						if ( 'queue' !== ( $row['source'] ?? '' ) ) {
							return false;
						}

						$n = (int) ( $row['next_run_at'] ?? 0 );

						if ( $n <= 0 ) {
							return false;
						}

						if ( $n_from > 0 && $n < $n_from ) {
							return false;
						}

						if ( $n_to > 0 && $n > $n_to ) {
							return false;
						}
					}

					if ( '' !== $err_q ) {
						$le = (string) ( $row['last_error'] ?? '' );

						if ( '' === $le || false === stripos( $le, $err_q ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Renders the GET filter bar (separate from bulk/retry POST form).
	 *
	 * @param array<string, mixed>               $req          Current request bundle.
	 * @param array<int, array<string, mixed>> $failed_rows  Failed queue rows (for type list).
	 * @param array<int, array<string, mixed>> $success_rows Success rows (for type list).
	 *
	 * @return void
	 */
	private function render_transactions_advanced_filter_form(
		array $req,
		array $failed_rows,
		array $success_rows
	): void {
		$adv = $req['adv'] ?? array();

		$types = $this->collect_distinct_transaction_types( $failed_rows, $success_rows );

		$type_val = isset( $adv['type'] ) ? (string) $adv['type'] : '';
		$attempts_val = isset( $adv['attempts'] ) ? (int) $adv['attempts'] : '';
		$created_date_val    = isset( $adv['created_date'] ) ? (string) $adv['created_date'] : '';
		$next_retry_date_val = isset( $adv['next_retry_date'] ) ? (string) $adv['next_retry_date'] : '';
		$err      = isset( $adv['last_error'] ) ? (string) $adv['last_error'] : '';

		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wc-kledo-transactions-adv-filters" style="margin:0;flex:1 1 260px;min-width:min(100%,200px);box-sizing:border-box;">
			<input type="hidden" name="page" value="<?php echo esc_attr( WC_Kledo_Admin::PAGE_ID ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::ID ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_STATUS ); ?>" value="<?php echo esc_attr( $req['status'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_ORDERBY ); ?>" value="<?php echo esc_attr( $req['orderby'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_ORDER ); ?>" value="<?php echo esc_attr( $req['order'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_PAGED ); ?>" value="1" />

			<span class="wc-kledo-tx-filter-fields" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
				<label for="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE ); ?>" class="screen-reader-text"><?php esc_html_e( 'Transaction type', WC_KLEDO_TEXT_DOMAIN ); ?></label>
				<select name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE ); ?>" id="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_TYPE ); ?>">
					<option value=""><?php esc_html_e( 'All types', WC_KLEDO_TEXT_DOMAIN ); ?></option>
					<?php foreach ( $types as $tv ) : ?>
						<option value="<?php echo esc_attr( $tv ); ?>" <?php selected( $type_val, $tv ); ?>><?php echo esc_html( $tv ); ?></option>
					<?php endforeach; ?>
				</select>

				<input type="number" min="0" step="1" style="width:6em;" name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_ATTEMPTS ); ?>" value="<?php echo esc_attr( '' !== $attempts_val ? (string) $attempts_val : '' ); ?>" placeholder="<?php esc_attr_e( 'Attempts (exact)', WC_KLEDO_TEXT_DOMAIN ); ?>" title="<?php esc_attr_e( 'Column Attempts: exact count for queue rows only. Leave empty to ignore.', WC_KLEDO_TEXT_DOMAIN ); ?>" />

				<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;">
					<label for="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_CREATED_DATE ); ?>" style="margin:0;">
						<?php esc_html_e( 'Created At', WC_KLEDO_TEXT_DOMAIN ); ?>
					</label>
					<input type="date" id="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_CREATED_DATE ); ?>" name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_CREATED_DATE ); ?>" value="<?php echo esc_attr( $created_date_val ); ?>" title="<?php esc_attr_e( 'Same column as the table: one calendar day in the site timezone.', WC_KLEDO_TEXT_DOMAIN ); ?>" />
				</span>

				<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;">
					<label for="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_NEXT_RETRY_DATE ); ?>" style="margin:0;">
						<?php esc_html_e( 'Next Retry', WC_KLEDO_TEXT_DOMAIN ); ?>
					</label>
					<input type="date" id="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_NEXT_RETRY_DATE ); ?>" name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_NEXT_RETRY_DATE ); ?>" value="<?php echo esc_attr( $next_retry_date_val ); ?>" title="<?php esc_attr_e( 'Same column as the table: queue rows only, one calendar day in the site timezone.', WC_KLEDO_TEXT_DOMAIN ); ?>" />
				</span>

				<input type="search" class="regular-text" style="max-width:220px;" name="<?php echo esc_attr( WC_Kledo_Admin_Table_Filter_Handler::PARAM_LAST_ERROR ); ?>" value="<?php echo esc_attr( $err ); ?>" placeholder="<?php esc_attr_e( 'Last error contains…', WC_KLEDO_TEXT_DOMAIN ); ?>" />

				<?php submit_button( __( 'Filter', WC_KLEDO_TEXT_DOMAIN ), 'secondary', 'wc_kledo_tx_adv_filter', false ); ?>
			</span>
		</form>
		<?php
	}

	/**
	 * Collects distinct non-empty type strings for the filter dropdown.
	 *
	 * @param array<int, array<string, mixed>> $failed_rows  Failed rows.
	 * @param array<int, array<string, mixed>> $success_rows Success rows.
	 *
	 * @return string[]
	 */
	private function collect_distinct_transaction_types( array $failed_rows, array $success_rows ): array {
		$types = array();

		foreach ( array_merge( $failed_rows, $success_rows ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$t = isset( $row['type'] ) ? (string) $row['type'] : '';

			if ( '' !== $t ) {
				$types[ $t ] = $t;
			}
		}

		sort( $types );

		return array_values( $types );
	}

	/**
	 * Max orders to scan (by modified date) when building success rows.
	 *
	 * @return int
	 */
	private function get_max_orders_for_success_scan(): int {
		/**
		 * Filters how many recent orders are scanned for Kledo success meta on the Transactions screen.
		 *
		 * @param  int  $max_orders  default 2500
		 *
		 * @since 1.6.0
		 */
		$max = (int) apply_filters( 'wc_kledo_transactions_max_orders_for_success_scan', 2500 );

		return max( 50, min( 20000, $max ) );
	}

	/**
	 * Admin URL for this screen preserving standard Kledo query args.
	 *
	 * @param  array<string, scalar>  $args
	 *
	 * @return string
	 */
	private function get_transactions_screen_url( array $args = array() ): string {
		$base = array_merge(
			array(
				'page' => WC_Kledo_Admin::PAGE_ID,
				'tab'  => self::ID,
			),
			$args
		);

		return add_query_arg( $base, admin_url( 'admin.php' ) );
	}

	/**
	 * Normalize failed-queue entries to table rows.
	 *
	 * @param  array<string, array<string, mixed>>  $queue
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_failed_rows_from_queue( array $queue ): array {
		$rows = array();
		$now  = time();

		foreach ( $queue as $key => $item ) {
			if ( ! is_string( $key ) || '' === $key || ! is_array( $item ) ) {
				continue;
			}

			$order_id    = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
			$type        = isset( $item['type'] ) ? (string) $item['type'] : '';
			$created     = isset( $item['created_at'] ) ? (int) $item['created_at'] : 0;
			$next        = isset( $item['next_run_at'] ) ? (int) $item['next_run_at'] : 0;
			$attempts    = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;
			$item_status = isset( $item['status'] ) ? (string) $item['status'] : '';

			// 'failed' = terminal (max attempts/lifetime exhausted); everything else in the
			// queue is still in the automatic retry lifecycle regardless of next_run_at timing.
			$display = ( 'failed' === $item_status ) ? self::STATUS_FAILED : self::STATUS_RETRYING;
			$sort_ts = $created > 0 ? $created : $next;

			if ( $sort_ts <= 0 ) {
				$sort_ts = $now;
			}

			$rows[] = array(
				'source'         => 'queue',
				'queue_key'      => $key,
				'order_id'       => $order_id,
				'type'           => $type,
				'attempts'       => $attempts,
				'created_at'     => $created,
				'next_run_at'    => $next,
				'last_error'     => isset( $item['last_error'] ) ? (string) $item['last_error'] : '',
				'sort_ts'        => $sort_ts,
				'display_status' => $display,
			);
		}

		return $rows;
	}

	/**
	 * Build success rows from recent orders carrying Kledo synced meta.
	 *
	 * Skips (order_id, type) pairs that still exist in the failed queue so the UI does not contradict itself.
	 *
	 * @param  string[]  $queue_keys  Keys currently in the failed option.
	 * @param  int  $max_orders
	 *
	 * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
	 */
	private function build_success_rows_from_orders( array $queue_keys, int $max_orders ): array {
		$queue_lookup = array_fill_keys( $queue_keys, true );
		$rows         = array();

		$orders = wc_get_orders(
			array(
				'limit'      => $max_orders,
				'paginate'   => false,
				'return'     => 'objects',
				'orderby'    => 'modified',
				'order'      => 'DESC',
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => '_wc_kledo_order_synced',
						'value' => 'yes',
					),
					array(
						'key'   => '_wc_kledo_invoice_synced',
						'value' => 'yes',
					),
				),
			)
		);

		if ( ! is_array( $orders ) ) {
			return array(
				'rows'      => array(),
				'truncated' => false,
			);
		}

		$truncated = count( $orders ) >= $max_orders;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$oid = $order->get_id();

			foreach ( array( 'order', 'invoice' ) as $type ) {
				$key = $type . ':' . $oid;

				if ( isset( $queue_lookup[ $key ] ) ) {
					continue;
				}

				if ( ! wc_kledo_is_delivery_synced( $order, $type ) ) {
					continue;
				}

				$modified = $order->get_date_modified();

				if ( $modified ) {
					$sort_ts = $modified->getTimestamp();
				} else {
					$created_dt = $order->get_date_created();
					$sort_ts    = $created_dt ? $created_dt->getTimestamp() : time();
				}

				$rows[] = array(
					'source'         => 'synced',
					'queue_key'      => '',
					'order_id'       => $oid,
					'type'           => $type,
					'attempts'       => null,
					'created_at'     => $sort_ts,
					'next_run_at'    => 0,
					'last_error'     => '',
					'sort_ts'        => $sort_ts,
					'display_status' => self::STATUS_SUCCESS,
				);
			}
		}

		return array(
			'rows'      => $rows,
			'truncated' => $truncated,
		);
	}

	/**
	 * Apply status filter to merged failed + success datasets.
	 *
	 * @param  array<int, array<string, mixed>>  $failed_rows
	 * @param  array<int, array<string, mixed>>  $success_rows
	 * @param  string  $status
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_and_filter_rows( array $failed_rows, array $success_rows, string $status ): array {
		if ( self::STATUS_SUCCESS === $status ) {
			return $success_rows;
		}

		// Terminal failures only.
		if ( self::STATUS_FAILED === $status ) {
			return array_values(
				array_filter(
					$failed_rows,
					static function ( $row ) {
						return self::STATUS_FAILED === ( $row['display_status'] ?? '' );
					}
				)
			);
		}

		// Items still in the automatic retry lifecycle.
		if ( self::STATUS_RETRYING === $status ) {
			return array_values(
				array_filter(
					$failed_rows,
					static function ( $row ) {
						return self::STATUS_RETRYING === ( $row['display_status'] ?? '' );
					}
				)
			);
		}

		return array_merge( $failed_rows, $success_rows );
	}

	/**
	 * Sort rows in place.
	 *
	 * @param  array<int, array<string, mixed>>  $rows
	 * @param  string  $orderby
	 * @param  string  $order
	 *
	 * @return void
	 */
	private function sort_rows( array &$rows, string $orderby, string $order ): void {
		$dir = ( 'asc' === $order ) ? 1 : - 1;

		usort(
			$rows,
			function ( $a, $b ) use ( $orderby, $dir ) {
				if ( 'order' === $orderby ) {
					$va = (int) ( $a['order_id'] ?? 0 );
					$vb = (int) ( $b['order_id'] ?? 0 );
				} elseif ( 'type' === $orderby ) {
					$va = (string) ( $a['type'] ?? '' );
					$vb = (string) ( $b['type'] ?? '' );
				} elseif ( 'status' === $orderby ) {
					$va = (string) ( $a['display_status'] ?? '' );
					$vb = (string) ( $b['display_status'] ?? '' );
				} elseif ( 'next_retry' === $orderby ) {
					return $this->compare_rows_by_next_retry( $a, $b, $dir );
				} else {
					$va = (int) ( $a['sort_ts'] ?? 0 );
					$vb = (int) ( $b['sort_ts'] ?? 0 );
				}

				if ( $va === $vb ) {
					return ( (int) ( $a['order_id'] ?? 0 ) <=> (int) ( $b['order_id'] ?? 0 ) ) * $dir;
				}

				if ( is_int( $va ) && is_int( $vb ) ) {
					return ( $va <=> $vb ) * $dir;
				}

				return $dir * strcmp( (string) $va, (string) $vb );
			}
		);
	}

	/**
	 * Sort bucket for "Next Retry": scheduled queue rows first, then queue without a positive time, then success rows.
	 *
	 * @param array<string, mixed> $row Row from {@see build_failed_rows_from_queue()} or {@see build_success_rows_from_orders()}.
	 *
	 * @return int 0 = queue with scheduled next_run_at, 1 = queue otherwise, 2 = synced (no next retry).
	 */
	private function get_next_retry_sort_bucket( array $row ): int {
		if ( 'synced' === ( $row['source'] ?? '' ) ) {
			return 2;
		}

		$n = (int) ( $row['next_run_at'] ?? 0 );

		if ( 'queue' === ( $row['source'] ?? '' ) && $n > 0 ) {
			return 0;
		}

		return 1;
	}

	/**
	 * Compare two rows by next retry time (queue timestamps; non-queue rows follow in a stable bucket).
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @param int                    $dir 1 = asc, -1 = desc
	 *
	 * @return int
	 */
	private function compare_rows_by_next_retry( array $a, array $b, int $dir ): int {
		$ba = $this->get_next_retry_sort_bucket( $a );
		$bb = $this->get_next_retry_sort_bucket( $b );

		if ( $ba !== $bb ) {
			return $ba <=> $bb;
		}

		if ( 0 === $ba ) {
			$va = (int) ( $a['next_run_at'] ?? 0 );
			$vb = (int) ( $b['next_run_at'] ?? 0 );

			if ( $va !== $vb ) {
				return ( $va <=> $vb ) * $dir;
			}
		}

		return ( (int) ( $a['order_id'] ?? 0 ) <=> (int) ( $b['order_id'] ?? 0 ) ) * $dir;
	}

	/**
	 * Counts for subsubsub navigation (approximate when success scan is capped).
	 *
	 * @param  array<int, array<string, mixed>>  $failed_rows
	 * @param  array<int, array<string, mixed>>  $success_rows
	 * @param  array<string, mixed>  $queue
	 * @param  bool  $success_truncated
	 *
	 * @return array{all:int,success:int,failed:int,retrying:int,queue:int,success_plus:bool}
	 */
	private function build_nav_counts(
		array $failed_rows,
		array $success_rows,
		array $queue,
		bool $success_truncated
	): array {
		$retrying_count = 0;
		$terminal_count = 0;

		foreach ( $failed_rows as $fr ) {
			if ( self::STATUS_RETRYING === ( $fr['display_status'] ?? '' ) ) {
				++ $retrying_count;
			} elseif ( self::STATUS_FAILED === ( $fr['display_status'] ?? '' ) ) {
				++ $terminal_count;
			}
		}

		$success_count = count( $success_rows );
		$queue_count   = count( $queue );
		$all           = $queue_count + $success_count;

		return array(
			'all'          => $all,
			'success'      => $success_count,
			'failed'       => $terminal_count,
			'retrying'     => $retrying_count,
			'queue'        => $queue_count,
			'success_plus' => $success_truncated,
		);
	}

	/**
	 * HTML for status filter links (subsubsub).
	 *
	 * @param array<string, int|bool> $counts Nav counts.
	 * @param array<string, mixed>    $req    Request bundle (preserves advanced filters in links).
	 */
	private function get_status_subsubsub_html( array $counts, array $req ): string {
		$parts = array();
		$base  = array_merge(
			$this->adv_filters_to_query_args( $req['adv'] ?? array() ),
			array(
				self::QUERY_ORDERBY => $req['orderby'],
				self::QUERY_ORDER   => $req['order'],
				self::QUERY_PAGED   => 1,
			)
		);

		$current_status = $req['status'];

		$defs = array(
			self::STATUS_ALL      => __( 'All', WC_KLEDO_TEXT_DOMAIN ),
			self::STATUS_SUCCESS  => __( 'Success', WC_KLEDO_TEXT_DOMAIN ),
			self::STATUS_FAILED   => __( 'Failed', WC_KLEDO_TEXT_DOMAIN ),
			self::STATUS_RETRYING => __( 'Retrying', WC_KLEDO_TEXT_DOMAIN ),
		);

		foreach ( $defs as $st => $label ) {
			if ( self::STATUS_ALL === $st ) {
				$count = (int) ( $counts['all'] ?? 0 );
			} elseif ( self::STATUS_SUCCESS === $st ) {
				$count = (int) ( $counts['success'] ?? 0 );
				if ( ! empty( $counts['success_plus'] ) ) {
					$label = sprintf(
					/* translators: %s: translated word "Success" when the success list may be truncated */
						__( '%s+', WC_KLEDO_TEXT_DOMAIN ),
						__( 'Success', WC_KLEDO_TEXT_DOMAIN )
					);
				}
			} elseif ( self::STATUS_FAILED === $st ) {
				$count = (int) ( $counts['failed'] ?? 0 );
			} else {
				$count = (int) ( $counts['retrying'] ?? 0 );
			}

			$url   = esc_url( $this->get_transactions_screen_url( array_merge( $base, array( self::QUERY_STATUS => $st ) ) ) );
			$class = ( $current_status === $st ) ? ' class="current"' : '';

			$parts[] = sprintf(
				'<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
				$url,
				$class,
				esc_html( $label ),
				$count
			);
		}

		return implode( ' | ', $parts );
	}

	/**
	 * Classes for sortable &lt;th&gt; (matches WP_List_Table so core list-table.css shows arrows).
	 *
	 * @param string               $column_key Orderby key (order, type, status, created, next_retry).
	 * @param array<string, mixed> $req        Request bundle.
	 *
	 * @return string Space-separated class names.
	 */
	private function get_sortable_th_class( string $column_key, array $req ): string {
		$orderby = isset( $req['orderby'] ) ? (string) $req['orderby'] : 'created';
		$order   = isset( $req['order'] ) ? (string) $req['order'] : 'desc';

		if ( $orderby === $column_key ) {
			return 'sorted ' . ( 'asc' === $order ? 'asc' : 'desc' );
		}

		return 'sortable desc';
	}

	/**
	 * Sortable column link markup (Dashicons sort / arrow — clear sort affordance).
	 *
	 * @return string HTML (already escaped).
	 */
	private function get_sortable_column_header( string $label, string $column_key, array $req ): string {
		$current_orderby = $req['orderby'];
		$current_order   = $req['order'];

		$next_order = ( $column_key === $current_orderby && 'desc' === $current_order ) ? 'asc' : 'desc';

		if ( $column_key !== $current_orderby ) {
			$next_order = 'desc';
		}

		$url = esc_url(
			$this->get_transactions_screen_url(
				array_merge(
					$this->adv_filters_to_query_args( $req['adv'] ?? array() ),
					array(
						self::QUERY_STATUS  => $req['status'],
						self::QUERY_ORDERBY => $column_key,
						self::QUERY_ORDER   => $next_order,
						self::QUERY_PAGED   => 1,
					)
				)
			)
		);

		$aria = array();
		if ( $column_key === $current_orderby ) {
			$aria[] = sprintf(
				/* translators: %s: sort direction, ascending or descending */
				__( 'Sorted %s.', WC_KLEDO_TEXT_DOMAIN ),
				'asc' === $current_order ? __( 'ascending', WC_KLEDO_TEXT_DOMAIN ) : __( 'descending', WC_KLEDO_TEXT_DOMAIN )
			);
		} else {
			$aria[] = __( 'Sort by this column.', WC_KLEDO_TEXT_DOMAIN );
		}

		$title = implode( ' ', array_filter( $aria ) );

		$icon_classes = array( 'wc-kledo-tx-sort-icon', 'dashicons' );

		if ( $column_key === $current_orderby ) {
			$icon_classes[] = 'asc' === $current_order ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';
		} else {
			$icon_classes[] = 'dashicons-sort';
		}

		return sprintf(
			'<a href="%s" class="wc-kledo-tx-sort-link"%s><span class="wc-kledo-tx-col-label">%s</span><span class="%s" aria-hidden="true"></span></a>',
			$url,
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			esc_html( $label ),
			esc_attr( implode( ' ', $icon_classes ) )
		);
	}

	/**
	 * Pagination links (top or bottom).
	 *
	 * @param int                  $total    Total number of rows across all pages.
	 * @param array<string, mixed> $req      Request bundle (sort + advanced filters).
	 * @param int                  $per_page Rows per page (from user preference or default).
	 * @param string               $which    Render position: 'top' or 'bottom'.
	 *
	 * @return void
	 */
	private function render_pagination_controls( int $total, array $req, int $per_page, string $which ): void {
		$total_pages = (int) ceil( $total / $per_page );

		if ( $total_pages <= 1 ) {
			return;
		}

		$paged = (int) $req['paged'];

		$base_url = $this->get_transactions_screen_url(
			array_merge(
				$this->adv_filters_to_query_args( $req['adv'] ?? array() ),
				array(
					self::QUERY_STATUS  => $req['status'],
					self::QUERY_ORDERBY => $req['orderby'],
					self::QUERY_ORDER   => $req['order'],
				)
			)
		);

		$paginate_base = esc_url_raw( $base_url . '&' . self::QUERY_PAGED . '=%#%' );

		$links = paginate_links(
			array(
				'base'      => $paginate_base,
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => max( 1, $paged ),
				'type'      => 'plain',
			)
		);

		if ( ! is_string( $links ) || '' === $links ) {
			return;
		}

		printf(
			'<div class="tablenav-pages"><span class="displaying-num">%s</span><span class="pagination-links">%s</span></div>',
			esc_html(
				sprintf(
				/* translators: %d: number of items */
					_n( '%d item', '%d items', $total, WC_KLEDO_TEXT_DOMAIN ),
					$total
				)
			),
			wp_kses_post( $links )
		);
	}

	/**
	 * Human label for display_status.
	 */
	private function get_display_status_label( string $display_status ): string {
		switch ( $display_status ) {
			case self::STATUS_SUCCESS:
				return __( 'Success', WC_KLEDO_TEXT_DOMAIN );
			case self::STATUS_RETRYING:
				return __( 'Retrying', WC_KLEDO_TEXT_DOMAIN );
			case self::STATUS_FAILED:
			default:
				return __( 'Failed', WC_KLEDO_TEXT_DOMAIN );
		}
	}

	/**
	 * Load WooCommerce orders for queue rows in a single query (avoids per-row wc_get_order calls).
	 *
	 * @param  int[]  $order_ids
	 *
	 * @return array<int, WC_Order>
	 */
	private function load_orders_for_queue( array $order_ids ): array {
		$map = array();

		if ( empty( $order_ids ) ) {
			return $map;
		}

		$orders = wc_get_orders(
			array(
				'include'  => $order_ids,
				// Ensure we can resolve trashed orders too; otherwise the UI loses link + name.
				'status'   => array( 'any', 'trash' ),
				'limit'    => count( $order_ids ),
				'paginate' => false,
				'return'   => 'objects',
			)
		);

		if ( ! is_array( $orders ) ) {
			return $map;
		}

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$map[ $order->get_id() ] = $order;
			}
		}

		return $map;
	}

	/**
	 * Build admin-safe HTML for the order column: #ID, optional billing name, link when the order exists.
	 *
	 * @param  int  $order_id
	 * @param  WC_Order|null  $order
	 * @param  string  $queue_key  Option key (e.g. order:62) when order_id is missing.
	 *
	 * @return string
	 */
	private function format_order_identifier_cell( int $order_id, $order, string $queue_key ): string {
		$order_obj = $order instanceof WC_Order ? $order : null;

		// Fallback: if bulk preload missed an order (e.g. status filtering), attempt a single resolve.
		if ( ! $order_obj && $order_id > 0 ) {
			$resolved = wc_get_order( $order_id );
			if ( $resolved instanceof WC_Order ) {
				$order_obj = $resolved;
			}
		}

		if ( $order_obj instanceof WC_Order ) {
			$label = '#' . $order_obj->get_id();
			$name  = '';

			$billing_name = trim( $order_obj->get_formatted_billing_full_name() );
			if ( '' !== $billing_name ) {
				$name = $billing_name;
			} else {
				$shipping_name = trim( $order_obj->get_formatted_shipping_full_name() );
				if ( '' !== $shipping_name ) {
					$name = $shipping_name;
				}
			}

			if ( '' !== $name ) {
				$label .= ' ' . $name;
			}

			$edit_url = $order_obj->get_edit_order_url();

			if ( is_string( $edit_url ) && '' !== $edit_url ) {
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( $edit_url ),
					esc_html( $label )
				);
			}

			return esc_html( $label );
		}

		if ( $order_id > 0 ) {
			return sprintf(
				'<span class="wc-kledo-order-missing" title="%s">%s</span>',
				esc_attr__( 'Order no longer exists.', WC_KLEDO_TEXT_DOMAIN ),
				esc_html( '#' . $order_id . ' (' . __( 'deleted', WC_KLEDO_TEXT_DOMAIN ) . ')' )
			);
		}

		return esc_html( '#' . $queue_key );
	}

	/**
	 * Format the Last Error cell for admin display.
	 *
	 * Messages longer than $max_length characters are truncated with an ellipsis
	 * and the full text is preserved in a title tooltip.
	 *
	 * @param  string  $last_error
	 * @param  int  $max_length  Visible character limit before truncation.
	 *
	 * @return string  HTML, already escaped.
	 * @since 1.7.0
	 */
	private function format_last_error_cell( string $last_error, int $max_length = 120 ): string {
		if ( '' === $last_error ) {
			return '&mdash;';
		}

		if ( mb_strlen( $last_error ) <= $max_length ) {
			return esc_html( $last_error );
		}

		$truncated = mb_substr( $last_error, 0, $max_length - 3 ) . '...';

		return sprintf(
			'<span title="%s" style="cursor:help;">%s</span>',
			esc_attr( $last_error ),
			esc_html( $truncated )
		);
	}

	/**
	 * Register Screen Options for the Transactions tab: hideable columns and items per page.
	 *
	 * Scoped to the transactions tab only — other tabs share the same WP screen ID
	 * (woocommerce_page_wc-kledo) and must not inherit these options.
	 *
	 * Column visibility default: all columns visible (no default_hidden_columns filter needed).
	 * Per-page default: {@see self::PER_PAGE_DEFAULT}.
	 *
	 * @return void
	 */
	private function register_screen_columns(): void {
		if ( wc_kledo_get_requested_value( 'tab' ) !== self::ID ) {
			return;
		}

		// Column show/hide checkboxes — WordPress renders these in Screen Options automatically
		// via WP_Screen::get_columns() which calls this filter. All columns visible by default
		// (no default_hidden_columns override means empty hidden array = all visible).
		add_filter( 'manage_woocommerce_page_wc-kledo_columns', array( $this, 'get_hideable_columns' ) );

		// Items per page input in Screen Options. WordPress renders this input in the
		// Screen Options panel and submits it via $_POST['wp_screen_options'].
		// Persistence is handled by the set_screen_option_wc_kledo_transactions_per_page
		// filter registered in register_screen_options_handlers() (admin_init).
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Transactions per page', WC_KLEDO_TEXT_DOMAIN ),
				'default' => self::PER_PAGE_DEFAULT,
				'option'  => 'wc_kledo_transactions_per_page',
			)
		);
	}

	/**
	 * Intercept the hidden-columns AJAX request for our Transactions table.
	 *
	 * WordPress's native wp_ajax_hidden_columns() (priority 10) opens with
	 *   $screen = get_current_screen(); if ( null === $screen ) { wp_die( 0 ); }
	 * In admin-ajax.php the screen is never initialised automatically, so the core handler
	 * silently discards the user's column preference every time.
	 *
	 * By registering at priority 1 we run before the core handler.  We save the hidden
	 * columns directly to user_meta using the exact key that get_hidden_columns() reads:
	 *   'manage' . $screen->id . 'columnshidden'
	 * then wp_die(1) so the request terminates and the broken core handler never runs.
	 * For any other admin page we return immediately — the core handler processes it normally.
	 *
	 * @return void
	 */
	public function ajax_save_hidden_columns(): void {
		// Only intercept the request when it belongs to our page.
		$page = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : '';

		if ( ( 'woocommerce_page_' . WC_Kledo_Admin::PAGE_ID ) !== $page ) {
			return; // Not our page; let WP core's handler run.
		}

		check_ajax_referer( 'screen-options-nonce', 'screenoptionnonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 0 );
		}

		$hidden_raw = isset( $_POST['hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['hidden'] ) ) : '';
		$hidden     = ( '' !== $hidden_raw ) ? explode( ',', $hidden_raw ) : array();

		// Accept only recognised hideable column keys; discard anything unexpected.
		$valid_keys = array_keys( $this->get_hideable_columns() );
		$hidden     = array_values( array_intersect( array_map( 'sanitize_key', $hidden ), $valid_keys ) );

		// Meta key format: 'manage' . $screen->id . 'columnshidden'
		// This is identical to what get_hidden_columns( $wp_screen ) reads on page render.
		update_user_meta(
			get_current_user_id(),
			'manage' . 'woocommerce_page_' . WC_Kledo_Admin::PAGE_ID . 'columnshidden',
			$hidden
		);

		wp_die( 1 );
	}

	/**
	 * Columns eligible for show/hide via Screen Options.
	 *
	 * Always-visible columns (Order, Status, Actions) are intentionally excluded so they
	 * cannot be hidden. WordPress renders one checkbox per entry returned here.
	 *
	 * @return array<string, string>
	 */
	public function get_hideable_columns(): array {
		return array(
			'type'       => __( 'Type', WC_KLEDO_TEXT_DOMAIN ),
			'attempts'   => __( 'Attempts', WC_KLEDO_TEXT_DOMAIN ),
			'created'    => __( 'Created At', WC_KLEDO_TEXT_DOMAIN ),
			'next_retry' => __( 'Next Retry', WC_KLEDO_TEXT_DOMAIN ),
			'last_error' => __( 'Last Error', WC_KLEDO_TEXT_DOMAIN ),
		);
	}

	/**
	 * Resolve the active items-per-page value for the current user.
	 *
	 * Reads the value saved by the Screen Options 'per_page' input (user meta key
	 * `wc_kledo_transactions_per_page`). Falls back to {@see self::PER_PAGE_DEFAULT}
	 * for users who have not yet saved a preference.
	 *
	 * @return int
	 */
	private function get_items_per_page(): int {
		$saved = (int) get_user_option( 'wc_kledo_transactions_per_page' );

		return ( $saved >= 1 ) ? min( $saved, 999 ) : self::PER_PAGE_DEFAULT;
	}

	/**
	 * Run the same manual retry pipeline used for a single row, for one queue key.
	 *
	 * @param  string  $key  Queue option key (e.g. order:62).
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

		if ( ! is_array( $queue ) ) {
			return self::RETRY_OUTCOME_NOT_IN_QUEUE;
		}

		if ( empty( $queue[ $key ] ) || ! is_array( $queue[ $key ] ) ) {
			return self::RETRY_OUTCOME_NOT_IN_QUEUE;
		}

		$item = $queue[ $key ];

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
					__( 'Kledo: manually resent %s to Kledo from Transactions screen.', WC_KLEDO_TEXT_DOMAIN ),
					$type
				)
			);

			return self::RETRY_OUTCOME_SUCCESS;
		}

		if ( ! empty( $result['skipped'] ) && 'already_synced' === ( $result['reason'] ?? '' ) ) {
			return self::RETRY_OUTCOME_SKIPPED_SYNCED;
		}

		if ( ! empty( $result['error'] ) ) {
			$item['last_error'] = $result['error'];
		} else {
			$http_code          = (int) ( $result['http_code'] ?? 0 );
			$item['last_error'] = wc_kledo_sanitize_api_error_message(
				$http_code > 0
					? sprintf( 'HTTP %d', $http_code )
					: __( 'There was a problem when connecting to the API.', WC_KLEDO_TEXT_DOMAIN )
			);
		}
		$item['next_run_at'] = time() + HOUR_IN_SECONDS;

		$queue[ $key ] = $item;
		update_option( $option_name, $queue, false );

		return self::RETRY_OUTCOME_FAILED;
	}
}
