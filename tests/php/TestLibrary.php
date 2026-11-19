<?php
/**
 * Library tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Duration;
use RevolterCookies\Library;

/**
 * Verifies cookie, script, iframe and host matching.
 */
class TestLibrary extends WP_UnitTestCase {

	/**
	 * Build a duration array.
	 *
	 * @param int    $n    Amount.
	 * @param string $unit Unit.
	 * @return array
	 */
	private static function d( int $n, string $unit ): array {
		return array(
			'n'    => $n,
			'unit' => $unit,
		);
	}

	/**
	 * Build a small fixture library.
	 *
	 * @return Library
	 */
	private function fixture(): Library {
		$purpose = array(
			'en' => 'English.',
			'sv' => 'Svenska.',
		);
		return new Library(
			array(
				'version'  => 1,
				'services' => array(
					array(
						'id'       => 'ga-exact',
						'label'    => 'GA exact',
						'provider' => 'Google',
						'category' => 'statistics',
						'scripts'  => array( 'googletagmanager.com/gtag/js', 'googletagmanager.com/gtag/js?id=AW-' ),
						'inline'   => array( "fbq('init'", "gtag('config'" ),
						'iframes'  => array( 'youtube-nocookie.com/embed' ),
						'domains'  => array( 'google-analytics.com' ),
						'cookies'  => array(
							array(
								'name'        => '_ga',
								'match'       => 'prefix',
								'type'        => 'cookie',
								'third_party' => false,
								'duration'    => 'session',
								'purpose'     => $purpose,
							),
							array(
								'name'        => '_ga',
								'match'       => 'exact',
								'type'        => 'cookie',
								'third_party' => false,
								'duration'    => 'persistent',
								'purpose'     => $purpose,
							),
							array(
								'name'        => '_ga_G',
								'match'       => 'prefix',
								'type'        => 'cookie',
								'third_party' => false,
								'duration'    => 'persistent',
								'purpose'     => $purpose,
							),
							array(
								'name'        => 'elementor',
								'match'       => 'exact',
								'type'        => 'storage',
								'third_party' => false,
								'duration'    => 'persistent',
								'purpose'     => $purpose,
							),
						),
					),
					array(
						'id'       => 'ads',
						'label'    => 'Ads',
						'provider' => 'Google',
						'category' => 'marketing',
						'scripts'  => array( 'googletagmanager.com/gtag/js?id=AW-' ),
						'inline'   => array( "gtag('config', 'AW-" ),
						'iframes'  => array(),
						'domains'  => array(),
						'cookies'  => array(),
					),
				),
			)
		);
	}

	/**
	 * Exact beats prefix, and the longest prefix wins.
	 */
	public function test_match_cookie_exact_beats_prefix() {
		$hit = $this->fixture()->match_cookie( '_ga' );
		$this->assertSame( 'exact', $hit['cookie']['match'] );
		$this->assertSame( 'ga-exact', $hit['service']['id'] );

		$hit = $this->fixture()->match_cookie( '_ga_GXYZ' );
		$this->assertSame( '_ga_G', $hit['cookie']['name'] );
		$this->assertNull( $this->fixture()->match_cookie( 'nothing' ) );
	}

	/**
	 * Type must agree.
	 */
	public function test_match_cookie_respects_type() {
		$this->assertNull( $this->fixture()->match_cookie( 'elementor' ) );
		$hit = $this->fixture()->match_cookie( 'elementor', 'storage' );
		$this->assertSame( 'ga-exact', $hit['service']['id'] );
	}

	/**
	 * A prefix keeps the lookup working for suffixed names.
	 */
	public function test_match_cookie_prefix_keeps_lookup() {
		$hit = Library::from_file()->match_cookie( '_ga_G-KVR25R6MMX' );
		$this->assertSame( 'google-analytics', $hit['service']['id'] );
	}

