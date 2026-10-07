<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Kledo bulk actions on the WooCommerce order list.
 *
 * Sending goes through the same `deliver()` pipeline as a status change, so the sales order still
 * goes ahead of its invoice and failures still land in the retry queue. What bulk sending will not
 * do is send again something that is in Kledo or on its way there: that is how a duplicate is
 * made, and a deliberate duplicate stays a per-order action ("Re-send … (may duplicate)").
 *
 * @since 1.8.0
 */
class WC_Kledo_Admin_Order_Bulk_Actions {
	/**
	 * Bulk action: send the sales order.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ACTION_SEND_ORDER = 'wc_kledo_bulk_send_order';

	/**
	 * Bulk action: send the invoice.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ACTION_SEND_INVOICE = 'wc_kledo_bulk_send_invoice';

	/**
	 * Bulk action: read the state back from Kledo.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ACTION_CHECK_STATUS = 'wc_kledo_bulk_check_status';

	/**
	 * Most orders sent in one request. Each is a synchronous call to Kledo — two for an invoice
	 * whose sales order is not there yet — so a larger selection would time the request out.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	public const MAX_SENDS_PER_REQUEST = 20;

	/**
	 * Transient carrying the result summary through the redirect, suffixed with the user id.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const RESULT_TRANSIENT_PREFIX = 'wc_kledo_bulk_result_';

	/**
	 * States a transaction can be bulk-sent from: never sent, or a send that did not stick.
	 *
	 * @var string[]
	 * @since 1.8.0
	 */
	private const SENDABLE_STATES = array( 'not_sent', 'retrying', 'failed', 'rejected', 'missing' );

	/**
	 * Register the hooks for both order storage modes.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'register_screen_hooks' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_result_notice' ) );
	}

	/**
	 * Hook into the order list screen of whichever storage mode is active.
	 *
	 * Both screen ids are hooked: only one list table ever renders, and the screen id of the HPOS
	 * one is resolved by WooCommerce rather than assumed.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function register_screen_hooks(): void {
		foreach ( $this->get_order_list_screen_ids() as $screen_id ) {
			add_filter( 'bulk_actions-' . $screen_id, array( $this, 'add_bulk_actions' ) );
			add_filter( 'handle_bulk_actions-' . $screen_id, array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * Screen ids of the order list in both storage modes.
	 *
	 * @return string[]
	 * @since 1.8.0
	 */
	private function get_order_list_screen_ids(): array {
		$screen_ids = array( 'edit-shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( $screen_ids ) );
	}

	/**
	 * Add the Kledo entries to the bulk action dropdown.
	 *
	 * @param  array $actions
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function add_bulk_actions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		if ( wc_string_to_bool( get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME, 'yes' ) ) ) {
			$actions[ self::ACTION_SEND_ORDER ] = __( 'Kledo: Send sales order', 'wc-kledo' );
		}

		if ( wc_string_to_bool( get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME, 'yes' ) ) ) {
			$actions[ self::ACTION_SEND_INVOICE ] = __( 'Kledo: Send invoice', 'wc-kledo' );
		}

		$actions[ self::ACTION_CHECK_STATUS ] = __( 'Kledo: Check status in Kledo', 'wc-kledo' );

		return $actions;
	}

	/**
	 * Run a Kledo bulk action.
	 *
	 * WooCommerce (HPOS) and WordPress (legacy) have already verified the `bulk-orders` /
	 * `bulk-posts` nonce before this filter runs.
	 *
	 * @param  string $redirect_to
	 * @param  string $action
	 * @param  array  $ids
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public function handle_bulk_action( $redirect_to, $action, $ids ): string {
		$redirect_to = (string) $redirect_to;

		if ( ! in_array( $action, array( self::ACTION_SEND_ORDER, self::ACTION_SEND_INVOICE, self::ACTION_CHECK_STATUS ), true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-kledo' ) );
		}

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

		if ( self::ACTION_CHECK_STATUS === $action ) {
			$summary = wc_kledo()->get_transaction_verifier()->check_orders_now( $order_ids );
			$message = $this->format_check_summary( $summary );
		} else {
			$type    = self::ACTION_SEND_ORDER === $action ? 'order' : 'invoice';
			$message = $this->format_send_summary( $type, $this->send( $order_ids, $type ) );
		}

		set_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), $message, 2 * MINUTE_IN_SECONDS );

		return $redirect_to;
	}

	/**
	 * Send one transaction type for each eligible order.
	 *
	 * @param  int[]  $order_ids
	 * @param  string $type
	 *
	 * @return array{sent: int, waiting: int, failed: int, skipped: int, not_eligible: int, over_limit: int}
	 * @since 1.8.0
	 */
	private function send( array $order_ids, string $type ): array {
		$summary = array(
			'sent'         => 0,
			'waiting'      => 0,
			'failed'       => 0,
			'skipped'      => 0,
			'not_eligible' => 0,
			'over_limit'   => 0,
		);

		$attempted = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$status_allows = 'order' === $type
				? wc_kledo_order_status_allows_manual_sales_order( $order )
				: wc_kledo_order_status_allows_manual_invoice( $order );

			if ( ! $status_allows ) {
				++$summary['not_eligible'];

				continue;
			}

			if ( ! in_array( wc_kledo_get_remote_state( $order, $type ), self::SENDABLE_STATES, true ) ) {
				++$summary['skipped'];

				continue;
			}

			if ( $attempted >= self::MAX_SENDS_PER_REQUEST ) {
				++$summary['over_limit'];

				continue;
			}

			++$attempted;

			$result = wc_kledo()->get_woocommerce_bridge()->deliver(
				$order,
				$type,
				array(
					'trigger'     => 'manual_admin',
					'manual_mode' => 'first',
				)
			);

			if ( ! empty( $result['success'] ) ) {
				++$summary['sent'];
			} elseif ( 'awaiting_sales_order' === ( $result['reason'] ?? '' ) ) {
				++$summary['waiting'];
			} elseif ( ! empty( $result['skipped'] ) ) {
				++$summary['skipped'];
			} else {
				++$summary['failed'];
			}
		}

		wc_kledo_log_info(
			sprintf(
				'Kledo bulk send type=%s user_id=%d sent=%d waiting=%d failed=%d skipped=%d not_eligible=%d over_limit=%d',
				$type,
				get_current_user_id(),
				$summary['sent'],
				$summary['waiting'],
				$summary['failed'],
				$summary['skipped'],
				$summary['not_eligible'],
				$summary['over_limit']
			)
		);

		return $summary;
	}

