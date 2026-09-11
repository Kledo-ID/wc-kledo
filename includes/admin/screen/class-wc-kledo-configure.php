<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Configure_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const ID = 'configure';

	/**
	 * The API connection setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const SETTING_ENABLE_API_CONNECTION = 'wc_kledo_enable_api_connection';

	/**
	 * The api key setting ID.
	 *
	 * @var string
	 * @since 1.4.0
	 */
	public const SETTING_API_KEY = 'wc_kledo_api_key';

	/**
	 * The API endpoint setting ID.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const SETTING_API_ENDPOINT = 'wc_kledo_api_endpoint';

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
				$this->label = __( 'Configure', 'wc-kledo' );
				$this->title = __( 'Configure', 'wc-kledo' );
			}
		);

		$this->init_hooks();
	}

	/**
	 * Init hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_hooks(): void {
		add_action( 'woocommerce_admin_field_wc_kledo_configure_title', array( $this, 'render_title' ) );
	}

	/**
	 * Render configure admin settings title.
	 *
	 * @param  array $field  field data
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_title( array $field ): void {
		?>

		<h2><?php echo esc_html( $field['title'] ); ?></h2>

		<table class="form-table">

		<?php
	}

	/**
	 * Gets the screen settings.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function get_settings(): array {
		return array(
			'title'              => array(
				'type'  => 'wc_kledo_configure_title',
				'title' => __( 'Configure', 'wc-kledo' ),
			),

			'enable_integration' => array(
				'id'      => self::SETTING_ENABLE_API_CONNECTION,
				'title'   => __( 'Enable Integration', 'wc-kledo' ),
				'type'    => 'checkbox',
				'label'   => ' ',
				'default' => 'yes',
			),

			'api_key'            => array(
				'id'    => self::SETTING_API_KEY,
				'title' => __( 'API Key', 'wc-kledo' ),
				'type'  => 'text',
			),

			'api_endpoint'       => array(
				'id'    => self::SETTING_API_ENDPOINT,
				'title' => __( 'API Endpoint', 'wc-kledo' ),
				'type'  => 'text',
			),

			'section_end'        => array(
				'type' => 'sectionend',
			),
		);
	}
}
