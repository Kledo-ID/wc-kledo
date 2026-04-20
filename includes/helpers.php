<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wc_kledo_get_requested_value' ) ) {
	/**
	 * Safely gets a value from $_REQUEST.
	 *
	 * If the expected data is a string also trims it.
	 *
	 * @param  string  $key  posted data key
	 * @param  int|float|array|bool|null|string  $default  default data type to return (default empty string)
	 *
	 * @return int|float|array|bool|null|string
	 * @since 1.0.0
	 */
	function wc_kledo_get_requested_value( string $key, $default = '' ) {
		$value = $default;

		if ( isset( $_REQUEST[ $key ] ) ) {
			$value = sanitize_text_field( $_REQUEST[ $key ] );
		}

		return $value;
	}
}

if ( ! function_exists( 'wc_kledo_get_posted_value' ) ) {
	/**
	 * Safely gets a value from $_POST.
	 *
	 * If the expected data is a string also trims it.
	 *
	 * @param  string  $key  posted data key
	 * @param  int|float|array|bool|null|string  $default  default data type to return (default empty string)
	 *
	 * @return int|float|array|bool|null|string posted data value if key found, or default
	 * @since 1.0.0
	 */
	function wc_kledo_get_posted_value( string $key, $default = '' ) {
		$value = $default;

		if ( isset( $_POST[ $key ] ) ) {
			$value = sanitize_text_field( $_POST[ $key ] );
		}

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
		return is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) || ( stripos( get_option( 'siteurl' ), 'https://' ) === 0 );
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
	 * @param  string  $version  version number to compare
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
	 * @param  string  $option_name
	 *
	 * @return array
	 * @since 1.0.0
	 */
	function wc_kledo_get_tags(string $option_name): array {
		$tags = get_option( $option_name, 'WooCommerce' );

		return explode( ',', $tags );
	}
}

if ( ! function_exists( 'wc_kledo_include_tax_or_not' ) ) {
	/**
	 * Check if the order has tax or not.
	 *
	 * @param  \WC_Order  $order
	 *
	 * @return string
	 * @since 1.1.0
	 */
	function wc_kledo_include_tax_or_not( WC_Order $order ): string {
		$total_tax = $order->get_total_tax();

		return ($total_tax > 0) ? 'yes' : 'no';
	}
}

if ( ! function_exists( 'wc_kledo_get_delivery_meta_key' ) ) {
	/**
	 * Order meta key used to mark a successful Kledo delivery for a type.
	 *
	 * @param  string  $type  "order" or "invoice".
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
	 * @param  \WC_Order  $order
	 * @param  string  $type  "order" or "invoice".
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
	 * @param  \WC_Order  $order
	 * @param  string  $type  "order" or "invoice".
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
	 * @param  int  $order_id
	 * @param  string  $type  "order" or "invoice".
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

if ( ! function_exists( 'wc_kledo_sanitize_api_error_message' ) ) {
	/**
	 * Shorten and strip unsafe characters from messages stored or shown in admin.
	 *
	 * @param  string  $message
	 *
	 * @return string
	 * @since 1.5.0
	 */
	function wc_kledo_sanitize_api_error_message( string $message ): string {
		$message = wp_strip_all_tags( $message );

		return substr( $message, 0, 500 );
	}
}

if ( ! function_exists( 'wc_kledo_add_failed_transaction_to_queue' ) ) {
	/**
	 * Add failed transaction (order or invoice) to retry queue and schedule cron.
	 *
	 * @param  int  $order_id
	 * @param  string  $type  Either "order" or "invoice".
	 * @param  string  $error_message
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

		if ( ! isset( $queue[ $key ] ) ) {
			$queue[ $key ] = array(
				'order_id'    => $order_id,
				'type'        => $type,
				'attempts'    => 0,
				'last_error'  => $error_message,
				'created_at'  => $now,
				'next_run_at' => $now,
			);
		} else {
			$queue[ $key ]['last_error'] = $error_message;
			if ( empty( $queue[ $key ]['created_at'] ) ) {
				$queue[ $key ]['created_at'] = $now;
			}

			if ( empty( $queue[ $key ]['next_run_at'] ) ) {
				$queue[ $key ]['next_run_at'] = $now;
			}
		}

		update_option( $option_name, $queue, false );

		if ( ! wp_next_scheduled( 'wc_kledo_retry_failed_transactions' ) ) {
			wp_schedule_single_event( $now + MINUTE_IN_SECONDS, 'wc_kledo_retry_failed_transactions' );
		}
	}
}
