<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Request_Connection extends WC_Kledo_Request {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function __construct() {
		parent::__construct();

		// Set API endpoint.
		$this->set_endpoint( 'woocommerce/connection' );
	}

	/**
	 * Read the state of the API key this shop is connected with.
	 *
	 * Response payload (`data`), as documented by the API:
	 *
	 *     expires_at      string|null  `Y-m-d H:i:s`, in the timezone named below
	 *     days_remaining  int|null     negative once the key has already expired
	 *     auto_renews     bool         false for a 24-hour key, which is never renewed
	 *     timezone        string       e.g. `Asia/Jakarta`
	 *
	 * There is deliberately no "expired" or "revoked" flag: the key being reported on is the key
	 * that authenticated the call, so a key in either state never reaches this endpoint at all.
	 * Kledo answers a dead key with HTTP 401, and that status is the signal — see
	 * `WC_Kledo_Connection_Status::refresh()`, which reads the code rather than the body.
	 *
	 * `days_remaining` is taken from the API rather than computed here on purpose: Kledo answers
	 * in its own timezone, and comparing that against WordPress' clock without converting is how
	 * an off-by-one day warning gets shipped.
	 *
	 * @return array|false Decoded response, or false on any non-200, on a body that is not JSON,
	 *                     or when the API reports failure.
	 * @throws \RuntimeException When the HTTP layer fails before any response exists.
	 * @since 1.7.4
	 */
	public function get_connection() {
		$this->set_method( 'GET' );

		$this->do_request();

		// Judge the outcome by the status code, not by the body. Kledo's 401 does carry
		// `success: false`, so the envelope check further down would reject it too — but only by
		// accident of how Kledo happens to shape errors today, and it would flatten a refused key
		// into the same `false` that a timeout produces. The caller needs the status code to tell
		// those apart, and reading it here means no future change to the error body can let a
		// non-200 pass as an answer.
		$response_code = (int) $this->get_response_code();

		if ( 200 !== $response_code ) {
			// 401 is expected and reported by the caller, which can say what it means. Anything
			// else reaching the admin as "could not be reached" needs a reason on record, or the
			// message is undiagnosable by anyone who later has to explain it.
			if ( WC_Kledo_Connection_Status::UNAUTHENTICATED_RESPONSE_CODE !== $response_code ) {
				wc_kledo_log_warning(
					sprintf( 'Kledo connection check: unexpected HTTP %d from the connection endpoint.', $response_code )
				);
			}

			return false;
		}

		// get_response() decodes with JSON_THROW_ON_ERROR, so an HTML error page or a truncated
		// body raises JsonException. That is a malformed response, not a transport failure, so it
		// is reported as false here rather than thrown at the caller.
		try {
			$response = $this->get_response();
		} catch ( JsonException $exception ) {
			wc_kledo_log_warning(
				sprintf(
					'Kledo connection check: response body was not valid JSON: %s',
					$exception->getMessage()
				)
			);

			return false;
		}

		if ( ! is_array( $response ) ) {
			return false;
		}

		if ( isset( $response['success'] ) && false === $response['success'] ) {
			return false;
		}

		return $response;
	}
}
