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
