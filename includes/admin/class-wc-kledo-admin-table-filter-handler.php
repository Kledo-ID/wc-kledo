<?php
/**
 * Parses, validates, and sanitizes admin table filter input for safe querying.
 *
 * @package WC_Kledo
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Collects filters from GET/POST/JSON and exposes before/after action hooks.
 */
class WC_Kledo_Admin_Table_Filter_Handler {
	/**
	 * Query param: row type filter.
	 */
	public const PARAM_TYPE = 'wc_kledo_tbl_type';

	/**
	 * Query param: exact attempts count (non-negative integer), column "Attempts" (queue rows).
	 */
	public const PARAM_ATTEMPTS = 'wc_kledo_tbl_attempts';

	/**
	 * Query param: filter "Created at" column to one calendar day (Y-m-d), site timezone.
	 */
	public const PARAM_CREATED_DATE = 'wc_kledo_tbl_created_date';

	/**
	 * Query param: filter "Next retry" column to one calendar day (Y-m-d), site timezone.
	 */
	public const PARAM_NEXT_RETRY_DATE = 'wc_kledo_tbl_next_retry_date';

	/**
	 * Query param: substring search on last error text.
	 */
	public const PARAM_LAST_ERROR = 'wc_kledo_tbl_last_error';

	/**
	 * Query param: sort column key.
	 */
	public const PARAM_ORDERBY = 'wc_kledo_tbl_orderby';

	/**
	 * Query param: sort direction asc|desc.
	 */
	public const PARAM_ORDER = 'wc_kledo_tbl_order';

	/**
	 * Nonce action for AJAX and form submissions.
	 */
	public const NONCE_ACTION = 'wc_kledo_admin_table';

	/**
	 * Nonce field name for forms and AJAX payload.
	 */
	public const NONCE_NAME = '_wc_kledo_admin_table_nonce';

	/**
	 * Fires before the table reads request data (extend context, logging).
	 */
	public const HOOK_BEFORE_DISPLAY = 'wc_kledo_admin_table_before_display';

	/**
	 * Fires after filters are sanitized and merged (audit, extra constraints).
	 *
	 * @param array  $filters Sanitized filter map.
	 * @param string $source  Request source: get|post|json|merged.
	 */
	public const HOOK_AFTER_FILTER_APPLY = 'wc_kledo_admin_table_after_filter_apply';

	/**
	 * Optional opaque context passed to {@see self::HOOK_BEFORE_DISPLAY}.
	 *
	 * @var array<string, mixed>
	 */
	private array $before_display_context;

	/**
	 * @param array<string, mixed> $before_display_context Context for {@see self::HOOK_BEFORE_DISPLAY}.
	 */
	public function __construct( array $before_display_context = array() ) {
		// Store hook context so extensions can correlate the current screen.
		$this->before_display_context = $before_display_context;
	}

	/**
	 * Runs the before_display hook so other code can prepare state safely.
	 *
	 * @return void
	 */
	public function fire_before_display(): void {
		/**
		 * Fires before the filterable table reads and applies request filters.
		 *
		 * @param array<string, mixed> $context Arbitrary context from the handler.
		 */
		do_action( self::HOOK_BEFORE_DISPLAY, $this->before_display_context );
	}

	/**
	 * Parses filters from $_GET and $_POST (typical admin form + URL sharing).
	 *
	 * @return array<string, mixed> Normalized filter values.
	 */
	public function parse_from_globals(): array {
		// Merge GET first so POST can override on submit.
		$merged = array();
		if ( ! empty( $_GET ) && is_array( $_GET ) ) {
			$merged = array_merge( $merged, wp_unslash( $_GET ) );
		}
		if ( ! empty( $_POST ) && is_array( $_POST ) ) {
			$merged = array_merge( $merged, wp_unslash( $_POST ) );
		}

		return $this->sanitize_filters( $merged, 'merged' );
	}

	/**
	 * Parses filters from a JSON-decoded array (typical for wp_ajax_* handlers).
	 *
	 * @param array<string, mixed> $data Raw associative array from JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function parse_from_array( array $data ): array {
		// Delegate to the same sanitizer so AJAX and GET behave identically.
		return $this->sanitize_filters( $data, 'json' );
	}

	/**
	 * Verifies WordPress nonce when present in the payload (forms and AJAX).
	 *
	 * @param array<string, mixed> $data Parsed request data including nonce fields.
	 *
	 * @return bool True when nonce is absent (caller may require it) or valid.
	 */
	public function verify_nonce_if_present( array $data ): bool {
		// Allow callers to require nonce only on mutating routes.
		if ( ! isset( $data[ self::NONCE_NAME ] ) || '' === $data[ self::NONCE_NAME ] ) {
			return true;
		}

		$token = sanitize_text_field( (string) $data[ self::NONCE_NAME ] );

		return (bool) wp_verify_nonce( $token, self::NONCE_ACTION );
	}

