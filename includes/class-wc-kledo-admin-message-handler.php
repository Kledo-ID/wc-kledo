<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Admin_Message_Handler {
	/**
	 * Transient message prefix.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const MESSAGE_TRANSIENT_PREFIX = '_wp_admin_message_';

	/**
	 * The message id GET name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const MESSAGE_ID_GET_NAME = 'wc_kledo';

	/**
	 * The unique message identifier.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $message_id;

	/**
	 * The array of messages.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $messages = array();

	/**
	 * The array of error messages.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $errors = array();

	/**
	 * The array of warning messages.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $warnings = array();

	/**
	 * The array of info messages.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $infos = array();

	/**
	 * Construct and initialize the admin message handler class.
	 *
	 * @param  string  $message_id  optional message id.  Best practice is to set
	 *                              this to a unique identifier based on the client plugin,
	 *                              such as __FILE__
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct( $message_id = null ) {
		$this->message_id = $message_id;

		// Load any available messages.
		$this->load_messages();

		add_filter( 'wp_redirect', array( $this, 'redirect' ), 1, 2 );
	}

	/**
	 * Persist messages.
	 *
	 * @return bool true if any messages were set, false otherwise
	 * @since 1.0.0
	 */
	public function set_messages() {
		// Any messages to persist?
		if ( $this->message_count() > 0 || $this->info_count() > 0 || $this->warning_count() > 0 || $this->error_count() > 0 ) {
			set_transient(
				self::MESSAGE_TRANSIENT_PREFIX . $this->get_message_id(),
				array(
					'errors'   => $this->errors,
					'warnings' => $this->warnings,
					'infos'    => $this->infos,
					'messages' => $this->messages,
				),
				60 * 60
			);

			return true;
		}

		return false;
	}

	/**
	 * Loads messages.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function load_messages() {
		if ( isset( $_GET[ self::MESSAGE_ID_GET_NAME ] ) && $this->get_message_id() === $_GET[ self::MESSAGE_ID_GET_NAME ] ) {
			$memo = get_transient( self::MESSAGE_TRANSIENT_PREFIX . $_GET[ self::MESSAGE_ID_GET_NAME ] );

			if ( isset( $memo['errors'] ) ) {
				$this->errors = $memo['errors'];
			}

			if ( isset( $memo['warnings'] ) ) {
				$this->warnings = $memo['warnings'];
			}

			if ( isset( $memo['infos'] ) ) {
				$this->infos = $memo['infos'];
			}

			if ( isset( $memo['messages'] ) ) {
				$this->messages = $memo['messages'];
			}

			$this->clear_messages( $_GET[ self::MESSAGE_ID_GET_NAME ] );
		}
	}

	/**
	 * Clear messages and errors.
	 *
	 * @param  string  $id  the messages identifier
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function clear_messages( $id ) {
		delete_transient( self::MESSAGE_TRANSIENT_PREFIX . $id );
	}

	/**
	 * Add an error message.
	 *
	 * @param  string  $error  error message
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_error( $error ) {
		$this->errors[] = $error;
	}

	/**
	 * Adds a warning message.
	 *
	 * @param  string  $message  warning message to add
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_warning( $message ) {
		$this->warnings[] = $message;
	}

	/**
	 * Adds a info message.
	 *
	 * @param  string  $message  info message to add
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_info( $message ) {
		$this->infos[] = $message;
	}

	/**
	 * Add a message.
	 *
	 * @param  string  $message  the message to add
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_message( $message ) {
		$this->messages[] = $message;
	}

	/**
	 * Get error count.
	 *
	 * @return int error message count
	 * @since 1.0.0
	 */
	public function error_count() {
		return count( $this->errors );
	}

	/**
	 * Gets the warning message count.
	 *
	 * @return int warning message count
	 * @since 1.0.0
	 */
	public function warning_count() {
		return count( $this->warnings );
	}

	/**
	 * Gets the info message count.
	 *
	 * @return int info message count
	 * @since 1.0.0
	 */
	public function info_count() {
		return count( $this->infos );
	}

	/**
	 * Get message count.
	 *
	 * @return int message count
	 * @since 1.0.0
	 */
	public function message_count() {
		return count( $this->messages );
	}

	/**
	 * Get error messages.
	 *
	 * @return array of error message strings
	 * @since 1.0.0
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Get an error message.
	 *
	 * @param  int  $index  the error index
	 *
	 * @return string the error message
	 * @since 1.0.0
	 */
	public function get_error( $index ) {
		return $this->errors[ $index ] ?? '';
	}

	/**
	 * Gets all warning messages.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_warnings() {
		return $this->warnings;
	}

	/**
	 * Gets a specific warning message.
	 *
	 * @param  int  $index  warning message index
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_warning( $index ) {
		return $this->warnings[ $index ] ?? '';
	}

	/**
	 * Gets all info messages.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_infos() {
		return $this->infos;
	}

	/**
	 * Gets a specific info message.
	 *
	 * @param  int  $index  info message index
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_info( $index ) {
		return $this->infos[ $index ] ?? '';
	}

	/**
	 * Get messages.
	 *
	 * @return array of message strings
	 * @since 1.0.0
	 */
	public function get_messages() {
		return $this->messages;
	}

	/**
	 * Get a message.
	 *
	 * @param  int  $index  the message index
	 *
	 * @return string the message
	 * @since 1.0.0
	 */
	public function get_message( $index ) {
		return $this->messages[ $index ] ?? '';
	}

	/**
	 * Render the errors and messages.
	 *
	 * @param  array|object  $params  {
	 *      Optional parameters.
	 *
	 * @type array $capabilities Any user capabilities to check
	 *                                if the user is allowed to view the messages,
	 *                                default: `manage_woocommerce`
	 * }
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function show_messages( $params = array() ) {
		$params = wp_parse_args( $params, array(
			'capabilities' => array(
				'manage_woocommerce',
			),
		) );

		$check_user_capabilities = array();

		// Check if user has at least one capability that allows to see messages.
		foreach ( $params['capabilities'] as $capability ) {
			$check_user_capabilities[] = current_user_can( $capability );
		}

		// Bail out if user has no minimum capabilities to see messages.
		if ( ! in_array( true, $check_user_capabilities, true ) ) {
			return;
		}

		$output = '';

		if ( $this->error_count() > 0 ) {
			$output .= '<div id="wp-admin-message-handler-error" class="notice-error notice"><ul><li><strong>' . implode( '</strong></li><li><strong>', $this->get_errors() ) . '</strong></li></ul></div>';
		}

		if ( $this->warning_count() > 0 ) {
			$output .= '<div id="wp-admin-message-handler-warning"  class="notice-warning notice"><ul><li><strong>' . implode( '</strong></li><li><strong>', $this->get_warnings() ) . '</strong></li></ul></div>';
		}

		if ( $this->info_count() > 0 ) {
			$output .= '<div id="wp-admin-message-handler-warning"  class="notice-info notice"><ul><li><strong>' . implode( '</strong></li><li><strong>', $this->get_infos() ) . '</strong></li></ul></div>';
		}

		if ( $this->message_count() > 0 ) {
			$output .= '<div id="wp-admin-message-handler-message"  class="notice-success notice"><ul><li><strong>' . implode( '</strong></li><li><strong>', $this->get_messages() ) . '</strong></li></ul></div>';
		}

		echo wp_kses_post( $output );
	}

	/**
	 * Redirection hook which persists messages into session data.
	 *
	 * @param  string  $location  the URL to redirect to
	 * @param  int  $status  the http status
	 *
	 * @return string the URL to redirect to
	 * @since 1.0.0
	 */
	public function redirect( $location, $status ) {
		// Add the admin message id param.
		if ( $this->set_messages() ) {
			$location = add_query_arg( self::MESSAGE_ID_GET_NAME, $this->get_message_id(), $location );
		}

		return $location;
	}

	/**
	 * Generate a unique id to identify the messages.
	 *
	 * @return string unique identifier
	 * @since 1.0.0
	 */
	protected function get_message_id() {
		if ( ! isset( $this->message_id ) ) {
			$this->message_id = __FILE__;
		}

		return wp_create_nonce( $this->message_id );
	}
}
