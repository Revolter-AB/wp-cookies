<?php
/**
 * Swedish translation tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Duration;
use RevolterCookies\Texts;

/**
 * Verifies that the bundled sv_SE translation loads and matches the approved copy.
 */
class TestTranslations extends WP_UnitTestCase {

	/**
	 * Locale switcher in use before the test.
	 *
	 * @var WP_Locale_Switcher
	 */
	private $original_switcher;

	/**
	 * Report sv_SE as installed. The test core has no WP_LANG_DIR translations,
	 * so switch_to_locale() would otherwise refuse the locale.
	 *
	 * @param string[] $languages Installed locales.
	 * @return string[]
	 */
	public function add_swedish( $languages ) {
		$languages[] = 'sv_SE';
		return $languages;
	}

	/**
	 * Switch to Swedish and make sure the plugin's own translation is loaded.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'get_available_languages', array( $this, 'add_swedish' ) );
		$this->original_switcher       = $GLOBALS['wp_locale_switcher'];
		$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
		$GLOBALS['wp_locale_switcher']->init();
		$this->assertTrue( switch_to_locale( 'sv_SE' ) );
		// The test copy of the plugin is not under WP_PLUGIN_DIR, so load_plugin_textdomain()'s
		// relative path cannot resolve here; load the bundled file directly instead.
		unload_textdomain( 'revolter-cookies' );
		$this->assertTrue( load_textdomain( 'revolter-cookies', REVOLTER_COOKIES_PATH . 'languages/revolter-cookies-sv_SE.mo', 'sv_SE' ) );
	}

	/**
	 * Back to the default locale.
	 */
	public function tear_down() {
		restore_previous_locale();
		$GLOBALS['wp_locale_switcher'] = $this->original_switcher;
		remove_filter( 'get_available_languages', array( $this, 'add_swedish' ) );
		unload_textdomain( 'revolter-cookies' );
		parent::tear_down();
	}

	/**
	 * Banner copy has its fixed Swedish wording.
	 */
	public function test_banner_swedish() {
		$b = Texts::banner();
		$this->assertSame( 'Vi använder cookies', $b['title'] );
		$this->assertSame( 'Neka alla', $b['reject_all'] );
		$this->assertSame( 'Godkänn alla', $b['accept_all'] );
		$this->assertSame( 'Skapad med omsorg av', $b['credit'] );
	}

	/**
	 * Category labels.
	 */
	public function test_category_label_swedish() {
		$this->assertSame( 'Marknadsföring', Texts::category_label( 'marketing' ) );
		$this->assertSame( 'Nödvändiga', Texts::category_label( 'necessary' ) );
	}

	/**
	 * Duration labels use Swedish plurals.
	 */
	public function test_duration_swedish() {
		$this->assertSame(
			'2 år',
			Duration::label(
				array(
					'n'    => 2,
					'unit' => 'year',
				)
			)
		);
		$this->assertSame(
			'1 dag',
			Duration::label(
				array(
					'n'    => 1,
					'unit' => 'day',
				)
			)
		);
		$this->assertSame( 'Beständig', Duration::label( 'persistent' ) );
	}

	/**
	 * Legal section cites the Swedish act.
	 */
	public function test_legal_section_swedish() {
		$s = Texts::sections(
			array(
				'company' => 'Acme AB',
				'email'   => 'info@example.com',
				'count'   => 1,
			)
		);
		$this->assertStringContainsString( '9 kap. 28 §', $s['legal']['body'] );
		$this->assertStringContainsString( 'Den här webbplatsen använder 1 cookie, fördelad', $s['how']['body'] );
	}

	/**
	 * Admin strings are translated too.
	 */
	public function test_admin_string_swedish() {
		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- plain string.
		$this->assertSame( 'Skanna webbplatsen igen', __( 'Rescan website', 'revolter-cookies' ) );
	}
}
