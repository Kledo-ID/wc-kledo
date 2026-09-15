<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

abstract class WC_Kledo_Request {
	/**
	 * The API host.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $api_host;

	/**
	 * The API endpoint path.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $endpoint = '';

	/**
	 * The request method.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $method;

	/**
	 * The request body.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private array $body = array();

	/**
	 * The query string.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private array $query = array();

	/**
	 * The request response.
	 *
	 * @var mixed
	 * @since 1.0.0
	 */
	private $response = null;

	/**
	 * The class constructor
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->api_host = wc_kledo()->get_connection_handler()->get_api_endpoint();
	}

	/**
	 * Create new transaction.
	 *
	 * @param  \WC_Order   $order
	 * @param  string      $ref_number_prefix
	 * @param  string|null $warehouse
	 * @param  array       $tags
	 * @param  array       $additional_body  Endpoint-specific fields merged over the shared body.
	 *                                       Both transaction endpoints share this builder, so a
	 *                                       field only one of them accepts belongs here rather
	 *                                       than in the body above — Kledo does not read
	 *                                       `link_order` on `woocommerce/order`.
	 *
	 * @return bool|array
	 * @throws \JsonException
	 * @throws \Exception
	 * @since 1.0.0
	 * @since 1.1.0 Add `has_tax` field.
	 * @since 1.3.0 Add `ref_number_prefix` parameter.
	 * @since 1.3.0 Add `tags` parameter.
	 * @since 1.7.4 Add `additional_body` parameter.
	 */
	protected function create_transaction(
		WC_Order $order,
		string $ref_number_prefix,
		?string $warehouse,
		array $tags,
		array $additional_body = array()
	) {
		$this->set_method( 'POST' );

		$body = array(
			'contact_name'               => $this->get_customer_name( $order ),
			'contact_email'              => $order->get_billing_email(),
			'contact_address'            => $order->get_billing_address_1(),
			'contact_phone'              => $order->get_billing_phone(),
			'ref_number_prefix'          => $ref_number_prefix,
			'ref_number'                 => $order->get_id(),
			'trans_date'                 => $order->get_date_created()->format( 'Y-m-d' ),
			'due_date'                   => $this->get_due_date( $order ),
			'memo'                       => $order->get_customer_note(),
			'has_tax'                    => wc_kledo_include_tax_or_not( $order ),
			'items'                      => $this->get_items( $order ),
			'warehouse'                  => $warehouse,
			'shipping_cost'              => $order->get_shipping_total(),
			'additional_discount_amount' => $order->get_total_discount(),
			'paid'                       => wc_kledo_paid_status(),
			'paid_to_account_code'       => wc_kledo_get_payment_account(),
			'tags'                       => $tags,
		);

		// Get shipping tracking data if exists.
		$shipping_data = $this->get_shipping_tracking( $order );
		if ( $shipping_data ) {
			$body['shipping_tracking'] = $shipping_data;
		}

		if ( ! empty( $additional_body ) ) {
			$body = array_merge( $body, $additional_body );
		}

		$this->set_body( $body );

		$this->do_request();

		$response = $this->get_response();

		if ( ( isset( $response['success'] ) && false === $response['success'] ) ) {
			return false;
		}

		return $response;
	}

	/**
	 * Get customer name.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_customer_name( WC_Order $order ): string {
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/**
	 * Get transaction due date.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return string
	 * @since 1.3.1
	 */
	protected function get_due_date( WC_Order $order ): string {
		$date_completed = $order->get_date_completed();

		if ( $date_completed ) {
			return $date_completed->format( 'Y-m-d' );
		}

		return $order->get_date_created()
					->modify( '+1 month' )
					->format( 'Y-m-d' );
	}

	/**
	 * Get shipping tracking data.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array
	 * @since 1.3.0
	 */
	protected function get_shipping_tracking( WC_Order $order ): array {
		if ( ! class_exists( 'WC_Shipment_Tracking' ) ) {
			return array();
		}

		return $order->get_meta( '_wc_shipment_tracking_items' );
	}

	/**
	 * Get the product items from order.
	 *
	 * @param  \WC_Order $order
	 *
	 * @return array
	 * @throws \Exception
	 * @since 1.0.0
	 *
	 * @noinspection PhpPossiblePolymorphicInvocationInspection
	 */
	public function get_items( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$items[] = array(
				'name'          => $product->get_name(),
				'code'          => $product->get_sku(),
				'desc'          => $product->get_short_description(),
				'qty'           => $item->get_quantity(),
				'regular_price' => $product->get_regular_price(),
				'sale_price'    => $product->get_sale_price(),
				'photo'         => wp_get_attachment_url( $product->get_image_id() ) ?: null,
				'category_name' => 'WooCommerce',
			);
		}

