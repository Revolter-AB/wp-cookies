<?php
/**
 * Blocking rules tests.
 *
 * @package RevolterCookies
 */

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- HTML fixtures, not enqueued assets.

use RevolterCookies\Blocking_Rules;
use RevolterCookies\Library;
use RevolterCookies\Settings;

/**
 * Verifies rule building and matching.
 */
class TestBlockingRules extends WP_UnitTestCase {

	/**
	 * Build rules from the real library.
	 *
	 * @param array $settings Setting overrides.
	 * @param array $domains  Reviewed domain rows.
	 * @return Blocking_Rules
	 */
	private function rules( array $settings = array(), array $domains = array() ): Blocking_Rules {
		return Blocking_Rules::build( Library::from_file(), array_merge( Settings::defaults(), $settings ), $domains );
	}

	/**
	 * GA src is statistics, Ads src beats it as marketing.
	 */
	public function test_longest_pattern_wins_for_src() {
		$rules = $this->rules();
		$ga    = $rules->match_script( 'https://www.googletagmanager.com/gtag/js?id=G-ABC', '' );
		$ads   = $rules->match_script( 'https://www.googletagmanager.com/gtag/js?id=AW-123', '' );
		$this->assertSame( 'statistics', $ga['category'] );
		$this->assertSame( 'Google Analytics', $ga['label'] );
		$this->assertSame( 'marketing', $ads['category'] );
	}

	/**
	 * Inline Ads config beats GA config.
	 */
	public function test_inline_ads_config_beats_ga_config() {
		$rules = $this->rules();
		$this->assertSame( 'statistics', $rules->match_script( null, "gtag('config', 'G-1');" )['category'] );
		$this->assertSame( 'marketing', $rules->match_script( null, "gtag('config', 'AW-1');" )['category'] );
	}

	/**
	 * Necessary services contribute nothing.
	 */
	public function test_necessary_services_have_no_rules() {
		$rules = $this->rules();
		$this->assertNull( $rules->match_script( 'https://js.stripe.com/v3/', '' ) );
		$this->assertNull( $rules->match_script( 'https://www.google.com/recaptcha/api.js', '' ) );
		$this->assertNull( $rules->match_iframe( 'https://js.stripe.com/v3/frame.html' ) );
		foreach ( $rules->signatures() as $signature ) {
			$this->assertStringNotContainsString( 'stripe', $signature );
		}
	}

	/**
	 * Host rules match the host or a subdomain, not a lookalike.
	 */
	public function test_host_rules_match_domain_and_subdomains_only() {
		$rules = $this->rules();
		$this->assertSame( 'statistics', $rules->match_script( '//foo.hotjar.io/x.js', '' )['category'] );
		$this->assertSame( 'statistics', $rules->match_script( 'https://hotjar.io/x.js', '' )['category'] );
		$this->assertNull( $rules->match_script( 'https://nothotjar.io/x.js', '' ) );
	}

	/**
	 * Script patterns in inline text only count in URL context.
	 */
	public function test_inline_script_pattern_requires_url_context() {
		$rules  = $this->rules();
		$loader = "(function(){var s=document.createElement('script');s.src='https://snap.licdn.com/li.lms-analytics/insight.min.js';})();";
		$this->assertSame( 'marketing', $rules->match_script( null, $loader )['category'] );
		$escaped = 'var cfg={"src":"https:\/\/snap.licdn.com\/x.js"};';
		$this->assertSame( 'marketing', $rules->match_script( null, $escaped )['category'] );
		$this->assertNull( $rules->match_script( null, 'var note = "see snap.licdn.com/ docs";' ) );
		$this->assertNull( $rules->match_script( null, 'var note = "stats.wp.com/ is great";' ) );
	}

	/**
	 * A path that merely starts like a tracker path is not a tracker (localized config with a Facebook page URL).
	 */
	public function test_facebook_page_url_is_not_meta_pixel() {
		$rules  = $this->rules();
		$config = 'var themeSettings = {"facebook":"https:\\/\\/www.facebook.com\\/travelbyran","tracking":"https:\\/\\/www.facebook.com\\/translate"};';
		$this->assertNull( $rules->match_script( null, $config ) );
		$this->assertSame( 'marketing', $rules->match_script( 'https://www.facebook.com/tr?id=1&ev=PageView', '' )['category'] );
		$this->assertSame( 'marketing', $rules->match_script( 'https://www.facebook.com/tr/?id=1', '' )['category'] );
		$this->assertSame( 'marketing', $rules->match_script( null, 'new Image().src="https://www.facebook.com/tr?id=1&ev=PageView";' )['category'] );
	}

