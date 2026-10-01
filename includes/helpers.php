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

if ( ! function_exists( 'wc_kledo_parse_kledo_datetime' ) ) {
	/**
	 * Turn a Kledo timestamp into a Unix timestamp, reading it in Kledo's own timezone.
	 *
	 * Kledo answers in its own zone and says which one, so the raw string must be parsed with
	 * that zone rather than assumed to be the store's. A store in another timezone would
	 * otherwise be shown a date off by hours, and near midnight off by a day, with nothing on
	 * screen to hint at it.
	 *
	 * @param  string $raw        A `Y-m-d H:i:s` timestamp as Kledo returned it.
	 * @param  string $timezone   The timezone Kledo reported, e.g. `Asia/Jakarta`.
	 *
	 * @return int|null The Unix timestamp, or null when it cannot be read with confidence.
	 * @since 1.7.4
	 */
	function wc_kledo_parse_kledo_datetime( string $raw, string $timezone ): ?int {
		$raw = trim( $raw );

		// Without the source zone there is no correct conversion, only a plausible-looking wrong
		// one. The callers show the raw value instead.
		if ( '' === $raw || '' === trim( $timezone ) ) {
			return null;
		}

		try {
			$date = new DateTimeImmutable( $raw, new DateTimeZone( $timezone ) );
		} catch ( Exception $exception ) {
			return null;
		}

		$timestamp = $date->getTimestamp();

		// A zero date parses without throwing and formats as "30 November -0001", which reads as
		// a rendering bug rather than as missing data. Anything before the epoch is not a real
		// value from Kledo, so the caller shows the raw string instead.
		if ( $timestamp < 0 ) {
			return null;
		}

		return $timestamp;
	}
}

if ( ! function_exists( 'wc_kledo_format_kledo_datetime' ) ) {
	/**
	 * A Kledo timestamp rendered in the store's own timezone and language.
	 *
	 * `wp_date()` rather than `date()` or `date_i18n()`: it renders in the site timezone and
	 * translates month names, which matters on a store running the plugin in Indonesian, where an
	 * English month in the middle of the screen looks like something only half finished.
	 *
	 * The store's timezone is the right one to show. The reader is a shop admin looking at their
	 * own WP Admin, where every other date — orders, posts — is already in site time; one row in
	 * a different zone would be the only inconsistent thing on the page.
	 *
	 * @param  string $raw       A `Y-m-d H:i:s` timestamp as Kledo returned it.
	 * @param  string $timezone  The timezone Kledo reported.
	 *
	 * @return string The formatted date, or the raw value when it cannot be converted.
	 * @since 1.7.4
	 */
	function wc_kledo_format_kledo_datetime( string $raw, string $timezone ): string {
		$timestamp = wc_kledo_parse_kledo_datetime( $raw, $timezone );

		if ( null === $timestamp ) {
			return trim( $raw );
		}

		$format = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'H:i' );

		return (string) wp_date( $format, $timestamp );
	}
}

if ( ! function_exists( 'wc_kledo_format_kledo_datetime_relative' ) ) {
	/**
	 * How far a Kledo timestamp is from now, in words.
	 *
	 * @param  string $raw       A `Y-m-d H:i:s` timestamp as Kledo returned it.
	 * @param  string $timezone  The timezone Kledo reported.
	 *
	 * @return string Something like "26 days from now" or "2 hours ago", empty when unreadable.
	 * @since 1.7.4
	 */
	function wc_kledo_format_kledo_datetime_relative( string $raw, string $timezone ): string {
		$timestamp = wc_kledo_parse_kledo_datetime( $raw, $timezone );

		if ( null === $timestamp ) {
			return '';
		}

		$now = time();

		if ( $timestamp >= $now ) {
			return sprintf(
				/* translators: %s: a length of time, e.g. "26 days" */
				__( '%s from now', 'wc-kledo' ),
				human_time_diff( $now, $timestamp )
			);
		}

		return sprintf(
			/* translators: %s: a length of time, e.g. "2 hours" */
			__( '%s ago', 'wc-kledo' ),
			human_time_diff( $timestamp, $now )
		);
	}
}

