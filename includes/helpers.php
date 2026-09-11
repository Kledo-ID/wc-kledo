<?php

// Exit if accessed directly.
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wc_kledo_get_requested_value' ) ) {
	/**
	 * Safely gets a value from $_REQUEST.
	 *
	 * If the expected data is a string also trims it.
	 *
	 * @param  string                           $key  posted data key
	 * @param  int|float|array|bool|null|string $default  default data type to return (default empty string)
	 *
	 * @return int|float|array|bool|null|string
	 * @since 1.0.0
	 */
	function wc_kledo_get_requested_value( string $key, $default = '' ) {
		$value = $default;

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST[ $key ] ) ) {
			$value = sanitize_text_field( wp_unslash( (string) $_REQUEST[ $key ] ) );
		}
		// phpcs:enable

		return $value;
	}
}

if ( ! function_exists( 'wc_kledo_get_posted_value' ) ) {
	/**
	 * Safely gets a value from $_POST.
	 *
	 * If the expected data is a string also trims it.
	 *
	 * @param  string                           $key  posted data key
	 * @param  int|float|array|bool|null|string $default  default data type to return (default empty string)
	 *
	 * @return int|float|array|bool|null|string posted data value if key found, or default
	 * @since 1.0.0
	 */
	function wc_kledo_get_posted_value( string $key, $default = '' ) {
		$value = $default;

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST[ $key ] ) ) {
			$value = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

		return $value;
	}
}

if ( ! function_exists( 'wc_kledo_is_ssl' ) ) {
	/**
	 * Determine if site used SSL.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	function wc_kledo_is_ssl(): bool {
		return is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || ( 0 === stripos( (string) get_option( 'siteurl' ), 'https://' ) );
	}
}

if ( ! function_exists( 'wc_kledo_get_wc_version' ) ) {
	/**
	 * Gets the version of the currently installed WooCommerce.
	 *
	 * @return string|null Woocommerce version number or null if undetermined
	 * @since 1.0.0
	 */
	function wc_kledo_get_wc_version(): ?string {
		return defined( 'WC_VERSION' ) && WC_VERSION ? WC_VERSION : null;
	}
}

if ( ! function_exists( 'wc_kledo_is_wc_version_gte' ) ) {
	/**
	 * Determines if the installed version of WooCommerce is equal or greater than a given version.
	 *
	 * @param  string $version  version number to compare
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	function wc_kledo_is_wc_version_gte( $version ): bool {
		$wc_version = wc_kledo_get_wc_version();

		return $wc_version && version_compare( $wc_version, $version, '>=' );
	}
}

if ( ! function_exists( 'wc_kledo_is_enhanced_admin_available' ) ) {
	/**
	 * Determines whether the enhanced admin is available.
	 * This checks both for WooCommerce v4.0+ and the underlying package availability.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	function wc_kledo_is_enhanced_admin_available(): bool {
		return wc_kledo_is_wc_version_gte( '4.0' ) && function_exists( 'wc_admin_url' );
	}
}

if ( ! function_exists( 'wc_kledo_get_invoice_prefix' ) ) {
	/**
	 * Get the invoice prefix.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	function wc_kledo_get_invoice_prefix(): string {
		return get_option( WC_Kledo_Invoice_Screen::INVOICE_PREFIX_OPTION_NAME, 'WC/INV/' );
	}
}

if ( ! function_exists( 'wc_kledo_get_order_prefix' ) ) {
	/**
	 * Get the order prefix.
	 *
	 * @return string
	 * @since 1.3.0
	 */
	function wc_kledo_get_order_prefix(): string {
		return get_option( WC_Kledo_Order_Screen::ORDER_PREFIX_OPTION_NAME, 'WC/SO/' );
	}
}

