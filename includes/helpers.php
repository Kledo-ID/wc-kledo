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
	function wc_kledo_get_posted_value( $key, $default = '' ) {
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
	function wc_kledo_is_ssl() {
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
	function wc_kledo_get_wc_version() {
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
	function wc_kledo_is_wc_version_gte( $version ) {
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
	function wc_kledo_is_enhanced_admin_available() {
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
	function wc_kledo_get_invoice_prefix() {
		return get_option( WC_Kledo_Invoice_Screen::INVOICE_PREFIX_OPTION_NAME );
	}
}

if ( ! function_exists( 'wc_kledo_get_warehouse' ) ) {
	/**
	 * Get the warehouse.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	function wc_kledo_get_warehouse() {
		return get_option( WC_Kledo_Invoice_Screen::INVOICE_WAREHOUSE_OPTION_NAME );
	}
}

if ( ! function_exists( 'wc_kledo_paid_status' ) ) {
	/**
	 * Get the paid status.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	function wc_kledo_paid_status() {
		$status = get_option( WC_Kledo_Invoice_Screen::INVOICE_STATUS_OPTION_NAME );

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
	function wc_kledo_get_payment_account() {
		$account = get_option( WC_Kledo_Invoice_Screen::INVOICE_PAYMENT_ACCOUNT_OPTION_NAME );

		if ( $account ) {
			$account = explode( '|', $account );
			$account = array_map( 'trim', $account );
		}

		return $account[0];
	}
}

if ( ! function_exists( 'wc_kledo_get_tags' ) ) {
	/**
	 * Get the invoice tags.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	function wc_kledo_get_tags() {
		$tags = get_option( WC_Kledo_Invoice_Screen::INVOICE_TAG_OPTION_NAME );

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
	function wc_kledo_include_tax_or_not( WC_Order $order ) {
		$total_tax = $order->get_total_tax();

		return ($total_tax > 0) ? 'yes' : 'no';
	}
}
