<?php
/**
 * Declaration renderer tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Declaration;
use RevolterCookies\Installer;
use RevolterCookies\Item_Repository;
use RevolterCookies\Settings;

/**
 * Verifies the declaration groups and markup.
 */
class TestDeclaration extends WP_UnitTestCase {

	/**
	 * Repository.
	 *
	 * @var Item_Repository
	 */
	private $repo;

	/**
	 * Empty items table and fixed settings.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		Installer::activate();
		$table = Installer::items_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->repo = new Item_Repository();
		update_option( Settings::OPTION, array() );
		Settings::set( 'company_name', 'Acme AB' );
		Settings::set( 'contact_email', 'privacy@example.com' );
		wp_dequeue_style( 'rc-declaration' );
		wp_deregister_style( 'rc-declaration' );
	}

	/**
	 * Add a declared item.
	 *
	 * @param string $name      Name.
	 * @param string $category  Category.
	 * @param array  $overrides Overrides.
	 */
	private function add( string $name, string $category, array $overrides = array() ): void {
		$this->repo->upsert(
			array_merge(
				array(
					'type'     => 'cookie',
					'name'     => $name,
					'category' => $category,
					'status'   => 'declared',
					'provider' => 'Acme',
					'duration' => '1:year',
				),
				$overrides
			)
		);
	}

	/**
	 * Groups follow the canonical order with counts.
	 */
	public function test_groups_order_and_counts() {
		$this->add( 'm1', 'marketing' );
		$this->add( 'n2', 'necessary' );
		$this->add( 'n1', 'necessary' );
		$this->add( 's1', 'statistics' );
		$groups = Declaration::groups( 'en' );
		$this->assertSame( array( 'necessary', 'statistics', 'marketing' ), wp_list_pluck( $groups, 'key' ) );
		$this->assertSame( array( 2, 1, 1 ), wp_list_pluck( $groups, 'count' ) );
		$this->assertSame( 'n1', $groups[0]['rows'][0]['name'] );
		$this->assertSame( '1 year', $groups[0]['rows'][0]['duration'] );
		$this->assertSame( 'Necessary', $groups[0]['label'] );
	}

	/**
	 * Override beats the library; the English override is the fallback.
	 */
	public function test_override_purpose_wins() {
		$this->add(
			'_ga',
			'statistics',
			array( 'purpose_override' => array( 'en' => 'Custom purpose' ) )
		);
		$groups = Declaration::groups( 'sv' );
		$this->assertSame( 'Custom purpose', $groups[0]['rows'][0]['purpose'] );
		$this->add(
			'_ga',
			'statistics',
			array(
				'purpose_override' => array(
					'en' => 'Custom purpose',
					'sv' => 'Eget syfte',
				),
			)
		);
		$groups = Declaration::groups( 'sv' );
		$this->assertSame( 'Eget syfte', $groups[0]['rows'][0]['purpose'] );
	}

	/**
	 * Unknown cookies get the generic purpose, and empty durations stay empty.
	 */
	public function test_generic_purpose_for_unknown() {
		$this->add( 'zz_unknown_cookie', 'marketing', array( 'duration' => null ) );
		$groups = Declaration::groups( 'en' );
		$this->assertSame( 'Used by the website for marketing.', $groups[0]['rows'][0]['purpose'] );
		$this->assertSame( '', $groups[0]['rows'][0]['duration'] );
	}

	/**
	 * Unclassified items are listed even when not declared.
	 */
	public function test_unclassified_group_shown_when_present() {
		$this->add(
			'mystery',
			'unclassified',
			array( 'status' => 'needs_review' )
		);
		$groups = Declaration::groups( 'en' );
		$this->assertSame( 'unclassified', $groups[0]['key'] );
		$this->assertSame( 'Not yet classified.', $groups[0]['rows'][0]['purpose'] );
	}

	/**
	 * Full page has the sections, company, a mailto link and the org number when set.
	 */
	public function test_full_page_contains_sections_and_company_and_email() {
		$this->add( 'n1', 'necessary' );
		$html = Declaration::render_full_page();
		$this->assertStringContainsString( 'What are cookies?', $html );
		$this->assertStringContainsString( 'How Acme AB uses cookies', $html );
		$this->assertStringContainsString( 'This website uses 1 cookie, divided', $html );
		$this->assertStringContainsString( 'How to manage cookies', $html );
		$this->assertStringContainsString( 'Legal basis', $html );
		$this->assertStringContainsString( 'Your rights', $html );
		$this->assertStringContainsString( '<a href="mailto:privacy@example.com">privacy@example.com</a>', $html );
		$this->assertStringContainsString( 'contact Acme AB at', $html );
		$this->assertStringContainsString( 'class="rc-decl rc-decl-full" data-no-translation', $html );
		$this->assertStringContainsString( '<div class="rc-consent-box" data-rc-consent-status>', $html );
		$this->assertStringContainsString( '<p class="rc-consent-text" data-rc-consent-text></p>', $html );
		$this->assertStringContainsString( 'data-rc-action="withdraw"', $html );
		$this->assertStringContainsString( 'data-rc-action="change"', $html );
		$this->assertTrue( wp_style_is( 'rc-declaration', 'enqueued' ) );

		Settings::set( 'org_number', '556000-0000' );
		$this->assertStringContainsString( 'contact Acme AB (org. no. 556000-0000) at', Declaration::render_full_page() );
	}

