<?php
/**
 * Banner tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Banner;
use RevolterCookies\Context;
use RevolterCookies\Installer;
use RevolterCookies\Item_Repository;
use RevolterCookies\Plugin;
use RevolterCookies\Settings;

/**
 * Verifies the banner markup, assets and config.
 */
class TestBanner extends WP_UnitTestCase {

	/**
	 * Fresh table and settings.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		Installer::activate();
		$table = Installer::items_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( Settings::OPTION );
		$this->go_to( home_url( '/' ) );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		unset( $_COOKIE['rc_consent'] );
		Context::set_scan_mode_override( null );
		remove_all_filters( 'revolter_cookies_show_credit' );
		parent::tear_down();
	}

	/**
	 * The Banner module registered at boot.
	 *
	 * @return Banner
	 */
	private function module(): Banner {
		foreach ( Plugin::instance()->modules() as $module ) {
			if ( $module instanceof Banner ) {
				return $module;
			}
		}
		$this->fail( 'Banner module not registered.' );
	}

	/**
	 * Output of the footer callback.
	 *
	 * @return string
	 */
	private function footer(): string {
		ob_start();
		$this->module()->print_banner();
		return (string) ob_get_clean();
	}

	/**
	 * Declared cookie fixture.
	 *
	 * @param string $name     Name.
	 * @param string $category Category.
	 */
	private function declare_cookie( string $name, string $category ): void {
		( new Item_Repository() )->upsert(
			array(
				'type'     => 'cookie',
				'name'     => $name,
				'category' => $category,
				'status'   => 'declared',
				'source'   => 'library',
			)
		);
	}