	/**
	 * Script src matching.
	 */
	public function test_match_script_src() {
		$lib     = Library::from_file();
		$service = $lib->match_script( 'https://www.googletagmanager.com/gtag/js?id=G-X', '' );
		$this->assertSame( 'google-analytics', $service['id'] );
		$service = $lib->match_script( '//WWW.GoogleTagManager.com/gtm.js?id=GTM-ABC', '' );
		$this->assertSame( 'google-tag-manager', $service['id'] );
		$service = $lib->match_script( 'https://www.googletagmanager.com/gtag/js?id=AW-123', '' );
		$this->assertSame( 'google-ads', $service['id'] );
		$this->assertNull( $lib->match_script( 'https://example.com/app.js', '' ) );
		$this->assertNull( $lib->match_script( null, '' ) );
	}

	/**
	 * Inline matching, longest pattern wins.
	 */
	public function test_match_script_inline() {
		$lib = Library::from_file();
		$this->assertSame( 'meta-pixel', $lib->match_script( null, "fbq('init', '123');" )['id'] );
		$this->assertSame( 'google-analytics', $lib->match_script( null, "gtag('config', 'G-ABC');" )['id'] );
		$this->assertSame( 'google-ads', $lib->match_script( null, "gtag('config', 'AW-123');" )['id'] );
	}