if ( ! function_exists( 'wc_kledo_asset_version' ) ) {
	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * The plugin version is the right answer for a released site: it changes exactly when the
	 * assets do. It is the wrong answer while developing, because it does not change at all — so
	 * an edited stylesheet keeps serving from the browser cache and the edit looks like it did
	 * nothing. That cost a real debugging session: a server-side fix and a CSS fix shipped
	 * together, only the server-side one appeared, and the CSS was suspected for it.
	 *
	 * @param  string $relative_path  Path under the plugin directory, e.g. `assets/css/style.css`.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	function wc_kledo_asset_version( string $relative_path ): string {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return WC_KLEDO_VERSION;
		}

		$full_path = WC_KLEDO_ABSPATH . ltrim( $relative_path, '/' );

		if ( ! is_readable( $full_path ) ) {
			return WC_KLEDO_VERSION;
		}

		$modified_at = filemtime( $full_path );

		return false !== $modified_at ? (string) $modified_at : WC_KLEDO_VERSION;
	}
}

if ( ! function_exists( 'wc_kledo_mask_api_key' ) ) {
	/**
	 * The display form of a stored API key: enough to recognise it, not enough to use it.
	 *
	 * Only a fallback. Kledo returns its own masked form of the key as `short_token_masked`, and
	 * that is what should be shown whenever it is available — see
	 * `wc_kledo_get_displayed_api_key_mask()`. Computing Kledo's exact shape here would mean
	 * hardcoding a checksum length that lives in Kledo's config, and the day that changed the two
	 * screens would disagree about the same key.
	 *
	 * This form is deliberately more conservative than Kledo's: the leading
	 * `kledo_pat_<tenant>_` names the company rather than granting anything, and the last four
	 * characters tell two keys apart. Everything that authenticates is replaced, with a run of a
	 * fixed width so the rendered value does not disclose the key's length either.
	 *
	 * Deliberately NOT the scheme used for masking keys in logs: that one is longer and
	 * reversible on purpose, so support can decode it, which is exactly wrong for a page.
	 *
	 * @param  string $api_key  The stored key.
	 *
	 * @return string The masked key, or an empty string when there is nothing stored.
	 * @since 1.7.4
	 */
	function wc_kledo_mask_api_key( string $api_key ): string {
		$api_key = trim( $api_key );

		if ( '' === $api_key ) {
			return '';
		}

		$hidden = str_repeat( WC_Kledo_Configure_Screen::API_KEY_MASK_CHARACTER, 20 );

		// Too short to reveal any of: whatever this is, it is not a key whose shape we know.
		if ( strlen( $api_key ) <= 8 ) {
			return $hidden;
		}

		$prefix = '';

		if ( preg_match( '/^kledo_pat_[A-Za-z0-9]{6}_/', $api_key, $matches ) ) {
			$prefix = $matches[0];
		}

		// Showing both ends would leave almost nothing hidden on an unusually short key.
		if ( ( strlen( $prefix ) + 4 ) >= strlen( $api_key ) ) {
			return $hidden;
		}

		return $prefix . $hidden . substr( $api_key, -4 );
	}
}

if ( ! function_exists( 'wc_kledo_get_api_key_mask_option_name' ) ) {
	/**
	 * Option holding the masked key exactly as Kledo last reported it.
	 *
	 * Kept outside the status transient on purpose: the transient is short-lived and is replaced
	 * by a failure marker when Kledo cannot be reached, while the settings screen still has to
	 * render the field. This survives that.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	function wc_kledo_get_api_key_mask_option_name(): string {
		return 'wc_kledo_api_key_masked';
	}
}

if ( ! function_exists( 'wc_kledo_get_api_key_detached_option_name' ) ) {
	/**
	 * Option marking that Kledo's renewal replaced a managed key with an unmanaged one.
	 *
	 * Needed as its own flag rather than inferred from the absence of the canonical fields:
	 * rotating the key clears everything cached about the old one, including the stored mask, so
	 * by the time the next status arrives there is nothing left to compare against. A store that
	 * has only ever used a raw token must not be told its key was detached, which is why this is
	 * only ever written at the moment the shape actually changes.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	function wc_kledo_get_api_key_detached_option_name(): string {
		return 'wc_kledo_api_key_detached';
	}
}

if ( ! function_exists( 'wc_kledo_is_api_key_detached' ) ) {
	/**
	 * Whether the key in use has drifted away from the one listed in Kledo.
	 *
	 * @return bool
	 * @since 1.7.4
	 */
	function wc_kledo_is_api_key_detached(): bool {
		return 'yes' === get_option( wc_kledo_get_api_key_detached_option_name(), 'no' );
	}
}

