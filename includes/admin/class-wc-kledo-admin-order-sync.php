<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Manual Kledo sync entry points on the WooCommerce order screens (additive to status-based sync).
 */
class WC_Kledo_Admin_Order_Sync {
	/**
	 * Transient key prefix for one-shot admin feedback after manual actions.
	 *
	 * @var string
	 */
	private static string $notice_transient_prefix = 'wc_kledo_sync_notice_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 30 );
		add_action( 'admin_post_wc_kledo_manual_push', array( $this, 'handle_admin_post_manual_push' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_flash_notice' ) );

		add_filter( 'woocommerce_order_actions', array( $this, 'register_order_actions' ), 10, 2 );

		add_action( 'woocommerce_order_action_wc_kledo_manual_send_order', array(
			$this,
			'order_action_manual_send_order',
		) );
		add_action( 'woocommerce_order_action_wc_kledo_manual_resend_order', array(
			$this,
			'order_action_manual_resend_order',
		) );
		add_action( 'woocommerce_order_action_wc_kledo_manual_send_invoice', array(
			$this,
			'order_action_manual_send_invoice',
		) );
		add_action( 'woocommerce_order_action_wc_kledo_manual_resend_invoice', array(
			$this,
			'order_action_manual_resend_invoice',
		) );
	}

	/**
	 * @return void
	 */
	public function register_meta_box(): void {
		if ( ! $this->current_user_can_sync_any() ) {
			return;
		}

		$screen = wc_kledo_get_order_admin_screen_id();

		add_meta_box(
			'wc_kledo_manual_sync',
			__( 'Kledo sync', WC_KLEDO_TEXT_DOMAIN ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'low'
		);
	}

	/**
	 * @param  \WP_Post|\WC_Order  $post_or_order_object
	 *
	 * @return void
	 */
	public function render_meta_box( $post_or_order_object ): void {
		$order = $this->resolve_order_from_meta_box_context( $post_or_order_object );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->current_user_can_sync_order( $order->get_id() ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to sync this order to Kledo.', WC_KLEDO_TEXT_DOMAIN ) . '</p>';

			return;
		}

		$order_synced   = wc_kledo_is_delivery_synced( $order, 'order' );
		$invoice_synced = wc_kledo_is_delivery_synced( $order, 'invoice' );

		$order_on   = wc_string_to_bool( get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME, 'yes' ) );
		$invoice_on = wc_string_to_bool( get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME, 'yes' ) );

		$allow_order_ui   = $order_on && wc_kledo_order_status_allows_manual_sales_order( $order );
		$allow_invoice_ui = $invoice_on && wc_kledo_order_status_allows_manual_invoice( $order );

		$action_url = admin_url( 'admin-post.php' );

		$any_button = ( $allow_order_ui ) || ( $allow_invoice_ui );
		?>
        <p class="description">
			<?php
			esc_html_e(
				'Manual actions match plugin rules: sales order when the order is Processing or Completed (same lifecycle as automatic sync on Processing); invoice only when the order is Completed. Re-send appears only after a successful sync (meta yes).',
				WC_KLEDO_TEXT_DOMAIN
			);
			?>
        </p>
		<?php if ( $allow_order_ui ) : ?>
            <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'wc_kledo_manual_push' ); ?>

                <input type="hidden" name="action" value="wc_kledo_manual_push"/>
                <input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>"/>
                <input type="hidden" name="wc_kledo_type" value="order"/>

				<?php if ( $order_synced ) : ?>
                    <input type="hidden" name="wc_kledo_force" value="1"/>
                    <button type="submit" class="button button-small"
                            onclick="return window.confirm('<?php echo esc_js( __( 'Re-sending may create a duplicate sales order in Kledo. Continue?', WC_KLEDO_TEXT_DOMAIN ) ); ?>');">
					    <?php esc_html_e( 'Re-send sales order to Kledo', WC_KLEDO_TEXT_DOMAIN ); ?>
                    </button>
				<?php else : ?>
                    <input type="hidden" name="wc_kledo_force" value="0"/>
                    <button type="submit" class="button button-small">
						<?php esc_html_e( 'Send sales order to Kledo (first manual)', WC_KLEDO_TEXT_DOMAIN ); ?>
                    </button>
				<?php
				endif; ?>
            </form>
		<?php endif; ?>

