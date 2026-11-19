<?php
/**
 * Item repository tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Installer;
use RevolterCookies\Item_Repository;

/**
 * Verifies persistence and queries.
 */
class TestItemRepository extends WP_UnitTestCase {

	/**
	 * Repository.
	 *
	 * @var Item_Repository
	 */
	private $repo;

	/**
	 * Ensure the table exists and is empty.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		Installer::activate();
		$table = Installer::items_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->repo = new Item_Repository();
	}

	/**
	 * Build an item.
	 *
	 * @param string $name      Name.
	 * @param array  $overrides Overrides.
	 * @return array
	 */
	private static function item( string $name, array $overrides = array() ): array {
		return array_merge(
			array(
				'type'       => 'cookie',
				'name'       => $name,
				'category'   => 'statistics',
				'status'     => 'declared',
				'source'     => 'library',
				'provider'   => 'Google',
				'first_seen' => '2026-01-01 00:00:00',
				'last_seen'  => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	/**
	 * Same type and name updates in place; first_seen is kept.
	 */
	public function test_upsert_updates_existing_by_type_and_name() {
		$id  = $this->repo->upsert( self::item( '_ga', array( 'pages' => array( 'https://a.test/' ) ) ) );
		$id2 = $this->repo->upsert(
			self::item(
				'_ga',
				array(
					'category'         => 'marketing',
					'first_seen'       => '2030-01-01 00:00:00',
					'last_seen'        => '2026-02-02 00:00:00',
					'purpose_override' => array(
						'sv' => 'a',
						'en' => 'b',
					),
					'duration'         => '2:year',
				)
			)
		);
		$this->assertSame( $id, $id2 );
		$this->assertCount( 1, $this->repo->all() );
		$row = $this->repo->get( $id );
		$this->assertSame( 'marketing', $row['category'] );
		$this->assertSame( '2026-01-01 00:00:00', $row['first_seen'] );
		$this->assertSame( '2026-02-02 00:00:00', $row['last_seen'] );
		$this->assertSame( '2:year', $row['duration'] );
		$this->assertSame(
			array(
				'sv' => 'a',
				'en' => 'b',
			),
			$row['purpose_override']
		);
		$this->assertSame( array( 'https://a.test/' ), $row['pages'] );
		$this->assertSame( $id, $row['id'] );
		$this->assertSame( 0, $row['missed_scans'] );

		$other = $this->repo->upsert( self::item( '_ga', array( 'type' => 'storage' ) ) );
		$this->assertNotSame( $id, $other );
	}

	/**
	 * Find, get, update, delete and filters.
	 */
	public function test_find_update_delete_and_filters() {
		$id = $this->repo->upsert( self::item( 'a' ) );
		$this->repo->upsert(
			self::item(
				'b',
				array(
					'status'   => 'needs_review',
					'category' => 'unclassified',
				)
			)
		);
		$this->assertSame( $id, $this->repo->find( 'cookie', 'a' )['id'] );
		$this->assertNull( $this->repo->find( 'cookie', 'zzz' ) );
		$this->assertNull( $this->repo->get( 99999 ) );
		$this->assertCount( 1, $this->repo->all( array( 'status' => 'needs_review' ) ) );
		$this->assertCount( 0, $this->repo->all( array( 'type' => 'domain' ) ) );
		$this->assertCount( 2, $this->repo->all( array( 'bogus' => "x' OR 1=1" ) ) );
		$this->repo->update(
			$id,
			array(
				'category' => 'marketing',
				'id'       => 777,
				'bogus'    => 'x',
			)
		);
		$this->assertSame( 'marketing', $this->repo->get( $id )['category'] );
		$this->assertSame( $id, $this->repo->get( $id )['id'] );
		$this->repo->delete( $id );
		$this->assertNull( $this->repo->get( $id ) );
	}

	/**
	 * Grouped in category order, unclassified included, sorted by name.
	 */
	public function test_declared_grouped_order_and_unclassified_included() {
		$this->repo->upsert( self::item( 'zeta', array( 'category' => 'marketing' ) ) );
		$this->repo->upsert( self::item( 'beta', array( 'category' => 'necessary' ) ) );
		$this->repo->upsert( self::item( 'alpha', array( 'category' => 'necessary' ) ) );
		$this->repo->upsert(
			self::item(
				'review',
				array(
					'category' => 'unclassified',
					'status'   => 'needs_review',
				)
			)
		);
		$this->repo->upsert(
			self::item(
				'hidden',
				array(
					'category' => 'statistics',
					'status'   => 'needs_review',
				)
			)
		);
		$this->repo->upsert(
			self::item(
				'dom',
				array(
					'type'     => 'domain',
					'category' => 'marketing',
				)
			)
		);
		$this->repo->upsert(
			self::item(
				'store',
				array(
					'type'     => 'storage',
					'category' => 'preferences',
				)
			)
		);

		$grouped = $this->repo->declared_grouped();
		$this->assertSame( array( 'necessary', 'preferences', 'marketing', 'unclassified' ), array_keys( $grouped ) );
		$this->assertSame( array( 'alpha', 'beta' ), array_column( $grouped['necessary'], 'name' ) );
		$this->assertSame( array( 'review' ), array_column( $grouped['unclassified'], 'name' ) );
		$this->assertSame( array( 'zeta' ), array_column( $grouped['marketing'], 'name' ) );
	}

	/**
	 * Cookie names for withdrawal deletion.
	 */
	public function test_cookie_names_by_category() {
		$this->repo->upsert( self::item( '_ga_', array( 'category' => 'statistics' ) ) );
		$this->repo->upsert( self::item( '_ga_G-ABC', array( 'category' => 'statistics' ) ) );
		$this->repo->upsert( self::item( '_fbp', array( 'category' => 'marketing' ) ) );
		$this->repo->upsert(
			self::item(
				'store',
				array(
					'type'     => 'storage',
					'category' => 'marketing',
				)
			)
		);
		$this->repo->upsert(
			self::item(
				'pending',
				array(
					'category' => 'unclassified',
					'status'   => 'needs_review',
				)
			)
		);

		$names = $this->repo->cookie_names_by_category();
		$this->assertSame( array( 'statistics', 'marketing' ), array_keys( $names ) );
		$this->assertSame( array( '_ga_', '_ga_G-ABC' ), $names['statistics'] );
		$this->assertSame( array( '_fbp' ), $names['marketing'] );
	}

	/**
	 * Counts.
	 */
	public function test_counts() {
		$this->repo->upsert( self::item( 'a' ) );
		$this->repo->upsert( self::item( 'b', array( 'pre_consent' => 1 ) ) );
		$this->repo->upsert(
			self::item(
				'c',
				array(
					'status'      => 'needs_review',
					'pre_consent' => 1,
				)
			)
		);
		$this->assertSame(
			array(
				'declared'     => 2,
				'needs_review' => 1,
				'pre_consent'  => 2,
			),
			$this->repo->counts()
		);
	}

	/**
	 * Data computed before a flush but written after it is not served from the cache.
	 */
	public function test_frontend_cache_written_before_flush_is_stale() {
		$repo = new Item_Repository();
		$repo->frontend_data();
		$stale = get_option( Item_Repository::FRONTEND_CACHE );
		$this->assertIsArray( $stale );
		$stale['counts'] = array( 'marketing' => 99 );

		Item_Repository::flush_frontend_cache();
		// A slower request that read the items before the flush now stores its old result.
		update_option( Item_Repository::FRONTEND_CACHE, $stale, false );

		$data = $repo->frontend_data();
		$this->assertArrayNotHasKey( 'marketing', $data['counts'] );
		$this->assertArrayNotHasKey( 'gen', $data );
		$this->assertSame( $data, $repo->frontend_data(), 'A fresh entry is served from the cache.' );
	}

	/**
	 * The fingerprint ignores scan bookkeeping and follows what visitors see.
	 */
	public function test_visible_fingerprint_ignores_volatile_columns() {
		$repo = new Item_Repository();
		$id   = $repo->upsert(
			array(
				'type'       => 'cookie',
				'name'       => '_ga',
				'category'   => 'statistics',
				'status'     => 'declared',
				'first_seen' => '2026-01-01 00:00:00',
				'last_seen'  => '2026-01-01 00:00:00',
			)
		);
		$base = $repo->visible_fingerprint();
		$repo->upsert(
			array(
				'type'      => 'cookie',
				'name'      => '_ga',
				'last_seen' => '2026-11-19 00:00:00',
				'pages'     => array( 'https://a.test/' ),
			)
		);
		$this->assertSame( $base, $repo->visible_fingerprint() );
		$repo->upsert(
			array(
				'type'     => 'cookie',
				'name'     => '_ga',
				'category' => 'marketing',
			)
		);
		$this->assertNotSame( $base, $repo->visible_fingerprint() );
		$this->assertIsInt( $id );
	}
}
