<?php
/**
 * Scan service tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Installer;
use RevolterCookies\Item_Repository;
use RevolterCookies\Scan_Service;
use RevolterCookies\Settings;

/**
 * Verifies persistence of scan results and settings side effects.
 */
class TestScanService extends WP_UnitTestCase {

	/**
	 * Clean state.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		Installer::activate();
		$table = Installer::items_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( 'revolter_cookies_settings' );
	}

	/**
	 * Build a processor result.
	 *
	 * @param array $categories Categories in use.
	 * @return array
	 */
	private static function result( array $categories ): array {
		return array(
			'upserts'           => array(
				array(
					'type'       => 'cookie',
					'name'       => 'rc_consent',
					'category'   => 'necessary',
					'status'     => 'declared',
					'source'     => 'plugin',
					'first_seen' => '2026-11-18 10:00:00',
					'last_seen'  => '2026-11-18 10:00:00',
				),
			),
			'delete_ids'        => array(),
			'leaks'             => array(
				array(
					'name' => '_ga',
					'page' => 'https://a.test/',
				),
			),
			'categories_in_use' => $categories,
			'cmp_detected'      => array( 'Cookiebot' ),
		);
	}

	/**
	 * A new category bumps the version and stores everything.
	 */
	public function test_apply_bumps_consent_version_when_new_category_appears() {
		set_transient( 'revolter_cookies_policy_page', 5 );
		Settings::set( 'rescan_recommended', true );
		$service = new Scan_Service( new Item_Repository() );
		$service->apply( self::result( array( 'necessary', 'marketing' ) ), 7, 2 );

		$this->assertSame( 2, Settings::get( 'consent_version' ) );
		$this->assertSame( array( 'necessary', 'marketing' ), Settings::get( 'categories_in_use' ) );
		$this->assertFalse( Settings::get( 'rescan_recommended' ) );
		$this->assertFalse( get_transient( 'revolter_cookies_policy_page' ) );
		$last = Settings::get( 'last_scan' );
		$this->assertSame( 7, $last['pages'] );
		$this->assertSame( 2, $last['failed'] );
		$this->assertSame( array( 'Cookiebot' ), $last['cmp_detected'] );
		$this->assertSame( 'https://a.test/', $last['leaks'][0]['page'] );
		$this->assertNotFalse( strtotime( $last['time'] ) );
		$this->assertNotNull( ( new Item_Repository() )->find( 'cookie', 'rc_consent' ) );
	}

	/**
	 * A shrinking set does not bump the version but is stored.
	 */
	public function test_apply_does_not_bump_when_set_shrinks() {
		Settings::set( 'categories_in_use', array( 'necessary', 'marketing' ) );
		Settings::set( 'consent_version', 3 );
		$service = new Scan_Service( new Item_Repository() );
		$service->apply( self::result( array( 'necessary' ) ), 1, 0 );
		$this->assertSame( 3, Settings::get( 'consent_version' ) );
		$this->assertSame( array( 'necessary' ), Settings::get( 'categories_in_use' ) );
	}

	/**
	 * Deletions are applied.
	 */
	public function test_apply_deletes_rows() {
		$repo                 = new Item_Repository();
		$id                   = $repo->upsert(
			array(
				'type'       => 'cookie',
				'name'       => 'old',
				'first_seen' => '2026-01-01 00:00:00',
				'last_seen'  => '2026-01-01 00:00:00',
			)
		);
		$result               = self::result( array( 'necessary' ) );
		$result['delete_ids'] = array( $id );
		( new Scan_Service( $repo ) )->apply( $result, 1, 0 );
		$this->assertNull( $repo->get( $id ) );
	}

	/**
	 * Applying a scan announces a rules change and drops the cached front-end data.
	 */
	public function test_apply_fires_rules_changed() {
		update_option( Item_Repository::FRONTEND_CACHE, array( 'stale' => true ), false );
		$before = did_action( 'revolter_cookies_rules_changed' );
		( new Scan_Service( new Item_Repository() ) )->apply( self::result( array( 'necessary' ) ), 1, 0 );
		$this->assertGreaterThan( $before, did_action( 'revolter_cookies_rules_changed' ) );
		$this->assertFalse( get_option( Item_Repository::FRONTEND_CACHE ) );
	}

	/**
	 * Rescanning with no visible change leaves page caches alone.
	 */
	public function test_apply_without_visible_change_does_not_fire_rules_changed() {
		$service = new Scan_Service( new Item_Repository() );
		$service->apply( self::result( array( 'necessary' ) ), 1, 0 );
		$before                           = did_action( 'revolter_cookies_rules_changed' );
		$again                            = self::result( array( 'necessary' ) );
		$again['upserts'][0]['last_seen'] = '2026-11-19 10:00:00';
		$service->apply( $again, 1, 0 );
		$this->assertSame( $before, did_action( 'revolter_cookies_rules_changed' ) );
	}
}
