<?php
/**
 * Turns raw scanner output into item changes.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Pure classification of scan data: no database, no WordPress state.
 */
class Scan_Processor {

	const MAX_PAGES = 5;

	const MAX_MISSED = 2;

	const IGNORED_PREFIXES = array( 'wordpress_logged_in_', 'wordpress_sec_', 'wp-settings-', 'comment_author_' );

	const IGNORED_NAMES = array( 'wp-saving-post' );

	const CMP_HOSTS = array(
		'consent.cookiebot.com'    => 'Cookiebot',
		'consentcdn.cookiebot.com' => 'Cookiebot',
		'app.usercentrics.eu'      => 'Usercentrics',
		'web.cmp.usercentrics.eu'  => 'Usercentrics',
	);

	/**
	 * Library.
	 *
	 * @var Library
	 */
	private $library;

	/**
	 * Site host without a leading www.
	 *
	 * @var string
	 */
	private $site_host;

	/**
	 * Provider recorded for unknown items.
	 *
	 * @var string
	 */
	private $company_name;

	/**
	 * Timestamp for first_seen and last_seen, UTC "Y-m-d H:i:s".
	 *
	 * @var string
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param Library     $library      Library.
	 * @param string      $site_host    The site's host name.
	 * @param string      $company_name Provider for unknown items.
	 * @param string|null $now          Timestamp to record, default the current UTC time.
	 */
	public function __construct( Library $library, string $site_host, string $company_name, ?string $now = null ) {
		$this->library      = $library;
		$this->site_host    = self::strip_www( strtolower( $site_host ) );
		$this->company_name = $company_name;
		$this->now          = null === $now ? gmdate( 'Y-m-d H:i:s' ) : $now;
	}

