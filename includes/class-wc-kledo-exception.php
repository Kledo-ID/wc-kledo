<?php
/**
 * Domain-specific exception for recoverable Kledo plugin errors (e.g. settings validation).
 *
 * @since 1.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Wraps runtime failures that should surface as admin-facing messages.
 *
 * @since 1.0.0
 */
class WC_Kledo_Exception extends RuntimeException {
	//
}
