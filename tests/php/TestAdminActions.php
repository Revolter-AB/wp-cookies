<?php
/**
 * Admin action tests.
 *
 * @package RevolterCookies
 */

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Tests drive handlers with synthetic requests.

use RevolterCookies\Admin\Log_Page;
use RevolterCookies\Admin\Menu;
use RevolterCookies\Admin\Overview_Page;
use RevolterCookies\Admin\Settings_Pages;
use RevolterCookies\Blocking_Rules;
use RevolterCookies\Consent_Log;
use RevolterCookies\Installer;
use RevolterCookies\Item_Repository;
use RevolterCookies\Library;
use RevolterCookies\Settings;

/**
 * Verifies the admin handlers and settings sanitising.
 */
class TestAdminActions extends WP_UnitTestCase {

	/**
	 * Repository.
	 *
	 * @var Item_Repository
	 */
	private $repo;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		$this->repo = new Item_Repository();
		delete_option( Settings::OPTION );
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Installer::items_table() ); // phpcs:ignore WordPress.DB
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Installer::log_table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Become an admin.
	 */
	private function as_admin() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Seed a scan cookie needing review.
	 *
	 * @return int Id.
	 */
	private function seed_cookie(): int {
		return $this->repo->upsert(
			array(
				'type'   => 'cookie',
				'name'   => '_ga',
				'source' => 'scan',
			)
		);
	}

	/**
	 * Capability and nonce are enforced.
	 */
	public function test_save_item_requires_cap_and_nonce() {
		$id = $this->seed_cookie();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST    = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'id'       => $id,
			'category' => 'statistics',
			'_wpnonce' => wp_create_nonce( 'revolter_cookies_save_item' ),
		);
		$_REQUEST = $_POST;
		try {
			( new Overview_Page() )->handle_save_item();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, (int) $e->getCode() );
		}

		$this->as_admin();
		$_POST    = array(
			'id'       => $id,
			'category' => 'statistics',
		);
		$_REQUEST = $_POST;
		$this->expectException( WPDieException::class );
		( new Overview_Page() )->handle_save_item();
	}

	/**
	 * Saving declares the item and keeps its scan source.
	 */
	public function test_save_item_sets_declared_and_category() {
		$id   = $this->seed_cookie();
		$page = new Overview_Page();
		$this->assertTrue(
			$page->save_item(
				array(
					'id'         => $id,
					'category'   => 'statistics',
					'purpose_sv' => 'Statistik',
					'purpose_en' => '',
				)
			)
		);
		$row = $this->repo->get( $id );
		$this->assertSame( 'statistics', $row['category'] );
		$this->assertSame( 'declared', $row['status'] );
		$this->assertSame( 'scan', $row['source'] );
		$this->assertSame(
			array(
				'sv' => 'Statistik',
				'en' => '',
			),
			$row['purpose_override']
		);

		$page->save_item(
			array(
				'id'       => $id,
				'category' => 'unclassified',
			)
		);
		$row = $this->repo->get( $id );
		$this->assertSame( 'needs_review', $row['status'] );
		$this->assertNull( $row['purpose_override'] );

		$this->assertFalse(
			$page->save_item(
				array(
					'id'       => $id,
					'category' => 'bogus',
				)
			)
		);
	}

	/**
	 * A reviewed domain becomes a blocking rule.
	 */
	public function test_save_domain_item_creates_blocking_rule() {
		$id = $this->repo->upsert(
			array(
				'type' => 'domain',
				'name' => 'cdn.example-tracker.com',
			)
		);
		( new Overview_Page() )->save_item(
			array(
				'id'       => $id,
				'category' => 'marketing',
			)
		);
		$rules = Blocking_Rules::build(
			Library::from_file(),
			Settings::all(),
			$this->repo->all(
				array(
					'type'   => 'domain',
					'status' => 'declared',
				)
			)
		);
		$this->assertNotNull( $rules->match_script( 'https://cdn.example-tracker.com/x.js', '' ) );
	}

	/**
	 * Declaring a domain as marketing adds the Marketing toggle and asks visitors again.
	 */
	public function test_declared_domain_puts_category_in_use() {
		Settings::set( 'categories_in_use', array( 'necessary' ) );
		Settings::set( 'consent_version', 2 );
		$id = $this->repo->upsert(
			array(
				'type' => 'domain',
				'name' => 'cdn.example-tracker.com',
			)
		);
		( new Overview_Page() )->save_item(
			array(
				'id'       => $id,
				'category' => 'marketing',
			)
		);
		$this->assertSame( array( 'marketing' ), Settings::get( 'categories_in_use' ) );
		$this->assertSame( 3, Settings::get( 'consent_version' ) );
		$this->assertSame( 0, $this->repo->counts()['declared'], 'Domains are not counted as declared cookies.' );
	}

	/**
	 * Saving, adding and deleting items announce a rules change and refresh the front-end cache.
	 */
	public function test_item_writes_fire_rules_changed_and_refresh_cache() {
		$id     = $this->seed_cookie();
		$before = did_action( 'revolter_cookies_rules_changed' );
		$this->assertSame( array(), $this->repo->frontend_data()['cookie_names'] );
		$this->assertIsArray( get_option( Item_Repository::FRONTEND_CACHE ) );

		$page = new Overview_Page();
		$page->save_item(
			array(
				'id'       => $id,
				'category' => 'statistics',
			)
		);
		$this->assertGreaterThan( $before, did_action( 'revolter_cookies_rules_changed' ) );
		$this->assertFalse( get_option( Item_Repository::FRONTEND_CACHE ) );
		$this->assertSame( array( 'statistics' => array( '_ga' ) ), $this->repo->frontend_data()['cookie_names'] );
		$this->assertSame( 1, $this->repo->frontend_data()['counts']['statistics'] );

		$page->add_item(
			array(
				'name'     => 'manual_one',
				'type'     => 'cookie',
				'category' => 'marketing',
			)
		);
		$after = did_action( 'revolter_cookies_rules_changed' );
		$this->assertGreaterThan( $before, $after );
		$this->assertSame( array( 'manual_one' ), $this->repo->frontend_data()['cookie_names']['marketing'] );

		$page->delete_item( $this->repo->find( 'cookie', 'manual_one' )['id'] );
		$this->assertGreaterThan( $after, did_action( 'revolter_cookies_rules_changed' ) );
		$this->assertArrayNotHasKey( 'marketing', $this->repo->frontend_data()['cookie_names'] );
	}

	/**
	 * Saving visitor-facing settings announces a rules change; internal scan state does not.
	 */
	public function test_settings_save_fires_rules_changed() {
		$before = did_action( 'revolter_cookies_rules_changed' );
		Settings::set( 'last_scan', array( 'time' => 'x' ) );
		Settings::set( 'rescan_recommended', true );
		$this->assertSame( $before, did_action( 'revolter_cookies_rules_changed' ) );
		Settings::set( 'accent_color', '#7048ef' );
		$this->assertSame( $before + 1, did_action( 'revolter_cookies_rules_changed' ) );
		Settings::set(
			'manual_rules',
			array(
				array(
					'pattern'  => 'x.com',
					'category' => 'marketing',
				),
			)
		);
		$this->assertSame( $before + 2, did_action( 'revolter_cookies_rules_changed' ) );
	}

	/**
	 * Manual item.
	 */
	public function test_add_manual_item() {
		$ok  = ( new Overview_Page() )->add_item(
			array(
				'name'          => 'my_cookie',
				'type'          => 'cookie',
				'provider'      => 'Acme',
				'category'      => 'preferences',
				'duration_n'    => 2,
				'duration_unit' => 'year',
				'purpose_sv'    => 'Minns val',
				'purpose_en'    => 'Remembers choices',
				'third_party'   => 1,
			)
		);
		$row = $this->repo->find( 'cookie', 'my_cookie' );
		$this->assertTrue( $ok );
		$this->assertSame( 'manual', $row['source'] );
		$this->assertSame( 'declared', $row['status'] );
		$this->assertSame( '2:year', $row['duration'] );
		$this->assertSame( 1, $row['third_party'] );
		$this->assertSame( 'Remembers choices', $row['purpose_override']['en'] );

		$this->assertFalse(
			( new Overview_Page() )->add_item(
				array(
					'name'     => '',
					'category' => 'preferences',
				)
			)
		);
	}

	/**
	 * Only manual rows can be deleted.
	 */
	public function test_delete_only_manual() {
		$scan   = $this->seed_cookie();
		$manual = $this->repo->upsert(
			array(
				'type'   => 'cookie',
				'name'   => 'mine',
				'source' => 'manual',
			)
		);
		$page   = new Overview_Page();
		$this->assertFalse( $page->delete_item( $scan ) );
		$this->assertNotNull( $this->repo->get( $scan ) );
		$this->assertTrue( $page->delete_item( $manual ) );
		$this->assertNull( $this->repo->get( $manual ) );
	}

	/**
	 * Manual rules parse and validate.
	 */
	public function test_manual_rules_parsing() {
		$rules = Settings_Pages::parse_manual_rules( "hotjar.com | statistics\n\nbad.com | nonsense\nno-pipe\n  spaced.io|marketing  \n | marketing\n" );
		$this->assertSame(
			array(
				array(
					'pattern'  => 'hotjar.com',
					'category' => 'statistics',
				),
				array(
					'pattern'  => 'spaced.io',
					'category' => 'marketing',
				),
			),
			$rules
		);
		$long = Settings_Pages::parse_manual_rules( str_repeat( 'a', 300 ) . ' | marketing' );
		$this->assertSame( 200, strlen( $long[0]['pattern'] ) );
	}

	/**
	 * Settings sanitising merges and validates.
	 */
	public function test_accent_color_sanitized() {
		( new Settings_Pages() )->register_settings();
		Settings::set( 'consent_version', 4 );
		update_option(
			Settings::OPTION,
			array_merge(
				get_option( Settings::OPTION ),
				array(
					'accent_color'    => 'javascript:alert(1)',
					'button_position' => 'middle',
					'manual_rules'    => 'x.com | marketing',
					'contact_email'   => 'not-an-email',
					'gtm_category'    => 'necessary',
				)
			)
		);
		$all = Settings::all();
		$this->assertSame( '#2F4BC7', $all['accent_color'] );
		$this->assertSame( 'left', $all['button_position'] );
		$this->assertSame( 4, $all['consent_version'] );
		$this->assertSame( 'statistics', $all['gtm_category'] );
		$this->assertSame( '', 'not-an-email' === $all['contact_email'] ? 'x' : '' );
		$this->assertSame( 'x.com', $all['manual_rules'][0]['pattern'] );

		update_option( Settings::OPTION, array( 'accent_color' => '#7048ef' ) );
		$this->assertSame( '#7048ef', Settings::get( 'accent_color' ) );
		$this->assertSame( 4, Settings::get( 'consent_version' ), 'Other keys survive a partial save.' );
	}

	/**
	 * A settings form posts only its own keys; internal state is never taken from POST.
	 */
	public function test_options_form_cannot_set_internal_keys() {
		( new Settings_Pages() )->register_settings();
		Settings::set( 'consent_version', 3 );
		Settings::set( 'company_name', 'Kept AB' );
		$_POST['option_page'] = Settings_Pages::GROUP_BANNER;
		update_option(
			Settings::OPTION,
			array(
				'accent_color'       => '#7048ef',
				'consent_version'    => 999,
				'categories_in_use'  => array( 'necessary', 'marketing' ),
				'last_scan'          => array( 'x' => 1 ),
				'rescan_recommended' => true,
				'company_name'       => 'Crafted',
			)
		);
		$all = Settings::all();
		$this->assertSame( '#7048ef', $all['accent_color'] );
		$this->assertSame( 3, $all['consent_version'] );
		$this->assertSame( array( 'necessary' ), $all['categories_in_use'] );
		$this->assertSame( array(), $all['last_scan'] );
		$this->assertFalse( $all['rescan_recommended'] );
		$this->assertSame( 'Kept AB', $all['company_name'], 'The banner form cannot change company keys.' );

		$_POST['option_page'] = Settings_Pages::GROUP_COMPANY;
		update_option(
			Settings::OPTION,
			array(
				'company_name' => 'Ny AB',
				'accent_color' => '#000000',
			)
		);
		$this->assertSame( 'Ny AB', Settings::get( 'company_name' ) );
		$this->assertSame( '#7048ef', Settings::get( 'accent_color' ) );
	}

	/**
	 * Manual rules accept only categories that wait for consent.
	 */
	public function test_manual_rules_reject_necessary_and_unclassified() {
		$rules = Settings_Pages::parse_manual_rules( "a.com | necessary\nb.com | unclassified\nc.com | preferences\n" );
		$this->assertSame(
			array(
				array(
					'pattern'  => 'c.com',
					'category' => 'preferences',
				),
			),
			$rules
		);
	}

	/**
	 * CSV export honours the range and defaults.
	 */
	public function test_export_csv_range() {
		$log = new Consent_Log();
		$log->insert(
			array(
				'consent_id' => 'aaaa-1',
				'categories' => array( 'necessary' ),
				'action'     => 'accept_all',
				'language'   => 'sv',
			)
		);
		$today = gmdate( 'Y-m-d' );
		$csv   = ( new Log_Page() )->export_csv( $today, $today );
		$this->assertStringContainsString( 'aaaa-1', $csv );
		$this->assertStringStartsWith( 'consent_id,', $csv );
		$old = ( new Log_Page() )->export_csv( '2001-01-01', '2001-01-02' );
		$this->assertStringNotContainsString( 'aaaa-1', $old );
		$default = ( new Log_Page() )->export_csv( 'garbage', '' );
		$this->assertStringContainsString( 'aaaa-1', $default );
		$this->assertSame( array( '2001-01-01', '2001-01-02' ), Log_Page::normalise_range( '2001-01-02', '2001-01-01' ) );
	}

	/**
	 * A range export stops at the limit and reports it; one entry exports on its own with nonce and capability.
	 */
	public function test_export_limit_and_single_entry() {
		$log = new Consent_Log();
		$ids = array();
		foreach ( array( 'bbbb-1', 'bbbb-2', 'bbbb-3' ) as $consent_id ) {
			$ids[] = $log->insert(
				array(
					'consent_id' => $consent_id,
					'categories' => array( 'necessary' ),
					'action'     => 'accept_all',
					'language'   => 'sv',
				)
			);
		}
		$today = gmdate( 'Y-m-d' );
		$page  = new Log_Page( 2 );
		$csv   = $page->export_csv( $today, $today );
		$this->assertStringContainsString( 'bbbb-1', $csv );
		$this->assertStringContainsString( 'bbbb-2', $csv );
		$this->assertStringNotContainsString( 'bbbb-3', $csv );
		$this->assertTrue( $page->over_limit( $today, $today ) );
		$this->assertFalse( ( new Log_Page( 3 ) )->over_limit( $today, $today ) );
		$this->assertSame( Log_Page::EXPORT_LIMIT, 50000 );

		$one = $page->export_record_csv( $ids[2] );
		$this->assertStringContainsString( 'bbbb-3', $one );
		$this->assertStringNotContainsString( 'bbbb-1', $one );
		$this->assertNull( $page->export_record_csv( 999999 ) );

		$url = Log_Page::record_url( $ids[2] );
		$this->assertStringContainsString( 'action=revolter_cookies_export_record', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST = array(
			'id'       => $ids[2],
			'_wpnonce' => wp_create_nonce( 'revolter_cookies_export_record' ),
		);
		$this->expectException( WPDieException::class );
		$page->handle_export_record();
	}

	/**
	 * The log page shows an inline message after an export over the limit.
	 */
	public function test_log_page_truncated_message() {
		$this->as_admin();
		$_GET = array(
			'rc_truncated' => '1',
			'from'         => '2026-01-01',
			'to'           => '2026-01-31',
		);
		ob_start();
		( new Log_Page() )->render();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'This range has more than 50,000 entries.', $html );
		$this->assertStringContainsString( 'name="truncate" value="1"', $html );
		$this->assertStringContainsString( 'value="2026-01-01"', $html );
	}

	/**
	 * Menu exists and needs manage_options.
	 */
	public function test_menu_registered_for_admin_only() {
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		remove_all_actions( 'admin_menu' );
		$this->as_admin();
		( new Menu() )->register();
		do_action( 'admin_menu' );
		$found = null;
		foreach ( $menu as $entry ) {
			if ( 'revolter-cookies' === $entry[2] ) {
				$found = $entry;
			}
		}
		$this->assertNotNull( $found );
		$this->assertSame( 'manage_options', $found[1] );
		$slugs = wp_list_pluck( $submenu['revolter-cookies'], 2 );
		$this->assertSame( array( 'revolter-cookies', 'revolter-cookies-banner', 'revolter-cookies-company', 'revolter-cookies-log' ), $slugs );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( $found[1] ) );
	}
}
