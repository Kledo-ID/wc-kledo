<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Works out why an order did not reach Kledo, step by step, and writes it up as a report.
 *
 * Steps 1–8 only read: the store, the settings, the order, the body that would be sent, and what
 * Kledo says it holds. The only calls to Kledo they make are `GET woocommerce/connection` and
 * `GET woocommerce/transactions/{id}`. Steps 9–10 resend and read back, and only run when the
 * admin ticked that option and Kledo does not hold the transaction yet.
 *
 * Every step returns `{status, summary, details, causes}`. The causes are codes from a fixed list
 * (see `describe_cause()`); the conclusion is the most serious one found, never a free guess.
 *
 * A diagnosis is kept as a transient for 7 days under a short code such as `DIAG-7F3K2`, so the
 * admin can download it again and the Kledo team can refer to it.
 *
 * @since 1.8.0
 */
class WC_Kledo_Diagnostics {
	/**
	 * Transient prefix of a report.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const REPORT_PREFIX = 'wc_kledo_diag_';

	/**
	 * Option listing the most recent report codes.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const HISTORY_OPTION = 'wc_kledo_diag_history';

	/**
	 * Reports kept in the history.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	private const HISTORY_SIZE = 10;

	/**
	 * Days a report stays downloadable.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	private const REPORT_LIFETIME = 7 * DAY_IN_SECONDS;

	/**
	 * The User-Agent pattern Kledo accepts; anything else is refused with HTTP 400.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const ACCEPTED_USER_AGENT = '/^Kledo\/\S+\s\(WooCommerce\/[^;]+;\s*WordPress\/[^)]+\)$/';

	/**
	 * Order notes whose text marks an order status change.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const STATUS_NOTE_PATTERN = '/status changed from/i';

	/**
	 * The steps in order, with their labels.
	 *
	 * @return array<string, string>
	 * @since 1.8.0
	 */
	public static function get_steps(): array {
		return array(
			'environment' => __( 'Store environment', 'wc-kledo' ),
			'connection'  => __( 'Connection to Kledo', 'wc-kledo' ),
			'settings'    => __( 'Plugin settings', 'wc-kledo' ),
			'order'       => __( 'The order', 'wc-kledo' ),
			'payload'     => __( 'Data that would be sent', 'wc-kledo' ),
			'kledo'       => __( 'Status in Kledo', 'wc-kledo' ),
			'history'     => __( 'Sending history', 'wc-kledo' ),
			'queues'      => __( 'Queues and scheduled tasks', 'wc-kledo' ),
			'resend'      => __( 'Resend and record', 'wc-kledo' ),
			'recheck'     => __( 'Check Kledo again', 'wc-kledo' ),
		);
	}

	/**
	 * Start a diagnosis.
	 *
	 * @param  int   $order_id
	 * @param  array $options {
	 *     @type bool $resend              Resend what Kledo does not hold, recording it.
	 *     @type bool $full_customer_data  Keep personal customer data unmasked.
	 * }
	 *
	 * @return array|WP_Error The report so far.
	 * @since 1.8.0
	 */
	public function start( int $order_id, array $options ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wc_kledo_diag_order', __( 'Order not found. Check the order number and try again.', 'wc-kledo' ) );
		}

		$report = array(
			'code'       => 'DIAG-' . strtoupper( wp_generate_password( 5, false ) ),
			'created_at' => time(),
			'created_by' => get_current_user_id(),
			'order_id'   => $order->get_id(),
			'order_no'   => $order->get_order_number(),
			'options'    => array(
				'resend'             => ! empty( $options['resend'] ),
				'full_customer_data' => ! empty( $options['full_customer_data'] ),
			),
			'steps'      => array(),
			'requests'   => array(),
			'conclusion' => null,
		);

		$this->save( $report );