if ( ! function_exists( 'wc_kledo_get_invoice_warehouse' ) ) {
	/**
	 * Get the warehouse.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	function wc_kledo_get_invoice_warehouse(): string {
		return get_option( WC_Kledo_Invoice_Screen::INVOICE_WAREHOUSE_OPTION_NAME, '' );
	}
}

if ( ! function_exists( 'wc_kledo_get_order_warehouse' ) ) {
	/**
	 * Get the warehouse.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	function wc_kledo_get_order_warehouse(): string {
		return get_option( WC_Kledo_Order_Screen::ORDER_WAREHOUSE_OPTION_NAME, '' );
	}
}

if ( ! function_exists( 'wc_kledo_paid_status' ) ) {
	/**
	 * Get the paid status.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	function wc_kledo_paid_status(): string {
		$status = get_option( WC_Kledo_Invoice_Screen::INVOICE_STATUS_OPTION_NAME, 'no' );

		return 'paid' === strtolower( $status ) ? 'yes' : 'no';
	}
}

if ( ! function_exists( 'wc_kledo_get_payment_account' ) ) {
	/**
	 * Get the payment account.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	function wc_kledo_get_payment_account(): string {
		$account = get_option( WC_Kledo_Invoice_Screen::INVOICE_PAYMENT_ACCOUNT_OPTION_NAME );

		if ( $account ) {
			$account = explode( '|', $account );
			$account = array_map( 'trim', $account );

			return $account[0];
		}

		return '';
	}
}

if ( ! function_exists( 'wc_kledo_get_tags' ) ) {
	/**
	 * Get the invoice tags.
	 *
	 * @param  string $option_name
	 *
	 * @return array
	 * @since 1.0.0
	 */
	function wc_kledo_get_tags( string $option_name ): array {
		$tags = get_option( $option_name, 'WooCommerce' );

		return explode( ',', $tags );
	}
}

if ( ! function_exists( 'wc_kledo_include_tax_or_not' ) ) {
	/**
	 * Check if the order has tax or not.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return string
	 * @since 1.1.0
	 */
	function wc_kledo_include_tax_or_not( WC_Order $order ): string {
		$total_tax = $order->get_total_tax();

		return ( $total_tax > 0 ) ? 'yes' : 'no';
	}
}

if ( ! function_exists( 'wc_kledo_get_delivery_meta_key' ) ) {
	/**
	 * Order meta key used to mark a successful Kledo delivery for a type.
	 *
	 * @param  string $type  "order" or "invoice".
	 *
	 * @return string|null
	 * @since 1.5.0
	 */
	function wc_kledo_get_delivery_meta_key( string $type ): ?string {
		if ( 'order' === $type ) {
			return '_wc_kledo_order_synced';
		}

		if ( 'invoice' === $type ) {
			return '_wc_kledo_invoice_synced';
		}

		return null;
	}
}

if ( ! function_exists( 'wc_kledo_is_delivery_synced' ) ) {
	/**
	 * Whether the order or invoice was already accepted by Kledo (HTTP 200 + valid payload path).
	 *
	 * @param  \WC_Order $order
	 * @param  string    $type  "order" or "invoice".
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	function wc_kledo_is_delivery_synced( WC_Order $order, string $type ): bool {
		$key = wc_kledo_get_delivery_meta_key( $type );

		if ( ! $key ) {
			return false;
		}

		return 'yes' === $order->get_meta( $key );
	}
}

if ( ! function_exists( 'wc_kledo_mark_delivery_synced' ) ) {
	/**
	 * Persist successful delivery and clear any stale queue row.
	 *
	 * @param  \WC_Order $order
	 * @param  string    $type  "order" or "invoice".
	 *
	 * @return void
	 * @since 1.5.0
	 */
	function wc_kledo_mark_delivery_synced( WC_Order $order, string $type ): void {
		$key = wc_kledo_get_delivery_meta_key( $type );

		if ( ! $key ) {
			return;
		}

		$order->update_meta_data( $key, 'yes' );
		$order->save();

		wc_kledo_remove_failed_transaction_from_queue( $order->get_id(), $type );
	}
}