if ( ! function_exists( 'wc_kledo_get_displayed_api_key_mask' ) ) {
	/**
	 * The masked key to print on the settings screen.
	 *
	 * Kledo's own mask wins whenever one has been received. Showing a different mask from the one
	 * Kledo shows for the same key would have someone comparing two screens conclude they are
	 * holding two different keys.
	 *
	 * @return string The mask, or an empty string when no key is stored.
	 * @since 1.7.4
	 */
	function wc_kledo_get_displayed_api_key_mask(): string {
		$api_key = (string) get_option( WC_Kledo_Configure_Screen::SETTING_API_KEY, '' );

		if ( '' === trim( $api_key ) ) {
			return '';
		}

		$reported = (string) get_option( wc_kledo_get_api_key_mask_option_name(), '' );

		if ( '' !== $reported ) {
			return $reported;
		}

		return wc_kledo_mask_api_key( $api_key );
	}
}

if ( ! function_exists( 'wc_kledo_link_invoice_to_order' ) ) {
	/**
	 * Whether the invoice should be linked to its Kledo sales order, as the API expects it.
	 *
	 * Returned as the literal `yes`/`no` the `link_order` field takes, matching how
	 * `wc_kledo_paid_status()` and `wc_kledo_include_tax_or_not()` already shape their values.
	 *
	 * Linking is what makes Kledo close the sales order once every quantity has been invoiced —
	 * the two are one operation on Kledo's side, not two independent switches.
	 *
	 * @return string Either `yes` or `no`.
	 * @since 1.7.4
	 */
	function wc_kledo_link_invoice_to_order(): string {
		$value = get_option( WC_Kledo_Invoice_Screen::LINK_ORDER_OPTION_NAME, 'yes' );

		return wc_string_to_bool( $value ) ? 'yes' : 'no';
	}
}

