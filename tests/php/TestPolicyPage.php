<?php
/**
 * Policy page tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Policy_Page;
use RevolterCookies\Settings;

/**
 * Verifies policy page detection.
 */
class TestPolicyPage extends WP_UnitTestCase {

	/**
	 * Start from a clean state.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_transient( 'revolter_cookies_policy_page' );
	}

	/**
	 * Shortcode page is found.
	 */
	public function test_detect_shortcode_page() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Intro [revolter_cookies_full_page] outro',
			)
		);
		$this->assertSame( $id, Policy_Page::detect() );
	}

	/**
	 * Block page is found.
	 */
	public function test_detect_block_page() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:revolter-cookies/full-page /-->',
			)
		);
		$this->assertSame( $id, Policy_Page::detect() );
	}

	/**
	 * Elementor widget page is found, in plain and slashed JSON.
	 */
	public function test_detect_elementor_meta_page() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, '_elementor_data', '[{"widgetType":"rc-full-page"}]' );
		$this->assertSame( $id, Policy_Page::detect() );

		delete_post_meta( $id, '_elementor_data' );
		add_post_meta( $id, '_elementor_data', '[{\"widgetType\":\"rc-full-page\"}]' );
		$this->assertSame( $id, Policy_Page::detect() );
	}

	/**
	 * Drafts are ignored and the lowest id wins.
	 */
	public function test_detect_ignores_drafts_and_picks_lowest() {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$first  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$second = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$this->assertSame( $first, Policy_Page::detect() );
		$this->assertGreaterThan( $first, $second );
	}

	/**
	 * The setting wins over detection.
	 */
	public function test_setting_overrides_detection() {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$chosen = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		Settings::set( 'policy_page_id', $chosen );
		$this->assertSame( $chosen, Policy_Page::id() );
	}

	/**
	 * Detection result, including 0, is cached until a page changes.
	 */
	public function test_id_caches_result() {
		$this->assertSame( 0, Policy_Page::id() );
		$this->assertSame( 0, get_transient( 'revolter_cookies_policy_page' ) );
		// A page saved through WordPress resets the cache (Shortcodes), so it is found at once.
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$this->assertSame( $page, Policy_Page::id() );
		$this->assertSame( $page, get_transient( 'revolter_cookies_policy_page' ) );
	}

	/**
	 * No page means an empty URL.
	 */
	public function test_url_empty_when_no_page() {
		$this->assertSame( '', Policy_Page::url() );
	}

	/**
	 * A found page yields its permalink.
	 */
	public function test_url_for_detected_page() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$this->assertSame( get_permalink( $id ), Policy_Page::url() );
	}

	/**
	 * A cached page that was trashed is dropped and detection re-runs.
	 */
	public function test_trashed_cached_page_redetected() {
		$first = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$this->assertSame( $first, Policy_Page::id() );
		$second = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		wp_trash_post( $first );
		$this->assertSame( $second, Policy_Page::id() );
	}

	/**
	 * A configured page that is not published yields no URL.
	 */
	public function test_url_empty_for_unpublished_setting() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		Settings::set( 'policy_page_id', $id );
		$this->assertSame( '', Policy_Page::url() );
	}
}
