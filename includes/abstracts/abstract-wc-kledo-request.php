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
		$this->api_host = wc_kledo()->get_connection_handler()->get_oauth_url();
	}

	/**
	 * Create new transaction.
	 *
	 * @param  \WC_Order  $order
	 * @param  string  $ref_number_prefix
	 * @param  string|null  $warehouse
	 * @param  array  $tags
	 *
	 * @return bool|array
	 * @throws \JsonException
	 * @throws \Exception
	 * @since 1.0.0
	 * @since 1.1.0 Add `has_tax` field.
	 * @since 1.3.0 Add `ref_number_prefix` parameter.
	 * @since 1.3.0 Add `tags` parameter.
	 */
	protected function create_transaction( WC_Order $order, string $ref_number_prefix, ?string $warehouse, array $tags) {
		$this->set_method( 'POST' );
		$this->set_body( array(
			'contact_name'               => $this->get_customer_name( $order ),
			'contact_email'              => $order->get_billing_email(),
			'contact_address'            => $order->get_billing_address_1(),
			'contact_phone'              => $order->get_billing_phone(),
			'ref_number_prefix'          => $ref_number_prefix,
			'ref_number'                 => $order->get_id(),
			'trans_date'                 => $order->get_date_created()->format( 'Y-m-d' ),
			'due_date'                   => $order->get_date_completed()->format( 'Y-m-d' ),
			'memo'                       => $order->get_customer_note(),
			'has_tax'                    => wc_kledo_include_tax_or_not( $order ),
			'items'                      => $this->get_items( $order ),
			'warehouse'                  => $warehouse,
			'shipping_cost'              => $order->get_shipping_total(),
			'additional_discount_amount' => $order->get_total_discount(),
			'paid'                       => wc_kledo_paid_status(),
			'paid_to_account_code'       => wc_kledo_get_payment_account(),
			'tags'                       => $tags,
		) );

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
	 * @param  \WC_Order  $order
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_customer_name( WC_Order $order ): string {
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/**
	 * Get the product items from order.
	 *
	 * @param  \WC_Order  $order
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
			/** @var \WC_Product $product */
			$product = $item->get_product();

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
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function do_request(): bool {
		// Check if connected.
		if ( ! wc_kledo()->get_connection_handler()->is_connected() ) {
			throw new \RuntimeException( __( "Can't do API request because the connection has not been made.", WC_KLEDO_TEXT_DOMAIN ) );
		}

		// Do the request.
		$this->response = wp_remote_request(
			$this->get_url(),
			array(
				'method'     => $this->get_method(),
				'timeout'    => 10,
				'user-agent' => $this->get_request_user_agent(),
				'headers'    => array(
					'Authorization' => 'Bearer ' . wc_kledo()->get_connection_handler()->get_access_token(),
					'Accept'        => 'application/json',
				),
				'body'       => $this->get_body(),
				'sslverify'  => false,
			)
		);

		// Check if request is an error.
		if ( is_wp_error( $this->response ) ) {
			$this->clear_response();
			throw new \RuntimeException( __( 'There was a problem when connecting to the API.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		return true;
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
	 * @param  string  $endpoint
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
	 * @param  string  $method
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
	 * @param  array  $body
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
	 * @param  array  $query
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
	 * @param  string|null  $header
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
	 * Clear the request response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function clear_response(): void {
		$this->response = null;
	}
}