if ( ! function_exists( 'wc_kledo_close_order_on_invoice' ) ) {
	/**
	 * Whether Kledo should close the sales order when the invoice is created.
	 *
	 * Only meaningful while linking is on, and only covers the moment of invoicing: the billed
	 * quantities are unchanged, so a later recalculation on Kledo's side closes the sales order
	 * regardless of this setting.
	 *
	 * Reports `no` whenever linking is off, rather than whatever happens to be stored. An invoice
	 * that is not recorded against a sales order bills nothing on it, so there is no sales order
	 * for this setting to close — a stored `yes` underneath an off `link_order` describes an
	 * outcome that cannot occur. Answering it here means the dependency holds for every caller,
	 * including a shop whose stored pair went out of step before this dependency existed, and one
	 * driving the options past the settings screen through `update_option()`.
	 *
	 * @return string Either `yes` or `no`.
	 * @since 1.7.4
	 */
	function wc_kledo_close_order_on_invoice(): string {
		if ( 'yes' !== wc_kledo_link_invoice_to_order() ) {
			return 'no';
		}

		$value = get_option( WC_Kledo_Invoice_Screen::CLOSE_ORDER_OPTION_NAME, 'yes' );

		return wc_string_to_bool( $value ) ? 'yes' : 'no';
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

if ( ! function_exists( 'wc_kledo_get_closure_queue_option_name' ) ) {
	/**
	 * Option holding the pending Kledo closure confirmations.
	 *
	 * Deliberately separate from `wc_kledo_failed_transactions`. That queue means "this delivery
	 * FAILED, try again"; this one means "this delivery SUCCEEDED, wait for Kledo to finish
	 * processing it". Merging them would make the Transactions screen show healthy orders as
	 * `retrying`.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	function wc_kledo_get_closure_queue_option_name(): string {
		return 'wc_kledo_pending_closure_checks';
	}
}

if ( ! function_exists( 'wc_kledo_get_order_closed_meta_key' ) ) {
	/**
	 * Order meta key marking that Kledo reported its own order as closed.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	function wc_kledo_get_order_closed_meta_key(): string {
		return '_wc_kledo_order_closed';
	}
}

if ( ! function_exists( 'wc_kledo_is_order_closed_in_kledo' ) ) {
	/**
	 * Whether Kledo has already reported this order as closed.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return bool
	 * @since 1.7.4
	 */
	function wc_kledo_is_order_closed_in_kledo( WC_Order $order ): bool {
		return 'yes' === $order->get_meta( wc_kledo_get_order_closed_meta_key() );
	}
}

if ( ! function_exists( 'wc_kledo_mark_order_closed_in_kledo' ) ) {
	/**
	 * Persist that Kledo closed its order for this WooCommerce order.
	 *
	 * Written regardless of the auto-close opt-in setting: the meta records what Kledo reported,
	 * while the setting only governs whether the WooCommerce status is moved as a result.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return void
	 * @since 1.7.4
	 */
	function wc_kledo_mark_order_closed_in_kledo( WC_Order $order ): void {
		$order->update_meta_data( wc_kledo_get_order_closed_meta_key(), 'yes' );
		$order->save();
	}
}

if ( ! function_exists( 'wc_kledo_get_invoice_link_mode_meta_key' ) ) {
	/**
	 * Order meta key recording whether the invoice was sent asking Kledo to link it.
	 *
	 * The setting behind it can be changed at any time, so reading the option later says nothing
	 * about an invoice that already left. This records the decision that was actually sent, which
	 * is what the order list column and any support question need.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	function wc_kledo_get_invoice_link_mode_meta_key(): string {
		return '_wc_kledo_invoice_link_order';
	}
}

if ( ! function_exists( 'wc_kledo_get_invoice_close_mode_meta_key' ) ) {
	/**
	 * Order meta key recording the `close_order` value sent with this order's invoice.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	function wc_kledo_get_invoice_close_mode_meta_key(): string {
		return '_wc_kledo_invoice_close_order';
	}
}

if ( ! function_exists( 'wc_kledo_record_invoice_link_settings' ) ) {
	/**
	 * Persist the `link_order` and `close_order` values sent with this order's invoice.
	 *
	 * Both are written in one save: they describe a single decision taken at one moment, and
	 * reading one without the other cannot tell "not linked" apart from "linked but left open".
	 *
	 * @param  \WC_Order $order
	 * @param  string    $link_mode   Either `yes` or `no`.
	 * @param  string    $close_mode  Either `yes` or `no`.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	function wc_kledo_record_invoice_link_settings( WC_Order $order, string $link_mode, string $close_mode ): void {
		$order->update_meta_data( wc_kledo_get_invoice_link_mode_meta_key(), 'no' === $link_mode ? 'no' : 'yes' );
		$order->update_meta_data( wc_kledo_get_invoice_close_mode_meta_key(), 'no' === $close_mode ? 'no' : 'yes' );
		$order->save();
	}
}

if ( ! function_exists( 'wc_kledo_get_invoice_link_mode' ) ) {
	/**
	 * The `link_order` value recorded for this order, if an invoice has been sent at all.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return string Either `yes`, `no`, or an empty string when nothing was recorded.
	 * @since 1.7.4
	 */
	function wc_kledo_get_invoice_link_mode( WC_Order $order ): string {
		$mode = (string) $order->get_meta( wc_kledo_get_invoice_link_mode_meta_key() );

		return in_array( $mode, array( 'yes', 'no' ), true ) ? $mode : '';
	}
}

if ( ! function_exists( 'wc_kledo_get_invoice_close_mode' ) ) {
	/**
	 * The `close_order` value recorded for this order, if an invoice has been sent at all.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return string Either `yes`, `no`, or an empty string when nothing was recorded.
	 * @since 1.7.4
	 */
	function wc_kledo_get_invoice_close_mode( WC_Order $order ): string {
		$mode = (string) $order->get_meta( wc_kledo_get_invoice_close_mode_meta_key() );

		return in_array( $mode, array( 'yes', 'no' ), true ) ? $mode : '';
	}
}

if ( ! function_exists( 'wc_kledo_enqueue_closure_check' ) ) {
	/**
	 * Queue a closure confirmation for an order and make sure the cron event exists.
	 *
	 * @param  int $order_id
	 *
	 * @return void
	 * @since 1.7.4
	 */
	function wc_kledo_enqueue_closure_check( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		$option_name = wc_kledo_get_closure_queue_option_name();
		$queue       = get_option( $option_name, array() );
		$key         = (string) $order_id;

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$now = time();

		// The confirmation backoff reuses the retry schedule rather than defining a second one.
		$first_delay = wc_kledo_get_retry_delay( 1 );

		if ( ! isset( $queue[ $key ] ) || ! is_array( $queue[ $key ] ) ) {
			$queue[ $key ] = array(
				'order_id'    => $order_id,
				'attempts'    => 0,
				'created_at'  => $now,
				'next_run_at' => $now + $first_delay,
				'status'      => 'pending',
			);
		} elseif ( 'pending' !== ( $queue[ $key ]['status'] ?? '' ) ) {
			// An order re-delivered after giving up deserves a fresh budget.
			$queue[ $key ]['status']      = 'pending';
			$queue[ $key ]['attempts']    = 0;
			$queue[ $key ]['created_at']  = $now;
			$queue[ $key ]['next_run_at'] = $now + $first_delay;
		} elseif ( empty( $queue[ $key ]['next_run_at'] ) ) {
			$queue[ $key ]['next_run_at'] = $now + $first_delay;
		}

		update_option( $option_name, $queue, false );

		if ( ! wp_next_scheduled( 'wc_kledo_check_order_closure' ) ) {
			$scheduled = wp_schedule_single_event( $now + $first_delay, 'wc_kledo_check_order_closure' );

			if ( false === $scheduled ) {
				wc_kledo_log_warning(
					sprintf(
						'wp_schedule_single_event returned false for closure check on order %d. '
						. 'The cron event was NOT registered; the admin-page fallback will still run it.',
						$order_id
					)
				);
			} else {
				wc_kledo_log_info(
					sprintf(
						'Closure check queued for order %d, first run at %s (in %d s).',
						$order_id,
						gmdate( 'Y-m-d H:i:s', $now + $first_delay ),
						$first_delay
					)
				);
			}
		} else {
			wc_kledo_log_info(
				sprintf( 'Closure check queued for order %d; cron already scheduled.', $order_id )
			);
		}
	}
}

if ( ! function_exists( 'wc_kledo_get_closure_check_state' ) ) {
	/**
	 * The state of an order's closure confirmation row, if it still has one.
	 *
	 * Rows leave the queue as soon as the closure is confirmed, so an empty string here means
	 * either "never queued" or "already done" — the closed meta is what separates those two.
	 *
	 * @param  int $order_id
	 *
	 * @return string Either `pending`, `gave_up`, or an empty string when there is no row.
	 * @since 1.7.4
	 */
	function wc_kledo_get_closure_check_state( int $order_id ): string {
		$queue = get_option( wc_kledo_get_closure_queue_option_name(), array() );

		if ( ! is_array( $queue ) ) {
			return '';
		}

		$key = (string) $order_id;

		if ( ! isset( $queue[ $key ] ) || ! is_array( $queue[ $key ] ) ) {
			return '';
		}

		$status = isset( $queue[ $key ]['status'] ) ? (string) $queue[ $key ]['status'] : '';

		return in_array( $status, array( 'pending', 'gave_up' ), true ) ? $status : '';
	}
}

if ( ! function_exists( 'wc_kledo_remove_closure_check' ) ) {
	/**
	 * Drop an order's closure confirmation row.
	 *
	 * @param  int $order_id
	 *
	 * @return void
	 * @since 1.7.4
	 */
	function wc_kledo_remove_closure_check( int $order_id ): void {
		$option_name = wc_kledo_get_closure_queue_option_name();
		$queue       = get_option( $option_name, array() );

		if ( ! is_array( $queue ) ) {
			return;
		}

		$key = (string) $order_id;

		if ( ! isset( $queue[ $key ] ) ) {
			return;
		}

		unset( $queue[ $key ] );
		update_option( $option_name, $queue, false );
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
