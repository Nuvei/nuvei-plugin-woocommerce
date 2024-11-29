<?php

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader class.
 */
class Nuvei_Pfw_Autoloader {


	/**
	 * Path to the includes directories.
	 *
	 * @var array
	 */
	private $include_paths = array();

	/**
	 * The Constructor.
	 */
	public function __construct() {
		$this->include_paths = array(
			untrailingslashit( plugin_dir_path( NUVEI_PFW_PLUGIN_FILE ) ) . '/includes/',
			untrailingslashit( plugin_dir_path( NUVEI_PFW_PLUGIN_FILE ) ) . '/includes/abstracts/',
		// untrailingslashit( ABSPATH ) . '/wp-admin/includes/',
		);

		if ( function_exists( '__autoload' ) ) {
			spl_autoload_register( '__autoload' );
		}

		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Take a class name and turn it into a file name.
	 *
	 * @param  string $class Class name.
	 * @return string
	 */
	private function get_file_name_from_class( $class ) {
		return 'class-' . str_replace( '_', '-', $class ) . '.php';
	}

	/**
	 * Auto-load Nuvei classes on demand to reduce memory consumption.
	 *
	 * @param string $class Class name.
	 */
	public function autoload( $class ) {
		$class = strtolower( $class );

		if ( 0 !== strpos( $class, 'nuvei_' ) ) {
			return;
		}

		$file = $this->get_file_name_from_class( $class );

		foreach ( $this->include_paths as $path ) {
			if ( file_exists( $path . $file )
				&& is_readable( $path . $file )
			) {
				include_once $path . $file;
			}
		}
	}
}

new Nuvei_Pfw_Autoloader();
