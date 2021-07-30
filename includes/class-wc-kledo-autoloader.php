<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WC_Kledo_Autoloader {
	/**
	 * The plugin includes directory path.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $path;

	/**
	 * The class constructor.
	 *
	 * @param  string  $path
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Autoload kledo classes on demand to reduce memory consumption.
	 *
	 * @param  string  $class  Class name.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function load( $class ) {
		$class = strtolower( $class );

		if ( 0 !== strpos( $class, 'wc_kledo_' ) ) {
			return;
		}

		$file = $this->get_file_name_from_class( $class );
		$path = '';

		if ( 0 === strpos( $class, 'wc_kledo_admin' ) ) {
			$path = $this->path . 'admin/';
		} elseif ( 0 === strpos( $class, 'wc_kledo_request' ) ) {
			$path = $this->path . 'requests/';
		} elseif ( preg_match( '~wc_kledo_.*_screen~', $class ) ) {
			$file = str_replace( '-screen', '', $file );
			$path = $this->path . 'admin/screen/';
		}

		if ( empty( $path ) || ! $this->load_file( $path . $file ) ) {
			$this->load_file( $this->path . $file );
		}
	}

	/**
	 * Take a class name and turn it into a file name.
	 *
	 * @param  string  $class  Class name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_file_name_from_class( $class ) {
		return 'class-' . str_replace( '_', '-', $class ) . '.php';
	}

	/**
	 * Include a class file.
	 *
	 * @param  string  $path  File path.
	 *
	 * @return bool Successful or not.
	 * @since 1.0.0
	 */
	private function load_file( $path ) {
		if ( $path && is_readable( $path ) ) {
			require_once( $path );

			return true;
		}

		return false;
	}
}