		<?php if ( $allow_invoice_ui ) : ?>
            <form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( 'wc_kledo_manual_push' ); ?>

                <input type="hidden" name="action" value="wc_kledo_manual_push"/>
                <input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>"/>
                <input type="hidden" name="wc_kledo_type" value="invoice"/>

				<?php if ( $invoice_synced ) : ?>
                    <input type="hidden" name="wc_kledo_force" value="1"/>
                    <button type="submit" class="button button-small"
                            onclick="return window.confirm('<?php echo esc_js( __( 'Re-sending may create a duplicate invoice in Kledo. Continue?', WC_KLEDO_TEXT_DOMAIN ) ); ?>');">
						<?php esc_html_e( 'Re-send invoice to Kledo', WC_KLEDO_TEXT_DOMAIN ); ?>
                    </button>
				<?php else : ?>
                    <input type="hidden" name="wc_kledo_force" value="0"/>
                    <button type="submit" class="button button-small">
						<?php esc_html_e( 'Send invoice to Kledo (first manual)', WC_KLEDO_TEXT_DOMAIN ); ?>
                    </button>
				<?php endif; ?>
            </form>
		<?php endif; ?>

		<?php if ( ! $order_on && ! $invoice_on ) : ?>
            <p><?php esc_html_e( 'Both sales order and invoice creation are disabled in Kledo settings.', WC_KLEDO_TEXT_DOMAIN ); ?></p>
		<?php elseif ( ! $any_button ) : ?>
            <p><?php esc_html_e( 'No manual Kledo actions apply to this order status. Automatic sync still runs on status changes (Processing → sales order, Completed → invoice).', WC_KLEDO_TEXT_DOMAIN ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param  array  $actions
	 * @param  \WC_Order|null  $order
	 *
	 * @return array
	 */
	public function register_order_actions( array $actions, ?WC_Order $order ): array {
		if ( ! $order instanceof WC_Order || ! $this->current_user_can_sync_order( $order->get_id() ) ) {
			return $actions;
		}

		$order_on   = wc_string_to_bool( get_option( WC_Kledo_Order_Screen::ENABLE_ORDER_OPTION_NAME, 'yes' ) );
		$invoice_on = wc_string_to_bool( get_option( WC_Kledo_Invoice_Screen::ENABLE_INVOICE_OPTION_NAME, 'yes' ) );

		if ( $order_on && wc_kledo_order_status_allows_manual_sales_order( $order ) ) {
			if ( wc_kledo_is_delivery_synced( $order, 'order' ) ) {
				$actions['wc_kledo_manual_resend_order'] = __( 'Kledo: Re-send sales order (already synced; may duplicate)', WC_KLEDO_TEXT_DOMAIN );
			} else {
				$actions['wc_kledo_manual_send_order'] = __( 'Kledo: Send sales order (first manual)', WC_KLEDO_TEXT_DOMAIN );
			}
		}

		if ( $invoice_on && wc_kledo_order_status_allows_manual_invoice( $order ) ) {
			if ( wc_kledo_is_delivery_synced( $order, 'invoice' ) ) {
				$actions['wc_kledo_manual_resend_invoice'] = __( 'Kledo: Re-send invoice (already synced; may duplicate)', WC_KLEDO_TEXT_DOMAIN );
			} else {
				$actions['wc_kledo_manual_send_invoice'] = __( 'Kledo: Send invoice (first manual)', WC_KLEDO_TEXT_DOMAIN );
			}
		}

		return $actions;
	}

	/**
	 * @param  \WC_Order  $order
	 *
	 * @return void
	 */
	public function order_action_manual_send_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->run_manual_deliver( $order, 'order', false );
		}
	}

	/**
	 * @param  \WC_Order  $order
	 *
	 * @return void
	 */
	public function order_action_manual_resend_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->run_manual_deliver( $order, 'order', true );
		}
	}

	/**
	 * @param  \WC_Order  $order
	 *
	 * @return void
	 */
	public function order_action_manual_send_invoice( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->run_manual_deliver( $order, 'invoice', false );
		}
	}

	/**
	 * @param  \WC_Order  $order
	 *
	 * @return void
	 */
	public function order_action_manual_resend_invoice( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->run_manual_deliver( $order, 'invoice', true );
		}
	}

	/**
	 * @return void
	 */
	public function handle_admin_post_manual_push(): void {
		if ( ! $this->current_user_can_sync_any() ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'wc_kledo_manual_push' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$type     = isset( $_POST['wc_kledo_type'] ) ? sanitize_key( wp_unslash( $_POST['wc_kledo_type'] ) ) : '';
		$force    = ! empty( $_POST['wc_kledo_force'] ) && '1' === (string) wp_unslash( $_POST['wc_kledo_force'] );

		if ( ! $order_id || ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
			wp_die( esc_html__( 'Invalid request.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		if ( ! $this->current_user_can_sync_order( $order_id ) ) {
			wp_die( esc_html__( 'You do not have permission to sync this order.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		$validation = $this->validate_manual_deliver_request( $order, $type, $force );

		if ( is_string( $validation ) ) {
			$this->set_flash_notice( $validation );

			wc_kledo_log_warning(
				sprintf(
					'Kledo manual_admin_post rejected order_id=%d type=%s reason=%s',
					$order_id,
					$type,
					$validation
				)
			);

			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}

		$manual_mode = $force ? 'resend' : 'first';

		$result = wc_kledo()->get_woocommerce_bridge()->deliver(
			$order,
			$type,
			array(
				'trigger'         => 'manual_admin',
				'force_if_synced' => $force,
				'manual_mode'     => $manual_mode,
			)
		);

		wc_kledo_log_info(
			sprintf(
				'Kledo manual_admin_post order_id=%d type=%s mode=%s success=%s',
				$order_id,
				$type,
				$manual_mode,
				! empty( $result['success'] ) ? '1' : '0'
			)
		);

		$message = $this->format_result_admin_message( $type, $result );

		$this->set_flash_notice( $message );

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * @return void
	 */
	public function maybe_show_flash_notice(): void {
		if ( ! $this->is_order_admin_screen() ) {
			return;
		}

		$key = self::$notice_transient_prefix . get_current_user_id();
		$msg = get_transient( $key );

		if ( ! is_string( $msg ) || '' === $msg ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html( $msg )
		);
	}

	/**
	 * @param  \WC_Order  $order
	 * @param  string  $type
	 * @param  bool  $resend
	 *
	 * @return void
	 */
	private function run_manual_deliver( WC_Order $order, string $type, bool $resend ): void {
		if ( ! $this->current_user_can_sync_order( $order->get_id() ) ) {
			return;
		}

		$validation = $this->validate_manual_deliver_request( $order, $type, $resend );

		if ( is_string( $validation ) ) {
			$this->set_flash_notice( $validation );

			wc_kledo_log_warning(
				sprintf(
					'Kledo manual order action rejected order_id=%d type=%s mode=%s msg=%s',
					$order->get_id(),
					$type,
					$resend ? 'resend' : 'first',
					$validation
				)
			);

			return;
		}

		$manual_mode = $resend ? 'resend' : 'first';

		$result = wc_kledo()->get_woocommerce_bridge()->deliver(
			$order,
			$type,
			array(
				'trigger'         => 'manual_admin',
				'force_if_synced' => $resend,
				'manual_mode'     => $manual_mode,
			)
		);

		$this->set_flash_notice( $this->format_result_admin_message( $type, $result ) );

		wc_kledo_log_info(
			sprintf(
				'Kledo manual order action type=%s order_id=%d mode=%s success=%s skipped=%s reason=%s',
				$type,
				$order->get_id(),
				$manual_mode,
				! empty( $result['success'] ) ? '1' : '0',
				! empty( $result['skipped'] ) ? '1' : '0',
				isset( $result['reason'] ) ? (string) $result['reason'] : ''
			)
		);
	}

	/**
	 * Validate manual send/resend against sync meta and order status. Returns null if OK, or an admin-facing error
	 * string.
	 *
	 * @param  \WC_Order  $order
	 * @param  string  $type
	 * @param  bool  $resend
	 *
	 * @return string|null
	 */
	private function validate_manual_deliver_request( WC_Order $order, string $type, bool $resend ): ?string {
		if ( 'order' === $type ) {
			if ( ! wc_kledo_order_status_allows_manual_sales_order( $order ) ) {
				return __( 'Kledo: manual sales order actions are only available when the order is Processing or Completed.', WC_KLEDO_TEXT_DOMAIN );
			}
		} elseif ( 'invoice' === $type ) {
			if ( ! wc_kledo_order_status_allows_manual_invoice( $order ) ) {
				return __( 'Kledo: manual invoice actions are only available when the order is Completed.', WC_KLEDO_TEXT_DOMAIN );
			}
		} else {
			return __( 'Kledo: invalid sync type.', WC_KLEDO_TEXT_DOMAIN );
		}

		$synced = wc_kledo_is_delivery_synced( $order, $type );

		if ( $resend ) {
			if ( ! $synced ) {
				return __( 'Kledo: re-send is only available when this record is already marked synced in WooCommerce.', WC_KLEDO_TEXT_DOMAIN );
			}
		} elseif ( $synced ) {
			return __( 'Kledo: initial manual send is not available because this is already marked synced. Use the re-send action instead.', WC_KLEDO_TEXT_DOMAIN );
		}

		return null;
	}

	/**
	 * @param  string  $type
	 * @param  array  $result
	 *
	 * @return string
	 */
	private function format_result_admin_message( string $type, array $result ): string {
		if ( ! empty( $result['success'] ) ) {
			return sprintf(
			/* translators: %s: order or invoice */
				__( 'Kledo: %s was sent successfully.', WC_KLEDO_TEXT_DOMAIN ),
				$type
			);
		}

		if ( ! empty( $result['skipped'] ) ) {
			if ( 'already_synced' === ( $result['reason'] ?? '' ) ) {
				return sprintf(
				/* translators: %s: order or invoice */
					__( 'Kledo: %s is already marked synced. Use the re-send action if you need to push again.', WC_KLEDO_TEXT_DOMAIN ),
					$type
				);
			}

			if ( 'api_disabled' === ( $result['reason'] ?? '' ) ) {
				return __( 'Kledo: API connection is disabled; nothing was sent.', WC_KLEDO_TEXT_DOMAIN );
			}

			if ( 'feature_disabled' === ( $result['reason'] ?? '' ) ) {
				return sprintf(
				/* translators: %s: order or invoice */
					__( 'Kledo: %s push is disabled in plugin settings.', WC_KLEDO_TEXT_DOMAIN ),
					$type
				);
			}

			return sprintf(
			/* translators: %s: order or invoice */
				__( 'Kledo: %s was skipped.', WC_KLEDO_TEXT_DOMAIN ),
				$type
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return sprintf(
			/* translators: 1: type, 2: error */
				__( 'Kledo: failed to push %1$s — %2$s', WC_KLEDO_TEXT_DOMAIN ),
				$type,
				$result['error']
			);
		}

		return sprintf(
		/* translators: 1: type, 2: HTTP code */
			__( 'Kledo: failed to push %1$s (HTTP %2$d).', WC_KLEDO_TEXT_DOMAIN ),
			$type,
			(int) $result['http_code']
		);
	}

	/**
	 * @param  string  $message
	 *
	 * @return void
	 */
	private function set_flash_notice( string $message ): void {
		set_transient( self::$notice_transient_prefix . get_current_user_id(), $message, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return bool
	 */
	private function is_order_admin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}

		$allowed = array(
			'shop_order',
			wc_kledo_get_order_admin_screen_id(),
			'woocommerce_page_wc-orders',
		);

		return in_array( $screen->id, $allowed, true );
	}

	/**
	 * @return bool
	 */
	private function current_user_can_sync_any(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
	}

	/**
	 * @param  int  $order_id
	 *
	 * @return bool
	 */
	private function current_user_can_sync_order( int $order_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return current_user_can( 'edit_shop_order', $order_id );
	}

	/**
	 * @param  \WP_Post|\WC_Order|null  $post_or_order_object
	 *
	 * @return \WC_Order|null
	 */
	private function resolve_order_from_meta_box_context( $post_or_order_object ): ?WC_Order {
		global $theorder;

		if ( isset( $theorder ) && $theorder instanceof WC_Order ) {
			return $theorder;
		}

		if ( $post_or_order_object instanceof WC_Order ) {
			return $post_or_order_object;
		}

		if ( $post_or_order_object instanceof WP_Post && 'shop_order' === $post_or_order_object->post_type ) {
			$order = wc_get_order( $post_or_order_object->ID );

			return $order instanceof WC_Order ? $order : null;
		}

		// HPOS screen may pass a different object; fall back to query arg.
		if ( isset( $_GET['id'] ) ) {
			$order = wc_get_order( absint( wp_unslash( $_GET['id'] ) ) );

			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}
}