	/**
	 * Admin notice text for a bulk send.
	 *
	 * @param  string $type
	 * @param  array  $summary
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function format_send_summary( string $type, array $summary ): string {
		$parts = array(
			sprintf(
				/* translators: 1: transaction type label, 2: number accepted by Kledo */
				__( 'Kledo %1$s: %2$d accepted by Kledo (the plugin now checks they appear there).', 'wc-kledo' ),
				strtolower( WC_Kledo_Status_Badge::type_label( $type ) ),
				$summary['sent']
			),
		);

		if ( $summary['waiting'] > 0 ) {
			/* translators: %d: number of invoices waiting */
			$parts[] = sprintf( __( '%d waiting for their sales order first.', 'wc-kledo' ), $summary['waiting'] );
		}

		if ( $summary['failed'] > 0 ) {
			/* translators: %d: number of failures */
			$parts[] = sprintf( __( '%d failed — see the order notes or WooCommerce > Kledo > Kledo Status.', 'wc-kledo' ), $summary['failed'] );
		}

		if ( $summary['skipped'] > 0 ) {
			/* translators: %d: number skipped */
			$parts[] = sprintf( __( '%d skipped because they are already in Kledo or on their way there.', 'wc-kledo' ), $summary['skipped'] );
		}

		if ( $summary['not_eligible'] > 0 ) {
			$parts[] = 'order' === $type
				/* translators: %d: number of orders */
				? sprintf( __( '%d skipped because only Processing or Completed orders get a sales order.', 'wc-kledo' ), $summary['not_eligible'] )
				/* translators: %d: number of orders */
				: sprintf( __( '%d skipped because only Completed orders get an invoice.', 'wc-kledo' ), $summary['not_eligible'] );
		}

		if ( $summary['over_limit'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: number not processed, 2: per-request limit */
				__( '%1$d not processed: at most %2$d are sent at once. Select them again to continue.', 'wc-kledo' ),
				$summary['over_limit'],
				self::MAX_SENDS_PER_REQUEST
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Admin notice text for a bulk status check.
	 *
	 * @param  array $summary
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function format_check_summary( array $summary ): string {
		$parts = array(
			/* translators: %d: number of orders */
			sprintf( __( 'Kledo status: %d order(s) found in Kledo.', 'wc-kledo' ), $summary['confirmed'] ),
		);

		if ( $summary['pending'] > 0 ) {
			/* translators: %d: number of orders */
			$parts[] = sprintf( __( '%d not found yet; the plugin keeps checking and marks them "Failed in Kledo" if they never appear.', 'wc-kledo' ), $summary['pending'] );
		}

		if ( $summary['queued'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: number of orders, 2: per-request limit */
				__( '%1$d queued to be checked in the background (at most %2$d are checked at once).', 'wc-kledo' ),
				$summary['queued'],
				WC_Kledo_Transaction_Verifier::MAX_IMMEDIATE_CHECKS
			);
		}

		if ( $summary['not_sent'] > 0 ) {
			/* translators: %d: number of orders */
			$parts[] = sprintf( __( '%d never sent and not in Kledo.', 'wc-kledo' ), $summary['not_sent'] );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Show the result of the last bulk action, once.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function maybe_show_result_notice(): void {
		// Only on the order list the action came from, where the redirect lands. Shown anywhere
		// else, a summary that outlived its page reads as news about something that just happened.
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, $this->get_order_list_screen_ids(), true ) ) {
			return;
		}

		$key     = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$message = get_transient( $key );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