		return $items;
	}

	/**
	 * Do the request.
	 *
	 * @return bool
	 * @throws \RuntimeException Configuration missing, connection failure, or unrecoverable API error.
	 * @since 1.0.0
	 */
	public function do_request(): bool {
		// Check if connected.
		if ( ! wc_kledo()->get_connection_handler()->is_configured() ) {
			throw new RuntimeException( esc_html( __( "Can't do API request because the api key & endpoint url has not been configured.", 'wc-kledo' ) ) );
		}

		// Do the request.
		$this->response = wp_remote_request(
			$this->get_url(),
			array(
				'method'     => $this->get_method(),
				'timeout'    => 10,
				'user-agent' => $this->get_request_user_agent(),
				'headers'    => array(
					'Authorization' => 'Bearer ' . wc_kledo()->get_connection_handler()->get_api_key(),
					'Accept'        => 'application/json',
				),
				'body'       => $this->get_body(),
				/**
				 * Whether to verify SSL for outbound Kledo requests.
				 * Default false for backward compatibility with legacy stacks; set to true in production when possible.
				 *
				 * @param  bool  $sslverify
				 *
				 * @since 1.5.0
				 */
				'sslverify'  => (bool) apply_filters( 'wc_kledo_http_sslverify', false ),
			)
		);

		// Check if request is an error.
		if ( is_wp_error( $this->response ) ) {
			$wp_error_msg = $this->response->get_error_message();
			$this->clear_response();

			if ( '' !== trim( $wp_error_msg ) ) {
				throw new RuntimeException( esc_html( sprintf( 'Connection error: %s', $wp_error_msg ) ) );
			}

			throw new RuntimeException( esc_html( __( 'There was a problem when connecting to the API.', 'wc-kledo' ) ) );
		}

		$this->maybe_store_rotated_api_key();
		$this->maybe_flag_rejected_api_key();

		return true;
	}

	/**
	 * Drop the cached connection status when Kledo refuses the key we are holding.
	 *
	 * Any endpoint answering 401 says the same thing: the stored key is dead. Acting on it here
	 * rather than waiting for the cached status to expire means a shop whose key was revoked
	 * overnight sees the warning on the next admin page load instead of up to six hours later —
	 * and the first thing that notices is usually an order sync, not an admin visit.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	private function maybe_flag_rejected_api_key(): void {
		if ( WC_Kledo_Connection_Status::UNAUTHENTICATED_RESPONSE_CODE !== (int) $this->get_response_code() ) {
			return;
		}

		delete_transient( WC_Kledo_Connection_Status::TRANSIENT_KEY );
	}

	/**
	 * Persist a replacement API key when Kledo rotated the one we just used.
	 *
	 * Kledo refreshes a token that is within its renewal window by revoking it and returning the
	 * replacement as `access_token` in the response body. That happens on any authenticated
	 * endpoint, which is why this sits in the shared request path rather than in one caller.
	 *
	 * Deliberately independent of the response status: the rotation has already happened on
	 * Kledo's side by the time the body reaches us, so skipping the save on a non-2xx response
	 * would throw away the only copy of the key that still works.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	private function maybe_store_rotated_api_key(): void {
		try {
			$body = $this->get_response();
		} catch ( JsonException $exception ) {
			// Not a JSON body (an HTML error page, a truncated response): nothing to read.
			return;
		}

		if ( ! is_array( $body ) || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
			return;
		}

		$connection   = wc_kledo()->get_connection_handler();
		$previous_key = $connection->get_api_key();

		if ( ! $connection->update_api_key( $body['access_token'] ) ) {
			return;
		}

		// Everything cached about the key describes the one that was just replaced — including
		// the masked form shown on the settings screen and the expiry, which belong to a
		// different credential now.
		wc_kledo()->get_connection_status()->invalidate();

		// Never log the key itself — WooCommerce logs are readable from the admin and are
		// routinely pasted into support threads. The shape is safe to name and is worth naming:
		// a managed `kledo_pat_` key being replaced by a raw token means the key the shop manages
		// in Kledo is no longer the key this store is using, which is not visible anywhere else.
		$was_managed = 0 === strpos( $previous_key, 'kledo_pat_' );
		$is_managed  = 0 === strpos( $body['access_token'], 'kledo_pat_' );

		// Recorded after invalidate() above, which clears it: rotation sets it again, a key
		// pasted by hand leaves it cleared. The settings screen reads this to explain why the key
		// Kledo lists is no longer the key this store sends.
		if ( $was_managed && ! $is_managed ) {
			update_option( wc_kledo_get_api_key_detached_option_name(), 'yes', false );
		}

		wc_kledo_log_info(
			sprintf(
				'Kledo issued a replacement API key while calling %s; the new key has been saved.%s',
				$this->get_endpoint(),
				( $was_managed && ! $is_managed )
					? ' Note: the replacement is not a managed kledo_pat_ key, so it will no longer'
						. ' match the token listed in Kledo.'
					: ''
			)
		);
	}

	/**
	 * Get the endpoint.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_endpoint(): string {
		return $this->endpoint;
	}

	/**
	 * Set the endpoint.
	 *
	 * @param  string $endpoint
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function set_endpoint( string $endpoint ): void {
		$this->endpoint = $endpoint;
	}

	/**
	 * Get the request method.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_method(): string {
		return $this->method;
	}

	/**
	 * Set the request method.
	 *
	 * @param  string $method
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function set_method( string $method ): void {
		$this->method = $method;
	}

	/**
	 * Get the request body.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	protected function get_body(): array {
		return $this->body;
	}

	/**
	 * Set the request body.
	 *
	 * @param  array $body
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function set_body( $body ): void {
		$this->body = $body;
	}

	/**
	 * Get the query.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	protected function get_query(): array {
		return $this->query;
	}

	/**
	 * Set the query.
	 *
	 * @param  array $query
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function set_query( array $query ): void {
		$this->query = $query;
	}

	/**
	 * Get the request response.
	 *
	 * @return mixed
	 * @throws \JsonException
	 * @since 1.0.0
	 */
	public function get_response( $json = true ) {
		$response = wp_remote_retrieve_body( $this->response );

		if ( $json ) {
			$response = @json_decode( $response, true, 512, JSON_THROW_ON_ERROR );
		}

		return $response;
	}

	/**
	 * Get the request header response.
	 *
	 * @param  string|null $header
	 *
	 * @return array|string
	 * @since 1.0.0
	 */
	public function get_header( ?string $header = null ) {
		if ( is_null( $header ) ) {
			return wp_remote_retrieve_headers( $this->response );
		}

		return wp_remote_retrieve_header( $this->response, $header );
	}

	/**
	 * Get the request response code.
	 *
	 * @return int|string
	 * @since 1.0.0
	 */
	public function get_response_code() {
		return wp_remote_retrieve_response_code( $this->response );
	}

	/**
	 * Get the request response message.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_response_message(): string {
		return wp_remote_retrieve_response_message( $this->response );
	}

	/**
	 * Get API url.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_url(): string {
		return add_query_arg( $this->get_query(), $this->api_host . '/' . $this->get_endpoint() );
	}

	/**
	 * Get the request user agent, defaults to:
	 *
	 * Dasherized-Plugin-Name/Plugin-Version (WooCommerce/WC-Version; WordPress/WP-Version)
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_request_user_agent(): string {
		return sprintf( '%s/%s (WooCommerce/%s; WordPress/%s)', str_replace( ' ', '-', WC_KLEDO_PLUGIN_NAME ), WC_KLEDO_VERSION, WC_VERSION, $GLOBALS['wp_version'] );
	}

	/**
	 * Build a human-readable error label from the current HTTP response.
	 *
	 * Combines HTTP status code + reason phrase with any message extracted from
	 * the response body (JSON fields: message > error > errors). Safe for
	 * storage and admin display.
	 *
	 * @return string Non-empty label.
	 * @throws \JsonException
	 * @since 1.7.0
	 */
	public function get_api_error_label(): string {
		$code   = (int) $this->get_response_code();
		$phrase = $this->get_response_message();

		$label = $code > 0 ? sprintf( 'HTTP %d', $code ) : 'HTTP error';

		if ( '' !== $phrase ) {
			$label .= ' - ' . $phrase;
		}

		$api_msg = $this->extract_body_error_message();

		if ( '' !== $api_msg ) {
			$label .= ': ' . $api_msg;
		}

		return $label;
	}

	/**
	 * Extract a meaningful error string from the raw response body.
	 *
	 * Priority for JSON bodies: `message` > `error` > `errors` (first 3 items).
	 * Falls back to short plain-text body when response is not JSON.
	 *
	 * @return string Empty string when nothing useful is found.
	 * @throws \JsonException
	 */
	private function extract_body_error_message(): string {
		$raw = $this->get_response( false );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}

		$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );

		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'error' ) as $field ) {
				if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
					return wp_strip_all_tags( $data[ $field ] );
				}
			}

			if ( ! empty( $data['errors'] ) ) {
				$errors = $data['errors'];

				if ( is_string( $errors ) && '' !== trim( $errors ) ) {
					return wp_strip_all_tags( $errors );
				}

				if ( is_array( $errors ) ) {
					$parts = array();

					foreach ( $errors as $value ) {
						if ( is_string( $value ) && '' !== trim( $value ) ) {
							$parts[] = wp_strip_all_tags( $value );
						} elseif ( is_array( $value ) ) {
							foreach ( $value as $v ) {
								if ( is_string( $v ) && '' !== trim( $v ) ) {
									$parts[] = wp_strip_all_tags( $v );
								}
							}
						}

						if ( count( $parts ) >= 3 ) {
							break;
						}
					}

					if ( ! empty( $parts ) ) {
						return implode( '; ', $parts );
					}
				}
			}

			return '';
		}

		// Plain-text fallback: include only when short enough to be meaningful.
		$text = trim( wp_strip_all_tags( $raw ) );

		if ( '' !== $text && mb_strlen( $text ) <= 200 ) {
			return $text;
		}

		return '';
	}

	/**
	 * Clear the request response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function clear_response(): void {
		$this->response = null;
	}
}
