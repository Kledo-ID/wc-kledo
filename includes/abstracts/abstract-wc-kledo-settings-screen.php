<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

abstract class WC_Kledo_Settings_Screen {
	/**
	 * The settings screen id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $id;

	/**
	 * The settings screen label.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $label;

	/**
	 * The settings screen title.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $title;

	/**
	 * The settings screen description.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $description;

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render() {
		/**
		 * Filters the screen settings.
		 *
		 * @param  array  $settings  settings
		 *
		 * @since 1.0.0
		 */
		$settings = (array) apply_filters( 'wc_kledo_admin_' . $this->get_id() . '_settings', $this->get_settings(), $this );

		if ( empty( $settings ) ) {
			return;
		}

		$connection   = wc_kledo()->get_connection_handler();
		$is_connected = $connection->is_connected();

		?>

		<?php if ( ! $is_connected && $this->get_disconnected_message() ) : ?>
			<div class="notice notice-info">
				<p><?php echo wp_kses_post( $this->get_disconnected_message() ); ?></p>
			</div>
		<?php endif; ?>

		<form class="wc-kledo-settings <?php echo $is_connected ? 'connected' : 'disconnected'; ?>" method="post" id="wc-kledo mainform" action="" enctype="multipart/form-data">
			<?php woocommerce_admin_fields( $settings ); ?>

			<input type="hidden" name="screen_id" value="<?php echo esc_attr( $this->get_id() ); ?>">

			<?php wp_nonce_field( 'wc_kledo_admin_save_' . $this->get_id() . '_settings' ); ?>

			<p class="submit">
				<input type="submit" name="save_<?php echo esc_attr( $this->get_id() ); ?>_settings" id="save_<?php echo esc_attr( $this->get_id() ); ?>_settings" class="button button-primary" value="<?php _e( 'Save changes', WC_KLEDO_TEXT_DOMAIN ); ?>"/>

				<?php do_action( 'wc_kledo_submit_button' ); ?>
			</p>
		</form>

		<?php
	}

	/**
	 * Saves the settings.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function save() {
		woocommerce_update_options( $this->get_settings() );
	}

	/**
	 * Determines whether the current screen is the same as identified by the current class.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	protected function is_current_screen_page() {
		if ( WC_Kledo_Admin::PAGE_ID !== wc_kledo_get_requested_value( 'page' ) ) {
			return false;
		}

		// Assume we are on configure tab by default
		// because the link under menu doesn't include the tab query arg.
		$tab = wc_kledo_get_requested_value( 'tab', 'configure' );

		return ! empty( $tab ) && $tab === $this->get_id();
	}

	/**
	 * Gets the settings.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	abstract public function get_settings();

	/**
	 * Get the message to display when the plugin is disconnected.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_disconnected_message() {
		return '';
	}

	/**
	 * Gets the screen ID.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Gets the screen label.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_label() {
		/**
		 * Filters the screen label.
		 *
		 * @param  string  $label  screen label, for display
		 *
		 * @since 1.0.0
		 */
		return (string) apply_filters( 'wc_kledo_admin_settings_' . $this->get_id() . '_screen_label', $this->label, $this );
	}

	/**
	 * Gets the screen title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_title() {
		/**
		 * Filters the screen title.
		 *
		 * @param  string  $title  screen title, for display
		 *
		 * @since 1.0.0
		 */
		return (string) apply_filters( 'wc_kledo_admin_settings_' . $this->get_id() . '_screen_title', $this->title, $this );
	}

	/**
	 * Gets the screen description.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_description() {
		/**
		 * Filters the screen description.
		 *
		 * @param  string  $description  screen description, for display
		 *
		 * @since 1.0.0
		 */
		return (string) apply_filters( 'wc_kledo_admin_settings_' . $this->get_id() . '_screen_description', $this->description, $this );
	}
}
