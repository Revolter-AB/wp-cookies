<?php
/**
 * Settings tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Settings;

/**
 * Verifies the settings store.
 */
class TestSettings extends WP_UnitTestCase {

	/**
	 * Defaults apply when nothing is stored.
	 */
	public function test_defaults() {
		delete_option( 'revolter_cookies_settings' );
		$this->assertSame( '#2F4BC7', Settings::get( 'accent_color' ) );
		$this->assertSame( array( 'necessary' ), Settings::get( 'categories_in_use' ) );
		$this->assertSame( get_bloginfo( 'name' ), Settings::get( 'company_name' ) );
		$this->assertSame( 1, Settings::get( 'consent_version' ) );
	}

	/**
	 * Values persist.
	 */
	public function test_set_persists() {
		Settings::set( 'button_position', 'right' );
		$this->assertSame( 'right', Settings::get( 'button_position' ) );
		$this->assertSame( 'right', Settings::all()['button_position'] );
		$this->assertSame( 'right', get_option( 'revolter_cookies_settings' )['button_position'] );
	}

	/**
	 * Unknown keys are rejected.
	 */
	public function test_unknown_key_throws() {
		$this->expectException( InvalidArgumentException::class );
		Settings::set( 'nope', 1 );
	}

	/**
	 * Unknown keys are rejected on read.
	 */
	public function test_unknown_key_get_throws() {
		$this->expectException( InvalidArgumentException::class );
		Settings::get( 'nope' );
	}
}