	/**
	 * Unrelated scripts are not matched.
	 */
	public function test_unrelated_script_unmatched() {
		$rules = $this->rules();
		$this->assertNull( $rules->match_script( 'https://example.org/wp-includes/js/jquery/jquery.min.js', '' ) );
		$this->assertNull( $rules->match_script( null, 'console.log("hello");' ) );
	}

	/**
	 * GTM category comes from settings.
	 */
	public function test_gtm_category_setting_respected() {
		$src = 'https://www.googletagmanager.com/gtm.js?id=GTM-ABC';
		$this->assertSame( 'statistics', $this->rules()->match_script( $src, '' )['category'] );
		$this->assertSame( 'marketing', $this->rules( array( 'gtm_category' => 'marketing' ) )->match_script( $src, '' )['category'] );
		$this->assertNull( $this->rules( array( 'gtm_category' => 'necessary' ) )->match_script( $src, '' ) );
	}

	/**
	 * Manual rules match scripts, iframes and hosts.
	 */
	public function test_manual_rules_all_kinds() {
		$rules = $this->rules(
			array(
				'manual_rules' => array(
					array(
						'pattern'  => 'tracker.example',
						'category' => 'preferences',
					),
					array(
						'pattern'  => 'ok.example',
						'category' => 'necessary',
					),
				),
			)
		);
		$this->assertSame( 'preferences', $rules->match_script( 'https://cdn.tracker.example/a.js', '' )['category'] );
		$this->assertSame( 'preferences', $rules->match_iframe( 'https://tracker.example/embed' )['category'] );
		$this->assertNull( $rules->match_script( 'https://ok.example/a.js', '' ) );
	}

	/**
	 * Only declared, non-necessary reviewed domains contribute host rules.
	 */
	public function test_reviewed_domains_filtered() {
		$rules = $this->rules(
			array(),
			array(
				array(
					'name'     => 'widgets.declared.example',
					'category' => 'marketing',
					'status'   => 'declared',
				),
				array(
					'name'     => 'pending.example',
					'category' => 'marketing',
					'status'   => 'needs_review',
				),
				array(
					'name'     => 'cdn.necessary.example',
					'category' => 'necessary',
					'status'   => 'declared',
				),
			)
		);
		$this->assertSame( 'marketing', $rules->match_iframe( 'https://widgets.declared.example/x' )['category'] );
		$this->assertNull( $rules->match_iframe( 'https://pending.example/x' ) );
		$this->assertNull( $rules->match_script( 'https://cdn.necessary.example/x.js', '' ) );
	}

	/**
	 * JS rules carry script/iframe/host kinds only.
	 */
	public function test_for_js_shape() {
		$js = $this->rules()->for_js();
		$this->assertNotEmpty( $js );
		$patterns = array();
		$kinds    = array();
		foreach ( $js as $rule ) {
			$this->assertSame( array( 'p', 'c', 'l', 'k' ), array_keys( $rule ) );
			$this->assertNotSame( 'necessary', $rule['c'] );
			$this->assertContains( $rule['k'], array( 'host', 'sub' ) );
			$patterns[]                        = $rule['k'] . ' ' . $rule['p'];
			$kinds[ $rule['k'] ][ $rule['p'] ] = true;
		}
		$this->assertArrayHasKey( 'googletagmanager.com/gtag/js', $kinds['sub'] );
		$this->assertArrayHasKey( 'youtube.com/embed', $kinds['sub'] );
		$this->assertArrayHasKey( 'hotjar.io', $kinds['host'] );
		$this->assertArrayHasKey( 'googletagmanager.com', $kinds['host'] );
		$this->assertArrayNotHasKey( 'hotjar.io', $kinds['sub'] );
		$this->assertNotContains( "sub gtag('config'", $patterns );
		$this->assertSame( count( $patterns ), count( array_unique( $patterns ) ) );
	}
}
