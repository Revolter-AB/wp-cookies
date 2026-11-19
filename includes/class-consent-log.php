<?php
/**
 * Anonymous consent log storage.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Stores proof of consent. No IP address, no user agent.
 */
class Consent_Log {

	const CATEGORIES = array( 'necessary', 'preferences', 'statistics', 'marketing' );

	const CSV_HEADER = array( 'consent_id', 'created_at_utc', 'categories', 'policy_version', 'action', 'language' );

	/**
	 * Insert a row stamped with the current UTC time.
	 *
	 * @param array $row consent_id, categories (string[]), policy_version, action, language.
	 * @return int Insert id, 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$categories = isset( $row['categories'] ) ? (array) $row['categories'] : array();
		$ordered    = array_values( array_intersect( self::CATEGORIES, $categories ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Installer::log_table(),
			array(
				'consent_id'     => (string) ( $row['consent_id'] ?? '' ),
				'categories'     => implode( ',', $ordered ),
				'policy_version' => (int) ( $row['policy_version'] ?? 1 ),
				'action'         => (string) ( $row['action'] ?? '' ),
				'language'       => (string) ( $row['language'] ?? '' ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Rows whose consent id starts with the prefix, newest first.
	 *
	 * @param string $prefix Hex/dash prefix.
	 * @param int    $limit  Max rows.
	 * @return array
	 */
	public function find_by_prefix( string $prefix, int $limit = 50 ): array {
		global $wpdb;
		$prefix = strtolower( $prefix );
		if ( '' === $prefix || ! preg_match( '/^[0-9a-f-]+$/', $prefix ) ) {
			return array();
		}
		$table = Installer::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE consent_id LIKE %s ORDER BY id DESC LIMIT %d", $wpdb->esc_like( $prefix ) . '%', max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Newest rows.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public function latest( int $limit = 50 ): array {
		global $wpdb;
		$table = Installer::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows between two UTC dates, both days inclusive, oldest first.
	 *
	 * @param string $from_ymd Y-m-d.
	 * @param string $to_ymd   Y-m-d.
	 * @param int    $limit    Max rows, 0 for all.
	 * @return array
	 */
	public function range( string $from_ymd, string $to_ymd, int $limit = 0 ): array {
		global $wpdb;
		$table = Installer::log_table();
		$sql   = "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC";
		$args  = array( $from_ymd . ' 00:00:00', $to_ymd . ' 23:59:59' );
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only; values are placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of rows between two UTC dates, both days inclusive.
	 *
	 * @param string $from_ymd Y-m-d.
	 * @param string $to_ymd   Y-m-d.
	 * @return int
	 */
	public function count_range( string $from_ymd, string $to_ymd ): int {
		global $wpdb;
		$table = Installer::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_ymd . ' 00:00:00',
				$to_ymd . ' 23:59:59'
			)
		);
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Installer::log_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete rows older than the given number of months.
	 *
	 * @param int $months Retention in months.
	 * @return int Rows deleted.
	 */
	public function purge_older_than( int $months = 24 ): int {
		global $wpdb;
		$table  = Installer::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $months ) . ' months' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * RFC 4180 CSV, CRLF line endings, formula-injection guarded.
	 *
	 * @param array $rows Rows as returned by the read methods.
	 * @return string
	 */
	public function to_csv( array $rows ): string {
		$lines = array( implode( ',', self::CSV_HEADER ) );
		foreach ( $rows as $row ) {
			$lines[] = implode(
				',',
				array(
					$this->csv_field( $row['consent_id'] ?? '' ),
					$this->csv_field( $row['created_at'] ?? '' ),
					$this->csv_field( $row['categories'] ?? '' ),
					$this->csv_field( $row['policy_version'] ?? '' ),
					$this->csv_field( $row['action'] ?? '' ),
					$this->csv_field( $row['language'] ?? '' ),
				)
			);
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Escape one field.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function csv_field( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			$value = "'" . $value;
		}
		if ( preg_match( '/[",\r\n]/', $value ) ) {
			$value = '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}