	/**
	 * Section order: what, how, declaration, manage, legal, rights, consent.
	 */
	public function test_full_page_order() {
		$html  = Declaration::render_full_page();
		$last  = -1;
		$marks = array( 'What are cookies?', 'How Acme AB', 'Cookie declaration', 'How to manage', 'Legal basis', 'Your rights', 'rc-consent-box' );
		foreach ( $marks as $mark ) {
			$pos = strpos( $html, $mark );
			$this->assertNotFalse( $pos, $mark );
			$this->assertGreaterThan( $last, $pos, $mark );
			$last = $pos;
		}
	}

	/**
	 * Hidden sections disappear; the table stays.
	 */
	public function test_hide_attribute_removes_section() {
		$html = do_shortcode( '[revolter_cookies_full_page hide="what, manage,consent,bogus"]' );
		$this->assertStringNotContainsString( 'What are cookies?', $html );
		$this->assertStringNotContainsString( 'How to manage cookies', $html );
		$this->assertStringNotContainsString( 'rc-consent-box', $html );
		$this->assertStringContainsString( 'Legal basis', $html );
		$this->assertStringContainsString( 'Cookie declaration', $html );
	}

	/**
	 * The table form carries no sections or consent box.
	 */
	public function test_table_has_no_sections() {
		$this->add( 'n1', 'necessary' );
		$html = Declaration::render_table();
		$this->assertStringContainsString( '<table class="rc-table">', $html );
		$this->assertStringContainsString( '<th scope="col">Name</th>', $html );
		$this->assertStringContainsString( 'data-label="Purpose"', $html );
		$this->assertStringContainsString( '<code>n1</code>', $html );
		$this->assertStringContainsString( 'class="rc-group-title"', $html );
		$this->assertStringNotContainsString( 'What are cookies?', $html );
		$this->assertStringNotContainsString( 'rc-consent-box', $html );
		$this->assertStringNotContainsString( 'Your rights', $html );
	}

	/**
	 * Last updated shows only after a scan.
	 */
	public function test_last_updated_only_after_scan() {
		$this->assertStringNotContainsString( 'Last updated', Declaration::render_table() );
		Settings::set( 'last_scan', array( 'time' => '2026-03-04T10:00:00+00:00' ) );
		$this->assertStringContainsString( 'Last updated', Declaration::render_table() );
	}

	/**
	 * The settings link is opt-in.
	 */
	public function test_table_settings_link_optional() {
		$this->assertStringNotContainsString( 'rc-open-settings', Declaration::render_table() );
		$html = Declaration::render_table( array( 'settings_link' => true ) );
		$this->assertStringContainsString( '<a href="#cookie-settings" class="rc-open-settings">Change cookie settings</a>', $html );
		$this->assertStringContainsString( 'rc-open-settings', do_shortcode( '[revolter_cookies_table settings_link="yes"]' ) );
		$this->assertStringNotContainsString( 'rc-open-settings', do_shortcode( '[revolter_cookies_table]' ) );
	}

	/**
	 * Swedish rendering uses the library's Swedish purposes.
	 */
	public function test_swedish_rendering_uses_sv_purposes() {
		$this->add( 'rc_consent', 'necessary' );
		add_filter(
			'locale',
			static function () {
				return 'sv_SE';
			}
		);
		$html = Declaration::render_table();
		$this->assertStringContainsString( 'Sparar besökarens cookieval.', $html );
		$this->assertStringNotContainsString( "Stores the visitor's cookie choices.", $html );
	}

	/**
	 * Names and providers are escaped.
	 */
	public function test_output_escaped() {
		$this->add( '<script>alert(1)</script>', 'marketing', array( 'provider' => '<b>x</b>' ) );
		Settings::set( 'company_name', '<img src=x onerror=1>' );
		$html = Declaration::render_full_page();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	/**
	 * No per-visitor content, so a consent cookie changes nothing.
	 */
	public function test_full_page_identical_with_and_without_consent_cookie() {
		$this->add( 'n1', 'necessary' );
		unset( $_COOKIE['rc_consent'] );
		$without = Declaration::render_full_page() . Declaration::render_table();
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Test input.
		$_COOKIE['rc_consent'] = rawurlencode( '{"v":1,"c":["necessary","statistics"],"t":1760000000,"id":"abcdef123456"}' );
		$with                  = Declaration::render_full_page() . Declaration::render_table();
		unset( $_COOKIE['rc_consent'] );
		$this->assertSame( $without, $with );
	}
}
