<?php
/**
 * Consent log storage tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Consent_Log;
use RevolterCookies\Installer;

/**
 * Verifies storage, retention, lookup and CSV export.
 */
class TestConsentLog extends WP_UnitTestCase {

	const ID_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
	const ID_B = 'bbbbbbbb-bbbb-4bbb-9bbb-bbbbbbbbbbbb';

	/**
	 * Fresh table state.
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . Installer::log_table() );
	}

	/**
	 * Build a row.
	 *
	 * @param string $id Consent id.
	 * @return array
	 */
	private function row( string $id = self::ID_A ): array {
		return array(
			'consent_id'     => $id,
			'categories'     => array( 'necessary', 'statistics' ),
			'policy_version' => 2,
			'action'         => 'custom',
			'language'       => 'sv',
		);
	}

	/**
	 * Backdate a row.
	 *
	 * @param int    $row_id Row id.
	 * @param string $when   MySQL datetime.
	 */
	private function backdate( int $row_id, string $when ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Installer::log_table(), array( 'created_at' => $when ), array( 'id' => $row_id ) );
	}

	/**
	 * Table holds no identifying columns.
	 */
	public function test_no_ip_or_user_agent_stored() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Installer::log_table() );
		$this->assertSame( array( 'id', 'consent_id', 'categories', 'policy_version', 'action', 'language', 'created_at' ), $columns );
	}

	/**
	 * Insert stores the row as csv categories.
	 */
	public function test_insert_stores_row() {
		$log = new Consent_Log();
		$id  = $log->insert( $this->row() );
		$this->assertGreaterThan( 0, $id );
		$rows = $log->latest( 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'necessary,statistics', $rows[0]['categories'] );
		$this->assertSame( self::ID_A, $rows[0]['consent_id'] );
		$this->assertSame( 2, (int) $rows[0]['policy_version'] );
	}

	/**
	 * Purge removes only rows older than 24 months.
	 */
	public function test_purge_removes_rows_older_than_24_months_only() {
		$log = new Consent_Log();
		$old = $log->insert( $this->row( self::ID_A ) );
		$new = $log->insert( $this->row( self::ID_B ) );
		$this->backdate( $old, gmdate( 'Y-m-d H:i:s', strtotime( '-25 months' ) ) );
		$this->backdate( $new, gmdate( 'Y-m-d H:i:s', strtotime( '-23 months' ) ) );
		$this->assertSame( 1, $log->purge_older_than( 24 ) );
		$rows = $log->latest( 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( self::ID_B, $rows[0]['consent_id'] );
	}

	/**
	 * Prefix lookup.
	 */
	public function test_find_by_prefix() {
		$log = new Consent_Log();
		$log->insert( $this->row( self::ID_A ) );
		$log->insert( $this->row( self::ID_B ) );
		$this->assertCount( 1, $log->find_by_prefix( 'aaaaaaaa-aa' ) );
		$this->assertCount( 0, $log->find_by_prefix( 'cccc' ) );
		$this->assertSame( array(), $log->find_by_prefix( "a%' OR 1=1 --" ) );
		$this->assertSame( array(), $log->find_by_prefix( '%' ) );
		$this->assertSame( array(), $log->find_by_prefix( '' ) );
	}

	/**
	 * Date range is inclusive of both days.
	 */
	public function test_range_is_inclusive() {
		$log = new Consent_Log();
		$a   = $log->insert( $this->row( self::ID_A ) );
		$b   = $log->insert( $this->row( self::ID_B ) );
		$this->backdate( $a, '2026-03-01 00:00:00' );
		$this->backdate( $b, '2026-03-02 23:59:59' );
		$this->assertCount( 2, $log->range( '2026-03-01', '2026-03-02' ) );
		$this->assertCount( 1, $log->range( '2026-03-02', '2026-03-02' ) );
		$this->assertCount( 0, $log->range( '2026-03-03', '2026-03-04' ) );
	}

	/**
	 * CSV header, quoting, line endings and formula guard.
	 */
	public function test_csv_format() {
		$log   = new Consent_Log();
		$csv   = $log->to_csv(
			array(
				array(
					'consent_id'     => self::ID_A,
					'created_at'     => '2026-03-01 10:00:00',
					'categories'     => 'necessary,statistics',
					'policy_version' => 2,
					'action'         => 'custom',
					'language'       => 'sv',
				),
				array(
					'consent_id'     => '=cmd',
					'created_at'     => '2026-03-01 10:00:00',
					'categories'     => 'necessary',
					'policy_version' => 1,
					'action'         => 'say "hi"',
					'language'       => 'en',
				),
			)
		);
		$lines = explode( "\r\n", $csv );
		$this->assertSame( 'consent_id,created_at_utc,categories,policy_version,action,language', $lines[0] );
		$this->assertSame( self::ID_A . ',2026-03-01 10:00:00,"necessary,statistics",2,custom,sv', $lines[1] );
		$this->assertSame( "'=cmd,2026-03-01 10:00:00,necessary,1,\"say \"\"hi\"\"\",en", $lines[2] );
		$this->assertSame( '', $lines[3] );
		$this->assertStringStartsNotWith( "\xEF\xBB\xBF", $csv );
	}

	/**
	 * Cron hook purges via the module.
	 */
	public function test_cron_hook_registered() {
		$module = new \RevolterCookies\Rest_Consent();
		$module->register();
		$this->assertNotFalse( has_action( 'revolter_cookies_daily', array( $module, 'purge' ) ) );
	}
}
