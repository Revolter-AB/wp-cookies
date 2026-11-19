<?php
/**
 * Scan processor tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Library;
use RevolterCookies\Scan_Processor;

/**
 * Verifies classification, third-party detection, leaks and row lifecycle.
 */
class TestScanProcessor extends WP_UnitTestCase {

	const NOW = '2026-11-18 10:00:00';

	/**
	 * Build a cookie entry.
	 *
	 * @param string $name        Name.
	 * @param string $mode       exact or prefix.
	 * @param bool   $third_party Third party flag.
	 * @param string $type        cookie or storage.
	 * @return array
	 */
	private static function cookie( string $name, string $mode = 'exact', bool $third_party = false, string $type = 'cookie' ): array {
		return array(
			'name'        => $name,
			'match'       => $mode,
			'type'        => $type,
			'third_party' => $third_party,
			'duration'    => array(
				'n'    => 2,
				'unit' => 'year',
			),
			'purpose'     => array(
				'en' => 'English.',
				'sv' => 'Svenska.',
			),
		);
	}

	/**
	 * Build a service.
	 *
	 * @param string $id       Id.
	 * @param string $category Category.
	 * @param array  $domains  Domains.
	 * @param array  $cookies  Cookies.
	 * @return array
	 */
	private static function service( string $id, string $category, array $domains, array $cookies ): array {
		return array(
			'id'       => $id,
			'label'    => ucfirst( $id ),
			'provider' => 'Provider ' . $id,
			'category' => $category,
			'domains'  => $domains,
			'cookies'  => $cookies,
		);
	}

	/**
	 * Small fixture library.
	 *
	 * @return Library
	 */
	private function library(): Library {
		return new Library(
			array(
				'version'  => 1,
				'services' => array(
					self::service( 'revolter-cookies', 'necessary', array(), array( self::cookie( 'rc_consent' ) ) ),
					self::service( 'wordpress', 'necessary', array(), array( self::cookie( 'wordpress_logged_in_', 'prefix' ) ) ),
					self::service(
						'google-analytics',
						'statistics',
						array( 'google-analytics.com' ),
						array( self::cookie( '_ga' ), self::cookie( '_ga_', 'prefix' ) )
					),
					self::service(
						'youtube',
						'marketing',
						array( 'youtube.com' ),
						array(
							self::cookie( 'YSC', 'exact', true ),
							self::cookie( 'yt-remote-', 'prefix', true, 'storage' ),
							self::cookie( 'own_cookie', 'exact', false ),
						)
					),
					self::service( 'google-fonts', 'necessary', array( 'fonts.googleapis.com' ), array() ),
					self::service( 'php-session', 'necessary', array(), array( self::cookie( 'PHPSESSID' ) ) ),
				),
			)
		);
	}

	/**
	 * Processor under test.
	 *
	 * @return Scan_Processor
	 */
	private function processor(): Scan_Processor {
		return new Scan_Processor( $this->library(), 'example.com', 'Example AB', self::NOW );
	}

	/**
	 * Build a page entry.
	 *
	 * @param array  $overrides Field overrides.
	 * @param string $url       Url.
	 * @return array
	 */
	private static function page( array $overrides = array(), string $url = 'https://example.com/' ): array {
		return array_merge(
			array(
				'url'              => $url,
				'status'           => 'ok',
				'cookies_none'     => array(),
				'cookies_accepted' => array(),
				'storage'          => array(),
				'resources'        => array(),
				'server_cookies'   => array(),
			),
			$overrides
		);
	}