	/**
	 * Process a scan.
	 *
	 * @param array $raw      array( 'pages' => array of page results ).
	 * @param array $existing Stored item rows.
	 * @return array{upserts: array[], delete_ids: int[], leaks: array[], categories_in_use: string[], cmp_detected: string[]}
	 */
	public function process( array $raw, array $existing ): array {
		$candidates = array();
		$none       = array();
		$resources  = array();
		$pages      = isset( $raw['pages'] ) && is_array( $raw['pages'] ) ? $raw['pages'] : array();
		$any_ok     = false;

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || ( isset( $page['status'] ) && 'ok' !== $page['status'] ) ) {
				continue;
			}
			$any_ok = true;
			$url    = isset( $page['url'] ) ? (string) $page['url'] : '';
			foreach ( array( 'cookies_none', 'cookies_accepted', 'server_cookies' ) as $key ) {
				foreach ( self::strings( $page, $key ) as $name ) {
					if ( self::is_ignored( $name ) ) {
						continue;
					}
					$this->add_candidate( $candidates, 'cookie', $name, $this->classify( $name, 'cookie' ), $url );
					if ( 'cookies_none' === $key ) {
						$none[ $name ][ $url ] = true;
					}
				}
			}
			foreach ( self::strings( $page, 'storage' ) as $name ) {
				$this->add_candidate( $candidates, 'storage', $name, $this->classify( $name, 'storage' ), $url );
			}
			// Only scripts and iframes can make an unknown host worth reviewing; images, fonts and
			// avatars only feed service inference. Reports without an embeds list treat every resource as one.
			$embed_hosts = array();
			$embed_urls  = isset( $page['embeds'] ) && is_array( $page['embeds'] ) ? self::strings( $page, 'embeds' ) : self::strings( $page, 'resources' );
			foreach ( $embed_urls as $embed ) {
				$embed_hosts[ self::host_of( $embed ) ] = true;
			}
			foreach ( self::strings( $page, 'resources' ) as $resource ) {
				$host = self::host_of( $resource );
				if ( '' === $host ) {
					continue;
				}
				$resources[ $host ] = true;
				if ( $this->is_own_host( $host ) ) {
					continue;
				}
				$service = $this->library->match_host( $host );
				if ( null === $service ) {
					if ( isset( $embed_hosts[ $host ] ) ) {
						$this->add_candidate( $candidates, 'domain', $host, $this->domain_fields( $host ), $url );
					}
					continue;
				}
				foreach ( $service['cookies'] as $cookie ) {
					if ( ! empty( $cookie['third_party'] ) && ! empty( $cookie['name'] ) ) {
						$type = isset( $cookie['type'] ) ? (string) $cookie['type'] : 'cookie';
						$this->add_candidate( $candidates, $type, (string) $cookie['name'], $this->library_fields( $service, $cookie ), $url );
					}
				}
			}
		}

		$this->add_candidate( $candidates, 'cookie', 'rc_consent', $this->own_cookie_fields(), '', true );

		$by_key = array();
		foreach ( $existing as $row ) {
			if ( isset( $row['type'], $row['name'] ) ) {
				$by_key[ $row['type'] . "\0" . $row['name'] ] = $row;
			}
		}

		$upserts = array();
		$leaks   = array();
		foreach ( $candidates as $key => $candidate ) {
			$row = $this->build_row( $candidate, isset( $by_key[ $key ] ) ? $by_key[ $key ] : null );
			if ( 'cookie' === $row['type'] && 'necessary' !== $row['category'] && isset( $none[ $row['name'] ] ) ) {
				$row['pre_consent'] = 1;
				foreach ( array_keys( $none[ $row['name'] ] ) as $leak_url ) {
					$leaks[] = array(
						'name' => $row['name'],
						'page' => $leak_url,
					);
				}
			}
			$upserts[] = $row;
		}

		$delete_ids = array();
		foreach ( $by_key as $key => $row ) {
			// A scan where every page failed says nothing about what is missing.
			if ( ! $any_ok || isset( $candidates[ $key ] ) ) {
				continue;
			}
			$missed = ( isset( $row['missed_scans'] ) ? (int) $row['missed_scans'] : 0 ) + 1;
			$source = isset( $row['source'] ) ? $row['source'] : 'scan';
			if ( $missed >= self::MAX_MISSED && 'manual' !== $source && 'plugin' !== $source ) {
				$delete_ids[]            = (int) $row['id'];
				$by_key[ $key ]['_gone'] = true;
				continue;
			}
			$upserts[]                      = array(
				'type'         => $row['type'],
				'name'         => $row['name'],
				'missed_scans' => $missed,
			);
			$by_key[ $key ]['missed_scans'] = $missed;
		}

		return array(
			'upserts'           => $upserts,
			'delete_ids'        => $delete_ids,
			'leaks'             => $leaks,
			'categories_in_use' => $this->categories_in_use( $upserts, $by_key ),
			'cmp_detected'      => $this->cmp_detected( array_keys( $resources ) ),
		);
	}

	/**
	 * Merge fresh data with an existing row.
	 *
	 * Rows a human chose (manual, or declared after a scan) keep category and purpose override.
	 *
	 * @param array      $candidate Candidate.
	 * @param array|null $existing  Existing row.
	 * @return array
	 */
	private function build_row( array $candidate, ?array $existing ): array {
		$row = array_merge(
			$candidate['fields'],
			array(
				'type'         => $candidate['type'],
				'name'         => $candidate['name'],
				'first_seen'   => $this->now,
				'last_seen'    => $this->now,
				'missed_scans' => 0,
				'pre_consent'  => 0,
				'pages'        => array_slice( array_keys( $candidate['pages'] ), 0, self::MAX_PAGES ),
			)
		);
		if ( $existing ) {
			if ( ! empty( $existing['first_seen'] ) ) {
				$row['first_seen'] = $existing['first_seen'];
			}
			$old          = isset( $existing['pages'] ) && is_array( $existing['pages'] ) ? $existing['pages'] : array();
			$pages        = array_values( array_unique( array_merge( array_keys( $candidate['pages'] ), $old ) ) );
			$row['pages'] = array_slice( $pages, 0, self::MAX_PAGES );

			$source = isset( $existing['source'] ) ? $existing['source'] : 'scan';
			$human  = ! $candidate['forced']
				&& isset( $existing['status'] ) && 'declared' === $existing['status']
				&& ( 'manual' === $source || 'scan' === $source );
			if ( $human ) {
				$row['category']         = $existing['category'];
				$row['purpose_override'] = isset( $existing['purpose_override'] ) ? $existing['purpose_override'] : null;
				$row['status']           = 'declared';
				$row['source']           = $source;
				if ( 'manual' === $source ) {
					foreach ( array( 'service_id', 'provider', 'duration', 'third_party' ) as $field ) {
						if ( array_key_exists( $field, $existing ) ) {
							$row[ $field ] = $existing[ $field ];
						}
					}
				}
			}
		}
		return $row;
	}

	/**
	 * Register a seen item, remembering the page. The first classification wins.
	 *
	 * @param array  $candidates Candidates by key, by reference.
	 * @param string $type       Type.
	 * @param string $name       Name.
	 * @param array  $fields     Fresh fields.
	 * @param string $url        Page url.
	 * @param bool   $forced     Whether existing human choices must be overridden.
	 */
	private function add_candidate( array &$candidates, string $type, string $name, array $fields, string $url, bool $forced = false ): void {
		$key = $type . "\0" . $name;
		if ( ! isset( $candidates[ $key ] ) ) {
			$candidates[ $key ] = array(
				'type'   => $type,
				'name'   => $name,
				'fields' => $fields,
				'pages'  => array(),
				'forced' => $forced,
			);
		} elseif ( $forced ) {
			$candidates[ $key ]['fields'] = $fields;
			$candidates[ $key ]['forced'] = true;
		}
		if ( '' !== $url ) {
			$candidates[ $key ]['pages'][ $url ] = true;
		}
	}

	/**
	 * Fields for a cookie or storage key from the library, or for an unknown one.
	 *
	 * @param string $name Name.
	 * @param string $type cookie or storage.
	 * @return array
	 */
	private function classify( string $name, string $type ): array {
		$hit = $this->library->match_cookie( $name, $type );
		if ( $hit ) {
			return $this->library_fields( $hit['service'], $hit['cookie'] );
		}
		return array(
			'service_id'       => null,
			'provider'         => $this->company_name,
			'category'         => 'unclassified',
			'status'           => 'needs_review',
			'purpose_override' => null,
			'duration'         => null,
			'third_party'      => 0,
			'source'           => 'scan',
		);
	}

	/**
	 * Declared fields from a library service and cookie.
	 *
	 * @param array $service Service.
	 * @param array $cookie  Cookie entry.
	 * @return array
	 */
	private function library_fields( array $service, array $cookie ): array {
		return array(
			'service_id'       => $service['id'],
			'provider'         => isset( $service['provider'] ) ? (string) $service['provider'] : '',
			'category'         => isset( $cookie['category'] ) ? $cookie['category'] : ( isset( $service['category'] ) ? $service['category'] : 'unclassified' ),
			'status'           => 'declared',
			'purpose_override' => null,
			'duration'         => isset( $cookie['duration'] ) ? Duration::encode( $cookie['duration'] ) : null,
			'third_party'      => ! empty( $cookie['third_party'] ) ? 1 : 0,
			'source'           => 'library',
		);
	}

	/**
	 * Fields for an unknown third-party host.
	 *
	 * @param string $host Host.
	 * @return array
	 */
	private function domain_fields( string $host ): array {
		return array(
			'service_id'       => null,
			'provider'         => $host,
			'category'         => 'unclassified',
			'status'           => 'needs_review',
			'purpose_override' => null,
			'duration'         => null,
			'third_party'      => 1,
			'source'           => 'scan',
		);
	}

	/**
	 * Fields for the plugin's own consent cookie.
	 *
	 * @return array
	 */
	private function own_cookie_fields(): array {
		$hit                = $this->library->match_cookie( 'rc_consent', 'cookie' );
		$fields             = $hit
			? $this->library_fields( $hit['service'], $hit['cookie'] )
			: array(
				'service_id'       => 'revolter-cookies',
				'provider'         => 'Revolter',
				'purpose_override' => null,
				'duration'         => Duration::encode(
					array(
						'n'    => 1,
						'unit' => 'year',
					)
				),
				'third_party'      => 0,
			);
		$fields['category'] = 'necessary';
		$fields['status']   = 'declared';
		$fields['source']   = 'plugin';
		return $fields;
	}

	/**
	 * Categories with a declared cookie or storage item, or a declared domain that waits for consent, canonical order.
	 *
	 * @param array[] $upserts Upserts.
	 * @param array[] $by_key  Existing rows by key.
	 * @return string[]
	 */
	private function categories_in_use( array $upserts, array $by_key ): array {
		$final = array();
		foreach ( $by_key as $key => $row ) {
			if ( empty( $row['_gone'] ) ) {
				$final[ $key ] = $row;
			}
		}
		foreach ( $upserts as $upsert ) {
			$key           = $upsert['type'] . "\0" . $upsert['name'];
			$final[ $key ] = array_merge( isset( $final[ $key ] ) ? $final[ $key ] : array(), $upsert );
		}
		return Item_Repository::categories_in_use( array_values( $final ) );
	}

	/**
	 * Consent management platforms found among the hosts.
	 *
	 * @param string[] $hosts Hosts.
	 * @return string[]
	 */
	private function cmp_detected( array $hosts ): array {
		$found = array();
		foreach ( self::CMP_HOSTS as $host => $label ) {
			if ( in_array( $host, $hosts, true ) && ! in_array( $label, $found, true ) ) {
				$found[] = $label;
			}
		}
		return $found;
	}

	/**
	 * Whether a host belongs to the site: itself, its www twin or a subdomain.
	 *
	 * @param string $host Lowercase host.
	 * @return bool
	 */
	private function is_own_host( string $host ): bool {
		if ( '' === $this->site_host ) {
			return false;
		}
		$host = self::strip_www( $host );
		return $host === $this->site_host || substr( $host, -strlen( $this->site_host ) - 1 ) === '.' . $this->site_host;
	}

	/**
	 * Whether a cookie belongs to a logged-in user only.
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	private static function is_ignored( string $name ): bool {
		if ( in_array( $name, self::IGNORED_NAMES, true ) ) {
			return true;
		}
		foreach ( self::IGNORED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lowercase host of a URL, empty for non-http(s) or invalid URLs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( string $url ): string {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( rtrim( $host, '.' ) ) : '';
	}

	/**
	 * Remove a leading www.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function strip_www( string $host ): string {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Non-empty string entries of a list field.
	 *
	 * @param array  $page Page.
	 * @param string $key  Field.
	 * @return string[]
	 */
	private static function strings( array $page, string $key ): array {
		if ( empty( $page[ $key ] ) || ! is_array( $page[ $key ] ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$page[ $key ],
				static function ( $value ) {
					return is_string( $value ) && '' !== $value;
				}
			)
		);
	}
}
