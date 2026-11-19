<?php
/**
 * Scanner backend tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Context;
use RevolterCookies\Installer;
use RevolterCookies\Scanner;
use RevolterCookies\Settings;

/**
 * Verifies the page list, scan sessions, scan mode and rescan hints.
 */
class TestScanner extends WP_UnitTestCase {

	/**
	 * Clean state.
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		global $wpdb;
		$table = Installer::items_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( 'revolter_cookies_settings' );
		Context::set_scan_mode_override( null );
		Context::reset_scan_mode_cache();
		unset( $_GET['rc_scan'], $_GET['rc_scan_mode'] );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		unset( $_GET['rc_scan'], $_GET['rc_scan_mode'] );
		Context::reset_scan_mode_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Home first, cap respected, deduped.
	 */
	public function test_page_list_starts_with_home_and_respects_cap() {
		$this->factory()->post->create_many( 5, array( 'post_type' => 'page' ) );
		$list = ( new Scanner() )->page_list( 3 );
		$this->assertCount( 3, $list );
		$this->assertSame( home_url( '/' ), $list[0] );
		$this->assertSame( $list, array_values( array_unique( $list ) ) );
	}

	/**
	 * Pages, CPT samples and a term archive; no drafts or attachments.
	 */
	public function test_page_list_includes_pages_and_cpt_samples() {
		register_post_type(
			'rc_thing',
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);
		$page   = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		$draft  = $this->factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$things = $this->factory()->post->create_many( 5, array( 'post_type' => 'rc_thing' ) );
		$cat    = $this->factory()->category->create();
		$post   = $this->factory()->post->create( array( 'post_category' => array( $cat ) ) );
		$att    = $this->factory()->attachment->create_object(
			'x.jpg',
			$post,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);

		$list = ( new Scanner() )->page_list( 50 );

		$this->assertContains( get_permalink( $page ), $list );
		$this->assertNotContains( get_permalink( $draft ), $list );
		$this->assertNotContains( get_permalink( $att ), $list );
		$thing_urls = array_filter(
			$list,
			static function ( $u ) use ( $things ) {
				foreach ( $things as $id ) {
					if ( get_permalink( $id ) === $u ) {
						return true;
					}
				}
				return false;
			}
		);
		$this->assertCount( 3, $thing_urls );
		$this->assertContains( get_term_link( $cat ), $list );
		unregister_post_type( 'rc_thing' );
	}

	/**
	 * Builder template libraries are skipped, landing pages are kept, and the filter decides the rest.
	 */
	public function test_page_list_skips_builder_templates_and_honours_filter() {
		$public = array(
			'public'             => true,
			'publicly_queryable' => true,
		);
		register_post_type( 'elementor_library', $public );
		register_post_type( 'e-landing-page', $public );
		$template = $this->factory()->post->create( array( 'post_type' => 'elementor_library' ) );
		$landing  = $this->factory()->post->create( array( 'post_type' => 'e-landing-page' ) );
		$post     = $this->factory()->post->create();

		$list = ( new Scanner() )->page_list( 50 );
		$this->assertNotContains( get_permalink( $template ), $list );
		$this->assertContains( get_permalink( $landing ), $list );
		$this->assertContains( get_permalink( $post ), $list );

		$only_landing = static function () {
			return array( 'e-landing-page', 'no-such-type' );
		};
		add_filter( 'revolter_cookies_scan_post_types', $only_landing );
		$list = ( new Scanner() )->page_list( 50 );
		remove_filter( 'revolter_cookies_scan_post_types', $only_landing );
		$this->assertContains( get_permalink( $landing ), $list );
		$this->assertNotContains( get_permalink( $post ), $list );

		unregister_post_type( 'elementor_library' );
		unregister_post_type( 'e-landing-page' );
	}

	/**
	 * Token must match the current user's session, and the user must manage options.
	 */
	public function test_scan_mode_requires_matching_token_and_user() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$other = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$sub   = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$scan  = new Scanner();
		$sess  = $scan->start( $admin );