	/**
	 * Find an upsert.
	 *
	 * @param array  $result Processor result.
	 * @param string $type   Type.
	 * @param string $name   Name.
	 * @return array|null
	 */
	private static function upsert( array $result, string $type, string $name ): ?array {
		foreach ( $result['upserts'] as $row ) {
			if ( $row['type'] === $type && $row['name'] === $name ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Build an existing row.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private static function existing( array $overrides ): array {
		return array_merge(
			array(
				'id'               => 1,
				'type'             => 'cookie',
				'name'             => 'x',
				'service_id'       => null,
				'provider'         => 'Example AB',
				'category'         => 'unclassified',
				'status'           => 'needs_review',
				'purpose_override' => null,
				'duration'         => null,
				'third_party'      => 0,
				'source'           => 'scan',
				'pages'            => array(),
				'first_seen'       => '2026-01-01 00:00:00',
				'last_seen'        => '2026-01-01 00:00:00',
				'missed_scans'     => 0,
				'pre_consent'      => 0,
			),
			$overrides
		);
	}

	/**
	 * Library cookies keep their real name.
	 */
	public function test_known_cookie_declared_with_real_name() {
		$result = $this->processor()->process( array( 'pages' => array( self::page( array( 'cookies_accepted' => array( '_ga_G-ABC' ) ) ) ) ), array() );
		$row    = self::upsert( $result, 'cookie', '_ga_G-ABC' );
		$this->assertNotNull( $row );
		$this->assertSame( 'declared', $row['status'] );
		$this->assertSame( 'statistics', $row['category'] );
		$this->assertSame( 'library', $row['source'] );
		$this->assertSame( 'google-analytics', $row['service_id'] );
		$this->assertSame( '2:year', $row['duration'] );
		$this->assertSame( array( 'https://example.com/' ), $row['pages'] );
		$this->assertSame( self::NOW, $row['last_seen'] );
		$this->assertSame( self::NOW, $row['first_seen'] );
		$this->assertSame( 0, $row['missed_scans'] );
	}

	/**
	 * Unknown cookies need review.
	 */
	public function test_unknown_cookie_needs_review_with_company_provider() {
		$result = $this->processor()->process( array( 'pages' => array( self::page( array( 'server_cookies' => array( 'mystery' ) ) ) ) ), array() );
		$row    = self::upsert( $result, 'cookie', 'mystery' );
		$this->assertSame( 'needs_review', $row['status'] );
		$this->assertSame( 'unclassified', $row['category'] );
		$this->assertSame( 'Example AB', $row['provider'] );
		$this->assertSame( 'scan', $row['source'] );
	}

	/**
	 * Logged-in cookies are ignored.
	 */
	public function test_logged_in_cookies_ignored() {
		$names  = array( 'wordpress_logged_in_abc', 'wordpress_sec_abc', 'wp-settings-1', 'wp-saving-post', 'comment_author_abc' );
		$result = $this->processor()->process( array( 'pages' => array( self::page( array( 'cookies_accepted' => $names ) ) ) ), array() );
		foreach ( $names as $name ) {
			$this->assertNull( self::upsert( $result, 'cookie', $name ), $name );
		}
	}

	/**
	 * A known embed host adds its third-party cookies only.
	 */
	public function test_youtube_host_adds_third_party_cookies() {
		$raw    = array( 'pages' => array( self::page( array( 'resources' => array( 'https://www.youtube.com/embed/abc' ) ) ) ) );
		$result = $this->processor()->process( $raw, array() );
		$ysc    = self::upsert( $result, 'cookie', 'YSC' );
		$this->assertSame( 'declared', $ysc['status'] );
		$this->assertSame( 'marketing', $ysc['category'] );
		$this->assertSame( 1, $ysc['third_party'] );
		$this->assertNotNull( self::upsert( $result, 'storage', 'yt-remote-' ) );
		$this->assertNull( self::upsert( $result, 'cookie', 'own_cookie' ) );
		$this->assertNull( self::upsert( $result, 'domain', 'www.youtube.com' ) );
		$this->assertContains( 'marketing', $result['categories_in_use'] );
	}

	/**
	 * Known service without cookies adds nothing; unknown host becomes a domain item.
	 */
	public function test_unknown_third_party_host_becomes_domain_item() {
		$raw    = array(
			'pages' => array(
				self::page(
					array(
						'resources' => array(
							'https://fonts.googleapis.com/css',
							'https://tracker.example.net/t.js',
							'data:image/png;base64,AAAA',
						),
					)
				),
			),
		);
		$result = $this->processor()->process( $raw, array() );
		$row    = self::upsert( $result, 'domain', 'tracker.example.net' );
		$this->assertSame( 'needs_review', $row['status'] );
		$this->assertSame( 'unclassified', $row['category'] );
		$this->assertSame( 1, $row['third_party'] );
		$this->assertNull( self::upsert( $result, 'domain', 'fonts.googleapis.com' ) );
		$this->assertNull( self::upsert( $result, 'domain', '' ) );
	}

	/**
	 * Only scripts and iframes on unknown hosts need review; other resources still feed service inference.
	 */
	public function test_unknown_host_needs_review_only_for_embeds() {
		$raw    = array(
			'pages' => array(
				self::page(
					array(
						'resources' => array(
							'https://secure.gravatar.com/avatar/abc',
							'https://lh3.googleusercontent.com/p/x.jpg',
							'https://tracker.example.net/t.js',
							'https://widget.example.org/frame',
							'https://www.youtube.com/s/player/base.css',
						),
						'embeds'    => array(
							'https://tracker.example.net/t.js',
							'https://widget.example.org/frame',
						),
					)
				),
			),
		);
		$result = $this->processor()->process( $raw, array() );
		$this->assertNull( self::upsert( $result, 'domain', 'secure.gravatar.com' ) );
		$this->assertNull( self::upsert( $result, 'domain', 'lh3.googleusercontent.com' ) );
		$this->assertSame( 'needs_review', self::upsert( $result, 'domain', 'tracker.example.net' )['status'] );
		$this->assertSame( 'needs_review', self::upsert( $result, 'domain', 'widget.example.org' )['status'] );
		$this->assertSame( 'declared', self::upsert( $result, 'cookie', 'YSC' )['status'], 'A known service is inferred from any resource.' );
	}

	/**
	 * A declared domain with a consent category puts that category in use; a necessary one does not.
	 */
	public function test_declared_domain_counts_toward_categories_in_use() {
		$existing = array(
			array(
				'id'       => 7,
				'type'     => 'domain',
				'name'     => 'tracker.example.net',
				'category' => 'marketing',
				'status'   => 'declared',
				'source'   => 'scan',
			),
			array(
				'id'       => 8,
				'type'     => 'domain',
				'name'     => 'cdn.example.org',
				'category' => 'preferences',
				'status'   => 'needs_review',
				'source'   => 'scan',
			),
		);
		$raw      = array( 'pages' => array( self::page( array( 'resources' => array( 'https://tracker.example.net/t.js', 'https://cdn.example.org/x.js' ) ) ) ) );
		$result   = $this->processor()->process( $raw, $existing );
		$this->assertSame( array( 'necessary', 'marketing' ), $result['categories_in_use'] );
	}

	/**
	 * The site's own host, its www twin and subdomains are not third parties.
	 */
	public function test_own_host_and_www_twin_ignored() {
		$resources = array( 'https://example.com/a.js', 'https://www.example.com/b.js', 'https://cdn.example.com/c.js' );
		$result    = $this->processor()->process( array( 'pages' => array( self::page( array( 'resources' => $resources ) ) ) ), array() );
		foreach ( $result['upserts'] as $row ) {
			$this->assertNotSame( 'domain', $row['type'] );
		}
		$processor = new Scan_Processor( $this->library(), 'www.example.com', 'Example AB', self::NOW );
		$result    = $processor->process( array( 'pages' => array( self::page( array( 'resources' => $resources ) ) ) ), array() );
		foreach ( $result['upserts'] as $row ) {
			$this->assertNotSame( 'domain', $row['type'] );
		}
	}

	/**
	 * Human choices survive a rescan, library data refreshes.
	 */
	public function test_reviewed_category_survives_rescan() {
		$override = array(
			'sv' => 'Eget.',
			'en' => 'Own.',
		);
		$existing = array(
			self::existing(
				array(
					'id'               => 5,
					'name'             => 'mystery',
					'category'         => 'marketing',
					'status'           => 'declared',
					'source'           => 'scan',
					'purpose_override' => $override,
					'first_seen'       => '2026-02-02 00:00:00',
					'pages'            => array( 'https://example.com/old' ),
				)
			),
			self::existing(
				array(
					'id'       => 6,
					'name'     => 'manual-one',
					'category' => 'preferences',
					'status'   => 'declared',
					'source'   => 'manual',
				)
			),
			self::existing(
				array(
					'id'       => 7,
					'name'     => '_ga',
					'category' => 'marketing',
					'status'   => 'declared',
					'source'   => 'library',
				)
			),
		);
		$raw      = array( 'pages' => array( self::page( array( 'cookies_accepted' => array( 'mystery', 'manual-one', '_ga' ) ) ) ) );
		$result   = $this->processor()->process( $raw, $existing );

		$mystery = self::upsert( $result, 'cookie', 'mystery' );
		$this->assertSame( 'marketing', $mystery['category'] );
		$this->assertSame( 'declared', $mystery['status'] );
		$this->assertSame( $override, $mystery['purpose_override'] );
		$this->assertSame( 'scan', $mystery['source'] );
		$this->assertSame( '2026-02-02 00:00:00', $mystery['first_seen'] );
		$this->assertSame( array( 'https://example.com/', 'https://example.com/old' ), $mystery['pages'] );

		$manual = self::upsert( $result, 'cookie', 'manual-one' );
		$this->assertSame( 'preferences', $manual['category'] );
		$this->assertSame( 'manual', $manual['source'] );

		$ga = self::upsert( $result, 'cookie', '_ga' );
		$this->assertSame( 'statistics', $ga['category'] );
		$this->assertSame( 'library', $ga['source'] );
	}

	/**
	 * Pages are distinct, newest first, and capped at five.
	 */
	public function test_pages_capped_at_five_distinct() {
		$existing = array(
			self::existing(
				array(
					'name'  => 'mystery',
					'pages' => array( 'https://example.com/1', 'https://example.com/2', 'https://example.com/3', 'https://example.com/4', 'https://example.com/5' ),
				)
			),
		);
		$pages    = array(
			self::page( array( 'cookies_accepted' => array( 'mystery' ) ), 'https://example.com/new' ),
			self::page( array( 'cookies_accepted' => array( 'mystery' ) ), 'https://example.com/3' ),
		);
		$result   = $this->processor()->process( array( 'pages' => $pages ), $existing );
		$row      = self::upsert( $result, 'cookie', 'mystery' );
		$this->assertSame(
			array( 'https://example.com/new', 'https://example.com/3', 'https://example.com/1', 'https://example.com/2', 'https://example.com/4' ),
			$row['pages']
		);
	}

	/**
	 * Unseen rows are counted, deleted at two misses, except manual and plugin rows.
	 */
	public function test_missed_twice_deleted_but_manual_kept() {
		$existing = array(
			self::existing(
				array(
					'id'           => 10,
					'name'         => 'gone-once',
					'missed_scans' => 0,
				)
			),
			self::existing(
				array(
					'id'           => 11,
					'name'         => 'gone-twice',
					'missed_scans' => 1,
				)
			),
			self::existing(
				array(
					'id'           => 12,
					'name'         => 'manual',
					'source'       => 'manual',
					'status'       => 'declared',
					'missed_scans' => 5,
				)
			),
			self::existing(
				array(
					'id'           => 13,
					'name'         => 'plugin',
					'source'       => 'plugin',
					'status'       => 'declared',
					'missed_scans' => 5,
				)
			),
			self::existing(
				array(
					'id'           => 14,
					'name'         => 'seen',
					'missed_scans' => 1,
				)
			),
		);
		$result   = $this->processor()->process( array( 'pages' => array( self::page( array( 'cookies_accepted' => array( 'seen' ) ) ) ) ), $existing );

		$this->assertSame( array( 11 ), $result['delete_ids'] );
		$this->assertSame( 1, self::upsert( $result, 'cookie', 'gone-once' )['missed_scans'] );
		$this->assertNull( self::upsert( $result, 'cookie', 'gone-twice' ) );
		$this->assertSame( 6, self::upsert( $result, 'cookie', 'manual' )['missed_scans'] );
		$this->assertSame( 0, self::upsert( $result, 'cookie', 'seen' )['missed_scans'] );
	}

	/**
	 * A tracking cookie set before consent is a leak.
	 */
	public function test_leak_detected_from_none_pass() {
		$raw    = array( 'pages' => array( self::page( array( 'cookies_none' => array( '_ga' ) ), 'https://example.com/leaky' ) ) );
		$result = $this->processor()->process( $raw, array() );
		$this->assertSame(
			array(
				array(
					'name' => '_ga',
					'page' => 'https://example.com/leaky',
				),
			),
			$result['leaks']
		);
		$this->assertSame( 1, self::upsert( $result, 'cookie', '_ga' )['pre_consent'] );
	}

	/**
	 * Necessary cookies before consent are fine.
	 */
	public function test_necessary_cookie_in_none_pass_not_leak() {
		$raw    = array( 'pages' => array( self::page( array( 'cookies_none' => array( 'PHPSESSID' ) ) ) ) );
		$result = $this->processor()->process( $raw, array() );
		$this->assertSame( array(), $result['leaks'] );
		$this->assertSame( 0, self::upsert( $result, 'cookie', 'PHPSESSID' )['pre_consent'] );
	}

	/**
	 * The plugin's own cookie is always declared.
	 */
	public function test_rc_consent_always_present() {
		$result = $this->processor()->process( array( 'pages' => array() ), array() );
		$row    = self::upsert( $result, 'cookie', 'rc_consent' );
		$this->assertSame( 'plugin', $row['source'] );
		$this->assertSame( 'necessary', $row['category'] );
		$this->assertSame( 'declared', $row['status'] );
		$this->assertSame( array( 'necessary' ), $result['categories_in_use'] );
	}

	/**
	 * Categories in use come out in canonical order.
	 */
	public function test_categories_in_use_canonical_order() {
		$raw    = array( 'pages' => array( self::page( array( 'cookies_accepted' => array( 'YSC', '_ga', 'mystery' ) ) ) ) );
		$result = $this->processor()->process( $raw, array() );
		$this->assertSame( array( 'necessary', 'statistics', 'marketing' ), $result['categories_in_use'] );
	}

	/**
	 * Failed pages contribute nothing.
	 */
	public function test_failed_page_ignored() {
		$raw    = array(
			'pages' => array(
				self::page(
					array(
						'status'           => 'failed',
						'cookies_accepted' => array( 'mystery' ),
					)
				),
			),
		);
		$result = $this->processor()->process( $raw, array() );
		$this->assertNull( self::upsert( $result, 'cookie', 'mystery' ) );
	}

	/**
	 * Cookiebot and Usercentrics are recognised.
	 */
	public function test_cmp_detected_cookiebot() {
		$raw    = array(
			'pages' => array(
				self::page(
					array(
						'resources' => array(
							'https://consent.cookiebot.com/uc.js',
							'https://consentcdn.cookiebot.com/x.js',
							'https://app.usercentrics.eu/browser-ui/latest/loader.js',
						),
					)
				),
			),
		);
		$result = $this->processor()->process( $raw, array() );
		$this->assertSame( array( 'Cookiebot', 'Usercentrics' ), $result['cmp_detected'] );
		$result = $this->processor()->process( array( 'pages' => array( self::page() ) ), array() );
		$this->assertSame( array(), $result['cmp_detected'] );
	}

	/**
	 * The plugin's own cookie stays plugin-owned when a page reports it.
	 */
	public function test_rc_consent_forced_when_reported_by_pages() {
		$existing = array(
			self::existing(
				array(
					'id'               => 9,
					'name'             => 'rc_consent',
					'category'         => 'marketing',
					'status'           => 'declared',
					'source'           => 'manual',
					'purpose_override' => array(
						'sv' => 'x',
						'en' => 'y',
					),
					'missed_scans'     => 1,
				)
			),
		);
		$raw      = array(
			'pages' => array(
				self::page(
					array(
						'cookies_none'     => array( 'rc_consent' ),
						'cookies_accepted' => array( 'rc_consent' ),
					),
					'https://example.com/p'
				),
			),
		);
		$result   = $this->processor()->process( $raw, $existing );
		$row      = self::upsert( $result, 'cookie', 'rc_consent' );
		$this->assertSame( 'plugin', $row['source'] );
		$this->assertSame( 'necessary', $row['category'] );
		$this->assertSame( 'declared', $row['status'] );
		$this->assertSame( 'revolter-cookies', $row['service_id'] );
		$this->assertNull( $row['purpose_override'] );
		$this->assertSame( 0, $row['missed_scans'] );
		$this->assertSame( array( 'https://example.com/p' ), $row['pages'] );
		$this->assertSame( array(), $result['leaks'] );
		$this->assertSame( array(), $result['delete_ids'] );
	}

	/**
	 * A scan where every page failed changes nothing.
	 */
	public function test_all_failed_scan_changes_nothing() {
		$existing = array(
			self::existing(
				array(
					'id'           => 20,
					'name'         => 'gone',
					'missed_scans' => 1,
				)
			),
			self::existing(
				array(
					'id'       => 21,
					'name'     => '_ga',
					'category' => 'statistics',
					'status'   => 'declared',
					'source'   => 'library',
				)
			),
		);
		$raw      = array( 'pages' => array( self::page( array( 'status' => 'failed' ) ), self::page( array( 'status' => 'failed' ), 'https://example.com/b' ) ) );
		$result   = $this->processor()->process( $raw, $existing );
		$this->assertSame( array(), $result['delete_ids'] );
		$this->assertNull( self::upsert( $result, 'cookie', 'gone' ) );
		$this->assertNull( self::upsert( $result, 'cookie', '_ga' ) );
		$this->assertSame( array( 'necessary', 'statistics' ), $result['categories_in_use'] );
	}
}
