<?php
/**
 * Declared cookie, storage and domain items.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Database access for the items table.
 */
class Item_Repository {

	const CATEGORIES = array( 'necessary', 'preferences', 'statistics', 'marketing', 'unclassified' );

	const FILTERS = array( 'type', 'status', 'category' );

	const COLUMNS = array( 'type', 'name', 'service_id', 'provider', 'category', 'status', 'purpose_override', 'duration', 'third_party', 'source', 'pages', 'first_seen', 'last_seen', 'missed_scans', 'pre_consent' );

	const JSON_COLUMNS = array( 'purpose_override', 'pages' );

	const INT_COLUMNS = array( 'id', 'third_party', 'missed_scans', 'pre_consent' );

	/**
	 * Non-autoloaded option holding the data front-end page views need.
	 */
	const FRONTEND_CACHE = 'revolter_cookies_frontend_cache';

	/**
	 * Autoloaded counter bumped on every flush. A cache entry stamped with an older value is stale,
	 * so a page view that read the items before a change cannot keep its result cached after it.
	 */
	const FRONTEND_CACHE_GEN = 'revolter_cookies_frontend_gen';

	/**
	 * Columns that change on every scan but never reach visitors.
	 */
	const VOLATILE_COLUMNS = array( 'id', 'pages', 'first_seen', 'last_seen', 'missed_scans', 'pre_consent' );