		return $report;
	}

	/**
	 * Run one step of a diagnosis and store its result.
	 *
	 * @param  string $code
	 * @param  string $step
	 *
	 * @return array|WP_Error The step result.
	 * @since 1.8.0
	 */
	public function run_step( string $code, string $step ) {
		$report = $this->get( $code );

		if ( null === $report ) {
			return new WP_Error( 'wc_kledo_diag_report', __( 'This diagnosis has expired. Start a new one.', 'wc-kledo' ) );
		}

		if ( ! array_key_exists( $step, self::get_steps() ) ) {
			return new WP_Error( 'wc_kledo_diag_step', __( 'Unknown step.', 'wc-kledo' ) );
		}

		$order = wc_get_order( $report['order_id'] );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wc_kledo_diag_order', __( 'Order not found. Check the order number and try again.', 'wc-kledo' ) );
		}

		$trace = new WC_Kledo_Debug_Trace( $report['options']['full_customer_data'] );
		$trace->start();

		try {
			$result = $this->{'step_' . $step}( $order, $report );
		} catch ( Throwable $exception ) {
			$result = $this->result(
				'fail',
				/* translators: %s: error message */
				sprintf( __( 'This step stopped with an error: %s', 'wc-kledo' ), wc_kledo_sanitize_api_error_message( $exception->getMessage() ) ),
				array(),
				array( 'unknown' )
			);
		} finally {
			$trace->stop();
		}

		// A User-Agent rewritten by another plugin is found here, on whichever request saw it.
		foreach ( $trace->get_entries() as $entry ) {
			if ( '' !== $entry['final_user_agent'] && ! preg_match( self::ACCEPTED_USER_AGENT, $entry['final_user_agent'] ) ) {
				$result['causes'][]                   = 'user_agent_modified';
				$result['status']                     = 'fail';
				$result['details']['user_agent_sent'] = $entry['final_user_agent'];
			}

			$report['requests'][] = array_merge( array( 'step' => $step ), $entry );
		}

		$result['causes']         = array_values( array_unique( $result['causes'] ) );
		$report['steps'][ $step ] = $result;

		$this->save( $report );

		return $result;
	}

	/**
	 * Close a diagnosis: pick the conclusion, note it on the order, add it to the history.
	 *
	 * @param  string $code
	 *
	 * @return array|WP_Error The finished report.
	 * @since 1.8.0
	 */
	public function finish( string $code ) {
		$report = $this->get( $code );

		if ( null === $report ) {
			return new WP_Error( 'wc_kledo_diag_report', __( 'This diagnosis has expired. Start a new one.', 'wc-kledo' ) );
		}

		$causes = array();

		foreach ( $report['steps'] as $step ) {
			$causes = array_merge( $causes, $step['causes'] );
		}

		$report['conclusion'] = $this->conclude( array_values( array_unique( $causes ) ) );

		$this->save( $report );

		$history = get_option( self::HISTORY_OPTION, array() );
		$history = is_array( $history ) ? $history : array();

		array_unshift( $history, $report['code'] );
		update_option( self::HISTORY_OPTION, array_slice( array_values( array_unique( $history ) ), 0, self::HISTORY_SIZE ), false );

		$order = wc_get_order( $report['order_id'] );

		if ( $order instanceof WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: report code, 2: conclusion */
					__( 'Kledo: diagnosis %1$s was run. Likely cause: %2$s', 'wc-kledo' ),
					$report['code'],
					$report['conclusion']['title']
				)
			);
		}

		return $report;
	}

	/**
	 * A stored report, or null when it expired.
	 *
	 * @param  string $code
	 *
	 * @return array|null
	 * @since 1.8.0
	 */
	public function get( string $code ): ?array {
		if ( ! preg_match( '/^DIAG-[A-Za-z0-9]{5}$/', $code ) ) {
			return null;
		}

		$report = get_transient( self::REPORT_PREFIX . strtolower( $code ) );

		return is_array( $report ) ? $report : null;
	}

	/**
	 * The recent reports that still exist, newest first.
	 *
	 * @return array[]
	 * @since 1.8.0
	 */
	public function get_history(): array {
		$codes   = get_option( self::HISTORY_OPTION, array() );
		$reports = array();

		foreach ( is_array( $codes ) ? $codes : array() as $code ) {
			$report = $this->get( (string) $code );

			if ( null !== $report ) {
				$reports[] = $report;
			}
		}

		return $reports;
	}

	/**
	 * The report as plain text, for pasting into WhatsApp or an email.
	 *
	 * @param  array $report
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public function to_text( array $report ): string {
		$icons = array(
			'ok'   => '[OK]',
			'warn' => '[!]',
			'fail' => '[X]',
			'info' => '[i]',
		);

		$lines   = array();
		$lines[] = sprintf( 'Kledo for WooCommerce — %s', $report['code'] );
		$lines[] = sprintf( '%s: #%s (ID %d)', __( 'Order', 'wc-kledo' ), $report['order_no'], $report['order_id'] );
		$lines[] = sprintf( '%s: %s (%s UTC)', __( 'Time', 'wc-kledo' ), wp_date( 'Y-m-d H:i:s', $report['created_at'] ), gmdate( 'Y-m-d H:i:s', $report['created_at'] ) );
		$lines[] = '';

		if ( ! empty( $report['conclusion'] ) ) {
			$lines[] = __( 'Likely cause', 'wc-kledo' ) . ': ' . $report['conclusion']['title'];
			$lines[] = $report['conclusion']['explanation'];
			$lines[] = __( 'What to do', 'wc-kledo' ) . ': ' . $report['conclusion']['fix'];
			$lines[] = '';
		}

		foreach ( self::get_steps() as $key => $label ) {
			if ( ! isset( $report['steps'][ $key ] ) ) {
				continue;
			}

			$step    = $report['steps'][ $key ];
			$lines[] = sprintf( '%s %s — %s', $icons[ $step['status'] ] ?? '[ ]', $label, $step['summary'] );

			foreach ( $step['details'] as $detail_key => $detail_value ) {
				$lines[] = sprintf( '    %s: %s', $detail_key, wp_json_encode( $detail_value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
		}

		if ( ! empty( $report['requests'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Requests to Kledo', 'wc-kledo' ) . ':';

			foreach ( $report['requests'] as $request ) {
				$lines[] = sprintf(
					'  [%s] %s %s → %s (%s ms)%s',
					$request['step'],
					$request['method'],
					$request['url'],
					null === $request['status'] ? '—' : (string) $request['status'],
					null === $request['duration_ms'] ? '—' : (string) $request['duration_ms'],
					'' !== $request['error'] ? ' ' . $request['error'] : ''
				);
				$lines[] = '    User-Agent: ' . $request['final_user_agent'];

				if ( '' !== $request['response_body'] ) {
					$lines[] = '    ' . __( 'Response', 'wc-kledo' ) . ': ' . $request['response_body'];
				}
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The report as JSON, for the Kledo team.
	 *
	 * @param  array $report
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public function to_json( array $report ): string {
		return (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Every step shares one signature so run_step() can call it by name.

	/**
	 * Step 1: versions, storage, timezone, scheduled tasks, plugins that might interfere.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_environment( WC_Order $order, array $report ): array {
		$details = array(
			'plugin_version'      => WC_KLEDO_VERSION,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php_version'         => PHP_VERSION,
			'order_storage'       => wc_kledo_uses_hpos() ? 'HPOS' : 'posts',
			'timezone'            => wp_timezone_string(),
			'disable_wp_cron'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'http_block_external' => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL,
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'active_plugins'      => $this->get_active_plugin_names(),
		);

		$causes = array();
		$status = 'ok';

		if ( $details['http_block_external'] ) {
			$causes[] = 'connection_error';
			$status   = 'fail';
		}

		return $this->result(
			$status,
			sprintf( 'WordPress %1$s · WooCommerce %2$s · PHP %3$s · %4$s', $details['wordpress_version'], $details['woocommerce_version'], $details['php_version'], $details['order_storage'] ),
			$details,
			$causes
		);
	}

	/**
	 * Step 2: is the plugin connected, and does Kledo accept the key?
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_connection( WC_Order $order, array $report ): array {
		$connection = wc_kledo()->get_connection_handler();
		$endpoint   = (string) $connection->get_api_endpoint();
		$details    = array(
			'api_connection_enabled' => wc_string_to_bool( get_option( WC_Kledo_Configure_Screen::SETTING_ENABLE_API_CONNECTION, 'yes' ) ),
			'configured'             => $connection->is_configured(),
			'endpoint_host'          => (string) wp_parse_url( $endpoint, PHP_URL_HOST ),
		);

		if ( ! $details['api_connection_enabled'] ) {
			return $this->result( 'fail', __( 'The API connection is switched off in the Configure tab.', 'wc-kledo' ), $details, array( 'api_disabled' ) );
		}

		if ( ! $details['configured'] ) {
			return $this->result( 'fail', __( 'The API key or the API endpoint URL is empty.', 'wc-kledo' ), $details, array( 'not_configured' ) );
		}

		$status_handler = wc_kledo()->get_connection_status();
		$status         = $status_handler->refresh();

		if ( $status_handler->is_unauthenticated() ) {
			$details['no_website_access'] = $status_handler->is_missing_website_access();

			return $this->result( 'fail', __( 'Kledo refused the API key (HTTP 401).', 'wc-kledo' ), $details, array( 'auth_failed' ) );
		}

		if ( null === $status ) {
			return $this->result( 'fail', __( 'Kledo could not be reached to check the API key.', 'wc-kledo' ), $details, array( 'connection_error' ) );
		}

		unset( $status['short_token_masked'] );
		$details['key'] = $status;

		return $this->result( 'ok', __( 'Connected; Kledo accepts the API key.', 'wc-kledo' ), $details, array() );
	}

	/**
	 * Step 3: the settings that decide what is sent.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_settings( WC_Order $order, array $report ): array {
		$details = array(
			'create_sales_order'          => get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME, 'yes' ),
			'create_invoice'              => get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME, 'yes' ),
			'create_order_on_completed'   => wc_kledo_create_order_on_completed(),
			'link_invoice_to_sales_order' => wc_kledo_link_invoice_to_order(),
			'close_sales_order'           => wc_kledo_close_order_on_invoice(),
			'invoice_status'              => get_option( WC_Kledo_Invoice_Screen::INVOICE_STATUS_OPTION_NAME, 'unpaid' ),
			'payment_account'             => wc_kledo_get_payment_account(),
			'invoice_prefix'              => wc_kledo_get_invoice_prefix(),
			'order_prefix'                => wc_kledo_get_order_prefix(),
			'invoice_warehouse'           => wc_kledo_get_invoice_warehouse(),
			'order_warehouse'             => wc_kledo_get_order_warehouse(),
			'daily_sync'                  => get_option( WC_Kledo_Sync_Screen::DAILY_ENABLED_OPTION, 'no' ),
		);

		if ( empty( WC_Kledo_Sync_Candidates::get_enabled_types() ) ) {
			return $this->result( 'fail', __( 'Both "Enable Create Order" and "Enable Create Invoice" are off.', 'wc-kledo' ), $details, array( 'feature_disabled' ) );
		}

		if ( 'yes' === wc_kledo_paid_status() && '' === $details['payment_account'] ) {
			return $this->result( 'warn', __( 'Invoices are created as paid, but no payment account is chosen in the Invoice tab.', 'wc-kledo' ), $details, array( 'payment_account_missing' ) );
		}

		return $this->result(
			'ok',
			sprintf(
				/* translators: 1: on/off, 2: on/off */
				__( 'Sales order: %1$s · Invoice: %2$s', 'wc-kledo' ),
				wc_string_to_bool( $details['create_sales_order'] ) ? __( 'on', 'wc-kledo' ) : __( 'off', 'wc-kledo' ),
				wc_string_to_bool( $details['create_invoice'] ) ? __( 'on', 'wc-kledo' ) : __( 'off', 'wc-kledo' )
			),
			$details,
			array()
		);
	}

	/**
	 * Step 4: what the order is, what it went through, and what should have been sent.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_order( WC_Order $order, array $report ): array {
		$notes        = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$status_notes = array();
		$kledo_notes  = array();

		foreach ( $notes as $note ) {
			$line = wp_date( 'Y-m-d H:i', $note->date_created->getTimestamp() ) . ' — ' . wp_strip_all_tags( $note->content );

			if ( preg_match( self::STATUS_NOTE_PATTERN, $note->content ) ) {
				$status_notes[] = $line;
			} elseif ( 0 === strpos( $note->content, 'Kledo' ) && ! preg_match( '/DIAG-[A-Z0-9]{5}/', $note->content ) ) {
				// A note left by an earlier diagnosis is not a trace of the order being sent.
				$kledo_notes[] = $line;
			}
		}

		$meta = array();

		foreach ( $order->get_meta_data() as $meta_item ) {
			if ( 0 === strpos( $meta_item->key, '_wc_kledo_' ) ) {
				$meta[ $meta_item->key ] = $meta_item->value;
			}
		}

		$date    = $order->get_date_created();
		$details = array(
			'status'         => $order->get_status(),
			'created'        => $date ? wp_date( 'Y-m-d H:i', $date->getTimestamp() ) : '',
			'payment_method' => $order->get_payment_method_title(),
			'total'          => $order->get_total(),
			'sales_order'    => wc_kledo_get_remote_state( $order, 'order' ),
			'invoice'        => wc_kledo_get_remote_state( $order, 'invoice' ),
			'should_send'    => WC_Kledo_Sync_Candidates::get_types_to_send( $order ),
			'status_history' => array_slice( $status_notes, 0, 20 ),
			'kledo_notes'    => array_slice( $kledo_notes, 0, 20 ),
			'kledo_meta'     => $meta,
		);

		$summary = sprintf(
			/* translators: 1: order status, 2: date */
			__( 'Status %1$s, created %2$s.', 'wc-kledo' ),
			wc_get_order_status_name( $order->get_status() ),
			$details['created']
		);

		if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			return $this->result( 'fail', $summary . ' ' . __( 'Only Processing and Completed orders are sent to Kledo.', 'wc-kledo' ), $details, array( 'status_not_triggering' ) );
		}

		// Processing or Completed, nothing ever recorded by this plugin: the status changed while
		// the plugin was not there to see it.
		if ( empty( $kledo_notes ) && 'not_sent' === $details['sales_order'] && 'not_sent' === $details['invoice'] ) {
			return $this->result( 'fail', $summary . ' ' . __( 'The plugin never tried to send it — it most likely reached this status before the plugin was installed or while it was inactive.', 'wc-kledo' ), $details, array( 'never_triggered' ) );
		}

		return $this->result( 'ok', $summary, $details, array() );
	}

	/**
	 * Step 5: build the bodies that would be sent, and check them, without sending.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_payload( WC_Order $order, array $report ): array {
		$details  = array();
		$warnings = array();
		$causes   = array();
		$enabled  = WC_Kledo_Sync_Candidates::get_enabled_types();

		foreach ( $enabled as $type ) {
			$body = 'order' === $type
				? ( new WC_Kledo_Request_Order() )->build_body( $order )
				: ( new WC_Kledo_Request_Invoice() )->build_body( $order );

			$details[ $type ] = $report['options']['full_customer_data'] ? $body : wc_kledo_mask_customer_data( $body );
		}

		$line_count = count( $order->get_items() );
		$items      = isset( $details[ $enabled[0] ?? 'order' ]['items'] ) ? $details[ $enabled[0] ?? 'order' ]['items'] : array();

		if ( 0 === $line_count || empty( $items ) ) {
			$warnings[] = __( 'No product would be sent: the order has no products, or every product was deleted from the store.', 'wc-kledo' );
			$causes[]   = 'items_missing';
		} elseif ( count( $items ) < $line_count ) {
			/* translators: %d: number of order lines */
			$warnings[] = sprintf( __( '%d order line(s) are skipped because their product was deleted from the store.', 'wc-kledo' ), $line_count - count( $items ) );
			$causes[]   = 'items_missing';
		}

		foreach ( $items as $item ) {
			if ( '' === (string) $item['code'] ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( 'Product "%s" has no SKU.', 'wc-kledo' ), $item['name'] );
			}

			if ( '' === (string) $item['regular_price'] || 0.0 === (float) $item['regular_price'] ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( 'Product "%s" has no regular price.', 'wc-kledo' ), $item['name'] );
			}
		}

		$details['warnings'] = $warnings;

		if ( ! empty( $causes ) ) {
			return $this->result( 'fail', implode( ' ', $warnings ), $details, $causes );
		}

		if ( ! empty( $warnings ) ) {
			return $this->result( 'warn', implode( ' ', $warnings ), $details, array() );
		}

		/* translators: %d: number of products */
		return $this->result( 'ok', sprintf( _n( '%d product, data looks complete.', '%d products, data looks complete.', count( $items ), 'wc-kledo' ), count( $items ) ), $details, array() );
	}

	/**
	 * Step 6: what Kledo holds for this order, read without changing anything here.
	 *
	 * Also reads `order_failure` / `invoice_failure` when the Kledo API provides them — the reason
	 * a background job failed on Kledo's side, which the plugin cannot see otherwise.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_kledo( WC_Order $order, array $report ): array {
		if ( ! wc_kledo()->get_connection_handler()->is_configured() ) {
			return $this->result( 'info', __( 'Skipped: the plugin is not connected.', 'wc-kledo' ), array(), array() );
		}

		$request  = new WC_Kledo_Request_Transaction_Status();
		$response = $request->get_status( $order->get_id() );
		$status   = wc_kledo_read_transaction_status( $response );
		$data     = is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		if ( ! $status['readable'] ) {
			$code = (int) $request->get_response_code();

			return $this->result(
				'fail',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Kledo did not answer the status request (HTTP %d).', 'wc-kledo' ), $code ),
				array( 'http_status' => $code ),
				array( 401 === $code ? 'auth_failed' : ( 429 === $code ? 'rate_limited' : 'connection_error' ) )
			);
		}

		$details = array(
			'sales_order'  => $status['order'],
			'invoice'      => $status['invoice'],
			'linked'       => $status['linked'],
			'external_ref' => array(
				'order'   => wc_kledo_get_order_prefix() . '#' . $order->get_id(),
				'invoice' => wc_kledo_get_invoice_prefix() . '#' . $order->get_id(),
			),
		);

		foreach ( array( 'order_failure', 'invoice_failure' ) as $failure_key ) {
			if ( ! empty( $data[ $failure_key ] ) ) {
				$details[ $failure_key ] = $data[ $failure_key ];
			}
		}

		$missing = array();

		foreach ( WC_Kledo_Sync_Candidates::get_enabled_types() as $type ) {
			$expected = 'invoice' === $type ? $order->has_status( 'completed' ) : $order->has_status( array( 'processing', 'completed' ) );

			if ( $expected && null === $status[ $type ] && ! ( 'order' === $type && $order->has_status( 'completed' ) && 'no' === wc_kledo_create_order_on_completed() ) ) {
				$missing[] = $type;
			}
		}

		$details['missing'] = $missing;

		$summary = sprintf(
			/* translators: 1: present/absent, 2: present/absent */
			__( 'Sales order: %1$s · Invoice: %2$s', 'wc-kledo' ),
			null !== $status['order'] ? wc_kledo_get_kledo_reference( $status['order'] ) : __( 'not in Kledo', 'wc-kledo' ),
			null !== $status['invoice'] ? wc_kledo_get_kledo_reference( $status['invoice'] ) : __( 'not in Kledo', 'wc-kledo' )
		);

		if ( empty( $missing ) ) {
			return $this->result( 'ok', $summary, $details, array( 'in_kledo' ) );
		}

		$causes = array();

		foreach ( $missing as $type ) {
			$state = wc_kledo_get_remote_state( $order, $type );

			if ( in_array( $state, array( 'missing' ), true ) || ! empty( $details[ $type . '_failure' ] ) ) {
				$causes[] = 'failed_in_kledo';
			} elseif ( 'verifying' === $state ) {
				$causes[] = 'pending_in_kledo';
			}
		}

		return $this->result( 'fail', $summary, $details, $causes );
	}

	/**
	 * Step 7: what happened the times it was sent — queue rows and their errors.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_history( WC_Order $order, array $report ): array {
		$details = array();
		$causes  = array();
		$queues  = array(
			'retry'        => get_option( 'wc_kledo_failed_transactions', array() ),
			'verification' => get_option( wc_kledo_get_verification_queue_option_name(), array() ),
		);

		foreach ( array( 'order', 'invoice' ) as $type ) {
			foreach ( $queues as $queue_name => $queue ) {
				$key = $type . ':' . $order->get_id();

				if ( is_array( $queue ) && isset( $queue[ $key ] ) ) {
					$details[ $queue_name . '_' . $type ] = $queue[ $key ];
				}
			}

			$state = wc_kledo_get_remote_state( $order, $type );
			$error = (string) ( $details[ 'retry_' . $type ]['last_error'] ?? '' );

			if ( 'rejected' === $state ) {
				$causes[] = 'rejected';
			} elseif ( 'waiting_sales_order' === $state ) {
				$causes[] = 'waiting_sales_order';
			} elseif ( in_array( $state, array( 'retrying', 'failed' ), true ) ) {
				if ( false !== strpos( $error, '401' ) ) {
					$causes[] = 'auth_failed';
				} elseif ( false !== strpos( $error, '429' ) ) {
					$causes[] = 'rate_limited';
				} elseif ( false !== stripos( $error, 'Connection error' ) || false !== stripos( $error, 'timed out' ) ) {
					$causes[] = 'connection_error';
				} else {
					$causes[] = 'send_failed';
				}
			}
		}

		$closure = wc_kledo_get_closure_check_state( $order->get_id() );

		if ( '' !== $closure ) {
			$details['closure_check'] = $closure;
		}

		$details['log_lines'] = $this->get_log_lines( $order->get_id() );

		if ( empty( $causes ) ) {
			return $this->result( 'ok', __( 'No failed sends on record.', 'wc-kledo' ), $details, array() );
		}

		$errors = array();

		foreach ( array( 'order', 'invoice' ) as $type ) {
			if ( ! empty( $details[ 'retry_' . $type ]['last_error'] ) ) {
				$errors[] = WC_Kledo_Status_Badge::type_label( $type ) . ': ' . $details[ 'retry_' . $type ]['last_error'];
			}
		}

		return $this->result(
			in_array( 'waiting_sales_order', $causes, true ) && 1 === count( $causes ) ? 'warn' : 'fail',
			! empty( $errors ) ? implode( ' · ', $errors ) : __( 'A send is held or failed; see the details.', 'wc-kledo' ),
			$details,
			$causes
		);
	}

	/**
	 * Step 8: are the background tasks running at all?
	 *
	 * A queue row more than 15 minutes past its run time means nothing is processing the queues —
	 * usually WP-Cron that cannot fire on this server.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_queues( WC_Order $order, array $report ): array {
		$now     = time();
		$overdue = 0;
		$details = array();

		foreach (
			array(
				'retry'        => get_option( 'wc_kledo_failed_transactions', array() ),
				'verification' => get_option( wc_kledo_get_verification_queue_option_name(), array() ),
				'closure'      => get_option( wc_kledo_get_closure_queue_option_name(), array() ),
			) as $queue_name => $queue
		) {
			$queue      = is_array( $queue ) ? $queue : array();
			$late_count = 0;

			foreach ( $queue as $item ) {
				if ( 'failed' !== ( $item['status'] ?? '' ) && isset( $item['next_run_at'] ) && (int) $item['next_run_at'] < $now - 15 * MINUTE_IN_SECONDS ) {
					++$late_count;
				}
			}

			$details[ $queue_name ] = array(
				'rows'    => count( $queue ),
				'overdue' => $late_count,
			);

			$overdue += $late_count;
		}

		$details['wp_cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$details['action_scheduler_past_due'] = count(
				as_get_scheduled_actions(
					array(
						'status'       => 'pending',
						'date'         => gmdate( 'Y-m-d H:i:s', $now - 15 * MINUTE_IN_SECONDS ),
						'date_compare' => '<',
						'per_page'     => 50,
					),
					'ids'
				)
			);
		}

		$sync = wc_kledo()->get_sync_job()->get_job();

		if ( null !== $sync ) {
			$details['sync_job'] = array(
				'number' => $sync['number'],
				'status' => $sync['status'],
			);
		}

		if ( $overdue > 0 || ( $details['action_scheduler_past_due'] ?? 0 ) > 0 ) {
			return $this->result(
				'fail',
				/* translators: %d: number of overdue tasks */
				sprintf( __( '%d background task(s) are more than 15 minutes late — scheduled tasks are not running on this site.', 'wc-kledo' ), $overdue + (int) ( $details['action_scheduler_past_due'] ?? 0 ) ),
				$details,
				array( 'cron_not_running' )
			);
		}

		return $this->result( 'ok', __( 'Background tasks are running on time.', 'wc-kledo' ), $details, array() );
	}

	/**
	 * Step 9: resend what Kledo does not hold, recording the requests.
	 *
	 * Refuses to send anything Kledo already holds — the point is to see why a send fails, not to
	 * create a duplicate.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_resend( WC_Order $order, array $report ): array {
		if ( empty( $report['options']['resend'] ) ) {
			return $this->result( 'info', __( 'Skipped: resending was not selected.', 'wc-kledo' ), array(), array() );
		}

		$missing = $report['steps']['kledo']['details']['missing'] ?? null;

		if ( ! is_array( $missing ) ) {
			return $this->result( 'info', __( 'Skipped: Kledo could not be read, so it is not safe to resend.', 'wc-kledo' ), array(), array() );
		}

		if ( empty( $missing ) ) {
			return $this->result( 'info', __( 'Skipped: everything this order needs is already in Kledo.', 'wc-kledo' ), array(), array() );
		}

		$bridge  = wc_kledo()->get_woocommerce_bridge();
		$details = array();
		$causes  = array();
		$failed  = false;

		foreach ( array( 'order', 'invoice' ) as $type ) {
			if ( ! in_array( $type, $missing, true ) ) {
				continue;
			}

			$result = $bridge->deliver(
				$order,
				$type,
				array(
					'trigger'         => 'manual_admin',
					'manual_mode'     => 'first',
					// Kledo said it does not hold this, so a record marked "sent" here is stale.
					'force_if_synced' => true,
				)
			);

			$details[ $type ] = $result;
			$order            = wc_get_order( $order->get_id() );

			if ( ! empty( $result['success'] ) || 'awaiting_sales_order' === ( $result['reason'] ?? '' ) ) {
				continue;
			}

			$failed = true;
			$code   = (int) $result['http_code'];

			if ( 401 === $code ) {
				$causes[] = 'auth_failed';
			} elseif ( 429 === $code ) {
				$causes[] = 'rate_limited';
			} elseif ( ! empty( $result['permanent'] ) ) {
				$causes[] = 'rejected';
			} elseif ( 0 === $code ) {
				$causes[] = 'connection_error';
			} else {
				$causes[] = 'send_failed';
			}
		}

		if ( $failed ) {
			return $this->result( 'fail', __( 'Kledo did not accept the resend; see the recorded request below.', 'wc-kledo' ), $details, $causes );
		}

		return $this->result( 'ok', __( 'Kledo accepted the resend. The next step checks whether it was created.', 'wc-kledo' ), $details, array() );
	}

	/**
	 * Step 10: after a resend, read Kledo again.
	 *
	 * The page calls this up to three times, 20 seconds apart; a later call overwrites the
	 * earlier answer.
	 *
	 * @param  \WC_Order $order
	 * @param  array     $report
	 *
	 * @return array
	 * @since 1.8.0
	 */
	private function step_recheck( WC_Order $order, array $report ): array {
		if ( empty( $report['steps']['resend'] ) || 'ok' !== $report['steps']['resend']['status'] ) {
			return $this->result( 'info', __( 'Skipped: nothing was resent.', 'wc-kledo' ), array(), array() );
		}

		$read    = wc_kledo()->get_transaction_verifier()->refresh_order( $order );
		$missing = array_diff( $report['steps']['kledo']['details']['missing'] ?? array(), $read['found'] );

		if ( empty( $missing ) ) {
			return $this->result( 'ok', __( 'Kledo has now created everything that was resent.', 'wc-kledo' ), array( 'found' => $read['found'] ), array( 'fixed_by_resend' ) );
		}

		return $this->result(
			'warn',
			__( 'Kledo accepted the resend but has not created it yet. If it still is not there in a few minutes, the problem is on Kledo\'s side — send this report to Kledo.', 'wc-kledo' ),
			array(
				'found'       => $read['found'],
				'not_yet'     => array_values( $missing ),
				'final_check' => true,
			),
			array( 'pending_in_kledo' )
		);
	}

	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Pick the conclusion: the most serious cause found.
	 *
	 * @param  string[] $causes
	 *
	 * @return array{cause: string, title: string, explanation: string, fix: string}
	 * @since 1.8.0
	 */
	private function conclude( array $causes ): array {
		// A resend that worked is the headline, but what made the order go missing in the first
		// place still matters — otherwise the next order goes missing the same way.
		if ( in_array( 'fixed_by_resend', $causes, true ) ) {
			$original = $this->conclude( array_values( array_diff( $causes, array( 'fixed_by_resend', 'in_kledo' ) ) ) );
			$fixed    = $this->describe_cause( 'fixed_by_resend' );

			if ( 'unknown' !== $original['cause'] ) {
				$fixed['explanation'] = sprintf(
					/* translators: 1: why it went missing, 2: explanation of that cause */
					__( 'It is in Kledo now. Why it was missing: %1$s — %2$s', 'wc-kledo' ),
					$original['title'],
					$original['explanation']
				);
			}

			return array_merge( array( 'cause' => 'fixed_by_resend' ), $fixed );
		}

		$priority = array(
			'api_disabled',
			'not_configured',
			'auth_failed',
			'user_agent_modified',
			'connection_error',
			'feature_disabled',
			'status_not_triggering',
			// Ahead of `never_triggered`: sending an order with products missing fails anyway,
			// so the products are what to fix first.
			'items_missing',
			'never_triggered',
			'rejected',
			'rate_limited',
			'payment_account_missing',
			'cron_not_running',
			'failed_in_kledo',
			'send_failed',
			'waiting_sales_order',
			'pending_in_kledo',
			'fixed_by_resend',
			'in_kledo',
		);

		foreach ( $priority as $cause ) {
			if ( in_array( $cause, $causes, true ) ) {
				return array_merge( array( 'cause' => $cause ), $this->describe_cause( $cause ) );
			}
		}

		return array_merge( array( 'cause' => 'unknown' ), $this->describe_cause( 'unknown' ) );
	}

	/**
	 * Plain words for each cause: what it means and what to do.
	 *
	 * @param  string $cause
	 *
	 * @return array{title: string, explanation: string, fix: string}
	 * @since 1.8.0
	 */
	private function describe_cause( string $cause ): array {
		$causes = array(
			'api_disabled'            => array(
				__( 'The connection to Kledo is switched off', 'wc-kledo' ),
				__( 'The plugin does not send anything while "Enable Integration" is off.', 'wc-kledo' ),
				__( 'Switch it on in WooCommerce > Kledo > Configure, then resend the order.', 'wc-kledo' ),
			),
			'not_configured'          => array(
				__( 'The plugin is not connected to Kledo', 'wc-kledo' ),
				__( 'The API key or the API endpoint URL is empty, so nothing can be sent.', 'wc-kledo' ),
				__( 'Fill in both in WooCommerce > Kledo > Configure, then resend the order.', 'wc-kledo' ),
			),
			'auth_failed'             => array(
				__( 'Kledo refuses the API key', 'wc-kledo' ),
				__( 'The saved API key has expired, was revoked, or belongs to an account that no longer has access to the company.', 'wc-kledo' ),
				__( 'Create a new API key in Kledo, save it in WooCommerce > Kledo > Configure, then resend.', 'wc-kledo' ),
			),
			'user_agent_modified'     => array(
				__( 'Another plugin changes the requests to Kledo', 'wc-kledo' ),
				__( 'Kledo only accepts requests that identify themselves as this plugin, and another plugin or the server rewrote that identification (the User-Agent), so Kledo refuses them.', 'wc-kledo' ),
				__( 'Send this report to Kledo; it shows the changed value. Usually a security or performance plugin that rewrites outgoing requests has to be configured to leave them alone.', 'wc-kledo' ),
			),
			'connection_error'        => array(
				__( 'The store cannot reach Kledo', 'wc-kledo' ),
				__( 'The request did not get an answer — the server may block outgoing connections, or there was a network, DNS or SSL problem.', 'wc-kledo' ),
				__( 'Ask your hosting provider whether outgoing connections to the Kledo API are allowed, then resend.', 'wc-kledo' ),
			),
			'feature_disabled'        => array(
				__( 'Creating transactions is switched off', 'wc-kledo' ),
				__( 'Both "Enable Create Order" and "Enable Create Invoice" are off, so nothing is sent.', 'wc-kledo' ),
				__( 'Switch on what you need in the Order and Invoice tabs.', 'wc-kledo' ),
			),
			'status_not_triggering'   => array(
				__( 'The order status does not send anything', 'wc-kledo' ),
				__( 'Only Processing orders get a sales order and only Completed orders get an invoice. This order has another status.', 'wc-kledo' ),
				__( 'Change the order to Processing or Completed when it is ready.', 'wc-kledo' ),
			),
			'never_triggered'         => array(
				__( 'The plugin never saw this order', 'wc-kledo' ),
				__( 'The order reached Processing or Completed before the plugin was installed, or while it was inactive, so it was never sent.', 'wc-kledo' ),
				__( 'Send it with "Kledo: Send sales order / invoice" in the order actions, or send many at once from the Sync tab.', 'wc-kledo' ),
			),
			'items_missing'           => array(
				__( 'Products are missing from the order', 'wc-kledo' ),
				__( 'Some or all products of this order were deleted from the store, so they cannot be sent.', 'wc-kledo' ),
				__( 'Restore the products or create the transaction in Kledo manually.', 'wc-kledo' ),
			),
			'rejected'                => array(
				__( 'Kledo rejected the data', 'wc-kledo' ),
				__( 'Kledo refused the order because something in it is not valid in Kledo — the error message in the report says what.', 'wc-kledo' ),
				__( 'Correct what the error names (product, customer, account, warehouse), then resend.', 'wc-kledo' ),
			),
			'rate_limited'            => array(
				__( 'Too many requests at once', 'wc-kledo' ),
				__( 'Kledo asked the store to slow down.', 'wc-kledo' ),
				__( 'Wait a few minutes; the plugin retries automatically.', 'wc-kledo' ),
			),
			'payment_account_missing' => array(
				__( 'No payment account is chosen', 'wc-kledo' ),
				__( 'Invoices are created as paid, but there is no account to record the payment on.', 'wc-kledo' ),
				__( 'Choose a Payment Account in the Invoice tab, then resend.', 'wc-kledo' ),
			),
			'cron_not_running'        => array(
				__( 'Scheduled tasks are not running on this site', 'wc-kledo' ),
				__( 'Retries and checks are done by WordPress scheduled tasks (WP-Cron), and they are not running, so failed sends are never retried.', 'wc-kledo' ),
				__( 'Ask your hosting provider to enable WP-Cron or a real cron job for WordPress. Meanwhile, resend from the Kledo Status tab.', 'wc-kledo' ),
			),
			'failed_in_kledo'         => array(
				__( 'Kledo accepted the order but could not create it', 'wc-kledo' ),
				__( 'The store sent it correctly, but creating it failed inside Kledo. The reason is only recorded on Kledo\'s side.', 'wc-kledo' ),
				__( 'Send this report to Kledo; it contains what the Kledo team needs to find the reason.', 'wc-kledo' ),
			),
			'send_failed'             => array(
				__( 'Sending failed', 'wc-kledo' ),
				__( 'The request to Kledo failed; the error message in the report says how.', 'wc-kledo' ),
				__( 'Resend from the Kledo Status tab. If it keeps failing, send this report to Kledo.', 'wc-kledo' ),
			),
			'waiting_sales_order'     => array(
				__( 'The invoice is waiting for its sales order', 'wc-kledo' ),
				__( 'The invoice is held until the sales order exists in Kledo, so the two can be linked. It is sent automatically.', 'wc-kledo' ),
				__( 'Nothing to do unless it is still waiting after an hour; then check the sales order.', 'wc-kledo' ),
			),
			'pending_in_kledo'        => array(
				__( 'Kledo is still creating it', 'wc-kledo' ),
				__( 'Kledo accepted the order and has not finished creating it yet.', 'wc-kledo' ),
				__( 'Check again in a few minutes. If it is still not there after an hour, send this report to Kledo.', 'wc-kledo' ),
			),
			'fixed_by_resend'         => array(
				__( 'Fixed by resending', 'wc-kledo' ),
				__( 'The order was missing in Kledo and is there now after the resend.', 'wc-kledo' ),
				__( 'Nothing more to do. If this keeps happening, send this report to Kledo.', 'wc-kledo' ),
			),
			'in_kledo'                => array(
				__( 'Everything is already in Kledo', 'wc-kledo' ),
				__( 'Kledo holds everything this order should have.', 'wc-kledo' ),
				__( 'If you do not see it in Kledo, search for the reference numbers shown in step 6.', 'wc-kledo' ),
			),
			'unknown'                 => array(
				__( 'No cause could be found from the store', 'wc-kledo' ),
				__( 'Every check on the store side passed.', 'wc-kledo' ),
				__( 'Send this report to Kledo for investigation.', 'wc-kledo' ),
			),
		);

		$entry = $causes[ $cause ] ?? $causes['unknown'];

		return array(
			'title'       => $entry[0],
			'explanation' => $entry[1],
			'fix'         => $entry[2],
		);
	}

	/**
	 * Lines of the plugin's WooCommerce logs from the last 7 days that mention this order.
	 *
	 * Reads the log files directly (`wc-kledo` and `wc-kledo-debug`). A shop logging to the
	 * database instead has no files to read; the report says so rather than looking empty.
	 *
	 * @param  int $order_id
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	private function get_log_lines( int $order_id ): array {
		if ( ! defined( 'WC_LOG_DIR' ) || ! is_dir( WC_LOG_DIR ) ) {
			return array( __( 'Log files are not available on this store (WooCommerce may be logging to the database).', 'wc-kledo' ) );
		}

		$files = glob( trailingslashit( WC_LOG_DIR ) . 'wc-kledo*.log' );
		$lines = array();

		// The plugin's log lines name an order in a few spellings — with a space, an id= prefix, a hash
		// or a colon — and the number must end there, so order 12 does not match order 123.
		$pattern = '/\border(?:[ _]?id=|[ #:])#?' . $order_id . '(?!\d)/i';

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( filemtime( $file ) < time() - 7 * DAY_IN_SECONDS ) {
				continue;
			}

			$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a local log file line by line.

			if ( false === $handle ) {
				continue;
			}

			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( preg_match( $pattern, $line ) ) {
					$lines[] = rtrim( $line );
				}
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return array_slice( $lines, -200 );
	}

	/**
	 * Names of the active plugins — names only, enough to spot one that rewrites requests.
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	private function get_active_plugin_names(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$names   = array();

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$names[] = isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ]['Name'] . ' ' . $plugins[ $plugin_file ]['Version'] : (string) $plugin_file;
		}

		return $names;
	}

	/**
	 * A step result.
	 *
	 * @param  string   $status  `ok`, `warn`, `fail` or `info`.
	 * @param  string   $summary
	 * @param  array    $details
	 * @param  string[] $causes
	 *
	 * @return array{status: string, summary: string, details: array, causes: string[]}
	 * @since 1.8.0
	 */
	private function result( string $status, string $summary, array $details, array $causes ): array {
		return array(
			'status'  => $status,
			'summary' => $summary,
			'details' => $details,
			'causes'  => $causes,
		);
	}

	/**
	 * Store a report.
	 *
	 * @param  array $report
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function save( array $report ): void {
		set_transient( self::REPORT_PREFIX . strtolower( $report['code'] ), $report, self::REPORT_LIFETIME );
	}
}
