<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Records the requests the plugin sends to Kledo and what comes back.
 *
 * Two users: the Diagnostics tab, which records only while it runs, and the temporary detailed log,
 * which writes every request to the WooCommerce log (source `wc-kledo-debug`) for 24 hours so an
 * issue that only happens now and then can still be caught.
 *
 * The User-Agent is read from `http_request_args` at the last priority rather than from the
 * plugin's own arguments: another plugin rewriting it is one of the reasons Kledo refuses a
 * request, and only the final arguments show that.
 *
 * The API key never reaches a record: the Authorization header is always redacted, and the
 * customer's personal data is masked unless the admin asked for it in full.
 *
 * @since 1.8.0
 */
class WC_Kledo_Debug_Trace {
	/**
	 * WooCommerce log source of the detailed log.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const LOG_SOURCE = 'wc-kledo-debug';

	/**
	 * Characters of a response body kept in a record.
	 *
	 * @var int
	 * @since 1.8.0
	 */
	private const MAX_BODY_LENGTH = 4000;

	/**
	 * Records of this trace.
	 *
	 * @var array<int, array>
	 * @since 1.8.0
	 */
	private array $entries = array();

	/**
	 * Whether personal customer data is kept as is.
	 *
	 * @var bool
	 * @since 1.8.0
	 */
	private bool $full_customer_data;

	/**
	 * Whether each completed record is also written to the WooCommerce log.
	 *
	 * @var bool
	 * @since 1.8.0
	 */
	private bool $write_to_log;

	/**
	 * The class constructor.
	 *
	 * @param  bool $full_customer_data
	 * @param  bool $write_to_log
	 *
	 * @since 1.8.0
	 */
	public function __construct( bool $full_customer_data = false, bool $write_to_log = false ) {
		$this->full_customer_data = $full_customer_data;
		$this->write_to_log       = $write_to_log;
	}

	/**
	 * Keep the detailed log running while it is switched on. Called once at plugin start.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public static function maybe_start_detailed_log(): void {
		if ( wc_kledo_debug_log_until() > 0 ) {
			( new self( false, true ) )->start();
		}
	}

	/**
	 * Start recording.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function start(): void {
		add_action( 'wc_kledo_http_request', array( $this, 'on_request' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'on_final_args' ), PHP_INT_MAX, 2 );
		add_action( 'wc_kledo_http_response', array( $this, 'on_response' ), 10, 4 );
	}

	/**
	 * Stop recording.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function stop(): void {
		remove_action( 'wc_kledo_http_request', array( $this, 'on_request' ), 10 );
		remove_filter( 'http_request_args', array( $this, 'on_final_args' ), PHP_INT_MAX );
		remove_action( 'wc_kledo_http_response', array( $this, 'on_response' ), 10 );
	}

	/**
	 * What was recorded.
	 *
	 * @return array<int, array>
	 * @since 1.8.0
	 */
	public function get_entries(): array {
		return $this->entries;
	}

	/**
	 * Record the request as the plugin built it.
	 *
	 * @param  string $method
	 * @param  string $url
	 * @param  array  $args
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function on_request( $method, $url, $args ): void {
		$args = is_array( $args ) ? $args : array();
		$body = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();

		$this->entries[] = array(
			'time'             => gmdate( 'c' ),
			'method'           => (string) $method,
			'url'              => (string) $url,
			'headers'          => wc_kledo_redact_http_headers( $args['headers'] ?? array() ),
			'user_agent'       => (string) ( $args['user-agent'] ?? '' ),
			'final_user_agent' => (string) ( $args['user-agent'] ?? '' ),
			'body'             => $this->full_customer_data ? $body : wc_kledo_mask_customer_data( $body ),
			'status'           => null,
			'response_headers' => array(),
			'response_body'    => '',
			'duration_ms'      => null,
			'error'            => '',
		);
	}

	/**
	 * Record the User-Agent the request really leaves with, after every other plugin had its say.
	 *
	 * @param  array  $args
	 * @param  string $url
	 *
	 * @return array Unchanged.
	 * @since 1.8.0
	 */
	public function on_final_args( $args, $url ) {
		$last = count( $this->entries ) - 1;

		if ( $last >= 0 && null === $this->entries[ $last ]['status'] && (string) $url === $this->entries[ $last ]['url'] && is_array( $args ) ) {
			$this->entries[ $last ]['final_user_agent'] = (string) ( $args['user-agent'] ?? '' );
		}

		return $args;
	}

	/**
	 * Record the response.
	 *
	 * @param  array|\WP_Error $response
	 * @param  int             $duration_ms
	 * @param  string          $method
	 * @param  string          $url
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function on_response( $response, $duration_ms, $method, $url ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$last = count( $this->entries ) - 1;

		if ( $last < 0 ) {
			return;
		}

		$this->entries[ $last ]['duration_ms'] = (int) $duration_ms;

		if ( is_wp_error( $response ) ) {
			$this->entries[ $last ]['status'] = 0;
			$this->entries[ $last ]['error']  = $response->get_error_message();
		} else {
			$headers = wp_remote_retrieve_headers( $response );

			$this->entries[ $last ]['status']           = (int) wp_remote_retrieve_response_code( $response );
			$this->entries[ $last ]['response_headers'] = wc_kledo_redact_http_headers( is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers );
			$this->entries[ $last ]['response_body']    = substr( (string) wp_remote_retrieve_body( $response ), 0, self::MAX_BODY_LENGTH );
		}

		if ( $this->write_to_log ) {
			wc_kledo_log( 'info', 'Kledo request ' . wp_json_encode( $this->entries[ $last ] ), array( 'source' => self::LOG_SOURCE ) );

			// The detailed log is per request; nothing needs to stay in memory.
			$this->entries = array();
		}
	}
}
