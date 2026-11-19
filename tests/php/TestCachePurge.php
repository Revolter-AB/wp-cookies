<?php
/**
 * Cache purge tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Cache_Purge;
use RevolterCookies\Item_Repository;

/**
 * Verifies that rule changes purge page caches once and drop derived data.
 */
class TestCachePurge extends WP_UnitTestCase {

	/**
	 * A rules change drops the front-end cache and queues one purge at shutdown.
	 */
	public function test_rules_changed_queues_one_purge() {
		update_option( Item_Repository::FRONTEND_CACHE, array( 'x' => 1 ), false );
		$purge = new Cache_Purge();
		$purge->rules_changed();
		$purge->rules_changed();
		$this->assertFalse( get_option( Item_Repository::FRONTEND_CACHE ) );
		$this->assertSame( 10, has_action( 'shutdown', array( Cache_Purge::class, 'purge' ) ) );
		remove_action( 'shutdown', array( Cache_Purge::class, 'purge' ) );
	}

	/**
	 * The purge calls caching plugins' hooks when they are present, and nothing fails without them.
	 */
	public function test_purge_calls_present_cache_hooks() {
		$calls = array();
		$spy   = static function () use ( &$calls ) {
			$calls[] = current_action();
		};
		Cache_Purge::purge();
		$this->assertSame( array(), $calls );

		add_action( 'litespeed_purge_all', $spy );
		add_action( 'breeze_clear_all_cache', $spy );
		Cache_Purge::purge();
		$this->assertSame( array( 'litespeed_purge_all', 'breeze_clear_all_cache' ), $calls );
	}
}
