<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Support_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const ID = 'support';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action(
			'load-woocommerce_page_wc-kledo',
			function () {
				$this->label = __( 'Support', 'wc-kledo' );
				$this->title = __( 'Support', 'wc-kledo' );
			}
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render(): void {
		?>

		<div id="wc-kledo-admin">
			<div class="wc-kledo-support">
				<h3 style="padding-bottom: 10px;">
					<?php esc_html_e( 'Need help?', 'wc-kledo' ); ?>
				</h3>

				<p>
					<span class="wc-kledo-support-title">
						<i class="dashicons dashicons-book" aria-hidden="true"></i>&nbsp; <a href="<?php echo esc_url( WC_Kledo_Help_Screen::get_url() ); ?>"><?php esc_html_e( 'How do I use the plugin?', 'wc-kledo' ); ?></a>
					</span>

					<?php esc_html_e( 'The Guide & FAQ tab explains everything step by step, from creating the API key to sending old orders and finding a missing one.', 'wc-kledo' ); ?>
				</p>

				<p>
					<span class="wc-kledo-support-title">
						<i class="dashicons dashicons-search" aria-hidden="true"></i>&nbsp; <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . WC_Kledo_Diagnostics_Screen::ID ) ); ?>"><?php esc_html_e( 'An order did not reach Kledo?', 'wc-kledo' ); ?></a>
					</span>

					<?php esc_html_e( 'Run a diagnosis first, in the Diagnostics tab. It tells you the most likely cause, and gives you a report to send us so we can help faster.', 'wc-kledo' ); ?>
				</p>

				<p>
					<span class="wc-kledo-support-title">
						<i class="dashicons dashicons-whatsapp" aria-hidden="true"></i>&nbsp; <a href="https://api.whatsapp.com/send?phone=6282383334000" target="_blank"><?php esc_html_e( 'Request Support', 'wc-kledo' ); ?></a>
					</span>

					<?php esc_html_e( 'Still need help? Submit a message and one of our support experts will get back to you as soon as possible.', 'wc-kledo' ); ?>
				</p>

				<p>
					<span class="wc-kledp-email">
						<i class="dashicons dashicons-email" aria-hidden="true"></i>&nbsp; <a href="mailto:hello@kledo.com">hello@kledo.com</a>
					</span>
				</p>
			</div>
		</div>

		<?php
	}

	/**
	 * Gets the settings definition for this screen.
	 *
	 * The Support tab uses a custom {@see render()} implementation and does not expose WooCommerce settings fields.
	 *
	 * @return array<int|string, mixed> Empty array.
	 * @since 1.0.0
	 */
	public function get_settings(): array {
		return array();
	}
}
