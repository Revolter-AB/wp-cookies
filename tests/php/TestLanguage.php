<?php
/**
 * Language detection tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Language;

/**
 * Verifies visitor language detection.
 */
class TestLanguage extends WP_UnitTestCase {

	/**
	 * Swedish locale maps to sv.
	 */
	public function test_wordpress_locale_sv() {
		add_filter(
			'locale',
			static function () {
				return 'sv_SE';
			}
		);
		$this->assertSame( 'wordpress', Language::source() ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Identifier, not prose.
		$this->assertSame( 'sv', Language::current_code() );
		$this->assertSame( 'sv', Language::current() );
	}

	/**
	 * Unsupported languages fall back to English.
	 */
	public function test_unsupported_falls_back_to_en() {
		add_filter(
			'locale',
			static function () {
				return 'de_DE';
			}
		);
		$this->assertSame( 'de', Language::current_code() );
		$this->assertSame( 'en', Language::current() );
	}

	/**
	 * Plain WordPress reports the site locale as its only language.
	 */
	public function test_site_languages_plain() {
		add_filter(
			'locale',
			static function () {
				return 'sv_SE';
			}
		);
		$languages = Language::site_languages();
		$this->assertArrayHasKey( 'sv', $languages );
		$this->assertCount( 1, $languages );
	}

	/**
	 * Without a multilingual plugin the id is returned unchanged.
	 */
	public function test_translate_post_id_falls_back() {
		$this->assertSame( 42, Language::translate_post_id( 42 ) );
	}

	/**
	 * Polylang is detected through its function.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_polylang_source_when_function_exists() {
		if ( ! function_exists( 'pll_current_language' ) ) {
			function pll_current_language( $field = 'slug' ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Stub for the Polylang API.
				return 'sv';
			}
		}
		$this->assertSame( 'polylang', Language::source() );
		$this->assertSame( 'sv', Language::current_code() );
		$this->assertSame( 'sv', Language::current() );
	}

	/**
	 * Polylang languages pair slug with name.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_polylang_site_languages() {
		if ( ! function_exists( 'pll_current_language' ) ) {
			function pll_current_language( $field = 'slug' ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Stub for the Polylang API.
				return 'sv';
			}
		}
		if ( ! function_exists( 'pll_languages_list' ) ) {
			function pll_languages_list( $args = array() ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Stub for the Polylang API.
				return 'name' === $args['fields'] ? array( 'Svenska', 'English' ) : array( 'sv', 'en' );
			}
		}
		$this->assertSame(
			array(
				'sv' => 'Svenska',
				'en' => 'English',
			),
			Language::site_languages()
		);
	}
}
