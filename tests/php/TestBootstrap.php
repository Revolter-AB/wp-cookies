<?php
/**
 * Bootstrap smoke tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Plugin;

/**
 * Verifies the plugin loads and the autoloader is safe.
 */
class TestBootstrap extends WP_UnitTestCase {

	/**
	 * Version constant is defined.
	 */
	public function test_version_constant_is_defined() {
		$this->assertTrue( defined( 'REVOLTER_COOKIES_VERSION' ) );
	}

	/**
	 * Plugin singleton is available.
	 */
	public function test_plugin_instance() {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
	}

	/**
	 * Missing classes return false quietly.
	 */
	public function test_autoloader_ignores_missing_class() {
		$this->assertFalse( class_exists( 'RevolterCookies\Does_Not_Exist' ) );
	}
}
