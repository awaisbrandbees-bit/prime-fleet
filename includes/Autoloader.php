<?php
/**
 * PSR-4 style autoloader for Prime_Fleet namespace.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	/**
	 * Register spl autoload.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * @param string $class Fully qualified class name.
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$file     = PRIME_FLEET_PATH . 'includes/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