	/**
	 * A bare "gtm-" in unrelated inline code is not GTM.
	 */
	public function test_match_script_inline_gtm_needs_real_signature() {
		$lib = Library::from_file();
		$this->assertNull( $lib->match_script( null, "document.querySelector('.gtm-banner').remove();" ) );
		$this->assertNull( $lib->match_script( null, 'var cfg = { id: "plugin-gtm-handle" };' ) );
		$snippet = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var j=d.createElement(s);j.src='https://www.googletagmanager.com/gtm.js?id='+i;})(window,document,'script','dataLayer','GTM-XXXX');";
		$this->assertSame( 'google-tag-manager', $lib->match_script( null, $snippet )['id'] );
		$this->assertSame( 'google-tag-manager', $lib->match_script( null, "w[l].push({'gtm.start': 1});" )['id'] );
	}

	/**
	 * Iframe matching.
	 */
	public function test_match_iframe_youtube_nocookie() {
		$lib = Library::from_file();
		$this->assertSame( 'youtube', $lib->match_iframe( 'https://www.youtube-nocookie.com/embed/abc' )['id'] );
		$this->assertSame( 'google-maps', $lib->match_iframe( 'https://www.google.com/maps/embed?pb=1' )['id'] );
		$this->assertSame( 'google-maps', $lib->match_iframe( 'https://maps.google.com/maps?q=Stockholm' )['id'] );
		$this->assertNull( $lib->match_iframe( 'https://example.com/embed' ) );
	}

	/**
	 * Host matching.
	 */
	public function test_match_host_subdomain() {
		$lib = Library::from_file();
		$this->assertSame( 'google-analytics', $lib->match_host( 'www.google-analytics.com' )['id'] );
		$this->assertSame( 'google-analytics', $lib->match_host( 'google-analytics.com' )['id'] );
		$this->assertNull( $lib->match_host( 'notgoogle-analytics.com' ) );
		$this->assertSame( 'google-fonts', $lib->match_host( 'fonts.gstatic.com' )['id'] );
	}

	/**
	 * Purpose language fallback.
	 */
	public function test_purpose_falls_back_to_en() {
		$lib    = $this->fixture();
		$cookie = $lib->service( 'ga-exact' )['cookies'][0];
		$this->assertSame( 'English.', $lib->purpose( $cookie, 'de' ) );
		$this->assertSame( 'Svenska.', $lib->purpose( $cookie, 'sv' ) );
		$this->assertSame( '', $lib->purpose( array(), 'sv' ) );
	}

	/**
	 * Service lookup.
	 */
	public function test_service_lookup() {
		$lib = Library::from_file();
		$this->assertNull( $lib->service( 'nope' ) );
		$this->assertSame( 'necessary', $lib->service( 'recaptcha' )['category'] );
		$this->assertSame( 'necessary', $lib->service( 'google-fonts' )['category'] );
		$this->assertSame( 'statistics', $lib->service( 'google-tag-manager' )['category'] );
		$this->assertSame( 'marketing', $lib->service( 'google-maps' )['category'] );
		$this->assertSame( self::d( 1, 'year' ), $lib->match_cookie( 'rc_consent' )['cookie']['duration'] );
		$this->assertSame( Library::from_file(), Library::from_file() );
	}

	/**
	 * Every shipped entry is complete.
	 */
	public function test_shipped_library_is_valid() {
		$categories = array( 'necessary', 'preferences', 'statistics', 'marketing', 'unclassified' );
		$required   = array(
			'revolter-cookies',
			'wordpress',
			'woocommerce',
			'wpml',
			'polylang',
			'translatepress',
			'elementor',
			'cloudflare',
			'recaptcha',
			'php-session',
			'google-analytics',
			'google-tag-manager',
			'hotjar',
			'microsoft-clarity',
			'google-ads',
			'meta-pixel',
			'linkedin-insight',
			'tiktok-pixel',
			'youtube',
			'vimeo',
			'google-maps',
			'google-fonts',
		);
		$services   = Library::from_file()->services();
		$ids        = array();
		foreach ( $services as $service ) {
			$this->assertNotEmpty( $service['id'] );
			$this->assertNotContains( $service['id'], $ids, 'duplicate id' );
			$ids[] = $service['id'];
			$this->assertNotEmpty( $service['label'], $service['id'] );
			$this->assertNotEmpty( $service['provider'], $service['id'] );
			$this->assertContains( $service['category'], $categories, $service['id'] );
			foreach ( array( 'scripts', 'inline', 'iframes', 'domains', 'cookies' ) as $key ) {
				$this->assertIsArray( $service[ $key ], $service['id'] . ' ' . $key );
			}
			foreach ( $service['cookies'] as $cookie ) {
				$where = $service['id'] . ':' . $cookie['name'];
				$this->assertNotEmpty( $cookie['name'], $where );
				$this->assertContains( $cookie['match'], array( 'exact', 'prefix' ), $where );
				$this->assertContains( $cookie['type'], array( 'cookie', 'storage' ), $where );
				$this->assertIsBool( $cookie['third_party'], $where );
				$this->assertTrue( Duration::is_valid( $cookie['duration'] ), $where );
				$this->assertNotEmpty( $cookie['purpose']['en'], $where );
				$this->assertNotEmpty( $cookie['purpose']['sv'], $where );
			}
		}
		foreach ( $required as $id ) {
			$this->assertContains( $id, $ids );
		}
	}

	/**
	 * A missing or invalid file is not memoised, so a later request retries, and the overview says so inline.
	 */
	public function test_load_failure_retries_and_shows_inline_error() {
		$path = get_temp_dir() . 'rc-library-' . wp_generate_password( 8, false ) . '.json';
		$this->assertFalse( Library::is_available( $path ) );
		$this->assertSame( array(), Library::from_file( $path )->services() );

		file_put_contents( $path, '{"services": [' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertFalse( Library::is_available( $path ) );

		file_put_contents( $path, '{"version":1,"services":[{"id":"x","category":"marketing","cookies":[]}]}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertTrue( Library::is_available( $path ) );
		$this->assertSame( Library::from_file( $path ), Library::from_file( $path ) );
		wp_delete_file( $path );

		$this->assertTrue( Library::is_available() );
		$view = array(
			'library_ok'   => false,
			'has_scan'     => false,
			'scan_failed'  => false,
			'pages'        => 0,
			'failed'       => 0,
			'when'         => '',
			'declared'     => 0,
			'needs_review' => 0,
			'blocked'      => array(),
			'leaks'        => array(),
			'leak_page'    => array(),
			'rescan'       => false,
			'items'        => array(),
			'languages'    => array(),
			'lang_source'  => '',
			'policy_title' => '',
			'updated'      => false,
			'error'        => '',
		);
		ob_start();
		include REVOLTER_COOKIES_PATH . 'templates/admin/overview.php';
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Cookie library could not be loaded; blocking of known services is disabled.', $html );
		$this->assertStringContainsString( 'rc-inline-error', $html );
	}
}