if ( ! function_exists( 'wc_kledo_remove_failed_transaction_from_queue' ) ) {
	/**
	 * Remove a failed-transaction queue entry for an order and type.
	 *
	 * @param  int    $order_id
	 * @param  string $type  "order" or "invoice".
	 *
	 * @return void
	 * @since 1.5.0
	 */
	function wc_kledo_remove_failed_transaction_from_queue( int $order_id, string $type ): void {
		if ( ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
			return;
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );

		if ( ! is_array( $queue ) ) {
			return;
		}

		$key = $type . ':' . $order_id;

		if ( ! isset( $queue[ $key ] ) ) {
			return;
		}

		unset( $queue[ $key ] );
		update_option( $option_name, $queue, false );
	}
}

if ( ! function_exists( 'wc_kledo_admin_datetime_format' ) ) {
	/**
	 * Returns the WordPress admin-style absolute datetime format string.
	 *
	 * Matches the convention used by WordPress post list screens:
	 * e.g. "2026/04/16 at 8:03 am".
	 *
	 * Always uses WordPress site timezone via wp_date().
	 *
	 * @return string PHP date format string.
	 * @since 1.7.1
	 */
	function wc_kledo_admin_datetime_format(): string {
		return 'Y/m/d \a\t g:i a';
	}
}

if ( ! function_exists( 'wc_kledo_format_admin_timestamp' ) ) {
	/**
	 * Format a UNIX timestamp for display in the WordPress admin.
	 *
	 * Produces an absolute datetime in WordPress admin style
	 * (e.g. "2026/04/16 at 8:03 am") and optionally appends a
	 * human-readable relative string in parentheses:
	 *
	 *   past   → "2026/04/16 at 8:03 am (30 minutes ago)"
	 *   future → "2026/04/16 at 8:03 am (in 30 minutes)"
	 *
	 * Relative text is omitted when:
	 * - $relative_mode is 'none'
	 * - the difference is less than 60 seconds (avoids "0 seconds ago")
	 * - the timestamp direction contradicts the requested mode
	 *   (e.g. mode='future' but timestamp is already in the past)
	 *
	 * @param  int    $timestamp      UNIX timestamp. Pass 0 or negative to get the placeholder.
	 * @param  string $relative_mode  Direction hint: 'none' | 'past' | 'future'. Default 'none'.
	 *
	 * @return string  Formatted string or '—'. NOT HTML-escaped; caller must esc_html() the output.
	 * @since 1.7.1
	 */
	function wc_kledo_format_admin_timestamp( int $timestamp, string $relative_mode = 'none' ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		$absolute = wp_date( wc_kledo_admin_datetime_format(), $timestamp );

		if ( false === $absolute || '' === $absolute ) {
			return '—';
		}

		if ( 'none' === $relative_mode ) {
			return $absolute;
		}

		$now  = time();
		$diff = $timestamp - $now;

		if ( 'past' === $relative_mode ) {
			// Timestamp should be in the past; skip relative if it is not or too recent.
			if ( $diff >= 0 || abs( $diff ) < 60 ) {
				return $absolute;
			}

			/* translators: %s: human-readable time difference, e.g. "30 minutes" */
			$relative = sprintf( __( '(%s ago)', 'wc-kledo' ), human_time_diff( $timestamp, $now ) );

			return $absolute . ' ' . $relative;
		}

		if ( 'future' === $relative_mode ) {
			// Timestamp should be in the future; skip relative gracefully if overdue or too near.
			if ( $diff < 60 ) {
				return $absolute;
			}

			/* translators: %s: human-readable time difference, e.g. "30 minutes" */
			$relative = sprintf( __( '(in %s)', 'wc-kledo' ), human_time_diff( $now, $timestamp ) );

			return $absolute . ' ' . $relative;
		}

		return $absolute;
	}
}

if ( ! function_exists( 'wc_kledo_sanitize_api_error_message' ) ) {
	/**
	 * Shorten and strip unsafe characters from messages stored or shown in admin.
	 *
	 * @param  string $message
	 *
	 * @return string
	 * @since 1.5.0
	 */
	function wc_kledo_sanitize_api_error_message( string $message ): string {
		$message = wp_strip_all_tags( $message );

		return substr( $message, 0, 500 );
	}
}

