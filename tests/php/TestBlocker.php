<?php
/**
 * Blocker and context tests.
 *
 * @package RevolterCookies
 */

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- HTML fixtures, not enqueued assets.

use RevolterCookies\Blocker;
use RevolterCookies\Blocking_Rules;
use RevolterCookies\Context;
use RevolterCookies\Html_Rewriter;
use RevolterCookies\Library;
use RevolterCookies\Settings;

/**
 * Verifies output buffering and front-end detection.
 */
class TestBlocker extends WP_UnitTestCase {

	/**
	 * Reset globals touched by the tests.
	 */
	public function tear_down() {
		unset( $_GET['elementor-preview'], $_GET['et_fb'], $_SERVER['HTTP_ACCEPT'] );
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ) {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		}
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_doing_cron' );
		Context::set_scan_mode_override( null );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Build a blocker with fake response headers.
	 *
	 * @param array $headers Header lines.
	 * @return Blocker
	 */
	private function blocker( array $headers = array() ): Blocker {
		return new Blocker(
			static function () {
				return new Html_Rewriter( Blocking_Rules::build( Library::from_file(), Settings::defaults(), array() ) );
			},
			static function () use ( $headers ) {
				return $headers;
			}
		);
	}

	/**
	 * Chunks after the one with <html are rewritten too, and a tag cut between chunks is held back.
	 */
	public function test_filter_output_rewrites_chunks_after_html() {
		$blocker = $this->blocker();
		$out     = $blocker->filter_output( '<!doctype html><html><head><title>T</title>', PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FLUSH );
		$out    .= $blocker->filter_output( '</head><body><script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><scr', PHP_OUTPUT_HANDLER_FLUSH );
		$out    .= $blocker->filter_output( 'ipt src="https://static.hotjar.com/c/hotjar.js"></script><script>gtag(\'config\',', PHP_OUTPUT_HANDLER_FLUSH );
		$out    .= $blocker->filter_output( '\'G-1\');</script><iframe src="https://www.youtube.com/embed/x"></iframe></body></html>', PHP_OUTPUT_HANDLER_FINAL );
		$this->assertSame( 4, substr_count( $out, 'data-rc-category=' ), $out );
		$this->assertSame( 3, substr_count( $out, 'type="text/plain"' ) );
		$this->assertStringContainsString( 'data-rc-src="https://static.hotjar.com/c/hotjar.js"', $out );
		$this->assertStringContainsString( 'data-rc-src="https://www.youtube.com/embed/x"', $out );
		$this->assertStringEndsWith( '</body></html>', $out );

		$next = $blocker->filter_output( '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>', PHP_OUTPUT_HANDLER_FINAL );
		$this->assertStringNotContainsString( 'text/plain', $next, 'A new buffer starts without the seen-html flag.' );

		$json = $this->blocker( array( 'Content-Type: application/json' ) );
		$raw  = '<html><script src="https://www.googletagmanager.com/gtag/js"></script>';
		$this->assertSame( $raw . $raw, $json->filter_output( $raw, PHP_OUTPUT_HANDLER_FLUSH ) . $json->filter_output( $raw, PHP_OUTPUT_HANDLER_FINAL ), 'Non-HTML content type wins.' );
	}

	/**
	 * An HTML page is rewritten.
	 */
	public function test_filter_output_rewrites_html() {
		$html = '<!doctype html><html><head></head><body><script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script></body></html>';
		$this->assertStringContainsString( 'type="text/plain"', $this->blocker()->filter_output( $html ) );
		$this->assertStringContainsString( 'type="text/plain"', $this->blocker( array( 'Content-Type: text/html; charset=UTF-8' ) )->filter_output( $html ) );
		$this->assertStringContainsString( 'type="text/plain"', $this->blocker( array( 'content-type:TEXT/HTML' ) )->filter_output( $html ) );
	}

	/**
	 * Non-HTML responses pass through byte-for-byte.
	 */
	public function test_filter_output_passes_non_html_unchanged() {
		$script  = '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>';
		$sitemap = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<?xml-stylesheet type="text/xsl" href="/wp-sitemap.xsl" ?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.org/</loc></url></urlset>' . "\n";
		$json    = wp_json_encode( array( 'content' => '<html><body>' . $script . '</body></html>' ) );
		$binary  = "\x89PNG\r\n\x1a\n\x00\x00<html>" . $script . "\xff\xfe\x00";
		$feed    = '<?xml version="1.0"?><rss><channel><description><![CDATA[<html>' . $script . ']]></description></channel></rss>';

		$this->assertSame( $sitemap, $this->blocker()->filter_output( $sitemap ) );
		$this->assertSame( $sitemap, $this->blocker( array( 'Content-Type: application/xml; charset=UTF-8' ) )->filter_output( $sitemap ) );
		$this->assertSame( $json, $this->blocker( array( 'Content-Type: application/json; charset=UTF-8' ) )->filter_output( $json ) );
		$this->assertSame( $binary, $this->blocker( array( 'X-Foo: bar', 'Content-Type: image/png' ) )->filter_output( $binary ) );
		$this->assertSame( $feed, $this->blocker( array( 'Content-Type: application/rss+xml; charset=UTF-8' ) )->filter_output( $feed ) );
		$this->assertSame( '', $this->blocker()->filter_output( '' ) );
		$this->assertSame( 'plain text', $this->blocker()->filter_output( 'plain text' ) );
	}

	/**
	 * A failing rewriter never breaks the page.
	 */
	public function test_filter_output_returns_original_on_error() {
		$blocker = new Blocker(
			static function () {
				throw new RuntimeException( 'boom' );
			},
			static function () {
				return array();
			}
		);
		$html    = '<html><body><script src="https://www.googletagmanager.com/gtag/js"></script></body></html>';
		$this->assertSame( $html, $blocker->filter_output( $html ) );
	}

