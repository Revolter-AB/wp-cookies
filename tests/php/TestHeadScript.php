<?php
/**
 * Head script tests.
 *
 * @package RevolterCookies
 */

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- HTML fixtures, not enqueued assets.

use RevolterCookies\Blocking_Rules;
use RevolterCookies\Context;
use RevolterCookies\Head_Script;
use RevolterCookies\Library;
use RevolterCookies\Settings;

/**
 * Verifies the inline head script.
 */
class TestHeadScript extends WP_UnitTestCase {

	/**
	 * Clean up.
	 */
	public function tear_down() {
		unset( $_COOKIE['rc_consent'] );
		Context::set_scan_mode_override( null );
		parent::tear_down();
	}

	/**
	 * Head script over the given rules.
	 *
	 * @param Blocking_Rules|null $rules Rules, default from the real library.
	 * @return Head_Script
	 */
	private function head( ?Blocking_Rules $rules = null ): Head_Script {
		if ( null === $rules ) {
			$rules = Blocking_Rules::build( Library::from_file(), Settings::defaults(), array() );
		}
		return new Head_Script(
			static function () use ( $rules ) {
				return $rules;
			}
		);
	}

	/**
	 * One ignored inline script with rules, the Consent Mode default and the guard, in order.
	 */
	public function test_head_script_contains_consent_default_and_ignore_attr() {
		$out = $this->head()->render();
		$this->assertStringStartsWith( '<script data-rc-ignore data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1" nowprocket>', $out );
		$this->assertStringEndsWith( "</script>\n", $out );
		$this->assertSame( 1, substr_count( $out, '<script' ) );
		$default = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
			. "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});\n"
			. "gtag('set','ads_data_redaction',true);";
		$this->assertStringContainsString( $default, $out );
		$this->assertStringContainsString( 'window.RevolterCookiesRules = [', $out );
		$this->assertStringContainsString( '"p":"googletagmanager.com/gtag/js"', $out );

		$rules_pos   = strpos( $out, 'window.RevolterCookiesRules' );
		$default_pos = strpos( $out, "gtag('consent','default'" );
		$guard_pos   = strpos( $out, 'Revolter Cookies guard' );
		$this->assertNotFalse( $guard_pos );
		$this->assertLessThan( $default_pos, $rules_pos );
		$this->assertLessThan( $guard_pos, $default_pos );
	}

	/**
	 * The consent version is printed before the guard so it can validate the cookie.
	 */
	public function test_head_script_prints_consent_version_before_guard() {
		Settings::set( 'consent_version', 7 );
		$out         = $this->head()->render();
		$version_pos = strpos( $out, 'window.RevolterCookiesVersion = 7;' );
		$this->assertNotFalse( $version_pos );
		$this->assertLessThan( strpos( $out, 'Revolter Cookies guard' ), $version_pos );
		delete_option( Settings::OPTION );
	}

	/**
	 * Rule JSON can never close the script element.
	 */
	public function test_rules_json_cannot_break_out() {
		$rules = new Blocking_Rules(
			array(
				array(
					'pattern'  => '</script><script>alert(1)</script>',
					'category' => 'marketing',
					'label'    => "Evil & 'co' \"x\"",
					'kind'     => 'script',
				),
			)
		);
		$out   = $this->head( $rules )->render();
		$this->assertSame( 1, substr_count( strtolower( $out ), '</script' ) );
		$this->assertStringContainsString( '</script>', $out );
	}

	/**
	 * Identical with and without a consent cookie.
	 */
	public function test_head_script_identical_with_and_without_consent_cookie() {
		$without               = $this->head()->render();
		$_COOKIE['rc_consent'] = rawurlencode( wp_json_encode( array( 'categories' => array( 'necessary', 'statistics', 'marketing' ) ) ) );
		$this->assertSame( $without, $this->head()->render() );
	}

	/**
	 * Hooked on wp_head priority 1 and prints only on front-end pages.
	 */
	public function test_register_and_print() {
		$head = $this->head();
		$head->register();
		$this->assertSame( 1, has_action( 'wp_head', array( $head, 'print_script' ) ) );
		remove_action( 'wp_head', array( $head, 'print_script' ), 1 );

		$this->go_to( home_url( '/' ) );
		ob_start();
		$head->print_script();
		$this->assertSame( $head->render(), ob_get_clean() );

		$this->go_to( get_feed_link() );
		ob_start();
		$head->print_script();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The scan mode is printed before the guard, only in scan mode.
	 */
	public function test_scan_mode_line_only_in_scan_mode() {
		$normal = $this->head()->render();
		$this->assertStringNotContainsString( "window.RevolterCookiesScanMode='", $normal );

		foreach ( array( 'none', 'accepted' ) as $mode ) {
			Context::set_scan_mode_override( $mode );
			$out  = $this->head()->render();
			$line = "window.RevolterCookiesScanMode='" . $mode . "';";
			$this->assertSame( 1, substr_count( $out, $line ) );
			$this->assertLessThan( strpos( $out, 'Revolter Cookies guard' ), strpos( $out, $line ) );
			$this->assertSame( 1, substr_count( $out, '<script' ) );
			$this->assertSame( 1, substr_count( strtolower( $out ), '</script' ) );
		}

		Context::set_scan_mode_override( null );
		$this->assertSame( $normal, $this->head()->render() );
	}
}
