<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Failed_Transactions_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.5.0
	 */
	public const ID = 'failed_transactions';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action( 'load-woocommerce_page_wc-kledo', function () {
			$this->label = __( 'Failed Transactions', WC_KLEDO_TEXT_DOMAIN );
			$this->title = __( 'Failed Transactions', WC_KLEDO_TEXT_DOMAIN );
		} );
	}

	/**
	 * Gets the screen settings.
	 *
	 * This screen does not use the standard WooCommerce settings API fields.
	 * We only render a custom table in render().
	 *
	 * @return array
	 * @since 1.5.0
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * Render the failed transactions table.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission to view this page.', WC_KLEDO_TEXT_DOMAIN ) );
		}

		if (
			isset( $_POST['wc_kledo_retry_failed_transaction'], $_POST['wc_kledo_failed_key'] )
			&& current_user_can( 'manage_woocommerce' )
		) {
			check_admin_referer( 'wc_kledo_retry_failed_transaction' );
			$this->handle_manual_retry( sanitize_text_field( wp_unslash( $_POST['wc_kledo_failed_key'] ) ) );
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php
						esc_html_e( 'Order ID', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                    <th><?php
						esc_html_e( 'Type', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                    <th><?php
						esc_html_e( 'Attempts', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                    <th><?php
						esc_html_e( 'Created At', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                    <th><?php
						esc_html_e( 'Next Retry', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                    <th><?php
						esc_html_e( 'Last Error', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                    <th><?php
						esc_html_e( 'Actions', WC_KLEDO_TEXT_DOMAIN ); ?></th>
                </tr>
            </thead>
            <tbody>
				<?php
				if ( empty( $queue ) ) : ?>
                    <tr>
                        <td colspan="7"><?php
							esc_html_e( 'No failed transactions.', WC_KLEDO_TEXT_DOMAIN ); ?></td>
                    </tr>
				<?php
				else : ?>
					<?php
					foreach ( $queue as $key => $item ) : ?>
						<?php
						$order_id  = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
						$type      = $item['type'] ?? '';
						$attempts  = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;
						$created   = isset( $item['created_at'] ) ? (int) $item['created_at'] : 0;
						$next_run  = isset( $item['next_run_at'] ) ? (int) $item['next_run_at'] : 0;
						$lastError = $item['last_error'] ?? '';

						$order_for_link = $order_id ? wc_get_order( $order_id ) : null;

						if ( $order_for_link instanceof WC_Order ) {
							$order_link = sprintf(
								'<a href="%s">%d</a>',
								esc_url( $order_for_link->get_edit_order_url() ),
								$order_id
							);
						} elseif ( $order_id ) {
							$order_link = (string) $order_id;
						} else {
							$order_link = '&mdash;';
						}

						?>
                        <tr>
                            <td><?php
								echo wp_kses_post( $order_link ); ?></td>
                            <td><?php
								echo esc_html( $type ); ?></td>
                            <td><?php
								echo esc_html( (string) $attempts ); ?></td>
                            <td><?php
								echo $created ? esc_html( wp_date( 'Y-m-d H:i:s', $created ) ) : '&mdash;'; ?></td>
                            <td><?php
								echo $next_run ? esc_html( wp_date( 'Y-m-d H:i:s', $next_run ) ) : '&mdash;'; ?></td>
                            <td><?php
								echo esc_html( $lastError ); ?></td>
                            <td>
                                <form method="post" class="wc-kledo-failed-retry-form">
									<?php
									wp_nonce_field( 'wc_kledo_retry_failed_transaction' ); ?>
                                    <button type="submit" class="button" name="wc_kledo_retry_failed_transaction" value="1">
										<?php
										esc_html_e( 'Retry now', WC_KLEDO_TEXT_DOMAIN ); ?>
                                    </button>
                                    <input type="hidden" name="wc_kledo_failed_key" value="<?php
									echo esc_attr( $key ); ?>"/>
                                </form>
                            </td>
                        </tr>
					<?php
					endforeach; ?>
				<?php
				endif; ?>
            </tbody>
        </table>
		<?php
	}

	/**
	 * Handle manual retry for a single failed transaction.
	 *
	 * @param  string  $key
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function handle_manual_retry( string $key ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$option_name = 'wc_kledo_failed_transactions';
		$queue       = get_option( $option_name, array() );

		if ( empty( $queue[ $key ] ) || ! is_array( $queue[ $key ] ) ) {
			return;
		}

		$item = $queue[ $key ];

		$order_id = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
		$type     = $item['type'] ?? '';

		if ( ! $order_id || ! in_array( $type, array( 'order', 'invoice' ), true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			unset( $queue[ $key ] );
			update_option( $option_name, $queue, false );

			return;
		}

		if ( wc_kledo_is_delivery_synced( $order, $type ) ) {
			wc_kledo_remove_failed_transaction_from_queue( $order_id, $type );
			$order->add_order_note(
				sprintf(
				/* translators: %s: transaction type (order/invoice) */
					__( 'Kledo: %s was already synced; removed stale entry from failed queue.', WC_KLEDO_TEXT_DOMAIN ),
					$type
				)
			);

			return;
		}

		try {
			if ( 'order' === $type ) {
				$request = new WC_Kledo_Request_Order();
				$result  = $request->create_order( $order );
			} else {
				$request = new WC_Kledo_Request_Invoice();
				$result  = $request->create_invoice( $order );
			}

			$response_code = method_exists( $request, 'get_response_code' ) ? (int) $request->get_response_code() : 0;

			if ( false !== $result && 200 === $response_code ) {
				wc_kledo_mark_delivery_synced( $order, $type );

				$order->add_order_note(
					sprintf(
						__( 'Kledo: manually resent %s to Kledo from Failed Transactions screen.', WC_KLEDO_TEXT_DOMAIN ),
						$type
					)
				);

				return;
			}

			$item['last_error']  = wc_kledo_sanitize_api_error_message(
				sprintf(
					'HTTP %d',
					$response_code
				)
			);
			$item['next_run_at'] = time() + HOUR_IN_SECONDS;
		} catch ( \Throwable $e ) {
			$item['last_error']  = wc_kledo_sanitize_api_error_message( $e->getMessage() );
			$item['next_run_at'] = time() + HOUR_IN_SECONDS;
		}

		$queue[ $key ] = $item;
		update_option( $option_name, $queue, false );
	}
}

