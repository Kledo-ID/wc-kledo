<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Issuing_Token {
	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'issue_token' ) );
	}

	/**
	 * Issues the token.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function issue_token() {
		// Return if not on plugin settings page.
		if ( ! wc_kledo()->is_plugin_settings() ) {
			return;
		}

		// Return if on plugin settings page but not in configure tab.
		if ( isset( $_GET['tab'] ) && 'configure' !== wc_kledo_get_requested_value( 'tab' ) ) {
			return;
		}

		$action = wc_kledo_get_requested_value( 'action' );

		// Redirecting for authorization.
		if ( 'redirect' === $action ) {
			$this->authorization();
		} // Converting authorization codes to access tokens.
		elseif ( 'callback' === $action ) {
			$this->convert_authorization_codes();
		} // Invalid verify state parameter.
		elseif ( 'invalid-state' === $action ) {
			$this->invalid_state();
		} // Successfully connected.
		elseif ( 'connected' === $action ) {
			$this->connected();
		} // Disconnect connection.
		elseif ( 'disconnect' === $action ) {
			$this->disconnect();
		} // Display disconnect message.
		elseif ( 'disconnected' === $action ) {
			$this->disconnect( true );
		} // Refreshing the access token.
		elseif ( 'refresh' === $action ) {
			$this->refresh_token();
		} // Display refreshed token message.
		elseif ( 'refreshed' === $action ) {
			$this->refresh_token( true );
		}
	}

	/**
	 * Redirect user to authorization page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function authorization() {
		$request_url = wc_kledo()->get_connection_handler()->get_redirect_authorization();

		wp_redirect( $request_url );
		die();
	}

	/**
	 * Converting authorization codes to access tokens.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function convert_authorization_codes() {
		$state = wc_kledo()->get_connection_handler()->get_state();

		if ( ! empty( $state ) && $state !== wc_kledo_get_requested_value( 'state' ) ) {
			$url = add_query_arg( 'action', 'invalid-state', wc_kledo()->get_settings_url() );

			wp_redirect( $url );
			exit;
		}

		$code = wc_kledo_get_requested_value( 'code' );

		if ( wc_kledo()->get_connection_handler()->converting_authorization_codes( $code ) ) {
			$url = add_query_arg( 'action', 'connected', wc_kledo()->get_settings_url() );

			wp_redirect( $url );

			exit;
		}
	}

	/**
	 * Display invalid state notification.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function invalid_state() {
		wc_kledo()->get_admin_notice_handler()->add_admin_notice(
			__( 'State parameter not valid. Please request a new token again.', WC_KLEDO_TEXT_DOMAIN ),
			'invalid_state_parameter',
			array(
				'dismissible'             => true,
				'always_show_on_settings' => false,
				'notice_class'            => 'error',
			)
		);
	}

	/**
	 * Render successful connected app notification.
	 *
	 * @return void
	 * @throws \Exception
	 * @since 1.0.0
	 */
	private function connected() {
		wc_kledo()->get_admin_notice_handler()->add_admin_notice(
			__( 'Successfully connected to kledo app. ', WC_KLEDO_TEXT_DOMAIN ),
			'connected',
			array(
				'dismissible'  => true,
				'notice_class' => 'notice-success',
			)
		);
	}

	/**
	 * Disconnect app connection.
	 *
	 * @param  false  $message
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function disconnect( $message = false ) {
		if ( $message ) {
			wc_kledo()->get_admin_notice_handler()->add_admin_notice(
				__( 'Successfully disconnect the connection.', WC_KLEDO_TEXT_DOMAIN ),
				'disconnected',
				array(
					'dismissible'  => true,
					'notice_class' => 'notice-success',
				)
			);

			return;
		}

		wc_kledo()->get_connection_handler()->disconnect();

		$url = add_query_arg( 'action', 'disconnected', wc_kledo()->get_settings_url() );

		wp_redirect( $url );

		exit;
	}

	/**
	 * Refresh the access token.
	 *
	 * @param  bool  $message
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function refresh_token( $message = false ) {
		if ( $message ) {
			wc_kledo()->get_admin_notice_handler()->add_admin_notice(
				__( 'Successfully refresh the access token.', WC_KLEDO_TEXT_DOMAIN ),
				'token_refreshed',
				array(
					'dismissible'  => true,
					'notice_class' => 'notice-success',
				)
			);

			return;
		}

		wc_kledo()->get_connection_handler()->refresh_access_token();

		$url = add_query_arg( 'action', 'refreshed', wc_kledo()->get_settings_url() );

		wp_redirect( $url );

		exit;
	}
}

// Init class.
new WC_Kledo_Issuing_Token();
