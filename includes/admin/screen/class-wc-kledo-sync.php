<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Sync tab: send orders that are missing from Kledo, a few at a time.
 *
 * Nothing here runs on its own. After an install or an update the plugin only counts the orders
 * that are missing from Kledo and says so in a notice; sending starts when an admin presses
 * "Process gradually", or every night when the admin switched the daily run on.
 *
 * @since 1.8.0
 */
class WC_Kledo_Sync_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ID = 'sync';

	/**
	 * First day of the default range; empty means 30 days ago.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const DATE_FROM_OPTION = 'wc_kledo_sync_date_from';

	/**
	 * Last day of the default range; empty means today.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const DATE_TO_OPTION = 'wc_kledo_sync_date_to';

	/**
	 * Orders per batch, 5–10.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const BATCH_SIZE_OPTION = 'wc_kledo_sync_batch_size';

	/**
	 * Whether the nightly run is switched on. Off by default.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const DAILY_ENABLED_OPTION = 'wc_kledo_sync_daily_enabled';

	/**
	 * Days the nightly run looks back.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const DAILY_DAYS_OPTION = 'wc_kledo_sync_daily_days';

	/**
	 * Hour the nightly run starts, store time.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const DAILY_HOUR_OPTION = 'wc_kledo_sync_daily_hour';

	/**
	 * Version whose candidates were last counted; a different running version means "recount".
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const COUNTED_VERSION_OPTION = 'wc_kledo_sync_counted_version';

	/**
	 * Nonce action of the job controls.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const CONTROL_NONCE = 'wc_kledo_sync_control';

	/**
	 * Nonce action of the progress poll.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const POLL_NONCE = 'wc_kledo_sync_poll';

	/**
	 * Query args a link can use to open this tab with a range filled in.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const PARAM_FROM = 'wc_kledo_sync_from';

	/**
	 * Last day of a range passed in a link.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const PARAM_TO = 'wc_kledo_sync_to';

	/**
	 * Orders per page of the list.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	private const PER_PAGE = 25;

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
				$this->label = __( 'Sync', 'wc-kledo' );
				$this->title = __( 'Sync', 'wc-kledo' );
			}
		);

		add_action( 'admin_init', array( $this, 'maybe_handle_control' ) );
		add_action( 'admin_init', array( $this, 'maybe_recount_candidates' ) );
		add_action( 'admin_notices', array( $this, 'add_notices' ), 5 );
		add_action( 'wp_ajax_wc_kledo_sync_poll', array( $this, 'ajax_poll' ) );
	}

	/**
	 * Orders per batch.
	 *
	 * @return int
	 * @since 1.8.0
	 */
	public static function get_batch_size(): int {
		return WC_Kledo_Sync_Job::sanitize_batch_size( get_option( self::BATCH_SIZE_OPTION, WC_Kledo_Sync_Job::MIN_BATCH ) );
	}

	/**
	 * Days the nightly run looks back, 1–90.
	 *
	 * @return int
	 * @since 1.8.0
	 */
	public static function get_daily_days(): int {
		return max( 1, min( 90, (int) get_option( self::DAILY_DAYS_OPTION, 7 ) ) );
	}

	/**
	 * Hour the nightly run starts, 0–23.
	 *
	 * @return int
	 * @since 1.8.0
	 */
	public static function get_daily_hour(): int {
		return max( 0, min( 23, (int) get_option( self::DAILY_HOUR_OPTION, 1 ) ) );
	}

	/**
	 * The default range: the saved one, or the last 30 days for either end left empty.
	 *
	 * @return array{from: string, to: string}
	 * @since 1.8.0
	 */
	public static function get_default_range(): array {
		$recent = WC_Kledo_Sync_Candidates::get_recent_range();
		$range  = wc_kledo_get_date_range( get_option( self::DATE_FROM_OPTION, '' ), get_option( self::DATE_TO_OPTION, '' ) );

		return array(
			'from' => '' !== $range['from'] ? $range['from'] : $recent['from'],
			'to'   => '' !== $range['to'] ? $range['to'] : $recent['to'],
		);
	}

	/**
	 * Gets the screen settings.
	 *
	 * The texts are written for a shop owner, not a developer: what happens, in plain words.
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function get_settings(): array {
		$hours = array();

		for ( $hour = 0; $hour < 24; $hour++ ) {
			$hours[ (string) $hour ] = sprintf( '%02d:00', $hour );
		}

		$sizes = array();

		for ( $size = WC_Kledo_Sync_Job::MIN_BATCH; $size <= WC_Kledo_Sync_Job::MAX_BATCH; $size++ ) {
			/* translators: %d: number of orders */
			$sizes[ (string) $size ] = sprintf( _n( '%d order per minute', '%d orders per minute', $size, 'wc-kledo' ), $size );
		}

		return array(
			'title'             => array(
				'title' => __( 'Sync Settings', 'wc-kledo' ),
				'type'  => 'title',
				'desc'  => __( 'Sends orders that are not in Kledo yet — for example orders placed before this plugin was installed. Only Processing and Completed orders are sent, and only the parts switched on in the Order and Invoice tabs. Orders already in Kledo are skipped.', 'wc-kledo' ),
			),

			'date_from'         => array(
				'id'      => self::DATE_FROM_OPTION,
				'title'   => __( 'Orders From', 'wc-kledo' ),
				'type'    => 'date',
				'class'   => 'wc-kledo-field',
				'default' => '',
				'desc'    => __( 'Leave empty to start 30 days before today.', 'wc-kledo' ),
			),

			'date_to'           => array(
				'id'      => self::DATE_TO_OPTION,
				'title'   => __( 'Orders Until', 'wc-kledo' ),
				'type'    => 'date',
				'class'   => 'wc-kledo-field',
				'default' => '',
				'desc'    => __( 'Leave empty to include today.', 'wc-kledo' ),
			),

			'batch_size'        => array(
				'id'      => self::BATCH_SIZE_OPTION,
				'title'   => __( 'Speed', 'wc-kledo' ),
				'type'    => 'select',
				'class'   => 'wc-kledo-field',
				'default' => (string) WC_Kledo_Sync_Job::MIN_BATCH,
				'options' => $sizes,
				'desc'    => __( 'How many orders are sent each minute. The next ones are only sent after Kledo has finished creating these.', 'wc-kledo' ),
			),

			'section_end'       => array(
				'type' => 'sectionend',
			),

			'daily_title'       => array(
				'title' => __( 'Send Automatically Every Day', 'wc-kledo' ),
				'type'  => 'title',
				'desc'  => __( 'What happens when this is switched on: every night the plugin looks at the Processing and Completed orders of the last few days. Orders already in Kledo are skipped, so nothing is created twice. Orders that are missing are sent slowly, a few per minute. Invoices are created as paid or unpaid following the Invoice tab. You can see the results in the Kledo Status tab, and you will see a message if something failed.', 'wc-kledo' ),
			),

			'daily_enabled'     => array(
				'id'      => self::DAILY_ENABLED_OPTION,
				'title'   => __( 'Send Automatically Every Day', 'wc-kledo' ),
				'type'    => 'checkbox',
				'class'   => 'wc-kledo-field',
				'default' => 'no',
				'desc'    => __( 'If ticked, every night the plugin looks for orders that are not in Kledo yet and sends them by itself, slowly. You do not need to press any button. Leave it unticked if you want to check first before anything is sent.', 'wc-kledo' ),
			),

			'daily_days'        => array(
				'id'                => self::DAILY_DAYS_OPTION,
				'title'             => __( 'Look Back', 'wc-kledo' ),
				'type'              => 'number',
				'class'             => 'wc-kledo-field small-text',
				'default'           => '7',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '90',
					'step' => '1',
				),
				'desc'              => __( 'days. Only orders from this many days back are checked each night. Older orders are not touched.', 'wc-kledo' ),
			),

			'daily_hour'        => array(
				'id'      => self::DAILY_HOUR_OPTION,
				'title'   => __( 'Start Time', 'wc-kledo' ),
				'type'    => 'select',
				'class'   => 'wc-kledo-field',
				'default' => '1',
				'options' => $hours,
				'desc'    => __( 'What time the check starts, in your store\'s timezone. Choose a quiet time for your store.', 'wc-kledo' ),
			),

			'daily_section_end' => array(
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Save the settings, then match the nightly schedule and the notice count to them.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function save(): void {
		parent::save();

		wc_kledo()->get_sync_job()->sync_daily_schedule();
		( new WC_Kledo_Sync_Candidates() )->refresh_cached_count();
	}

	/**
	 * Render the settings, then the sync panel.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render(): void {
		parent::render();

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$job   = wc_kledo()->get_sync_job()->get_job();
		$range = $this->get_requested_range();

		?>
		<hr class="wc-kledo-sync-divider"/>

		<h2><?php esc_html_e( 'Orders Missing From Kledo', 'wc-kledo' ); ?></h2>

		<div id="wc-kledo-sync-progress" class="wc-kledo-sync-progress">
			<?php $this->render_progress( $job ); ?>
		</div>

		<?php
		if ( null === $job || ! in_array( $job['status'], array( 'running', 'paused' ), true ) ) {
			$this->render_start_form( $range );
		}

		$this->render_list( $range, $job );
	}

	/**
	 * The form that starts a job, with its range and the confirmation it asks for.
	 *
	 * @param  array{from: string, to: string} $range
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_start_form( array $range ): void {
		$counts  = ( new WC_Kledo_Sync_Candidates() )->count( $range );
		$minutes = (int) ceil( $counts['orders'] / max( 1, self::get_batch_size() ) );

		$paid_label = 'yes' === wc_kledo_paid_status() ? __( 'Paid', 'wc-kledo' ) : __( 'Unpaid', 'wc-kledo' );

		$confirm = implode(
			"\n\n",
			array(
				sprintf(
					/* translators: 1: number of orders, 2: orders per minute */
					__( 'Send %1$d order(s) to Kledo, %2$d per minute?', 'wc-kledo' ),
					$counts['orders'],
					self::get_batch_size()
				),
				/* translators: %s: Paid or Unpaid */
				sprintf( __( 'Invoices will be created as: %s (following the Invoice tab).', 'wc-kledo' ), $paid_label ),
				__( 'Each transaction is dated with its order date, so reports of earlier months in Kledo will change. Kledo does not refuse dates inside a period you have locked (closed books), so make sure the range does not reach into one.', 'wc-kledo' ),
				__( 'Invoices reduce warehouse stock in Kledo on the order date.', 'wc-kledo' ),
			)
		);

		?>
		<form method="post" class="wc-kledo-sync-start" action="<?php echo esc_url( $this->get_screen_url() ); ?>">
			<?php wp_nonce_field( self::CONTROL_NONCE ); ?>
			<input type="hidden" name="page" value="<?php echo esc_attr( WC_Kledo_Admin::PAGE_ID ); ?>"/>
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::ID ); ?>"/>

			<p class="wc-kledo-date-range">
				<label for="wc-kledo-sync-start-from"><?php esc_html_e( 'Orders from', 'wc-kledo' ); ?></label>
				<input type="date" id="wc-kledo-sync-start-from" name="<?php echo esc_attr( self::PARAM_FROM ); ?>" value="<?php echo esc_attr( $range['from'] ); ?>"/>
				<label for="wc-kledo-sync-start-to"><?php esc_html_e( 'to', 'wc-kledo' ); ?></label>
				<input type="date" id="wc-kledo-sync-start-to" name="<?php echo esc_attr( self::PARAM_TO ); ?>" value="<?php echo esc_attr( $range['to'] ); ?>"/>
				<?php // Submits the same fields as a GET, so the typed range is counted without starting anything. ?>
				<button type="submit" class="button" formmethod="get" formaction="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><?php esc_html_e( 'Count again', 'wc-kledo' ); ?></button>
			</p>

			<p class="wc-kledo-sync-summary">
				<?php
				printf(
					/* translators: 1: number of orders, 2: number of sales orders, 3: number of invoices */
					esc_html__( '%1$d order(s) need sending: %2$d sales order(s) and %3$d invoice(s).', 'wc-kledo' ),
					(int) $counts['orders'],
					(int) $counts['order'],
					(int) $counts['invoice']
				);

				if ( $counts['orders'] > 0 ) {
					echo ' ';
					/* translators: %d: minutes */
					echo esc_html( sprintf( _n( 'About %d minute at the current speed.', 'About %d minutes at the current speed.', $minutes, 'wc-kledo' ), $minutes ) );
				}
				?>
			</p>

			<p>
				<button type="submit" class="button button-primary" name="wc_kledo_sync_control" value="start" <?php disabled( 0, $counts['orders'] ); ?> data-confirm="<?php echo esc_attr( $confirm ); ?>">
					<?php esc_html_e( 'Process gradually', 'wc-kledo' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * The progress block of the current or last job, also served by the poll.
	 *
	 * @param  array|null $job
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_progress( ?array $job ): void {
		if ( null === $job ) {
			return;
		}

		$counts = $job['counts'];
		$total  = max( 1, (int) $job['total'] );
		$done   = min( $total, (int) $counts['processed'] );
		$active = in_array( $job['status'], array( 'running', 'paused' ), true );

		$labels = array(
			'running'   => __( 'Running', 'wc-kledo' ),
			'paused'    => __( 'Paused', 'wc-kledo' ),
			'done'      => __( 'Finished', 'wc-kledo' ),
			'cancelled' => __( 'Cancelled', 'wc-kledo' ),
		);

		printf(
			'<div class="wc-kledo-sync-job" data-status="%1$s"><p><strong>%2$s</strong> &middot; %3$s &middot; %4$s</p>',
			esc_attr( $job['status'] ),
			/* translators: %d: job number */
			esc_html( sprintf( __( 'Synchronisation #%d', 'wc-kledo' ), $job['number'] ) ),
			esc_html( $labels[ $job['status'] ] ?? $job['status'] ),
			/* translators: 1: first day, 2: last day */
			esc_html( sprintf( __( 'orders %1$s to %2$s', 'wc-kledo' ), $job['date_from'], $job['date_to'] ) )
		);

		if ( $active ) {
			printf(
				'<progress class="wc-kledo-sync-bar" max="%1$d" value="%2$d"></progress>',
				(int) $total,
				(int) $done
			);
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: processed, 2: total, 3: confirmed, 4: already in Kledo, 5: failed */
				__( '%1$d of %2$d orders processed — %3$d confirmed in Kledo, %4$d were already there, %5$d failed.', 'wc-kledo' ),
				$counts['processed'],
				$job['total'],
				$counts['confirmed'],
				$counts['adopted'],
				$counts['failed']
			)
		) . '</p>';

		if ( 'running' === $job['status'] && ! empty( $job['batch']['orders'] ) ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: batch number, 2: number of orders */
					__( 'Batch %1$d: waiting for Kledo to create %2$d order(s) before sending more.', 'wc-kledo' ),
					$job['batches'],
					count( $job['batch']['orders'] )
				)
			) . '</p>';
		}

		if ( (int) $job['backoff_until'] > time() ) {
			echo '<p class="description">' . esc_html__( 'Kledo asked to slow down; continuing in a few minutes.', 'wc-kledo' ) . '</p>';
		}

		if ( 'paused' === $job['status'] && 'kledo_slow' === $job['pause_reason'] ) {
			echo '<p class="wc-kledo-sync-warning">' . esc_html__( 'Paused automatically: Kledo had not finished the last batch after 30 minutes. Check that Kledo is working, then resume.', 'wc-kledo' ) . '</p>';
		}

		if ( $active ) {
			echo '<form method="post" class="wc-kledo-sync-controls" action="' . esc_url( $this->get_screen_url() ) . '">';
			wp_nonce_field( self::CONTROL_NONCE );

			if ( 'running' === $job['status'] ) {
				echo '<button type="submit" class="button" name="wc_kledo_sync_control" value="pause">' . esc_html__( 'Pause', 'wc-kledo' ) . '</button> ';
			} else {
				echo '<button type="submit" class="button button-primary" name="wc_kledo_sync_control" value="resume">' . esc_html__( 'Resume', 'wc-kledo' ) . '</button> ';
			}

			echo '<button type="submit" class="button" name="wc_kledo_sync_control" value="cancel" data-confirm="' . esc_attr__( 'Stop this synchronisation? Orders already sent stay in Kledo.', 'wc-kledo' ) . '">' . esc_html__( 'Cancel', 'wc-kledo' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * The list of orders in the range, with their Kledo state and what the job did.
	 *
	 * @param  array{from: string, to: string} $range
	 * @param  array|null                      $job
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_list( array $range, ?array $job ): void {
		$status          = (string) wc_kledo_get_requested_value( 'wc_kledo_sync_status' );
		$status          = in_array( $status, array( 'processing', 'completed' ), true ) ? $status : '';
		$only_candidates = 'all' !== wc_kledo_get_requested_value( 'wc_kledo_sync_show', 'missing' );
		$paged           = max( 1, absint( wc_kledo_get_requested_value( 'paged', 1 ) ) );
		$page            = ( new WC_Kledo_Sync_Candidates() )->get_list_page( $range, $status, $only_candidates, $paged, self::PER_PAGE );
		$base_args       = array(
			self::PARAM_FROM => $range['from'],
			self::PARAM_TO   => $range['to'],
		);

		?>
		<form method="get" class="wc-kledo-sync-list-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( WC_Kledo_Admin::PAGE_ID ); ?>"/>
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::ID ); ?>"/>
			<input type="hidden" name="<?php echo esc_attr( self::PARAM_FROM ); ?>" value="<?php echo esc_attr( $range['from'] ); ?>"/>
			<input type="hidden" name="<?php echo esc_attr( self::PARAM_TO ); ?>" value="<?php echo esc_attr( $range['to'] ); ?>"/>

			<label class="screen-reader-text" for="wc-kledo-sync-show"><?php esc_html_e( 'Show', 'wc-kledo' ); ?></label>
			<select name="wc_kledo_sync_show" id="wc-kledo-sync-show">
				<option value="missing" <?php selected( $only_candidates ); ?>><?php esc_html_e( 'Only orders that need sending', 'wc-kledo' ); ?></option>
				<option value="all" <?php selected( ! $only_candidates ); ?>><?php esc_html_e( 'All Processing and Completed orders', 'wc-kledo' ); ?></option>
			</select>

			<label class="screen-reader-text" for="wc-kledo-sync-status"><?php esc_html_e( 'Order status', 'wc-kledo' ); ?></label>
			<select name="wc_kledo_sync_status" id="wc-kledo-sync-status">
				<option value=""><?php esc_html_e( 'Processing and Completed', 'wc-kledo' ); ?></option>
				<option value="processing" <?php selected( $status, 'processing' ); ?>><?php echo esc_html( wc_get_order_status_name( 'processing' ) ); ?></option>
				<option value="completed" <?php selected( $status, 'completed' ); ?>><?php echo esc_html( wc_get_order_status_name( 'completed' ) ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'wc-kledo' ), 'secondary', '', false ); ?>
		</form>

		<table class="wp-list-table widefat fixed striped wc-kledo-sync-table">
			<thead>
				<tr>
					<th scope="col" class="column-order column-primary"><?php esc_html_e( 'Order', 'wc-kledo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sales order', 'wc-kledo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Invoice', 'wc-kledo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Synchronisation', 'wc-kledo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $page['orders'] ) ) : ?>
					<tr class="no-items"><td colspan="4"><?php esc_html_e( 'No orders in this range need sending.', 'wc-kledo' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $page['orders'] as $order ) : ?>
					<?php $date = $order->get_date_created(); ?>
					<tr>
						<td class="column-order column-primary" data-colname="<?php esc_attr_e( 'Order', 'wc-kledo' ); ?>">
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><strong>#<?php echo esc_html( $order->get_order_number() ); ?> <?php echo esc_html( trim( $order->get_formatted_billing_full_name() ) ); ?></strong></a>
							<span class="wc-kledo-tx-order-meta"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?> &middot; <?php echo esc_html( $date ? wc_kledo_format_admin_timestamp( $date->getTimestamp() ) : '—' ); ?></span>
							<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'wc-kledo' ); ?></span></button>
						</td>
						<?php foreach ( array( 'order', 'invoice' ) as $type ) : ?>
							<td data-colname="<?php echo esc_attr( WC_Kledo_Status_Badge::type_label( $type ) ); ?>">
								<?php echo WC_Kledo_Status_Badge::render( wc_kledo_get_remote_state( $order, $type ), $type, wc_kledo_get_remote_ref( $order, $type ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render(). ?>
							</td>
						<?php endforeach; ?>
						<td data-colname="<?php esc_attr_e( 'Synchronisation', 'wc-kledo' ); ?>"><?php echo esc_html( $this->describe_result( $order, $job ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		if ( $page['pages'] > 1 ) {
			$links = paginate_links(
				array(
					'base'    => esc_url_raw(
						add_query_arg(
							array_merge(
								$base_args,
								array(
									'wc_kledo_sync_show'   => $only_candidates ? 'missing' : 'all',
									'wc_kledo_sync_status' => $status,
								)
							),
							$this->get_screen_url()
						) . '&paged=%#%'
					),
					'format'  => '',
					'total'   => $page['pages'],
					'current' => $paged,
				)
			);

			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></div>';
		}
	}

	/**
	 * What the last job did with one order, in words.
	 *
	 * @param  \WC_Order  $order
	 * @param  array|null $job
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function describe_result( WC_Order $order, ?array $job ): string {
		$handled_by = (string) $order->get_meta( WC_Kledo_Sync_Job::ORDER_JOB_META );

		if ( null !== $job && $handled_by === $job['id'] ) {
			$results = array(
				'sent'      => __( 'Sent, waiting for Kledo', 'wc-kledo' ),
				'confirmed' => __( 'Done', 'wc-kledo' ),
				'adopted'   => __( 'Was already in Kledo', 'wc-kledo' ),
				'skipped'   => __( 'Nothing to send', 'wc-kledo' ),
				'failed'    => __( 'Failed — see the Kledo Status tab', 'wc-kledo' ),
			);

			$result = (string) $order->get_meta( WC_Kledo_Sync_Job::ORDER_RESULT_META );

			return $results[ $result ] ?? '—';
		}

		return empty( WC_Kledo_Sync_Candidates::get_types_to_send( $order ) )
			? '—'
			: __( 'Waiting for its turn', 'wc-kledo' );
	}

	/**
	 * Handle Process gradually / Pause / Resume / Cancel (Post/Redirect/Get).
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_handle_control(): void {
		if ( WC_Kledo_Admin::PAGE_ID !== wc_kledo_get_requested_value( 'page' ) || self::ID !== wc_kledo_get_requested_value( 'tab' ) ) {
			return;
		}

		$control = sanitize_key( (string) wc_kledo_get_posted_value( 'wc_kledo_sync_control' ) );

		if ( '' === $control ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-kledo' ) );
		}

		check_admin_referer( self::CONTROL_NONCE );

		$job_runner = wc_kledo()->get_sync_job();

		switch ( $control ) {
			case 'start':
				$range  = wc_kledo_get_date_range( wc_kledo_get_posted_value( self::PARAM_FROM ), wc_kledo_get_posted_value( self::PARAM_TO ) );
				$result = $job_runner->start(
					array(
						'date_from'  => $range['from'],
						'date_to'    => $range['to'],
						'batch_size' => self::get_batch_size(),
						'source'     => 'manual',
					)
				);

				if ( is_wp_error( $result ) ) {
					wc_kledo()->get_message_handler()->add_error( $result->get_error_message() );
				} else {
					wc_kledo()->get_message_handler()->add_message( __( 'Synchronisation started. You can leave this page; it continues in the background.', 'wc-kledo' ) );
				}
				break;

			case 'pause':
				$job_runner->pause();
				wc_kledo()->get_message_handler()->add_message( __( 'Synchronisation paused.', 'wc-kledo' ) );
				break;

			case 'resume':
				$job_runner->resume();
				wc_kledo()->get_message_handler()->add_message( __( 'Synchronisation resumed.', 'wc-kledo' ) );
				break;

			case 'cancel':
				$job_runner->cancel();
				wc_kledo()->get_message_handler()->add_message( __( 'Synchronisation cancelled. Orders already sent stay in Kledo.', 'wc-kledo' ) );
				break;
		}

		wp_safe_redirect( $this->get_screen_url() );
		exit;
	}

	/**
	 * Count the candidates after an install or update, and refresh the count once a day.
	 *
	 * Only counts — nothing is sent. The count feeds the admin notice.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_recount_candidates(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$cached     = get_option( WC_Kledo_Sync_Candidates::COUNT_OPTION );
		$counted_at = is_array( $cached ) ? (int) ( $cached['counted_at'] ?? 0 ) : 0;

		if ( WC_KLEDO_VERSION === get_option( self::COUNTED_VERSION_OPTION ) && time() - $counted_at < DAY_IN_SECONDS ) {
			return;
		}

		update_option( self::COUNTED_VERSION_OPTION, WC_KLEDO_VERSION, false );
		( new WC_Kledo_Sync_Candidates() )->refresh_cached_count();
	}

	/**
	 * Notices: orders missing from Kledo, a job paused for a slow Kledo, a nightly run that failed.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function add_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$handler    = wc_kledo()->get_admin_notice_handler();
		$job        = wc_kledo()->get_sync_job()->get_job();
		$on_sync    = $this->is_current_screen_page();
		$sync_link  = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $this->get_screen_url() ), esc_html__( 'Open Sync', 'wc-kledo' ) );
		$job_active = null !== $job && in_array( $job['status'], array( 'running', 'paused' ), true );

		if ( null !== $job && 'paused' === $job['status'] && 'kledo_slow' === $job['pause_reason'] ) {
			$handler->add_admin_notice(
				sprintf(
					/* translators: 1: job number, 2: link to the Sync tab */
					esc_html__( 'Kledo: synchronisation #%1$d was paused because Kledo had not finished a batch after 30 minutes. %2$s', 'wc-kledo' ),
					(int) $job['number'],
					$on_sync ? '' : $sync_link
				),
				'sync_paused_' . $job['id'],
				array(
					'dismissible'  => true,
					'notice_class' => 'notice-warning',
				)
			);
		}

		if ( null !== $job && 'daily' === $job['source'] && 'done' === $job['status'] && (int) $job['counts']['failed'] > 0 ) {
			$handler->add_admin_notice(
				sprintf(
					/* translators: 1: confirmed count, 2: failed count, 3: link to the Kledo Status tab */
					esc_html__( 'Kledo: last night\'s automatic sync sent %1$d order(s); %2$d failed. %3$s', 'wc-kledo' ),
					(int) $job['counts']['confirmed'],
					(int) $job['counts']['failed'],
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . WC_Kledo_Transactions_Screen::ID . '&wc_kledo_tx_status=send_failed' ) ),
						esc_html__( 'See what failed', 'wc-kledo' )
					)
				),
				'sync_daily_' . $job['id'],
				array(
					'dismissible'  => true,
					'notice_class' => 'notice-warning',
				)
			);
		}

		if ( $on_sync || $job_active ) {
			return;
		}

		$cached = get_option( WC_Kledo_Sync_Candidates::COUNT_OPTION );
		$count  = is_array( $cached ) ? (int) ( $cached['orders'] ?? 0 ) : 0;

		if ( $count <= 0 ) {
			return;
		}

		$handler->add_admin_notice(
			sprintf(
				/* translators: 1: number of orders, 2: number of days, 3: sales orders, 4: invoices, 5: link to the Sync tab */
				esc_html__( 'Kledo: %1$d order(s) from the last %2$d days are not in Kledo yet (%3$d sales order(s), %4$d invoice(s)). Nothing is sent until you choose to. %5$s', 'wc-kledo' ),
				$count,
				WC_Kledo_Sync_Candidates::DEFAULT_DAYS,
				(int) ( $cached['order'] ?? 0 ),
				(int) ( $cached['invoice'] ?? 0 ),
				$sync_link
			),
			// Keyed on the count, so a dismissed notice comes back when more orders go missing.
			'sync_candidates_' . $count,
			array(
				'dismissible'  => true,
				'notice_class' => 'notice-info',
			)
		);
	}

	/**
	 * Progress poll from the Sync tab.
	 *
	 * Also runs an overdue step, which keeps the job moving on servers where WP-Cron cannot fire.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function ajax_poll(): void {
		check_ajax_referer( self::POLL_NONCE, 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$job_runner = wc_kledo()->get_sync_job();
		$job_runner->maybe_run_overdue_tick();

		$job = $job_runner->get_job();

		ob_start();
		$this->render_progress( $job );

		wp_send_json_success(
			array(
				'status' => null !== $job ? $job['status'] : '',
				'html'   => (string) ob_get_clean(),
			)
		);
	}

	/**
	 * The range of the start form: from a link, else the saved default.
	 *
	 * @return array{from: string, to: string}
	 * @since 1.8.0
	 */
	private function get_requested_range(): array {
		$requested = wc_kledo_get_date_range( wc_kledo_get_requested_value( self::PARAM_FROM ), wc_kledo_get_requested_value( self::PARAM_TO ) );
		$default   = self::get_default_range();

		return array(
			'from' => '' !== $requested['from'] ? $requested['from'] : $default['from'],
			'to'   => '' !== $requested['to'] ? $requested['to'] : $default['to'],
		);
	}

	/**
	 * URL of this tab.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function get_screen_url(): string {
		return admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . self::ID );
	}
}
