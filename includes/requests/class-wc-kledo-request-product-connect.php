<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Hands this store's WooCommerce REST credentials to Kledo so Kledo can read the catalogue and
 * write stock back.
 *
 * This is the one place the plugin gives Kledo a credential rather than using one. Everything else
 * in this plugin pushes *out* with the Kledo API key; product sync needs traffic in the other
 * direction, so Kledo needs a key of its own for this store.
 *
 * Kledo verifies before it stores: it calls `system_status` on this store with the credentials
 * below, and only creates the connection if that call succeeds. A failure comes back as HTTP 422
 * with a message written for the shop owner, which is why `connect()` returns that message
 * verbatim instead of inventing its own.
 *
 * @since 1.9.0
 */
class WC_Kledo_Request_Product_Connect extends WC_Kledo_Request {
	/**
	 * Status Kledo answers when it could not verify the store.
	 *
	 * @var int
	 * @since 1.9.0
	 */
	public const VERIFICATION_FAILED_RESPONSE_CODE = 422;

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	public function __construct() {
		parent::__construct();

		$this->set_endpoint( 'woocommerce/productConnect' );
	}

	/**
	 * Register this store with Kledo for product sync.
	 *
	 * The store URL is taken from WordPress itself (`get_home_url()`), never typed by the admin:
	 * Kledo refuses a URL that redirects, and the home URL is by definition the one this site
	 * answers on. Asking the admin to type it is how a `www`/non-`www` mismatch gets shipped.
	 *
	 * @param  string    $consumer_key     Consumer key just created for Kledo.
	 * @param  string    $consumer_secret  Its secret. Only available at creation time.
	 * @param  int|null  $warehouse_id     Kledo warehouse stock should land in; null = default.
	 *
	 * @return array{ok: bool, message: string, data: array} `ok` false carries a message that is
	 *                                                        safe to show the shop owner.
	 * @throws \RuntimeException When the HTTP layer fails before any response exists.
	 * @since 1.9.0
	 */
	public function connect( string $consumer_key, string $consumer_secret, ?int $warehouse_id = null ): array {
		$this->set_method( 'POST' );

		$body = array(
			'store_url'       => get_home_url(),
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
			'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'plugin_version'  => defined( 'WC_KLEDO_VERSION' ) ? WC_KLEDO_VERSION : null,
		);

		if ( null !== $warehouse_id ) {
			$body['warehouse_id'] = $warehouse_id;
		}

		$this->set_body( $body );

		$this->do_request();

		$response_code = (int) $this->get_response_code();

		try {
			$response = $this->get_response();
		} catch ( JsonException $exception ) {
			wc_kledo_log_warning(
				sprintf( 'Kledo product connect: response body was not valid JSON: %s', $exception->getMessage() )
			);

			return $this->failure( __( 'Kledo returned an unreadable response. Please try again.', 'wc-kledo' ) );
		}

		if ( ! is_array( $response ) ) {
			return $this->failure( __( 'Kledo returned an unreadable response. Please try again.', 'wc-kledo' ) );
		}

		if ( 200 !== $response_code ) {
			// Kledo's own message is the useful one — it distinguishes "store unreachable" from
			// "API key rejected" from "REST API not found", each needing a different fix. Only
			// fall back to a generic line when Kledo sent nothing usable.
			$message = isset( $response['message'] ) && is_string( $response['message'] ) && '' !== $response['message']
				? $response['message']
				: __( 'Kledo could not connect this store. Please try again.', 'wc-kledo' );

			if ( self::VERIFICATION_FAILED_RESPONSE_CODE !== $response_code ) {
				wc_kledo_log_warning(
					sprintf( 'Kledo product connect: unexpected HTTP %d — %s', $response_code, $message )
				);
			}

			return $this->failure( $message );
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		if ( empty( $data['connect_id'] ) ) {
			return $this->failure( __( 'Kledo did not return a connection id. Please try again.', 'wc-kledo' ) );
		}

		return array(
			'ok'      => true,
			'message' => isset( $response['message'] ) && is_string( $response['message'] )
				? $response['message']
				: '',
			'data'    => $data,
		);
	}

	/**
	 * @param  string  $message  Shown to the shop owner as-is.
	 *
	 * @return array{ok: bool, message: string, data: array}
	 * @since 1.9.0
	 */
	private function failure( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'data'    => array(),
		);
	}
}
