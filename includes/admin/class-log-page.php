<?php
/**
 * Consent log page.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies\Admin;

use DateTime;
use RevolterCookies\Consent_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Search, list and export the consent log.
 */
class Log_Page {

	const SEARCH_LIMIT = 500;
	const LATEST_LIMIT = 50;
	const EXPORT_LIMIT = 50000;

	/**
	 * Maximum rows in one range export.
	 *
	 * @var int
	 */
	private $export_limit;

	/**
	 * Constructor.
	 *
	 * @param int $export_limit Maximum rows in one range export.
	 */
	public function __construct( int $export_limit = self::EXPORT_LIMIT ) {
		$this->export_limit = max( 1, $export_limit );
	}

	/**
	 * Hook the export handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_revolter_cookies_export_log', array( $this, 'handle_export' ) );
		add_action( 'admin_post_revolter_cookies_export_record', array( $this, 'handle_export_record' ) );
	}

	/**
	 * Keep only characters a consent id can contain.
	 *
	 * @param string $value Raw search.
	 * @return string
	 */
	public static function clean_search( string $value ): string {
		return (string) preg_replace( '/[^0-9a-f-]/', '', strtolower( $value ) );
	}

	/**
	 * Valid Y-m-d date or empty string.
	 *
	 * @param string $value Raw date.
	 * @return string
	 */
	private static function valid_date( string $value ): string {
		$date = DateTime::createFromFormat( '!Y-m-d', $value );
		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * Validated, ordered range; missing or invalid dates default to the last 30 days.
	 *
	 * @param string $from From date.
	 * @param string $to   To date.
	 * @return string[] array( from, to ).
	 */
	public static function normalise_range( string $from, string $to ): array {
		$from = self::valid_date( $from );
		$to   = self::valid_date( $to );
		if ( '' === $to ) {
			$to = gmdate( 'Y-m-d' );
		}
		if ( '' === $from ) {
			$from = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $to . ' 00:00:00 UTC' ) ) );
		}
		if ( $from > $to ) {
			return array( $to, $from );
		}
		return array( $from, $to );
	}

	/**
	 * CSV text for a date range, at most the export limit of rows.
	 *
	 * @param string $from From date.
	 * @param string $to   To date.
	 * @return string
	 */
	public function export_csv( string $from, string $to ): string {
		list( $from, $to ) = self::normalise_range( $from, $to );
		$log               = new Consent_Log();
		return $log->to_csv( $log->range( $from, $to, $this->export_limit ) );
	}

	/**
	 * Whether a range has more rows than one export holds.
	 *
	 * @param string $from From date.
	 * @param string $to   To date.
	 * @return bool
	 */
	public function over_limit( string $from, string $to ): bool {
		list( $from, $to ) = self::normalise_range( $from, $to );
		return ( new Consent_Log() )->count_range( $from, $to ) > $this->export_limit;
	}

	/**
	 * Stream the CSV download. A range over the limit goes back to the page with an inline message
	 * unless the request asks for the first rows up to the limit.
	 */
	public function handle_export(): void {
		Menu::authorize( 'revolter_cookies_export_log' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in Menu::authorize().
		$from     = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to       = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$truncate = ! empty( $_POST['truncate'] );
		// phpcs:enable
		list( $from, $to ) = self::normalise_range( $from, $to );
		if ( ! $truncate && $this->over_limit( $from, $to ) ) {
			Menu::redirect(
				Menu::LOG,
				array(
					'rc_truncated' => 1,
					'from'         => $from,
					'to'           => $to,
				)
			);
		}
		self::send_csv( $this->export_csv( $from, $to ), 'consent-log-' . $from . '-' . $to . '.csv' );
	}

	/**
	 * CSV for one log entry.
	 *
	 * @param int $id Row id.
	 * @return string|null Null when the entry does not exist.
	 */
	public function export_record_csv( int $id ): ?string {
		$log = new Consent_Log();
		$row = $id > 0 ? $log->get( $id ) : null;
		return $row ? $log->to_csv( array( $row ) ) : null;
	}

	/**
	 * Stream the CSV download of one entry.
	 */
	public function handle_export_record(): void {
		Menu::authorize( 'revolter_cookies_export_record' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in Menu::authorize().
		$id  = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		$csv = $this->export_record_csv( $id );
		if ( null === $csv ) {
			wp_die( esc_html__( 'Invalid request.', 'revolter-cookies' ), '', array( 'response' => 404 ) );
		}
		self::send_csv( $csv, 'consent-log-entry-' . $id . '.csv' );
	}

	/**
	 * Send a CSV download and stop.
	 *
	 * @param string $csv      CSV text.
	 * @param string $filename File name (only safe characters).
	 */
	private static function send_csv( string $csv, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, fields quoted by Consent_Log::to_csv().
		exit;
	}

	/**
	 * Admin-post URL that downloads one entry, with nonce.
	 *
	 * @param int $id Row id.
	 * @return string
	 */
	public static function record_url( int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'revolter_cookies_export_record',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'revolter_cookies_export_record'
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$raw    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$search = self::clean_search( $raw );
		$log    = new Consent_Log();
		$rows   = '' !== $search ? $log->find_by_prefix( $search, self::SEARCH_LIMIT ) : $log->latest( self::LATEST_LIMIT );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flags set by a redirect.
		$truncated         = isset( $_GET['rc_truncated'] );
		list( $from, $to ) = self::normalise_range(
			isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''
		);
		// phpcs:enable
		Menu::render(
			'log',
			array(
				'search'    => $search,
				'rows'      => $rows,
				'from'      => $from,
				'to'        => $to,
				'truncated' => $truncated,
				'limit'     => $this->export_limit,
			)
		);
	}
}
