<?php
/**
 * Plugin Name: Kledo
 * Requires Plugins: woocommerce
 * Plugin URI: https://github.com/Kledo-ID/wc-kledo
 * Description: Integrates <a href="https://woocommerce.com/" target="_blank" >WooCommerce</a> with the <a href="https://kledo.com" target="_blank">Kledo</a> accounting software.
 * Author: Kledo
 * Author URI: https://kledo.com
 * Version: 1.5.0
 * Text Domain: wc-kledo
 * WC requires at least: 3.5.0
 * WC tested up to: 10.4.3
 *
 * @package wc-kledo
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Define the main kledo plugin file.
if ( ! defined( 'WC_KLEDO_PLUGIN_FILE' ) ) {
	define( 'WC_KLEDO_PLUGIN_FILE', __FILE__ );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-kledo-loader.php';

// Fire it up!
WC_Kledo_Loader::instance();