if ( ! function_exists( 'wc_kledo_get_retry_delay' ) ) {
	/**
	 * Seconds to wait before the nth retry attempt (1-based).
	 *
	 * Defines the progressive backoff schedule used by both the automatic cron
	 * retry loop and the initial queue entry so that every scheduled next_run_at
	 * value is consistent and meaningfully in the future.
	 *
	 * Attempt index semantics:
	 *   1 = delay before the first automatic retry (applied when item is first enqueued)
	 *   2 = delay applied after the first automatic retry fails
	 *   3+ = continuing backoff up to the 8-hour cap
	 *
	 * @param  int $attempt  1-based attempt index. Values outside the map cap at 8 hours.
	 *
	 * @return int Delay in seconds.
	 * @since 1.7.2
	 */
	function wc_kledo_get_retry_delay( int $attempt ): int {
		// Index 1 is always the delay applied when a new item is first enqueued.
		// The cron retry loop uses index (attempts + 1) after incrementing the
		// counter, so each consecutive retry advances exactly one step forward.
		//
		// Full schedule (cumulative time from initial failure):
		// enqueue       → retry 1 in  5 min
		// retry 1 fails → retry 2 in 10 min  (total ~15 min)
		// retry 2 fails → retry 3 in 30 min  (total ~45 min)
		// retry 3 fails → retry 4 in  1 h    (total ~1 h 45 min)
		// retry 4 fails → retry 5 in  2 h    (total ~3 h 45 min)
		// retry 5 fails → retry 6 in  4 h    (total ~7 h 45 min)
		// retry 6+ fails → 8 h cap each
		$map = array(
			1 => 5 * MINUTE_IN_SECONDS,   // 5 min – initial enqueue wait
			2 => 10 * MINUTE_IN_SECONDS,  // 10 min – after retry 1 fails
			3 => 30 * MINUTE_IN_SECONDS,  // 30 min – after retry 2 fails
			4 => HOUR_IN_SECONDS,         // 1 h   – after retry 3 fails
			5 => 2 * HOUR_IN_SECONDS,     // 2 h   – after retry 4 fails
			6 => 4 * HOUR_IN_SECONDS,     // 4 h   – after retry 5 fails
			7 => 8 * HOUR_IN_SECONDS,     // 8 h   – after retry 6 fails (cap)
		);

		return $map[ $attempt ] ?? 8 * HOUR_IN_SECONDS;
	}
}