	/**
	 * Rows, optionally filtered by type, status and category.
	 *
	 * @param array $where Filter values keyed by type, status or category; other keys are ignored.
	 * @return array[]
	 */
	public function all( array $where = array() ): array {
		global $wpdb;
		$table   = Installer::items_table();
		$clauses = array();
		$values  = array();
		foreach ( self::FILTERS as $key ) {
			if ( isset( $where[ $key ] ) && is_string( $where[ $key ] ) ) {
				$clauses[] = "{$key} = %s";
				$values[]  = $where[ $key ];
			}
		}
		$sql = "SELECT * FROM {$table}";
		if ( $clauses ) {
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}
		$sql .= ' ORDER BY type ASC, name ASC';
		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table and whitelisted column names only; values are placeholders.
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Installer::items_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * One row by type and name.
	 *
	 * @param string $type Type.
	 * @param string $name Name.
	 * @return array|null
	 */
	public function find( string $type, string $name ): ?array {
		global $wpdb;
		$table = Installer::items_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND name = %s", $type, $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Insert, or update the row with the same type and name. first_seen is never changed on update.
	 *
	 * @param array $item Item fields; type and name are required.
	 * @return int Row id.
	 */
	public function upsert( array $item ): int {
		global $wpdb;
		$type     = isset( $item['type'] ) ? (string) $item['type'] : '';
		$name     = isset( $item['name'] ) ? (string) $item['name'] : '';
		$existing = $this->find( $type, $name );
		if ( $existing ) {
			unset( $item['first_seen'] );
			$this->update( $existing['id'], $item );
			return $existing['id'];
		}
		$now          = gmdate( 'Y-m-d H:i:s' );
		$data         = array_merge(
			array(
				'service_id'       => null,
				'provider'         => '',
				'category'         => 'unclassified',
				'status'           => 'needs_review',
				'purpose_override' => null,
				'duration'         => null,
				'third_party'      => 0,
				'source'           => 'scan',
				'pages'            => array(),
				'first_seen'       => $now,
				'last_seen'        => $now,
				'missed_scans'     => 0,
				'pre_consent'      => 0,
			),
			array_intersect_key( $item, array_flip( self::COLUMNS ) )
		);
		$data['type'] = $type;
		$data['name'] = $name;
		$wpdb->insert( Installer::items_table(), $this->dehydrate( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id; // Before the flush, whose option write replaces insert_id.
		self::flush_frontend_cache();
		return $id;
	}

	/**
	 * Update columns of one row. Unknown columns, id, type and name are ignored.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		unset( $fields['type'], $fields['name'] );
		$data = $this->dehydrate( array_intersect_key( $fields, array_flip( self::COLUMNS ) ) );
		if ( ! $data ) {
			return;
		}
		$wpdb->update( Installer::items_table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush_frontend_cache();
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Installer::items_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush_frontend_cache();
	}

	/**
	 * Data for front-end page views, cached in one non-autoloaded option until items or rules change.
	 *
	 * @return array{counts: array<string,int>, cookie_names: array<string,string[]>, domains: array[]}
	 */
	public function frontend_data(): array {
		// Read the generation before the items: see FRONTEND_CACHE_GEN.
		$gen    = (int) get_option( self::FRONTEND_CACHE_GEN, 0 );
		$cached = get_option( self::FRONTEND_CACHE );
		if ( is_array( $cached ) && isset( $cached['counts'], $cached['cookie_names'], $cached['domains'], $cached['gen'] ) && $gen === (int) $cached['gen'] ) {
			unset( $cached['gen'] );
			return $cached;
		}
		$counts = array();
		foreach ( $this->declared_grouped() as $category => $items ) {
			$counts[ $category ] = count( $items );
		}
		$domains = array();
		foreach ( $this->all(
			array(
				'type'   => 'domain',
				'status' => 'declared',
			)
		) as $row ) {
			$domains[] = array(
				'name'       => $row['name'],
				'category'   => $row['category'],
				'status'     => $row['status'],
				'service_id' => $row['service_id'],
			);
		}
		$data = array(
			'counts'       => $counts,
			'cookie_names' => $this->cookie_names_by_category(),
			'domains'      => $domains,
		);
		update_option( self::FRONTEND_CACHE, $data + array( 'gen' => $gen ), false );
		return $data;
	}

	/**
	 * Drop the cached front-end data and invalidate any copy a running request is about to write.
	 */
	public static function flush_frontend_cache(): void {
		delete_option( self::FRONTEND_CACHE );
		update_option( self::FRONTEND_CACHE_GEN, (int) get_option( self::FRONTEND_CACHE_GEN, 0 ) + 1, true );
	}

	/**
	 * Hash of everything about the items that visitors can see, so callers can tell whether a
	 * write changed the declaration or the blocking rules.
	 *
	 * @return string
	 */
	public function visible_fingerprint(): string {
		$rows = array();
		foreach ( $this->all() as $row ) {
			$rows[] = array_diff_key( $row, array_flip( self::VOLATILE_COLUMNS ) );
		}
		return md5( (string) wp_json_encode( $rows ) );
	}

	/**
	 * Categories in use, canonical order: declared cookie and storage items, plus declared domains
	 * that wait for consent (their scripts are blocked, so visitors need that toggle).
	 *
	 * @param array[] $rows Item rows.
	 * @return string[]
	 */
	public static function categories_in_use( array $rows ): array {
		$used = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['type'], $row['status'], $row['category'] ) || 'declared' !== $row['status'] ) {
				continue;
			}
			if ( in_array( $row['type'], array( 'cookie', 'storage' ), true ) || ( 'domain' === $row['type'] && 'necessary' !== $row['category'] ) ) {
				$used[ $row['category'] ] = true;
			}
		}
		return array_values( array_intersect( self::CATEGORIES, array_keys( $used ) ) );
	}

	/**
	 * Cookie and storage items for the declaration: declared ones plus anything unclassified.
	 *
	 * @return array<string, array[]> Rows by category in canonical order, empty groups omitted, sorted by name.
	 */
	public function declared_grouped(): array {
		$groups = array();
		foreach ( $this->all() as $row ) {
			if ( ! in_array( $row['type'], array( 'cookie', 'storage' ), true ) ) {
				continue;
			}
			if ( 'declared' !== $row['status'] && 'unclassified' !== $row['category'] ) {
				continue;
			}
			$groups[ $row['category'] ][] = $row;
		}
		$ordered = array();
		foreach ( self::CATEGORIES as $category ) {
			if ( ! empty( $groups[ $category ] ) ) {
				usort(
					$groups[ $category ],
					static function ( $a, $b ) {
						return strcasecmp( $a['name'], $b['name'] );
					}
				);
				$ordered[ $category ] = $groups[ $category ];
			}
		}
		return $ordered;
	}

	/**
	 * Names of declared cookies by category, for deleting them on withdrawal.
	 *
	 * Names are stored as seen, so prefix-matched cookies keep their real name.
	 *
	 * @return array<string, string[]> Canonical category order, empty categories omitted.
	 */
	public function cookie_names_by_category(): array {
		$names = array();
		foreach ( $this->all(
			array(
				'type'   => 'cookie',
				'status' => 'declared',
			)
		) as $row ) {
			$names[ $row['category'] ][] = $row['name'];
		}
		$ordered = array();
		foreach ( self::CATEGORIES as $category ) {
			if ( ! empty( $names[ $category ] ) ) {
				$ordered[ $category ] = $names[ $category ];
			}
		}
		return $ordered;
	}

	/**
	 * Item counts. Declared counts cookies and storage only; needs_review and pre_consent count every type.
	 *
	 * @return array{declared:int, needs_review:int, pre_consent:int}
	 */
	public function counts(): array {
		global $wpdb;
		$table = Installer::items_table();
		$row   = $wpdb->get_row( "SELECT SUM(status = 'declared' AND type IN ('cookie','storage')) AS declared, SUM(status = 'needs_review') AS needs_review, SUM(pre_consent = 1) AS pre_consent FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = is_array( $row ) ? $row : array();
		return array(
			'declared'     => (int) ( $row['declared'] ?? 0 ),
			'needs_review' => (int) ( $row['needs_review'] ?? 0 ),
			'pre_consent'  => (int) ( $row['pre_consent'] ?? 0 ),
		);
	}

	/**
	 * Decode JSON columns and cast integers.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			$decoded = isset( $row[ $column ] ) && is_string( $row[ $column ] ) ? json_decode( $row[ $column ], true ) : null;
			if ( 'pages' === $column ) {
				$row[ $column ] = is_array( $decoded ) ? $decoded : array();
			} else {
				$row[ $column ] = is_array( $decoded ) ? $decoded : null;
			}
		}
		foreach ( self::INT_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}

	/**
	 * Encode JSON columns for storage.
	 *
	 * @param array $data Fields.
	 * @return array
	 */
	private function dehydrate( array $data ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$data[ $column ] = ( is_array( $data[ $column ] ) && $data[ $column ] ) || ( 'pages' === $column && is_array( $data[ $column ] ) )
					? wp_json_encode( $data[ $column ] )
					: null;
			}
		}
		return $data;
	}
}
