<?php
/**
 * PHPUnit bootstrap.
 *
 * @package RevolterCookies
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

$rc_root = dirname( __DIR__, 2 );

require_once $rc_root . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$rc_wp_phpunit = $rc_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $rc_wp_phpunit . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $rc_root ) {
		// Optional: load Elementor so the integration test can run (RC_ELEMENTOR_PATH=/path/to/elementor.php).
		$rc_elementor = getenv( 'RC_ELEMENTOR_PATH' );
		if ( $rc_elementor && is_readable( $rc_elementor ) ) {
			require $rc_elementor;
		}
		require $rc_root . '/revolter-cookies.php';
	}
);

require $rc_wp_phpunit . '/includes/bootstrap.php';
