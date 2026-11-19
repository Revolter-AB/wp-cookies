<?php
/**
 * Persists scan results.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a Scan_Processor result to the database and settings.
 */
class Scan_Service {

	/**
	 * Repository.
	 *
	 * @var Item_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Item_Repository $repository Repository.
	 */
	public function __construct( Item_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Persist a processed scan.
	 *
	 * @param array $result      Scan_Processor::process() result.
	 * @param int   $pages_total Pages scanned.
	 * @param int   $pages_failed Pages that failed.
	 * @param bool  $clear_rescan Whether a finished scan clears the rescan recommendation; false for an all-failed scan.
	 */
	public function apply( array $result, int $pages_total, int $pages_failed, bool $clear_rescan = true ): void {
		$before = $this->repository->visible_fingerprint();
		foreach ( $result['upserts'] as $item ) {
			$this->repository->upsert( $item );
		}
		foreach ( $result['delete_ids'] as $id ) {
			$this->repository->delete( (int) $id );
		}

		Settings::set(
			'last_scan',
			array(
				'time'         => gmdate( 'c' ),
				'pages'        => $pages_total,
				'failed'       => $pages_failed,
				'leaks'        => $result['leaks'],
				'cmp_detected' => $result['cmp_detected'],
			)
		);
		if ( $clear_rescan ) {
			Settings::set( 'rescan_recommended', false );
		}

		$in_use = array_values( $result['categories_in_use'] );
		$stored = Settings::get( 'categories_in_use' );
		if ( array_diff( $in_use, is_array( $stored ) ? $stored : array() ) ) {
			Settings::set( 'consent_version', (int) Settings::get( 'consent_version' ) + 1 );
		}
		Settings::set( 'categories_in_use', $in_use );

		delete_transient( Policy_Page::TRANSIENT );
		Item_Repository::flush_frontend_cache();
		// A scan that found nothing new leaves page caches alone. Category and consent-version
		// changes purge through Cache_Purge::settings_updated().
		if ( $this->repository->visible_fingerprint() !== $before ) {
			/** This action is documented in includes/admin/class-overview-page.php */
			do_action( 'revolter_cookies_rules_changed' );
		}
	}
}
