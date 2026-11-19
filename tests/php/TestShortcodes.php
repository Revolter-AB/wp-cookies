<?php
/**
 * Shortcode tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Policy_Page;

/**
 * Verifies shortcode registration and the policy page cache reset.
 */
class TestShortcodes extends WP_UnitTestCase {

	/**
	 * Both shortcodes exist.
	 */
	public function test_shortcodes_registered() {
		$this->assertTrue( shortcode_exists( 'revolter_cookies_full_page' ) );
		$this->assertTrue( shortcode_exists( 'revolter_cookies_table' ) );
	}

	/**
	 * Saving, trashing and deleting a page clears the cached policy page id.
	 */
	public function test_policy_page_cache_cleared_on_page_changes() {
		set_transient( Policy_Page::TRANSIENT, 0, HOUR_IN_SECONDS );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[revolter_cookies_full_page]',
			)
		);
		$this->assertFalse( get_transient( Policy_Page::TRANSIENT ) );
		$this->assertSame( $id, Policy_Page::id() );

		set_transient( Policy_Page::TRANSIENT, $id, HOUR_IN_SECONDS );
		wp_trash_post( $id );
		$this->assertFalse( get_transient( Policy_Page::TRANSIENT ) );

		set_transient( Policy_Page::TRANSIENT, $id, HOUR_IN_SECONDS );
		wp_delete_post( $id, true );
		$this->assertFalse( get_transient( Policy_Page::TRANSIENT ) );
	}
}
