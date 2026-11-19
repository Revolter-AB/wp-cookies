<?php
/**
 * Class autoloader.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Maps RevolterCookies\Foo_Bar to includes/class-foo-bar.php.
 */
class Autoloader {

	const PREFIX = 'RevolterCookies\\';

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file if it exists; silently ignore anything else.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );
		$subdir   = '';

		foreach ( $parts as $part ) {
			$subdir .= strtolower( $part ) . '/';
		}

		$file = REVOLTER_COOKIES_PATH . 'includes/' . $subdir . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
