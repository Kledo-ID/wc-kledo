<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Diagnostics tab: find out why an order did not reach Kledo, and hand the Kledo team a report.
 *
 * The steps run one by one over AJAX so the admin sees which one fails as it happens. The report
 * is stored for 7 days and can be downloaded as text or JSON, or referred to by its code.
 * Nothing is ever sent anywhere on its own.
 *
 * @since 1.8.0
 */
class WC_Kledo_Diagnostics_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ID = 'diagnostics';

	/**
	 * Nonce action of the AJAX steps and downloads.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const NONCE = 'wc_kledo_diagnostics';

	/**
	 * Orders listed per group, or per search, in the order picker.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	private const PICKER_LIMIT = 20;

	/**
	 * Hours the detailed log stays on.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	private const DETAILED_LOG_HOURS = 24;

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action(
			'load-woocommerce_page_wc-kledo',
			function () {
				$this->label = __( 'Diagnostics', 'wc-kledo' );
				$this->title = __( 'Diagnostics', 'wc-kledo' );
			}
		);

		add_action( 'wp_ajax_wc_kledo_diag_orders', array( $this, 'ajax_search_orders' ) );
		add_action( 'wp_ajax_wc_kledo_diag_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_wc_kledo_diag_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_wc_kledo_diag_finish', array( $this, 'ajax_finish' ) );
		add_action( 'admin_post_wc_kledo_diag_download', array( $this, 'handle_download' ) );
		add_action( 'admin_init', array( $this, 'maybe_toggle_detailed_log' ) );
	}

	/**
	 * This screen has no WooCommerce settings fields.
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order_id = absint( wc_kledo_get_requested_value( 'order_id' ) );

		?>
		<div class="wc-kledo-diag">
			<p class="wc-kledo-diag-intro">
				<?php esc_html_e( 'An order did not reach Kledo? Choose it below and run a diagnosis. The plugin checks, step by step, everything that decides whether an order is sent, and tells you the most likely cause. You can then download the report and send it to the Kledo team.', 'wc-kledo' ); ?>
			</p>

			<form class="wc-kledo-diag-form" id="wc-kledo-diag-form">
				<p class="wc-kledo-diag-order-field">
					<label for="wc-kledo-diag-order"><strong><?php esc_html_e( 'Order', 'wc-kledo' ); ?></strong></label><br/>
					<select id="wc-kledo-diag-order" name="order" class="wc-kledo-diag-order-select">
						<?php
						$preselected = $order_id > 0 ? wc_get_order( $order_id ) : false;

						if ( $preselected instanceof WC_Order ) {
							$option = $this->build_order_option( $preselected );

							printf( '<option value="%1$s" selected="selected">%2$s</option>', esc_attr( (string) $option['id'] ), esc_html( $option['text'] ) );
						}
						?>
					</select>
					<br/>
					<span class="description"><?php esc_html_e( 'Pick from the list — orders with a problem in Kledo come first — or search by order number, customer name or email. You can also type an order number that is not listed.', 'wc-kledo' ); ?></span>
				</p>

				<p>
					<label>
						<input type="checkbox" name="resend" value="1"/>
						<?php esc_html_e( 'Also resend to Kledo and record what happens', 'wc-kledo' ); ?>
					</label>
					<br/>
					<span class="description"><?php esc_html_e( 'Only what Kledo does not have yet is resent, so nothing is created twice. Leave this off to only check.', 'wc-kledo' ); ?></span>
				</p>

				<p>
					<label>
						<input type="checkbox" name="full_customer_data" value="1"/>
						<?php esc_html_e( 'Include full customer data in the report', 'wc-kledo' ); ?>
					</label>
					<br/>
					<span class="description"><?php esc_html_e( 'Off by default: the customer\'s name, email, phone and address are partly hidden. Turn on only when Kledo rejects the customer data itself.', 'wc-kledo' ); ?></span>
				</p>

				<p>
					<button type="submit" class="button button-primary" id="wc-kledo-diag-run"><?php esc_html_e( 'Run diagnosis', 'wc-kledo' ); ?></button>
				</p>

				<noscript><p class="wc-kledo-sync-warning"><?php esc_html_e( 'The diagnosis needs JavaScript to run.', 'wc-kledo' ); ?></p></noscript>
			</form>

			<ol class="wc-kledo-diag-steps" id="wc-kledo-diag-steps" hidden></ol>

			<div class="wc-kledo-diag-conclusion" id="wc-kledo-diag-conclusion" hidden></div>

			<?php $this->render_detailed_log(); ?>
			<?php $this->render_history(); ?>
		</div>
		<?php
	}

	/**
	 * The detailed log switch.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_detailed_log(): void {
		$until = wc_kledo_debug_log_until();

		?>
		<h2><?php esc_html_e( 'Problems that only happen sometimes', 'wc-kledo' ); ?></h2>
		<p>
			<?php esc_html_e( 'When orders only fail now and then, a diagnosis afterwards may find nothing wrong. Record every request to Kledo for 24 hours instead; diagnoses run during that time include what was recorded for the order. It switches itself off after 24 hours. Customer data in the recording is partly hidden and the API key is never recorded.', 'wc-kledo' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . self::ID ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php if ( $until > 0 ) : ?>
				<p class="wc-kledo-diag-recording">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Recording until %s.', 'wc-kledo' ), wc_kledo_format_admin_timestamp( $until, 'future' ) ) );
					?>
				</p>
				<button type="submit" class="button" name="wc_kledo_detailed_log" value="off"><?php esc_html_e( 'Stop recording', 'wc-kledo' ); ?></button>
			<?php else : ?>
				<button type="submit" class="button" name="wc_kledo_detailed_log" value="on"><?php esc_html_e( 'Record for 24 hours', 'wc-kledo' ); ?></button>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * The recent reports, downloadable again.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_history(): void {
		$reports = ( new WC_Kledo_Diagnostics() )->get_history();

		if ( empty( $reports ) ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Recent diagnoses', 'wc-kledo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Reports are kept for 7 days.', 'wc-kledo' ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Report', 'wc-kledo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Order', 'wc-kledo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time', 'wc-kledo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Likely cause', 'wc-kledo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Download', 'wc-kledo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $reports as $report ) : ?>
					<tr>
						<td><code><?php echo esc_html( $report['code'] ); ?></code></td>
						<td>#<?php echo esc_html( (string) $report['order_no'] ); ?></td>
						<td><?php echo esc_html( wc_kledo_format_admin_timestamp( (int) $report['created_at'], 'past' ) ); ?></td>
						<td><?php echo esc_html( $report['conclusion']['title'] ?? '—' ); ?></td>
						<td>
							<a href="<?php echo esc_url( self::get_download_url( $report['code'], 'txt' ) ); ?>"><?php esc_html_e( 'Text', 'wc-kledo' ); ?></a>
							&middot;
							<a href="<?php echo esc_url( self::get_download_url( $report['code'], 'json' ) ); ?>">JSON</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Download URL of a report.
	 *
	 * @param  string $code
	 * @param  string $format  `txt` or `json`.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public static function get_download_url( string $code, string $format ): string {
		// Not wp_nonce_url(): it returns the URL HTML-escaped ("&amp;"), which breaks the link once
		// it travels through JSON into an href and loses its nonce ("The link you followed has
		// expired"). Callers escape it for their own context.
		return add_query_arg(
			array(
				'action'   => 'wc_kledo_diag_download',
				'code'     => $code,
				'format'   => $format,
				'_wpnonce' => wp_create_nonce( self::NONCE ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * URL of this tab for one order.
	 *
	 * @param  int $order_id
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public static function get_url_for_order( int $order_id ): string {
		return admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . self::ID . '&order_id=' . $order_id );
	}

	/**
	 * AJAX: the orders offered by the order picker.
	 *
	 * Without a search term: orders with a problem in Kledo first (failed, rejected, missing, or
	 * Processing/Completed and never sent), then the most recent orders. With a term: WooCommerce's
	 * own order search (number, customer name, email…), which works in both storage modes.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function ajax_search_orders(): void {
		$this->verify_ajax();

		$term = trim( ltrim( trim( (string) wc_kledo_get_posted_value( 'term' ) ), '#' ) );

		if ( '' !== $term ) {
			$ids = array_map( 'intval', (array) wc_order_search( $term ) );

			// An order number from a sequential-numbers plugin, typed in full.
			$resolved = $this->resolve_order_id( $term );

			if ( $resolved > 0 ) {
				array_unshift( $ids, $resolved );
			}

			wp_send_json_success( array( 'results' => $this->build_order_options( $ids ) ) );
		}

		$problem_ids = $this->get_problem_order_ids();
		$recent_ids  = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'limit'   => self::PICKER_LIMIT * 2,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		// De-duplicated here rather than with `exclude`, which the two order stores do not read
		// the same way.
		$recent_ids = array_slice( array_values( array_diff( array_map( 'intval', is_array( $recent_ids ) ? $recent_ids : array() ), $problem_ids ) ), 0, self::PICKER_LIMIT );

		$groups = array();

		foreach (
			array(
				__( 'Not in Kledo or failed', 'wc-kledo' ) => $problem_ids,
				__( 'Recent orders', 'wc-kledo' )          => $recent_ids,
			) as $label => $ids
		) {
			$children = $this->build_order_options( $ids );

			if ( ! empty( $children ) ) {
				$groups[] = array(
					'text'     => $label,
					'children' => $children,
				);
			}
		}

		wp_send_json_success( array( 'results' => $groups ) );
	}

	/**
	 * The most recent orders whose sales order or invoice has a problem in Kledo.
	 *
	 * @return int[]
	 * @since 1.8.0
	 */
	private function get_problem_order_ids(): array {
		$clauses = array( 'relation' => 'OR' );

		foreach ( array( 'order', 'invoice' ) as $type ) {
			$clauses[] = wc_kledo_get_remote_state_meta_query( $type, 'failed' );
		}

		$failed = wc_kledo_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'limit'      => self::PICKER_LIMIT,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'ids',
				'meta_query' => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$not_sent = wc_kledo_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array( 'wc-processing', 'wc-completed' ),
				'limit'      => self::PICKER_LIMIT,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'ids',
				'meta_query' => wc_kledo_get_remote_state_meta_query( 'order', 'not_sent' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$ids = array_merge( is_array( $failed ) ? $failed : array(), is_array( $not_sent ) ? $not_sent : array() );
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		rsort( $ids );

		return array_slice( $ids, 0, self::PICKER_LIMIT );
	}

	/**
	 * Picker options for a list of order ids, skipping anything that is not an order.
	 *
	 * @param  int[] $ids
	 *
	 * @return array[]
	 * @since 1.8.0
	 */
	private function build_order_options( array $ids ): array {
		$options = array();

		foreach ( array_slice( array_values( array_unique( $ids ) ), 0, self::PICKER_LIMIT ) as $id ) {
			$order = wc_get_order( $id );

			// Refunds share the order tables and come back from the search too.
			if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
				$options[] = $this->build_order_option( $order );
			}
		}

		return $options;
	}

	/**
	 * One picker option: the order, and a one-line summary of where it stands.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array{id: int, text: string, meta: string, states: string, problem: bool}
	 * @since 1.8.0
	 */
	private function build_order_option( WC_Order $order ): array {
		$name = trim( $order->get_formatted_billing_full_name() );
		$date = $order->get_date_created();

		$states  = array();
		$problem = false;

		foreach ( array( 'order', 'invoice' ) as $type ) {
			$state    = wc_kledo_get_remote_state( $order, $type );
			$states[] = WC_Kledo_Status_Badge::type_label( $type ) . ': ' . WC_Kledo_Status_Badge::describe( $state, $type )['label'];

			if ( in_array( $state, wc_kledo_get_remote_state_groups()['failed'], true )
				|| ( 'not_sent' === $state && in_array( $type, WC_Kledo_Sync_Candidates::get_types_to_send( $order ), true ) ) ) {
				$problem = true;
			}
		}

		return array(
			'id'      => $order->get_id(),
			'text'    => '#' . $order->get_order_number() . ( '' !== $name ? ' — ' . $name : '' ),
			'meta'    => implode(
				' · ',
				array_filter(
					array(
						wc_get_order_status_name( $order->get_status() ),
						$date ? wc_kledo_format_admin_timestamp( $date->getTimestamp() ) : '',
						html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ),
					)
				)
			),
			'states'  => implode( ' · ', $states ),
			'problem' => $problem,
		);
	}

	/**
	 * AJAX: start a diagnosis for an order number.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function ajax_start(): void {
		$this->verify_ajax();

		$order_id = $this->resolve_order_id( (string) wc_kledo_get_posted_value( 'order' ) );

		$report = ( new WC_Kledo_Diagnostics() )->start(
			$order_id,
			array(
				'resend'             => '' !== (string) wc_kledo_get_posted_value( 'resend' ),
				'full_customer_data' => '' !== (string) wc_kledo_get_posted_value( 'full_customer_data' ),
			)
		);

		if ( is_wp_error( $report ) ) {
			wp_send_json_error( array( 'message' => $report->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'code'     => $report['code'],
				'order_no' => $report['order_no'],
			)
		);
	}

	/**
	 * AJAX: run one step.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function ajax_step(): void {
		$this->verify_ajax();

		$result = ( new WC_Kledo_Diagnostics() )->run_step(
			(string) wc_kledo_get_posted_value( 'code' ),
			sanitize_key( (string) wc_kledo_get_posted_value( 'step' ) )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'status'  => $result['status'],
				'summary' => $result['summary'],
				'details' => (string) wp_json_encode( $result['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);
	}

	/**
	 * AJAX: finish and return the conclusion and the text report.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function ajax_finish(): void {
		$this->verify_ajax();

		$diagnostics = new WC_Kledo_Diagnostics();
		$report      = $diagnostics->finish( (string) wc_kledo_get_posted_value( 'code' ) );

		if ( is_wp_error( $report ) ) {
			wp_send_json_error( array( 'message' => $report->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'code'       => $report['code'],
				'conclusion' => $report['conclusion'],
				'download'   => array(
					'txt'  => self::get_download_url( $report['code'], 'txt' ),
					'json' => self::get_download_url( $report['code'], 'json' ),
				),
			)
		);
	}

	/**
	 * Send a report as a file.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function handle_download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-kledo' ) );
		}

		check_admin_referer( self::NONCE );

		$diagnostics = new WC_Kledo_Diagnostics();
		$report      = $diagnostics->get( (string) wc_kledo_get_requested_value( 'code' ) );

		if ( null === $report ) {
			wp_die( esc_html__( 'This diagnosis has expired. Start a new one.', 'wc-kledo' ) );
		}

		$is_json  = 'json' === sanitize_key( (string) wc_kledo_get_requested_value( 'format' ) );
		$filename = 'kledo-' . strtolower( $report['code'] ) . '-order-' . $report['order_id'] . ( $is_json ? '.json' : '.txt' );

		nocache_headers();
		header( 'Content-Type: ' . ( $is_json ? 'application/json' : 'text/plain' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo $is_json ? $diagnostics->to_json( $report ) : $diagnostics->to_text( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A downloaded file, not HTML.
		exit;
	}

	/**
	 * Switch the detailed log on (24 hours) or off.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_toggle_detailed_log(): void {
		if ( WC_Kledo_Admin::PAGE_ID !== wc_kledo_get_requested_value( 'page' ) || self::ID !== wc_kledo_get_requested_value( 'tab' ) ) {
			return;
		}

		$value = sanitize_key( (string) wc_kledo_get_posted_value( 'wc_kledo_detailed_log' ) );

		if ( '' === $value ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-kledo' ) );
		}

		check_admin_referer( self::NONCE );

		if ( 'on' === $value ) {
			update_option( 'wc_kledo_debug_log_until', time() + self::DETAILED_LOG_HOURS * HOUR_IN_SECONDS, false );
			wc_kledo()->get_message_handler()->add_message( __( 'Every request to Kledo is now recorded for 24 hours.', 'wc-kledo' ) );
		} else {
			delete_option( 'wc_kledo_debug_log_until' );
			wc_kledo()->get_message_handler()->add_message( __( 'Recording stopped.', 'wc-kledo' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . self::ID ) );
		exit;
	}

	/**
	 * Turn what the admin typed into an order id.
	 *
	 * Accepts "#1234" as well as "1234", and asks plugins that give orders their own numbers
	 * (sequential order number plugins hook this WooCommerce filter) to map the number back.
	 *
	 * @param  string $input
	 *
	 * @return int
	 * @since 1.8.0
	 */
	private function resolve_order_id( string $input ): int {
		$number = ltrim( trim( $input ), '#' );

		return absint( apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ) );
	}

	/**
	 * Nonce and capability check of the AJAX endpoints.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function verify_ajax(): void {
		check_ajax_referer( self::NONCE, 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'wc-kledo' ) ), 403 );
		}
	}
}