if ( ! function_exists( 'wc_kledo_add_failed_transaction_to_queue' ) ) {
	/**
	 * Add failed transaction (order or invoice) to retry queue and schedule cron.
	 *
	 * @param  int    $order_id
	 * @param  string $type  Either "order" or "invoice".
	 * @param  string $error_message
	 *
	 * @return void
	 * @since 1.5.0
	 */
	function wc_kledo_add_failed_transaction_to_queue( int $order_id, string $type, string $error_message = '' ): void {
		if ( ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
			return;
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );
		$key         = $type . ':' . $order_id;

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$now = time();

		$error_message = wc_kledo_sanitize_api_error_message( $error_message );

		// Delay before the first automatic retry. Used both for the queue entry and
		// for scheduling the cron so they stay aligned.
		$first_delay = wc_kledo_get_retry_delay( 1 );

		if ( ! isset( $queue[ $key ] ) ) {
			$queue[ $key ] = array(
				'order_id'    => $order_id,
				'type'        => $type,
				'attempts'    => 0,
				'last_error'  => $error_message,
				'created_at'  => $now,
				'next_run_at' => $now + $first_delay,
				'status'      => 'retrying',
			);
		} else {
			$queue[ $key ]['last_error'] = $error_message;

			if ( empty( $queue[ $key ]['created_at'] ) ) {
				$queue[ $key ]['created_at'] = $now;
			}

			// Only backfill next_run_at when truly absent; never move a future schedule backwards.
			if ( empty( $queue[ $key ]['next_run_at'] ) ) {
				$queue[ $key ]['next_run_at'] = $now + $first_delay;
			}

			// Preserve 'failed' (terminal) status; only set default when field is absent.
			if ( empty( $queue[ $key ]['status'] ) ) {
				$queue[ $key ]['status'] = 'retrying';
			}
		}

		update_option( $option_name, $queue, false );

		$next_scheduled = wp_next_scheduled( 'wc_kledo_retry_failed_transactions' );

		if ( ! $next_scheduled ) {
			$scheduled = wp_schedule_single_event( $now + $first_delay, 'wc_kledo_retry_failed_transactions' );

			if ( false === $scheduled ) {
				wc_kledo_log_warning(
					sprintf(
						'wp_schedule_single_event returned false for order %d (%s). ' .
						'The retry cron event was NOT registered. ' .
						'Retries will still execute via the admin-page fallback.',
						$order_id,
						$type
					)
				);
			} else {
				wc_kledo_log_info(
					sprintf(
						'Retry cron event scheduled for order %d (%s) at %s (in %d s).',
						$order_id,
						$type,
						gmdate( 'Y-m-d H:i:s', $now + $first_delay ),
						$first_delay
					)
				);
			}
		} else {
			wc_kledo_log_info(
				sprintf(
					'Retry cron already scheduled (next: %s). Order %d (%s) added to queue.',
					gmdate( 'Y-m-d H:i:s', (int) $next_scheduled ),
					$order_id,
					$type
				)
			);
		}
	}
}

if ( ! function_exists( 'wc_kledo_is_permanent_api_failure' ) ) {
	/**
	 * Decide whether an API status code represents a failure that retrying cannot fix.
	 *
	 * Kledo validates the order/invoice payload server-side and answers HTTP 400 when the
	 * payload does not match the expected schema — not 422. Its exception handler has no
	 * validation branch at all, so a Laravel ValidationException falls through to the generic
	 * `badRequest()` path and every validation failure across the whole Kledo API comes back as
	 * 400 with `{ success: false, message: "<first error>" }`. Resending the exact same payload
	 * will be rejected exactly the same way, so such a transaction must never enter the retry
	 * queue: it would produce up to 20 identical order notes over two days for no benefit.
	 *
	 * Three families of 4xx are deliberately kept retryable:
	 *
	 * - 401 / 403 — the API key is wrong or lacks access. An admin can fix that in the plugin
	 *   settings without touching the order, after which the queued retry succeeds.
	 * - 408 — the server itself reports a timeout, which is transient by definition.
	 * - 429 — rate limiting; the server is explicitly asking for the request to be repeated later.
	 *
	 * Every other 4xx (400, 404, 409, 422, ...) is treated as permanent. 5xx and transport
	 * failures are never permanent and keep using the normal retry path.
	 *
	 * @param  int $response_code  HTTP status code returned by the Kledo API.
	 *
	 * @return bool True when the transaction must not be retried automatically.
	 * @since 1.7.4
	 */
	function wc_kledo_is_permanent_api_failure( int $response_code ): bool {
		if ( $response_code < 400 || $response_code > 499 ) {
			return false;
		}

		$retryable_client_errors = array( 401, 403, 408, 429 );

		return ! in_array( $response_code, $retryable_client_errors, true );
	}
}