	/**
	 * Footer hook at priority 5 prints the root with banner, modal and settings button.
	 */
	public function test_banner_rendered_in_footer_on_frontend() {
		$this->assertSame( 5, has_action( 'wp_footer', array( $this->module(), 'print_banner' ) ) );
		$out = $this->footer();
		$this->assertStringContainsString( '<div id="rc-root"', $out );
		$this->assertMatchesRegularExpression( '/<div id="rc-banner" class="rc-banner" role="region" aria-label="We use cookies" hidden>/', $out );
		$this->assertMatchesRegularExpression( '/<div id="rc-modal" class="rc-modal" role="dialog" aria-modal="true" aria-labelledby="rc-modal-title" hidden>/', $out );
		$this->assertMatchesRegularExpression( '/<button type="button" id="rc-settings-button" class="rc-settings-button rc-pos-left" aria-label="Cookie settings" hidden>/', $out );
		foreach ( array( 'accept-all', 'reject-all', 'customize', 'save', 'close' ) as $action ) {
			$this->assertStringContainsString( 'data-rc-action="' . $action . '"', $out );
		}
		$this->assertSame( 2, substr_count( $out, 'data-rc-action="accept-all"' ) );
		$this->assertSame( 2, substr_count( $out, 'data-rc-action="reject-all"' ) );
		$this->assertStringContainsString( '<h2 id="rc-modal-title"', $out );

		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( 'rc-consent', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'rc-consent', 'enqueued' ) );
		$inline = wp_scripts()->get_data( 'rc-consent', 'before' );
		$this->assertStringContainsString( 'window.RevolterCookiesConfig={', implode( "\n", (array) $inline ) );
	}

	/**
	 * Nothing is printed in scan mode or outside front-end pages.
	 */
	public function test_banner_not_rendered_in_scan_mode() {
		Context::set_scan_mode_override( 'accepted' );
		$this->assertSame( '', $this->footer() );
		Context::set_scan_mode_override( 'none' );
		$this->assertSame( '', $this->footer() );
		Context::set_scan_mode_override( null );
		$this->assertStringContainsString( 'id="rc-root"', $this->footer() );

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->footer() );
		set_current_screen( 'front' );
	}

	/**
	 * Rows for necessary plus categories in use, never unclassified, in canonical order.
	 */
	public function test_modal_lists_only_categories_in_use_plus_necessary() {
		Settings::set( 'categories_in_use', array( 'statistics', 'unclassified' ) );
		$this->declare_cookie( '_ga', 'statistics' );
		$this->declare_cookie( '_gid', 'statistics' );
		$this->declare_cookie( 'rc_consent', 'necessary' );
		$this->declare_cookie( '_fbp', 'marketing' );

		$out = $this->footer();
		$this->assertSame( 2, substr_count( $out, 'data-rc-category=' ) );
		$this->assertStringContainsString( 'data-rc-category="necessary"', $out );
		$this->assertStringContainsString( 'data-rc-category="statistics"', $out );
		$this->assertStringNotContainsString( 'data-rc-category="marketing"', $out );
		$this->assertStringNotContainsString( 'data-rc-category="unclassified"', $out );
		$this->assertLessThan( strpos( $out, 'data-rc-category="statistics"' ), strpos( $out, 'data-rc-category="necessary"' ) );
		$this->assertStringContainsString( 'Always on · 1 cookie', $out );
		$this->assertStringContainsString( '2 cookies', $out );
		$this->assertStringContainsString( '<label for="rc-cat-statistics"', $out );

		$config = Banner::config();
		$this->assertSame(
			array(
				array(
					'key'   => 'necessary',
					'count' => 1,
				),
				array(
					'key'   => 'statistics',
					'count' => 2,
				),
			),
			$config['categories']
		);
	}

	/**
	 * Necessary is checked and disabled; others are off.
	 */
	public function test_necessary_checkbox_disabled_checked() {
		Settings::set( 'categories_in_use', array( 'necessary', 'marketing' ) );
		$out = $this->footer();
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rc-cat-necessary" class="rc-switch-input" data-rc-category="necessary"[^>]* checked disabled>/', $out );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rc-cat-marketing" class="rc-switch-input" data-rc-category="marketing"[^>]*>/', $out );
		preg_match( '/<input type="checkbox" id="rc-cat-marketing"[^>]*>/', $out, $m );
		$this->assertStringNotContainsString( 'checked', $m[0] );
		$this->assertStringNotContainsString( 'disabled', $m[0] );
	}

	/**
	 * Credit row in banner and modal; the filter hides it.
	 */
	public function test_credit_rendered_and_filter_hides_it() {
		$out = $this->footer();
		$this->assertSame( 2, substr_count( $out, '<div class="rc-credit"><a href="https://revolter.se" rel="noopener">Made with care by <svg class="rc-credit-logo"' ) );
		$this->assertStringContainsString( 'aria-hidden="true" focusable="false"', $out );
		$this->assertStringContainsString( 'fill="var(--rc-credit-accent, #7048ef)"', $out );

		add_filter( 'revolter_cookies_show_credit', '__return_false' );
		$out = $this->footer();
		$this->assertStringNotContainsString( 'rc-credit', $out );
		$this->assertStringNotContainsString( 'revolter.se', $out );
	}

	/**
	 * Config and markup are identical with or without a consent cookie (full-page caches).
	 */
	public function test_config_contains_no_consent_specific_data() {
		Settings::set( 'categories_in_use', array( 'necessary', 'statistics' ) );
		$this->declare_cookie( '_ga', 'statistics' );
		unset( $_COOKIE['rc_consent'] );
		$config = Banner::config();
		$markup = $this->footer();

		$_COOKIE['rc_consent'] = wp_json_encode(
			array(
				'v'   => 1,
				'id'  => '0b5f3a2e-1111-4222-8333-444455556666',
				'ts'  => time(),
				'cat' => array( 'statistics' => true ),
			)
		);
		$this->assertEquals( $config, Banner::config() );
		$this->assertSame( wp_json_encode( $config ), wp_json_encode( Banner::config() ) );
		$this->assertSame( $markup, $this->footer() );

		$this->assertSame( array( 'version', 'rest', 'policyUrl', 'lang', 'categories', 'cookieNames', 'strings', 'position', 'wpConsentApi', 'scanMode' ), array_keys( $config ) );
		$this->assertNull( $config['scanMode'] );
		$this->assertSame( 1, $config['version'] );
		$this->assertSame( rest_url( 'revolter-cookies/v1/consent' ), $config['rest'] );
		$this->assertSame( 'en', $config['lang'] );
		$this->assertSame( 'left', $config['position'] );
		$this->assertFalse( $config['wpConsentApi'] );
		$this->assertSame( array( 'statistics' => array( '_ga' ) ), $config['cookieNames'] );
		$this->assertSame( 'Accept and show', $config['strings']['placeholder']['marketing']['accept'] );
		$this->assertSame( 'You have not made a choice yet.', $config['strings']['consentBox']['none'] );
	}

	/**
	 * Root opts out of TranslatePress.
	 */
	public function test_root_has_data_no_translation() {
		$this->assertMatchesRegularExpression( '/^<div id="rc-root" class="rc-root" data-no-translation style="/', ltrim( $this->footer() ) );
	}

	/**
	 * Accent colour is sanitised; garbage falls back to the default.
	 */
	public function test_accent_css_var_escaped() {
		Settings::set( 'accent_color', '#123abc' );
		$this->assertStringContainsString( 'style="--rc-accent:#123abc;--rc-accent-ink:#ffffff;--rc-accent-text:#123abc"', $this->footer() );

		Settings::set( 'accent_color', '#ffee00' );
		$this->assertStringContainsString( '--rc-accent-ink:#1c1f1a', $this->footer() );

		Settings::set( 'accent_color', 'red;"><script>alert(1)</script>' );
		$out = $this->footer();
		$this->assertStringContainsString( 'style="--rc-accent:#2F4BC7;--rc-accent-ink:#ffffff;--rc-accent-text:#2F4BC7"', $out );
		$this->assertStringNotContainsString( '<script', $out );

		Settings::set( 'button_position', 'right' );
		$this->assertStringContainsString( 'rc-settings-button rc-pos-right', $this->footer() );
		Settings::set( 'button_position', '"><b>' );
		$this->assertStringContainsString( 'rc-settings-button rc-pos-left', $this->footer() );
	}

	/**
	 * The policy link is only printed when a policy page exists, with an escaped URL.
	 */
	public function test_policy_link() {
		$this->assertStringNotContainsString( 'Read our cookie policy', $this->footer() );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		Settings::set( 'policy_page_id', $page );
		$out = $this->footer();
		$this->assertStringContainsString( '<a class="rc-link" href="' . esc_url( get_permalink( $page ) ) . '">Read our cookie policy</a>', $out );
		$this->assertSame( get_permalink( $page ), Banner::config()['policyUrl'] );
	}

	/**
	 * In scan mode the config carries the mode and the scan agent is enqueued in the head; never otherwise.
	 */
	public function test_scan_agent_and_scan_mode_only_in_scan_mode() {
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( 'rc-consent', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'rc-scan-agent', 'enqueued' ) );
		wp_dequeue_script( 'rc-consent' );

		foreach ( array( 'none', 'accepted' ) as $mode ) {
			Context::set_scan_mode_override( $mode );
			$this->assertSame( $mode, Banner::config()['scanMode'] );
			$this->module()->enqueue();
			$this->assertTrue( wp_script_is( 'rc-scan-agent', 'enqueued' ) );
			$agent = wp_scripts()->registered['rc-scan-agent'];
			$this->assertStringEndsWith( 'assets/js/scan-agent.js', $agent->src );
			$this->assertArrayNotHasKey( 'group', $agent->extra, 'printed in the head' );
			$this->assertTrue( $this->module()->mark_own_script( array( 'id' => 'rc-scan-agent-js' ) )['data-rc-ignore'] );
			wp_dequeue_script( 'rc-scan-agent' );
			wp_deregister_script( 'rc-scan-agent' );
		}
	}

	/**
	 * Our script tags and their inline config carry the JS optimiser opt-outs.
	 */
	public function test_own_scripts_carry_optimizer_opt_outs() {
		$this->module()->enqueue();
		$html = get_echo( 'wp_print_scripts', array( 'rc-consent' ) );
		$this->assertSame( 2, preg_match_all( '#<script\b[^>]*>#', $html, $tags ) );
		foreach ( $tags[0] as $tag ) {
			foreach ( array( 'data-rc-ignore', 'data-cfasync="false"', 'data-no-optimize="1"', 'data-no-defer="1"', 'data-no-minify="1"', 'nowprocket' ) as $attribute ) {
				$this->assertStringContainsString( $attribute, $tag );
			}
		}
		wp_dequeue_script( 'rc-consent' );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Markup fixture.
		$bare = "<script id=\"rc-consent-js-before\">window.RevolterCookiesConfig={};</script>\n<script src=\"/consent.js\" id=\"rc-consent-js\"></script>\n";
		$out  = $this->module()->mark_own_tag( $bare, 'rc-consent' );
		$this->assertSame( 2, substr_count( $out, 'data-cfasync="false"' ) );
		$this->assertSame( $out, $this->module()->mark_own_tag( $out, 'rc-consent' ), 'Already marked tags are left alone.' );
		$this->assertSame( $bare, $this->module()->mark_own_tag( $bare, 'jquery' ) );

		$this->assertContains( 'rc-consent', $this->module()->rocket_exclusions( array( 'x' ) ) );
		$this->assertContains( 'x', $this->module()->rocket_exclusions( array( 'x' ) ) );
		$this->assertNull( $this->module()->rocket_exclusions( null ) );
	}

	/**
	 * Accent used as text on white falls back to a readable shade; the declaration gets the same vars.
	 */
	public function test_accent_text_is_readable_on_white() {
		$this->assertSame( '#2F4BC7', Banner::accent_text( '#2F4BC7' ) );
		$this->assertSame( '#7048ef', Banner::accent_text( '#7048ef' ) );
		$light = Banner::accent_text( '#ffd400' );
		$this->assertNotSame( '#ffd400', $light );
		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $light );
		$this->assertNotSame( '#ffffff', Banner::accent_text( '#ffffff' ) );
		$this->assertSame( '#1c1f1a', Banner::accent_text( 'nope' ) );

		Settings::set( 'accent_color', '#ffd400' );
		$this->assertStringContainsString( '--rc-accent-text:' . $light, $this->footer() );
		$this->assertStringContainsString( '--rc-accent:#ffd400;--rc-accent-ink:#1c1f1a;--rc-accent-text:' . $light, \RevolterCookies\Declaration::render_table() );
		$this->assertStringContainsString( '--rc-accent-text:' . $light, \RevolterCookies\Declaration::render_full_page() );
	}

	/**
	 * A category in use only through a blocked domain shows no "0 cookies" count.
	 */
	public function test_zero_count_is_not_shown() {
		Settings::set( 'categories_in_use', array( 'marketing' ) );
		$out = $this->footer();
		$this->assertStringContainsString( 'data-rc-category="marketing"', $out );
		$this->assertStringNotContainsString( '0 cookies', $out );
		$this->assertMatchesRegularExpression( '/<label for="rc-cat-marketing" class="rc-cat-name">[^<]*<\/label><\/div>/', $out );
		$this->assertMatchesRegularExpression( '/<span class="rc-cat-count">Always on<\/span>/', $out );
	}
}
