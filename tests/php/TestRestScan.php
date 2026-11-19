<?php
/**
 * Scan REST tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Installer;
use RevolterCookies\Rest_Scan;
use RevolterCookies\Scanner;

/**
 * Verifies the admin-only scan endpoints.
 */
class TestRestScan extends WP_UnitTestCase {

	/**
	 * Admin id.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Register routes.
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		$this->admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		( new Rest_Scan( new Scanner() ) )->register();
		rest_get_server();
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Dispatch.
	 *
	 * @param string $route  Route suffix.
	 * @param array  $params Body params.
	 * @return WP_REST_Response
	 */
	private function post( string $route, array $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/revolter-cookies/v1/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Only administrators.
	 */
	public function test_rest_requires_manage_options() {
		foreach ( array( 'scan/start', 'scan/page', 'scan/server', 'scan/finish' ) as $route ) {
			wp_set_current_user( 0 );
			$this->assertContains( $this->post( $route )->get_status(), array( 401, 403 ), $route );
		}
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->post( 'scan/start' )->get_status() );

		wp_set_current_user( $this->admin );
		$res = $this->post( 'scan/start' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'token', $res->get_data() );
	}

	/**
	 * Page results go into the session.
	 */
	public function test_page_results_merged_by_mode() {
		wp_set_current_user( $this->admin );
		$this->post( 'scan/start' );
		$url = home_url( '/' );
		$res = $this->post(
			'scan/page',
			array(
				'url'       => $url,
				'mode'      => 'none',
				'cookies'   => array( 'a', '<b>b</b>' ),
				'storage'   => array( 's' ),
				'resources' => array( 'https://x.example/a.js', '', 'javascript:alert(1)' ),
				'status'    => 'ok',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$page = ( new Scanner() )->session( $this->admin )['results'][ $url ];
		$this->assertSame( array( 'a', 'b' ), $page['cookies_none'] );
		$this->assertSame( array( 'https://x.example/a.js' ), $page['resources'] );
	}

	/**
	 * Resources may be objects with a type; only scripts and iframes are listed as embeds.
	 */
	public function test_page_resources_accept_typed_objects() {
		wp_set_current_user( $this->admin );
		$this->post( 'scan/start' );
		$url = home_url( '/' );
		$res = $this->post(
			'scan/page',
			array(
				'url'       => $url,
				'mode'      => 'none',
				'status'    => 'ok',
				'resources' => array(
					array(
						'url'  => 'https://secure.gravatar.com/avatar/a',
						'type' => 'img',
					),
					array(
						'url'  => 'https://x.example/a.js',
						'type' => 'script',
					),
					array(
						'url'  => 'https://y.example/frame',
						'type' => 'subdocument',
					),
					array(
						'url'  => 'javascript:alert(1)',
						'type' => 'script',
					),
					array( 'type' => 'script' ),
					'https://legacy.example/b.js',
					42,
				),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$page = ( new Scanner() )->session( $this->admin )['results'][ $url ];
		$this->assertSame( array( 'https://secure.gravatar.com/avatar/a', 'https://x.example/a.js', 'https://y.example/frame', 'https://legacy.example/b.js' ), $page['resources'] );
		$this->assertSame( array( 'https://x.example/a.js', 'https://y.example/frame', 'https://legacy.example/b.js' ), $page['embeds'] );
	}

	/**
	 * Bad input is a 400.
	 */
	public function test_page_rejects_bad_input() {
		wp_set_current_user( $this->admin );
		$this->post( 'scan/start' );
		$ok = array(
			'url'       => home_url( '/' ),
			'mode'      => 'none',
			'cookies'   => array(),
			'storage'   => array(),
			'resources' => array(),
			'status'    => 'ok',
		);
		$this->assertSame( 400, $this->post( 'scan/page', array_merge( $ok, array( 'url' => 'https://evil.example/' ) ) )->get_status() );
		$this->assertSame( 400, $this->post( 'scan/page', array_merge( $ok, array( 'url' => home_url( '/not-in-list/' ) ) ) )->get_status() );
		$this->assertSame( 400, $this->post( 'scan/page', array_merge( $ok, array( 'mode' => 'both' ) ) )->get_status() );
		$this->assertSame( 400, $this->post( 'scan/page', array_merge( $ok, array( 'status' => 'meh' ) ) )->get_status() );
	}

	/**
	 * Arrays and strings are capped.
	 */
	public function test_page_caps_sizes() {
		wp_set_current_user( $this->admin );
		$this->post( 'scan/start' );
		$url = home_url( '/' );
		$this->post(
			'scan/page',
			array(
				'url'       => $url,
				'mode'      => 'accepted',
				'cookies'   => array_merge( array( str_repeat( 'x', 5000 ) ), array_map( 'strval', range( 1, 700 ) ) ),
				'storage'   => array(),
				'resources' => array(),
				'status'    => 'ok',
			)
		);
		$cookies = ( new Scanner() )->session( $this->admin )['results'][ $url ]['cookies_accepted'];
		$this->assertLessThanOrEqual( 500, count( $cookies ) );
		$this->assertLessThanOrEqual( 2048, strlen( $cookies[0] ) );
	}

	/**
	 * Server cookies: foreign host is a 400 and never fetched.
	 */
	public function test_server_endpoint_rejects_foreign_and_merges_own() {
		wp_set_current_user( $this->admin );
		$this->post( 'scan/start' );
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
		$this->assertSame( 400, $this->post( 'scan/server', array( 'url' => 'https://evil.example/' ) )->get_status() );
		$this->assertSame( 0, $calls );
		$res = $this->post( 'scan/server', array( 'url' => home_url( '/' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'srv' ), $res->get_data()['cookies'] );
		$this->assertSame( array( 'srv' ), ( new Scanner() )->session( $this->admin )['results'][ home_url( '/' ) ]['server_cookies'] );
	}

	/**
	 * Finish without a session conflicts; with one returns the summary.
	 */
	public function test_finish() {
		wp_set_current_user( $this->admin );
		$this->assertSame( 409, $this->post( 'scan/finish' )->get_status() );
		$this->post( 'scan/start' );
		$res = $this->post( 'scan/finish' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['all_failed'] );
	}

	/**
	 * Resource URLs keep their percent-encoding.
	 */
	public function test_resource_urls_keep_percent_encoding() {
		wp_set_current_user( $this->admin );
		$this->post( 'scan/start' );
		$this->post(
			'scan/page',
			array(
				'url'       => home_url( '/' ),
				'mode'      => 'none',
				'cookies'   => array(),
				'storage'   => array(),
				'resources' => array( 'https://x.example/a%20b.js?q=%E2%9C%93' ),
				'status'    => 'ok',
			)
		);
		$page = ( new Scanner() )->session( $this->admin )['results'][ home_url( '/' ) ];
		$this->assertSame( array( 'https://x.example/a%20b.js?q=%E2%9C%93' ), $page['resources'] );
	}
}