		$_GET['rc_scan']      = $sess['token'];
		$_GET['rc_scan_mode'] = 'accepted';

		wp_set_current_user( $admin );
		Context::reset_scan_mode_cache();
		$this->assertSame( 'accepted', Context::scan_mode() );

		wp_set_current_user( $other );
		Context::reset_scan_mode_cache();
		$this->assertNull( Context::scan_mode() );

		wp_set_current_user( $sub );
		Context::reset_scan_mode_cache();
		$this->assertNull( Context::scan_mode() );

		wp_set_current_user( 0 );
		Context::reset_scan_mode_cache();
		$this->assertNull( Context::scan_mode() );

		wp_set_current_user( $admin );
		$_GET['rc_scan'] = 'wrong';
		Context::reset_scan_mode_cache();
		$this->assertNull( Context::scan_mode() );

		$_GET['rc_scan']      = $sess['token'];
		$_GET['rc_scan_mode'] = 'bogus';
		Context::reset_scan_mode_cache();
		$this->assertNull( Context::scan_mode() );
	}

	/**
	 * No session means no scan mode.
	 */
	public function test_scan_mode_null_without_session() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_GET['rc_scan']      = 'whatever';
		$_GET['rc_scan_mode'] = 'none';
		Context::reset_scan_mode_cache();
		$this->assertNull( Context::scan_mode() );
	}

	/**
	 * Start twice returns the same token and flags resumed.
	 */
	public function test_start_resumes_existing_session() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$a     = $scan->start( $admin );
		$b     = $scan->start( $admin );
		$this->assertFalse( $a['resumed'] );
		$this->assertTrue( $b['resumed'] );
		$this->assertSame( $a['token'], $b['token'] );
		$this->assertSame( 32, strlen( $a['token'] ) );
		$this->assertSame( $a['urls'], $b['urls'] );
	}

	/**
	 * Results merge by mode, resources union.
	 */
	public function test_page_results_merged_by_mode() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$url   = home_url( '/' );
		$scan->start( $admin );
		$scan->add_page_result(
			$admin,
			array(
				'url'       => $url,
				'mode'      => 'none',
				'cookies'   => array( 'a' ),
				'storage'   => array( 's1' ),
				'resources' => array( 'https://x.example/a.js' ),
				'status'    => 'ok',
			)
		);
		$scan->add_page_result(
			$admin,
			array(
				'url'       => $url,
				'mode'      => 'accepted',
				'cookies'   => array( 'a', 'b' ),
				'storage'   => array( 's2' ),
				'resources' => array( 'https://y.example/b.js' ),
				'status'    => 'ok',
			)
		);
		$page = $scan->session( $admin )['results'][ $url ];
		$this->assertSame( array( 'a' ), $page['cookies_none'] );
		$this->assertSame( array( 'a', 'b' ), $page['cookies_accepted'] );
		$this->assertSame( array( 's1', 's2' ), $page['storage'] );
		$this->assertCount( 2, $page['resources'] );
		$this->assertSame( 'ok', $page['status'] );
	}

	/**
	 * A foreign URL is never fetched.
	 */
	public function test_server_cookies_rejects_foreign_host() {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 200 ),
					'cookies'  => array( new WP_Http_Cookie( array( 'name' => 'srv' ) ) ),
				);
			}
		);
		$scan = new Scanner();
		$this->assertSame( array(), $scan->server_cookies( 'https://evil.example/' ) );
		$this->assertSame( array(), $scan->server_cookies( 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.evil.example/' ) );
		$this->assertSame( 0, $calls );
		$this->assertSame( array( 'srv' ), $scan->server_cookies( home_url( '/' ) ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Finish persists results and removes the session.
	 */
	public function test_finish_applies_and_clears_session() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$url   = home_url( '/' );
		$sess  = $scan->start( $admin );
		$scan->add_page_result(
			$admin,
			array(
				'url'       => $url,
				'mode'      => 'accepted',
				'cookies'   => array( '_ga' ),
				'storage'   => array(),
				'resources' => array(),
				'status'    => 'ok',
			)
		);
		$summary = $scan->finish( $admin );
		$this->assertSame( count( $sess['urls'] ), $summary['pages'] );
		$this->assertSame( count( $sess['urls'] ) - 1, $summary['failed'] );
		$this->assertFalse( $summary['all_failed'] );
		$this->assertGreaterThanOrEqual( 1, $summary['declared'] + $summary['needs_review'] );
		$this->assertNull( $scan->session( $admin ) );
		$this->assertNotEmpty( Settings::get( 'last_scan' ) );
	}

	/**
	 * An all-failed scan leaves the recommendation alone.
	 */
	public function test_all_failed_finish_keeps_rescan_recommended() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$scan->start( $admin );
		Settings::set( 'rescan_recommended', true );
		$summary = $scan->finish( $admin );
		$this->assertTrue( $summary['all_failed'] );
		$this->assertTrue( Settings::get( 'rescan_recommended' ) );
		$this->assertSame( $summary['pages'], $summary['failed'] );
	}

	/**
	 * Successful finish clears the recommendation.
	 */
	public function test_successful_finish_clears_rescan_recommended() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$scan->start( $admin );
		$scan->add_page_result(
			$admin,
			array(
				'url'       => home_url( '/' ),
				'mode'      => 'none',
				'cookies'   => array(),
				'storage'   => array(),
				'resources' => array(),
				'status'    => 'ok',
			)
		);
		Settings::set( 'rescan_recommended', true );
		$scan->finish( $admin );
		$this->assertFalse( Settings::get( 'rescan_recommended' ) );
	}

	/**
	 * Plugin and theme changes recommend a rescan; this plugin itself does not.
	 */
	public function test_plugin_activation_sets_rescan_recommended() {
		// Core's own listeners expect a real upgrader object.
		remove_all_actions( 'upgrader_process_complete' );
		( new Scanner() )->register();
		Settings::set( 'rescan_recommended', false );
		do_action( 'activated_plugin', plugin_basename( REVOLTER_COOKIES_FILE ) );
		$this->assertFalse( Settings::get( 'rescan_recommended' ) );
		do_action( 'activated_plugin', 'other/other.php' );
		$this->assertTrue( Settings::get( 'rescan_recommended' ) );

		Settings::set( 'rescan_recommended', false );
		do_action( 'deactivated_plugin', 'other/other.php' );
		$this->assertTrue( Settings::get( 'rescan_recommended' ) );

		Settings::set( 'rescan_recommended', false );
		do_action( 'switch_theme' );
		$this->assertTrue( Settings::get( 'rescan_recommended' ) );

		Settings::set( 'rescan_recommended', false );
		do_action( 'upgrader_process_complete', null, array() );
		$this->assertTrue( Settings::get( 'rescan_recommended' ) );
	}

	/**
	 * Stub HTTP: map URL => array( code, location, cookies ); logs requested URLs.
	 *
	 * @param array $map Responses.
	 * @param array $log Request log (by reference).
	 */
	private function stub_http( array $map, array &$log ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $map, &$log ) {
				unset( $pre, $args );
				$log[] = $url;
				$row   = isset( $map[ $url ] ) ? $map[ $url ] : array( 404, '', array() );
				$ck    = array();
				foreach ( $row[2] as $name ) {
					$ck[] = new WP_Http_Cookie( array( 'name' => $name ) );
				}
				return array(
					'headers'  => '' !== $row[1] ? array( 'location' => $row[1] ) : array(),
					'body'     => '',
					'response' => array( 'code' => $row[0] ),
					'cookies'  => $ck,
				);
			},
			10,
			3
		);
	}

	/**
	 * An off-site redirect is never fetched and contributes nothing.
	 */
	public function test_server_cookies_does_not_follow_offsite_redirect() {
		$home = home_url( '/' );
		$log  = array();
		$this->stub_http(
			array(
				$home                   => array( 302, 'https://evil.example/', array( 'mine' ) ),
				'https://evil.example/' => array( 200, '', array( 'foreign' ) ),
			),
			$log
		);
		$this->assertSame( array( 'mine' ), ( new Scanner() )->server_cookies( $home ) );
		$this->assertSame( array( $home ), $log );
	}

	/**
	 * Same-host redirects (absolute or relative) are followed, cookies collected from each hop.
	 */
	public function test_server_cookies_follows_same_host_redirects() {
		$home = home_url( '/' );
		$log  = array();
		$this->stub_http(
			array(
				$home             => array( 301, home_url( '/b/' ), array( 'one' ) ),
				home_url( '/b/' ) => array( 302, '/c/', array( 'two' ) ),
				home_url( '/c/' ) => array( 200, '', array( 'three' ) ),
			),
			$log
		);
		$this->assertSame( array( 'one', 'two', 'three' ), ( new Scanner() )->server_cookies( $home ) );
		$this->assertCount( 3, $log );
	}

	/**
	 * Redirect chains stop after three hops.
	 */
	public function test_server_cookies_limits_redirect_hops() {
		$log = array();
		$map = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$map[ home_url( '/h' . $i . '/' ) ] = array( 302, home_url( '/h' . ( $i + 1 ) . '/' ), array( 'c' . $i ) );
		}
		$this->stub_http( $map, $log );
		( new Scanner() )->server_cookies( home_url( '/h0/' ) );
		$this->assertCount( 4, $log );
	}

	/**
	 * A different port is a different site.
	 */
	public function test_is_site_url_checks_port() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertTrue( Scanner::is_site_url( 'https://' . $host . '/x' ) );
		$this->assertTrue( Scanner::is_site_url( 'http://' . $host . ':80/x' ) );
		$this->assertFalse( Scanner::is_site_url( 'https://' . $host . ':8443/x' ) );
	}

	/**
	 * Merged lists are capped after the union.
	 */
	public function test_merged_lists_are_capped() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$url   = home_url( '/' );
		$scan->start( $admin );
		foreach ( array( 'a', 'b' ) as $p ) {
			$scan->add_page_result(
				$admin,
				array(
					'url'     => $url,
					'mode'    => 'none',
					'status'  => 'ok',
					'cookies' => array_map(
						static function ( $n ) use ( $p ) {
							return $p . $n;
						},
						range( 1, 400 )
					),
				)
			);
		}
		$this->assertCount( 500, $scan->session( $admin )['results'][ $url ]['cookies_none'] );
	}

	/**
	 * Early scan-mode start sends no-cache once for a valid token; override still wins.
	 */
	public function test_maybe_start_scan_mode_is_cache_safe() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$sess  = ( new Scanner() )->start( $admin );
		wp_set_current_user( $admin );
		$count = 0;
		// nocache_headers() itself is a no-op once PHPUnit has sent output, so observe the start hook.
		add_action(
			'revolter_cookies_scan_mode_started',
			static function () use ( &$count ) {
				++$count;
			}
		);

		$_GET['rc_scan']      = 'wrong';
		$_GET['rc_scan_mode'] = 'none';
		Context::reset_scan_mode_cache();
		Context::maybe_start_scan_mode();
		$this->assertSame( 0, $count );

		$_GET['rc_scan'] = $sess['token'];
		Context::reset_scan_mode_cache();
		Context::maybe_start_scan_mode();
		Context::maybe_start_scan_mode();
		$this->assertSame( 1, $count );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );

		unset( $_GET['rc_scan'], $_GET['rc_scan_mode'] );
		Context::set_scan_mode_override( 'accepted' );
		$this->assertSame( 'accepted', Context::scan_mode() );
	}

	/**
	 * The early hook is registered before the Blocker's.
	 */
	public function test_scan_mode_hook_registered_early() {
		( new Scanner() )->register();
		$this->assertSame( -2000, has_action( 'template_redirect', array( 'RevolterCookies\\Context', 'maybe_start_scan_mode' ) ) );
	}

	/**
	 * Start lists the URLs reported in both modes, so a resumed run can skip them.
	 */
	public function test_start_reports_done_urls() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$first = $scan->start( $admin );
		$this->assertSame( array(), $first['done'] );
		$url = $first['urls'][0];

		$scan->add_page_result(
			$admin,
			array(
				'url'    => $url,
				'mode'   => 'none',
				'status' => 'ok',
			)
		);
		$this->assertSame( array(), $scan->start( $admin )['done'], 'one mode is not done' );

		$scan->add_page_result(
			$admin,
			array(
				'url'    => $url,
				'mode'   => 'accepted',
				'status' => 'failed',
			)
		);
		$again = $scan->start( $admin );
		$this->assertTrue( $again['resumed'] );
		$this->assertSame( array( $url ), $again['done'] );
		$this->assertSame( array( 'token', 'urls', 'resumed', 'done' ), array_keys( $again ) );
	}

	/**
	 * The admin runner is registered (not enqueued) for administrators, with its localized data.
	 */
	public function test_admin_scan_script_registered_with_l10n() {
		( new Scanner() )->register();
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( 'RevolterCookies\\Scanner', 'register_admin_script' ) ) );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'editor' ) ) );
		Scanner::register_admin_script();
		$this->assertFalse( wp_script_is( 'rc-admin-scan', 'registered' ) );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		Scanner::register_admin_script();
		$this->assertTrue( wp_script_is( 'rc-admin-scan', 'registered' ) );
		$this->assertFalse( wp_script_is( 'rc-admin-scan', 'enqueued' ) );
		$script = wp_scripts()->registered['rc-admin-scan'];
		$this->assertStringEndsWith( 'assets/js/admin-scan.js', $script->src );
		$this->assertSame( 1, $script->extra['group'] );

		$data = Scanner::admin_script_data();
		$this->assertSame( rest_url( 'revolter-cookies/v1/' ), $data['rest'] );
		$this->assertSame( 1, wp_verify_nonce( $data['nonce'], 'wp_rest' ) );
		$this->assertSame( 'Scanning page %1$d of %2$d (%3$s)', $data['strings']['progress'] );
		$this->assertSame( 'checking without consent', $data['strings']['phaseNone'] );
		$this->assertSame( 'checking with consent', $data['strings']['phaseAccepted'] );
		foreach ( array( 'phaseServer', 'finishing', 'pageFailed', 'saveFailed', 'startFailed', 'finishFailed', 'summary', 'allFailed' ) as $key ) {
			$this->assertIsString( $data['strings'][ $key ] );
			$this->assertNotSame( '', $data['strings'][ $key ] );
		}
		$localized = wp_scripts()->get_data( 'rc-admin-scan', 'data' );
		$this->assertStringContainsString( 'var RevolterCookiesScanL10n = {', $localized );
		wp_deregister_script( 'rc-admin-scan' );
	}

	/**
	 * Clearing sessions also ends those the options-table sweep cannot see (persistent object cache).
	 */
	public function test_clear_sessions_ends_sessions_outside_options_table() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$scan  = new Scanner();
		$scan->start( $admin );
		$session = $scan->session( $admin );
		$this->assertIsArray( $session );

		Scanner::clear_sessions();
		// Simulate an object-cache copy surviving the sweep.
		set_transient( Scanner::SESSION_PREFIX . $admin, $session, Scanner::SESSION_TTL );

		$this->assertNull( $scan->session( $admin ) );
		$this->assertFalse( $scan->start( $admin )['resumed'] );
	}
}
