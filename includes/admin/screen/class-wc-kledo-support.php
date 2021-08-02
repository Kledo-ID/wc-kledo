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
		$this->id    = self::ID;
		$this->label = __( 'Support', WC_KLEDO_TEXT_DOMAIN );
		$this->title = __( 'Support', WC_KLEDO_TEXT_DOMAIN );
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render() {
		?>

		<div id="wc-kledo-admin">
			<div class="wc-kledo-support">
				<h3 style="padding-bottom: 10px;">
					<?php _e( 'Need help?', WC_KLEDO_TEXT_DOMAIN ); ?>
				</h3>

				<p>
					<span class="wc-kledo-support-title">
						<i class="fa fa-envelope" aria-hidden="true"></i>&nbsp; <a href="https://api.whatsapp.com/send?phone=6282383334000" target="_blank"><?php _e( 'Request Support', WC_KLEDO_TEXT_DOMAIN ); ?></a>
					</span>

					<?php _e( 'Still need help? Submit a message and one of our support experts will get back to you as soon as possible.', WC_KLEDO_TEXT_DOMAIN ); ?>
				</p>
			</div>
		</div>

		<?php
	}

	/**
	 * Gets the settings.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function get_settings() {
		// TODO: Implement get_settings() method.
	}
}
