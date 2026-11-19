<?php
/**
 * WP Consent API integration tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Banner;
use RevolterCookies\Plugin;
use RevolterCookies\Wp_Consent_Api;

/**
 * Verifies registration with the WP Consent API plugin.
 */
class TestWpConsentApi extends WP_UnitTestCase {

	/**
	 * Clean up.
	 */
	public function tear_down() {
		Wp_Consent_Api::set_presence_override( null );
		remove_all_filters( 'wp_get_consent_type' );
		remove_all_filters( self::registered_hook() );
		parent::tear_down();
	}

	/**
	 * The filter the WP Consent API reads for this plugin.
	 *
	 * @return string
	 */
	private static function registered_hook() {
		return 'wp_consent_api_registered_' . plugin_basename( REVOLTER_COOKIES_FILE );
	}

	/**
	 * Registers as opt-in when the API plugin is present.
	 */
	public function test_registers_with_wp_consent_api_when_present() {
		Wp_Consent_Api::set_presence_override( true );
		remove_all_filters( self::registered_hook() );
		remove_all_filters( 'wp_get_consent_type' );
		$this->assertTrue( Wp_Consent_Api::active() );

		( new Wp_Consent_Api() )->register();

		$this->assertTrue( apply_filters( self::registered_hook(), false ) );
		$this->assertSame( 'optin', apply_filters( 'wp_get_consent_type', '' ) );
		$this->assertTrue( Banner::config()['wpConsentApi'] );
	}

	/**
	 * Does nothing without the API plugin.
	 */
	public function test_inactive_without_wp_consent_api() {
		Wp_Consent_Api::set_presence_override( false );
		remove_all_filters( self::registered_hook() );
		remove_all_filters( 'wp_get_consent_type' );
		$this->assertFalse( Wp_Consent_Api::active() );

		( new Wp_Consent_Api() )->register();

		$this->assertFalse( apply_filters( self::registered_hook(), false ) );
		$this->assertSame( '', apply_filters( 'wp_get_consent_type', '' ) );
		$this->assertFalse( Banner::config()['wpConsentApi'] );
	}

	/**
	 * Real detection: the test environment has no WP Consent API plugin.
	 */
	public function test_detects_absence_in_test_environment() {
		$this->assertSame( function_exists( 'wp_set_consent' ) || class_exists( 'WP_CONSENT_API' ), Wp_Consent_Api::active() );
	}

	/**
	 * Booted as a module.
	 */
	public function test_module_registered_in_boot() {
		$found = false;
		foreach ( Plugin::instance()->modules() as $module ) {
			if ( $module instanceof Wp_Consent_Api ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}
}