	/**
	 * Front-end detection excludes non-page requests.
	 */
	public function test_not_buffered_for_rest_feed_ajax() {
		$post_id = self::factory()->post->create();

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( Context::is_frontend_page(), 'home' );

		$this->go_to( get_feed_link() );
		$this->assertFalse( Context::is_frontend_page(), 'feed' );

		$this->go_to( home_url( '/?robots=1' ) );
		$this->assertFalse( Context::is_frontend_page(), 'robots' );

		$this->go_to( home_url( '/?p=' . $post_id . '&tb=1' ) );
		$this->assertFalse( Context::is_frontend_page(), 'trackback' );

		$this->go_to( home_url( '/?p=' . $post_id . '&embed=true' ) );
		$this->assertFalse( Context::is_frontend_page(), 'embed' );

		// go_to() builds a fresh WP without the sitemap query var, so set it as core's sitemap router would.
		$this->go_to( home_url( '/' ) );
		set_query_var( 'sitemap', 'index' );
		$this->assertFalse( Context::is_frontend_page(), 'sitemap' );
		set_query_var( 'sitemap', '' );

		$this->go_to( home_url( '/' ) );
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/posts';
		$this->assertFalse( Context::is_frontend_page(), 'rest' );
		unset( $GLOBALS['wp']->query_vars['rest_route'] );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( Context::is_frontend_page(), 'ajax' );
		remove_all_filters( 'wp_doing_ajax' );

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( Context::is_frontend_page(), 'cron' );
		remove_all_filters( 'wp_doing_cron' );

		set_current_screen( 'dashboard' );
		$this->assertFalse( Context::is_frontend_page(), 'admin' );
		set_current_screen( 'front' );

		$this->assertTrue( Context::is_frontend_page(), 'home again' );
	}

	/**
	 * Request headers and preview params from anonymous visitors cannot skip blocking (cache safety).
	 */
	public function test_request_dependent_signals_do_not_skip_blocking_for_visitors() {
		$this->go_to( home_url( '/' ) );

		$_SERVER['HTTP_ACCEPT'] = 'application/json';
		$this->assertTrue( Context::is_frontend_page(), 'anonymous json accept' );
		unset( $_SERVER['HTTP_ACCEPT'] );

		$_GET['elementor-preview'] = '1';
		$this->assertTrue( Context::is_frontend_page(), 'anonymous elementor preview' );
		unset( $_GET['elementor-preview'] );

		$_GET['et_fb'] = '1';
		$this->assertTrue( Context::is_frontend_page(), 'anonymous divi builder' );
		unset( $_GET['et_fb'] );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_GET['elementor-preview'] = '1';
		$this->assertTrue( Context::is_frontend_page(), 'subscriber elementor preview' );
		unset( $_GET['elementor-preview'] );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$_GET['elementor-preview'] = '1';
		$this->assertFalse( Context::is_frontend_page(), 'editor elementor preview' );
		unset( $_GET['elementor-preview'] );
		$_GET['et_fb'] = '1';
		$this->assertFalse( Context::is_frontend_page(), 'editor divi builder' );
		unset( $_GET['et_fb'] );
		$this->assertTrue( Context::is_frontend_page(), 'editor plain page' );
		wp_set_current_user( 0 );
	}

	/**
	 * The buffer starts only on front-end pages.
	 */
	public function test_start_buffers_only_frontend() {
		$this->go_to( get_feed_link() );
		$level = ob_get_level();
		$this->blocker()->start();
		$this->assertSame( $level, ob_get_level() );

		$this->go_to( home_url( '/' ) );
		$this->blocker()->start();
		$this->assertSame( $level + 1, ob_get_level() );
		ob_end_clean();
	}

	/**
	 * Hooked at template_redirect -1000.
	 */
	public function test_register_hooks_template_redirect() {
		$blocker = $this->blocker();
		$blocker->register();
		$this->assertSame( -1000, has_action( 'template_redirect', array( $blocker, 'start' ) ) );
		remove_action( 'template_redirect', array( $blocker, 'start' ), -1000 );
	}

	/**
	 * Scan mode is null unless overridden in tests.
	 */
	public function test_scan_mode_override() {
		$this->assertNull( Context::scan_mode() );
		Context::set_scan_mode_override( 'accepted' );
		$this->assertSame( 'accepted', Context::scan_mode() );
		Context::set_scan_mode_override( null );
		$this->assertNull( Context::scan_mode() );
	}

	/**
	 * A rewriter that fails after a tail was held back sends every byte exactly once.
	 */
	public function test_filter_output_error_after_held_back_tail_sends_input_once() {
		$blocker = new Blocker(
			static function () {
				return new class( Blocking_Rules::build( Library::from_file(), Settings::defaults(), array() ) ) extends Html_Rewriter {
					/**
					 * Always fail.
					 *
					 * @param string $html HTML.
					 * @throws RuntimeException Always.
					 */
					public function rewrite( string $html ): string {
						throw new RuntimeException( 'boom' );
					}
				};
			},
			static function () {
				return array();
			}
		);
		$first   = '<!doctype html><html><head><title>T</title></head><body><p>a</p><scr';
		$second  = 'ipt src="https://static.hotjar.com/c/hotjar.js"></script></body></html>';
		$out     = $blocker->filter_output( $first, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FLUSH );
		$out    .= $blocker->filter_output( $second, PHP_OUTPUT_HANDLER_FINAL );
		$this->assertSame( $first . $second, $out );
	}
}