if ( ! function_exists( 'wc_kledo_mark_transaction_permanently_failed' ) ) {
	/**
	 * Record a transaction as a terminal failure without scheduling any automatic retry.
	 *
	 * The row is written into the same `wc_kledo_failed_transactions` option used by the retry
	 * queue so the admin still sees it on the Transactions screen (and can still trigger a manual
	 * retry after fixing the data), but with `status = failed`, which the cron loop skips.
	 *
	 * @param  int    $order_id
	 * @param  string $type  Either "order" or "invoice".
	 * @param  string $error_message
	 *
	 * @return void
	 * @since 1.7.4
	 */
	function wc_kledo_mark_transaction_permanently_failed( int $order_id, string $type, string $error_message = '' ): void {
		if ( ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
			return;
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );
		$key         = $type . ':' . $order_id;

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$now           = time();
		$error_message = wc_kledo_sanitize_api_error_message( $error_message );

		if ( ! isset( $queue[ $key ] ) || ! is_array( $queue[ $key ] ) ) {
			$queue[ $key ] = array(
				'order_id'   => $order_id,
				'type'       => $type,
				'attempts'   => 0,
				'created_at' => $now,
			);
		}

		$queue[ $key ]['last_error'] = $error_message;
		$queue[ $key ]['status']     = 'failed';

		// No future run: the payload is rejected deterministically, so there is nothing to wait for.
		unset( $queue[ $key ]['next_run_at'] );

		if ( empty( $queue[ $key ]['created_at'] ) ) {
			$queue[ $key ]['created_at'] = $now;
		}

		update_option( $option_name, $queue, false );

		wc_kledo_log_warning(
			sprintf(
				'Kledo delivery rejected permanently: order %d (%s) recorded as failed without retry. Error: %s',
				$order_id,
				$type,
				$error_message
			)
		);
	}
}

if ( ! function_exists( 'wc_kledo_log' ) ) {
	/**
	 * Write a line to the WooCommerce logger when available.
	 *
	 * @param  string $level  WC_Log_Levels level, e.g. info, warning, error.D
	 * @param  string $message
	 * @param  array  $context
	 *
	 * @return void
	 * @since 1.6.0
	 */
	function wc_kledo_log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();

		if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$payload = array_merge( array( 'source' => 'wc-kledo' ), $context );

		$logger->log( $level, $message, $payload );
	}
}

if ( ! function_exists( 'wc_kledo_log_info' ) ) {
	/**
	 * Writes an informational line to the WooCommerce logger.
	 *
	 * @param string               $message Context message.
	 * @param array<string, mixed> $context Optional structured context.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	function wc_kledo_log_info( string $message, array $context = array() ): void {
		wc_kledo_log( 'info', $message, $context );
	}
}

if ( ! function_exists( 'wc_kledo_log_warning' ) ) {
	/**
	 * Writes a warning line to the WooCommerce logger.
	 *
	 * @param string               $message Context message.
	 * @param array<string, mixed> $context Optional structured context.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	function wc_kledo_log_warning( string $message, array $context = array() ): void {
		wc_kledo_log( 'warning', $message, $context );
	}
}

if ( ! function_exists( 'wc_kledo_get_order_admin_screen_id' ) ) {
	/**
	 * Screen id for WooCommerce order edit (classic CPT or HPOS).
	 *
	 * @return string
	 * @since 1.6.0
	 */
	function wc_kledo_get_order_admin_screen_id(): string {
		if ( function_exists( 'wc_get_page_screen_id' )
			&& class_exists( OrderUtil::class )
			&& call_user_func( array( OrderUtil::class, 'custom_orders_table_usage_is_enabled' ) )
		) {
			return wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}
}

if ( ! function_exists( 'wc_kledo_order_status_allows_manual_sales_order' ) ) {
	/**
	 * Whether the order status allows a manual sales-order push (aligns with automatic hook on `processing`, extended to `completed` if sync was missed).
	 *
	 * @param  \WC_Order $order
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	function wc_kledo_order_status_allows_manual_sales_order( WC_Order $order ): bool {
		return $order->has_status( array( 'processing', 'completed' ) );
	}
}

if ( ! function_exists( 'wc_kledo_order_status_allows_manual_invoice' ) ) {
	/**
	 * Whether the order status allows a manual invoice push (matches automatic hook on `completed`).
	 *
	 * @param  \WC_Order $order
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	function wc_kledo_order_status_allows_manual_invoice( WC_Order $order ): bool {
		return $order->has_status( 'completed' );
	}
}
