<?php
/**
 * Overview page: scan results and the review flow.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies\Admin;

use RevolterCookies\Duration;
use RevolterCookies\Item_Repository;
use RevolterCookies\Language;
use RevolterCookies\Library;
use RevolterCookies\Policy_Page;
use RevolterCookies\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Overview screen and its admin-post handlers.
 */
class Overview_Page {

	/**
	 * Item repository.
	 *
	 * @var Item_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Item_Repository|null $repository Repository.
	 */
	public function __construct( ?Item_Repository $repository = null ) {
		$this->repository = $repository ? $repository : new Item_Repository();
	}

	/**
	 * Hook the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_revolter_cookies_save_item', array( $this, 'handle_save_item' ) );
		add_action( 'admin_post_revolter_cookies_add_item', array( $this, 'handle_add_item' ) );
		add_action( 'admin_post_revolter_cookies_delete_item', array( $this, 'handle_delete_item' ) );
	}

	/**
	 * Bad request.
	 */
	private function bad_request(): void {
		wp_die( esc_html__( 'Invalid request.', 'revolter-cookies' ), '', array( 'response' => 400 ) );
	}

	/**
	 * Sanitised POST values, after the nonce was verified by Menu::authorize().
	 *
	 * @return array
	 */
	private function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in Menu::authorize().
		$text = static function ( string $key ): string {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};
		$area = static function ( string $key ): string {
			return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};
		$data = array(
			'id'            => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
			'name'          => $text( 'name' ),
			'type'          => sanitize_key( $text( 'type' ) ),
			'provider'      => $text( 'provider' ),
			'category'      => sanitize_key( $text( 'category' ) ),
			'duration_n'    => isset( $_POST['duration_n'] ) ? absint( $_POST['duration_n'] ) : 0,
			'duration_unit' => sanitize_key( $text( 'duration_unit' ) ),
			'purpose_sv'    => $area( 'purpose_sv' ),
			'purpose_en'    => $area( 'purpose_en' ),
			'third_party'   => isset( $_POST['third_party'] ) ? 1 : 0,
		);
		// phpcs:enable
		return $data;
	}

	/**
	 * Save-item handler.
	 */
	public function handle_save_item(): void {
		Menu::authorize( 'revolter_cookies_save_item' );
		if ( ! $this->save_item( $this->posted() ) ) {
			$this->bad_request();
		}
		Menu::redirect( Menu::SLUG );
	}

	/**
	 * Add-item handler.
	 */
	public function handle_add_item(): void {
		Menu::authorize( 'revolter_cookies_add_item' );
		$data = $this->posted();
		if ( ! $this->add_item( $data ) ) {
			if ( '' !== $data['name'] && $this->repository->find( 'storage' === $data['type'] ? 'storage' : 'cookie', $data['name'] ) ) {
				Menu::redirect( Menu::SLUG, array( 'rc_error' => 'exists' ) );
			}
			$this->bad_request();
		}
		Menu::redirect( Menu::SLUG );
	}

	/**
	 * Delete-item handler.
	 */
	public function handle_delete_item(): void {
		Menu::authorize( 'revolter_cookies_delete_item' );
		$data = $this->posted();
		if ( ! $this->delete_item( $data['id'] ) ) {
			$this->bad_request();
		}
		Menu::redirect( Menu::SLUG );
	}

	/**
	 * Purpose override in storage form: array( sv, en ), or null when both are empty.
	 *
	 * @param array $data Posted data.
	 * @return array|null
	 */
	private static function purpose( array $data ): ?array {
		$sv = isset( $data['purpose_sv'] ) ? trim( (string) $data['purpose_sv'] ) : '';
		$en = isset( $data['purpose_en'] ) ? trim( (string) $data['purpose_en'] ) : '';
		if ( '' === $sv && '' === $en ) {
			return null;
		}
		return array(
			'sv' => $sv,
			'en' => $en,
		);
	}

	/**
	 * Classify an item.
	 *
	 * @param array $data Sanitised data: id, category, purpose_sv, purpose_en.
	 * @return bool False for an unknown item or category.
	 */
	public function save_item( array $data ): bool {
		$id       = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$category = isset( $data['category'] ) ? (string) $data['category'] : '';
		$row      = $id > 0 ? $this->repository->get( $id ) : null;
		if ( ! $row || ! in_array( $category, Item_Repository::CATEGORIES, true ) ) {
			return false;
		}
		// The source is left alone: a declared scan row counts as human-chosen for later scans.
		$this->repository->update(
			$id,
			array(
				'category'         => $category,
				'status'           => 'unclassified' === $category ? 'needs_review' : 'declared',
				'purpose_override' => self::purpose( $data ),
			)
		);
		$this->refresh_categories();
		return true;
	}

	/**
	 * Add a manual cookie or storage item.
	 *
	 * @param array $data Sanitised data.
	 * @return bool False for invalid input or an existing name.
	 */
	public function add_item( array $data ): bool {
		$name     = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		$category = isset( $data['category'] ) ? (string) $data['category'] : '';
		$type     = isset( $data['type'] ) && 'storage' === $data['type'] ? 'storage' : 'cookie';
		if ( '' === $name || ! in_array( $category, Item_Repository::CATEGORIES, true ) || $this->repository->find( $type, $name ) ) {
			return false;
		}
		$unit = isset( $data['duration_unit'] ) ? (string) $data['duration_unit'] : 'session';
		if ( in_array( $unit, Duration::UNITS, true ) && ! empty( $data['duration_n'] ) ) {
			$duration = array(
				'n'    => (int) $data['duration_n'],
				'unit' => $unit,
			);
		} else {
			$duration = 'persistent' === $unit ? 'persistent' : 'session';
		}
		$this->repository->upsert(
			array(
				'type'             => $type,
				'name'             => $name,
				'provider'         => isset( $data['provider'] ) ? (string) $data['provider'] : '',
				'category'         => $category,
				'status'           => 'declared',
				'source'           => 'manual',
				'purpose_override' => self::purpose( $data ),
				'duration'         => Duration::encode( $duration ),
				'third_party'      => empty( $data['third_party'] ) ? 0 : 1,
			)
		);
		$this->refresh_categories();
		return true;
	}

	/**
	 * Delete a manual item.
	 *
	 * @param int $id Item id.
	 * @return bool False when the item is missing or not manual.
	 */
	public function delete_item( int $id ): bool {
		$row = $this->repository->get( $id );
		if ( ! $row || 'manual' !== $row['source'] ) {
			return false;
		}
		$this->repository->delete( $id );
		$this->refresh_categories();
		return true;
	}

	/**
	 * Keep categories_in_use current, and ask visitors again when a new category appears.
	 */
	private function refresh_categories(): void {
		$in_use = Item_Repository::categories_in_use( $this->repository->all() );
		$stored = Settings::get( 'categories_in_use' );
		if ( array_diff( $in_use, is_array( $stored ) ? $stored : array() ) ) {
			Settings::set( 'consent_version', (int) Settings::get( 'consent_version' ) + 1 );
		}
		Settings::set( 'categories_in_use', $in_use );
		/** Fires when blocking rules, categories or banner settings change. */
		do_action( 'revolter_cookies_rules_changed' );
	}

	/**
	 * Services and domains that wait for consent: distinct non-necessary library services seen in the
	 * items (by service_id) plus reviewed non-necessary domain rows with no service.
	 *
	 * @param array[] $items Item rows.
	 * @return string[] Labels.
	 */
	public static function blocked_labels( array $items ): array {
		$library = Library::from_file();
		$labels  = array();
		foreach ( $items as $row ) {
			if ( in_array( $row['category'], array( 'necessary', 'unclassified' ), true ) ) {
				continue;
			}
			if ( ! empty( $row['service_id'] ) ) {
				$service                             = $library->service( (string) $row['service_id'] );
				$labels[ 's:' . $row['service_id'] ] = $service && ! empty( $service['label'] ) ? (string) $service['label'] : (string) $row['service_id'];
			} elseif ( 'domain' === $row['type'] && 'declared' === $row['status'] ) {
				$labels[ 'd:' . $row['name'] ] = (string) $row['name'];
			}
		}
		return array_values( $labels );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		$items     = $this->repository->all();
		$last      = Settings::get( 'last_scan' );
		$last      = is_array( $last ) ? $last : array();
		$pages     = isset( $last['pages'] ) ? (int) $last['pages'] : 0;
		$failed    = isset( $last['failed'] ) ? (int) $last['failed'] : 0;
		$leaks     = array();
		$leak_page = array();
		foreach ( isset( $last['leaks'] ) && is_array( $last['leaks'] ) ? $last['leaks'] : array() as $leak ) {
			if ( is_array( $leak ) && isset( $leak['name'] ) ) {
				$leaks[ (string) $leak['name'] ] = true;
				if ( ! isset( $leak_page[ $leak['name'] ] ) ) {
					$leak_page[ $leak['name'] ] = isset( $leak['page'] ) ? (string) $leak['page'] : '';
				}
			}
		}
		$counts = $this->repository->counts();
		$when   = '';
		if ( ! empty( $last['time'] ) && strtotime( (string) $last['time'] ) ) {
			$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $last['time'] ) );
		}
		$blocked   = self::blocked_labels( $items );
		$policy_id = Policy_Page::id();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flags set by redirects.
		$view = array(
			'has_scan'     => ! empty( $last ),
			'scan_failed'  => ! empty( $last ) && ( ! empty( $last['all_failed'] ) || ( $pages > 0 && $failed >= $pages ) ),
			'pages'        => $pages,
			'failed'       => $failed,
			'when'         => $when,
			'declared'     => $counts['declared'],
			'needs_review' => $counts['needs_review'],
			'blocked'      => $blocked,
			'leaks'        => $leaks,
			'leak_page'    => $leak_page,
			'rescan'       => (bool) Settings::get( 'rescan_recommended' ),
			'items'        => $items,
			'languages'    => Language::site_languages(),
			'lang_source'  => Language::source(),
			'policy_title' => $policy_id ? get_the_title( $policy_id ) : '',
			'library_ok'   => Library::is_available(),
			'updated'      => isset( $_GET['updated'] ),
			'error'        => isset( $_GET['rc_error'] ) ? sanitize_key( wp_unslash( $_GET['rc_error'] ) ) : '',
		);
		// phpcs:enable
		Menu::render( 'overview', $view );
	}
}