	/**
	 * Builds a normalized filter map with validation and hook after_filter_apply.
	 *
	 * @param array<string, mixed> $raw    Raw input map.
	 * @param string               $source Label for the after_filter_apply hook.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize_filters( array $raw, string $source ): array {
		try {
			$filters = array();

			// Type: single-line string from an allowlist enforced by the UI.
			if ( isset( $raw[ self::PARAM_TYPE ] ) && '' !== $raw[ self::PARAM_TYPE ] ) {
				$filters['type'] = sanitize_text_field( (string) $raw[ self::PARAM_TYPE ] );
			}

			// Single exact attempts value for the Attempts column (queue rows).
			if ( isset( $raw[ self::PARAM_ATTEMPTS ] ) && '' !== $raw[ self::PARAM_ATTEMPTS ] ) {
				$filters['attempts'] = max( 0, absint( $raw[ self::PARAM_ATTEMPTS ] ) );
			}

			// Single calendar day for created-at (column "Created at").
			$created_date = $this->parse_date_param( $raw, self::PARAM_CREATED_DATE );
			if ( null !== $created_date ) {
				$filters['created_date'] = $created_date;
			}

			// Single calendar day for next retry (column "Next retry").
			$next_retry_date = $this->parse_date_param( $raw, self::PARAM_NEXT_RETRY_DATE );
			if ( null !== $next_retry_date ) {
				$filters['next_retry_date'] = $next_retry_date;
			}

			// Last error: narrow search string, no HTML.
			if ( isset( $raw[ self::PARAM_LAST_ERROR ] ) && '' !== $raw[ self::PARAM_LAST_ERROR ] ) {
				$filters['last_error'] = sanitize_text_field( (string) $raw[ self::PARAM_LAST_ERROR ] );
			}

			// Ordering: whitelist keys are enforced in the query builder.
			if ( isset( $raw[ self::PARAM_ORDERBY ] ) && '' !== $raw[ self::PARAM_ORDERBY ] ) {
				$filters['orderby'] = sanitize_key( (string) $raw[ self::PARAM_ORDERBY ] );
			}
			if ( isset( $raw[ self::PARAM_ORDER ] ) && '' !== $raw[ self::PARAM_ORDER ] ) {
				$order_raw       = strtolower( sanitize_text_field( (string) $raw[ self::PARAM_ORDER ] ) );
				$filters['order'] = in_array( $order_raw, array( 'asc', 'desc' ), true ) ? $order_raw : 'desc';
			}

			// Pagination: reuse WordPress paged query var when present.
			if ( isset( $raw['paged'] ) ) {
				$filters['paged'] = max( 1, absint( $raw['paged'] ) );
			}

			/**
			 * Fires after filters are sanitized and before SQL construction.
			 *
			 * @param array<string, mixed> $filters Sanitized filters.
			 * @param string               $source  get|post|json|merged.
			 */
			do_action( self::HOOK_AFTER_FILTER_APPLY, $filters, $source );

			return apply_filters( 'wc_kledo_admin_table_sanitized_filters', $filters, $raw, $source );
		} catch ( \Throwable $e ) {
			// Never break the admin screen on malformed input; return empty filters.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( $e->getMessage() );
			}

			return array();
		}
	}

	/**
	 * Validates Y-m-d and returns the string or null when invalid or empty.
	 *
	 * @param array<string, mixed> $raw  Source array.
	 * @param string               $key  Parameter name.
	 *
	 * @return string|null Canonical Y-m-d or null.
	 */
	private function parse_date_param( array $raw, string $key ): ?string {
		if ( ! isset( $raw[ $key ] ) || '' === $raw[ $key ] ) {
			return null;
		}

		$s = sanitize_text_field( (string) $raw[ $key ] );
		// Reject anything that is not a strict calendar day token.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $s );
		// Ensure the string round-trips (catches 2024-02-30 style issues).
		if ( ! $dt instanceof \DateTimeImmutable || $dt->format( 'Y-m-d' ) !== $s ) {
			return null;
		}

		return $s;
	}

	/**
	 * Creates a nonce field HTML for embedding inside filter forms.
	 *
	 * @return string Markup safe for echoing in admin.
	 */
	public static function nonce_field(): string {
		return wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, true, false );
	}

	/**
	 * Returns the nonce value for JSON clients (localized script).
	 *
	 * @return string
	 */
	public static function create_nonce(): string {
		return wp_create_nonce( self::NONCE_ACTION );
	}
}
