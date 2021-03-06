<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Admin_Notice_Handler {
	/**
	 * The plugin instance.
	 *
	 * @var \WC_Kledo
	 * @since 1.0.0
	 */
	private $plugin;

	/**
	 * Associative array of id to notice text.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $admin_notices = array();

	/**
	 * Static member to enforce a single rendering of the admin notice placeholder element.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	static private $admin_notice_placeholder_rendered = false;

	/**
	 * Static member to enforce a single rendering of the admin notice javascript.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	static private $admin_notice_js_rendered = false;

	/**
	 * The class constructor.
	 *
	 * @param  \WC_Kledo  $plugin
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		// Render any admin notices.
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ), 15 );
		add_action( 'admin_footer', array( $this, 'render_delayed_admin_notices' ), 15 );
		add_action( 'admin_footer', array( $this, 'render_admin_notice_js' ), 20 );

		// Ajax handler to dismiss any warning/error notices.
		add_action(
			'wp_ajax_' . $this->get_plugin()->get_id() . '_dismiss_notice',
			array(
				$this,
				'handle_dismiss_notice',
			)
		);
	}

	/**
	 * Adds the given $message as a dismissible notice identified by $message_id,
	 * unless the notice has been dismissed, or we're on the plugin settings page.
	 *
	 * @param  string  $message  the notice message to display
	 * @param  string  $message_id  the message id
	 * @param  array|object  $params  {
	 *      Optional parameters.
	 *
	 *      @type bool $dismissible If the notice should be dismissible
	 *      @type bool $always_show_on_settings If the notice should be forced to display on the
	 *                                          plugin settings page, regardless of `$dismissible`.
	 *      @type string $notice_class Additional classes for the notice.
	 * }
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_admin_notice( $message, $message_id, $params = array() ) {
		$params = wp_parse_args( $params, array(
			'dismissible'             => true,
			'always_show_on_settings' => true,
			'notice_class'            => 'updated',
		) );

		if ( $this->should_display_notice( $message_id, $params ) ) {
			$this->admin_notices[ $message_id ] = array(
				'message'  => $message,
				'rendered' => false,
				'params'   => $params,
			);
		}
	}

	/**
	 * Returns true if the identified notice hasn't been cleared, or we're on
	 * the plugin settings page (where notices are always displayed).
	 *
	 * @param  string  $message_id  the message id
	 * @param  array|object  $params  {
	 *      Optional parameters.
	 *
	 *      @type bool $dismissible If the notice should be dismissible
	 *      @type bool $always_show_on_settings If the notice should be forced to display on the
	 *                                          plugin settings page, regardless of `$dismissible`.
	 * }
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function should_display_notice( $message_id, $params = array() ) {
		// Bail out if user is not a shop manager.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$params = wp_parse_args( $params, array(
			'dismissible'             => true,
			'always_show_on_settings' => true,
		) );

		// If the notice is always shown on the settings page, and we're on the settings page.
		if ( $params['always_show_on_settings'] && $this->get_plugin()->is_plugin_settings() ) {
			return true;
		}

		// Non-dismissible, always display.
		if ( ! $params['dismissible'] ) {
			return true;
		}

		// Dismissible: display if notice has not been dismissed.
		return ! $this->is_notice_dismissed( $message_id );
	}

	/**
	 * Render any admin notices, as well as the admin notice placeholder.
	 *
	 * @param  boolean  $is_visible  true if the notices should be immediately visible, false otherwise.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_admin_notices( $is_visible = true ) {
		// Default for actions.
		if ( ! is_bool( $is_visible ) ) {
			$is_visible = true;
		}

		foreach ( $this->admin_notices as $message_id => $message_data ) {
			if ( ! $message_data['rendered'] ) {
				$message_data['params']['is_visible'] = $is_visible;
				$this->render_admin_notice( $message_data['message'], $message_id, $message_data['params'] );
				$this->admin_notices[ $message_id ]['rendered'] = true;
			}
		}

		if ( $is_visible && ! self::$admin_notice_placeholder_rendered ) {
			// Placeholder for moving delayed notices up into place.
			echo '<div class="js-wc-kledo-' . esc_attr( $this->get_plugin()->get_id_dasherized() ) . '-admin-notice-placeholder"></div>';
			self::$admin_notice_placeholder_rendered = true;
		}
	}

	/**
	 * Render any delayed admin notices, which have not yet already been rendered.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_delayed_admin_notices() {
		$this->render_admin_notices( false );
	}

	/**
	 * Render a single admin notice
	 *
	 * @param  string  $message  the notice message to display
	 * @param  string  $message_id  the message id
	 * @param  array|object  $params  {
	 *      Optional parameters.
	 *
	 *      @type bool $dismissible If the notice should be dismissible
	 *      @type bool $is_visible If the notice should be immediately visible
	 *      @type bool $always_show_on_settings If the notice should be forced to display on the
	 *                                          plugin settings page, regardless of `$dismissible`.
	 *      @type string $notice_class Additional classes for the notice.
	 * }
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_admin_notice( $message, $message_id, $params = array() ) {
		$params = wp_parse_args( $params, array(
			'dismissible'             => true,
			'is_visible'              => true,
			'always_show_on_settings' => true,
			'notice_class'            => 'updated',
		) );

		$classes = array(
			'notice',
			'js-wc-kledo-admin-notice',
			$params['notice_class'],
		);

		// Maybe make this notice dismissible
		// uses a WP core class which handles the markup and styling.
		if ( $params['dismissible']
		     && ( ! $params['always_show_on_settings'] || ! $this->get_plugin()->is_plugin_settings() )
		) {
			$classes[] = 'is-dismissible';
		}

		echo sprintf(
			'<div class="%1$s" data-plugin-id="%2$s" data-message-id="%3$s" %4$s><p>%5$s</p></div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $this->get_plugin()->get_id() ),
			esc_attr( $message_id ),
			( ! $params['is_visible'] ) ? 'style="display:none;"' : '',
			wp_kses_post( $message )
		);
	}

	/**
	 * Render the javascript to handle the notice "dismiss" functionality.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_admin_notice_js() {
		// If there were no notices, or we've already rendered the js, there's nothing to do.
		if ( empty( $this->admin_notices ) || self::$admin_notice_js_rendered ) {
			return;
		}

		$plugin_slug = $this->get_plugin()->get_id_dasherized();

		self::$admin_notice_js_rendered = true;

		ob_start();

		?>

		// Log dismissed notices.
		$('.js-wc-kledo-admin-notice').on('click.wp-dismiss-notice', '.notice-dismiss', function(e) {
			var $notice = $(this).closest('.js-wc-kledo-admin-notice');

			log_dismissed_notice(
				$($notice).data('plugin-id'),
				$($notice).data('message-id')
			);
		});

		// Log and hide legacy notices.
		$('a.js-wc-kledo-plugin-framework-notice-dismiss').click(function(e) {
			e.preventDefault();

			var $notice = $(this).closest('.js-wc-kledo-admin-notice');

			log_dismissed_notice(
				$($notice).data('plugin-id'),
				$($notice).data('message-id')
			);

			$($notice).fadeOut();
		});

		function log_dismissed_notice(pluginID, messageID) {
			$.get(ajaxurl, {
				action: pluginID + '_dismiss_notice',
				messageid: messageID
			});
		}

		// Move any delayed notices up into position .show();
		$('.js-wc-kledo-admin-notice:hidden').insertAfter('.js-wc-kledo-<?php echo esc_js( $plugin_slug ); ?>-admin-notice-placeholder').show();

		<?php

		$javascript = ob_get_clean();

		wc_enqueue_js( $javascript );
	}

	/**
	 * Marks the identified admin notice as dismissed for the given user.
	 *
	 * @param  string  $message_id  the message identifier
	 * @param  int  $user_id  optional user identifier, defaults to current user
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function dismiss_notice( $message_id, $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$dismissed_notices = $this->get_dismissed_notices( $user_id );

		$dismissed_notices[ $message_id ] = true;

		update_user_meta( $user_id, $this->get_plugin()->get_id() . '_dismissed_messages', $dismissed_notices );

		/**
		 * Admin notice dismissed action.
		 *
		 * Fired when a user dismisses an admin notice.
		 *
		 * @param  string  $message_id  notice identifier
		 * @param  string|int  $user_id
		 *
		 * @since 1.0.0
		 */
		do_action( $this->get_plugin()->get_id() . '_dismiss_notice', $message_id, $user_id );
	}

	/**
	 * Marks the identified admin notice as not dismissed for the identified user.
	 *
	 * @param  string  $message_id  the message identifier
	 * @param  int  $user_id  optional user identifier, defaults to current user
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function undismiss_notice( $message_id, $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$dismissed_notices = $this->get_dismissed_notices( $user_id );

		$dismissed_notices[ $message_id ] = false;

		update_user_meta( $user_id, $this->get_plugin()->get_id() . '_dismissed_messages', $dismissed_notices );
	}

	/**
	 * Returns true if the identified admin notice has been dismissed for the
	 * given user.
	 *
	 * @param  string  $message_id  the message identifier
	 * @param  int  $user_id  optional user identifier, defaults to current user
	 *
	 * @return boolean true if the message has been dismissed by the admin user
	 * @since 1.0.0
	 */
	public function is_notice_dismissed( $message_id, $user_id = null ) {
		$dismissed_notices = $this->get_dismissed_notices( $user_id );

		return isset( $dismissed_notices[ $message_id ] ) && $dismissed_notices[ $message_id ];
	}

	/**
	 * Returns the full set of dismissed notices for the user identified by
	 * $user_id, for this plugin.
	 *
	 * @param  int  $user_id  optional user identifier, defaults to current user
	 *
	 * @return array of message id to dismissed status (true or false)
	 * @since 1.0.0
	 */
	public function get_dismissed_notices( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$dismissed_notices = get_user_meta( $user_id, $this->get_plugin()->get_id() . '_dismissed_messages', true );

		if ( empty( $dismissed_notices ) ) {
			return array();
		}

		return $dismissed_notices;
	}

	/**
	 * Dismiss the identified notice.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_dismiss_notice() {
		$this->dismiss_notice( $_REQUEST['messageid'] );
	}

	/**
	 * Get the plugin main instance.
	 *
	 * @return \WC_Kledo
	 * @since 1.0.0
	 */
	protected function get_plugin() {
		return $this->plugin;
	}
}
