<?php
/**
 * Known services and cookies.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only index over data/library.json.
 */
class Library {

	/**
	 * Services in file order.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Services by id.
	 *
	 * @var array
	 */
	private $by_id = array();

	/**
	 * Exact cookie index: "type\0name" => array( service index, cookie index ).
	 *
	 * @var array
	 */
	private $exact = array();

	/**
	 * Prefix cookies, longest first: array( type, name, service index, cookie index ).
	 *
	 * @var array
	 */
	private $prefixes = array();

	/**
	 * Memoised libraries by path.
	 *
	 * @var Library[]
	 */
	private static $loaded = array();

	/**
	 * Constructor.
	 *
	 * @param array $data Decoded library data.
	 */
	public function __construct( array $data ) {
		$services = isset( $data['services'] ) && is_array( $data['services'] ) ? array_values( $data['services'] ) : array();
		foreach ( $services as $i => $service ) {
			if ( ! is_array( $service ) || empty( $service['id'] ) ) {
				continue;
			}
			foreach ( array( 'scripts', 'inline', 'iframes', 'domains', 'cookies' ) as $key ) {
				if ( ! isset( $service[ $key ] ) || ! is_array( $service[ $key ] ) ) {
					$service[ $key ] = array();
				}
			}
			$this->services[ $i ]          = $service;
			$this->by_id[ $service['id'] ] = $i;
			foreach ( $service['cookies'] as $j => $cookie ) {
				$type = isset( $cookie['type'] ) ? $cookie['type'] : 'cookie';
				if ( ! isset( $cookie['name'] ) || '' === $cookie['name'] ) {
					continue;
				}
				if ( isset( $cookie['match'] ) && 'prefix' === $cookie['match'] ) {
					$this->prefixes[] = array( $type, $cookie['name'], $i, $j );
				} elseif ( ! isset( $this->exact[ $type . "\0" . $cookie['name'] ] ) ) {
					$this->exact[ $type . "\0" . $cookie['name'] ] = array( $i, $j );
				}
			}
		}
		usort(
			$this->prefixes,
			static function ( $a, $b ) {
				return strlen( $b[1] ) - strlen( $a[1] );
			}
		);
	}

	/**
	 * Load (and memoise) a library file.
	 *
	 * @param string|null $path File path, default data/library.json.
	 * @return Library
	 */
	public static function from_file( ?string $path = null ): Library {
		if ( null === $path ) {
			$path = REVOLTER_COOKIES_PATH . 'data/library.json';
		}
		if ( isset( self::$loaded[ $path ] ) ) {
			return self::$loaded[ $path ];
		}
		$decoded = null;
		if ( is_readable( $path ) ) {
			$json    = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$decoded = json_decode( (string) $json, true );
		}
		if ( ! is_array( $decoded ) || empty( $decoded['services'] ) || ! is_array( $decoded['services'] ) ) {
			// A missing or broken file is not memoised, so the next call tries again.
			return new self( array() );
		}
		self::$loaded[ $path ] = new self( $decoded );
		return self::$loaded[ $path ];
	}

	/**
	 * Whether the library file loads and holds services.
	 *
	 * @param string|null $path File path, default data/library.json.
	 * @return bool
	 */
	public static function is_available( ?string $path = null ): bool {
		return array() !== self::from_file( $path )->services();
	}

	/**
	 * Service by id.
	 *
	 * @param string $id Service id.
	 * @return array|null
	 */
	public function service( string $id ): ?array {
		return isset( $this->by_id[ $id ] ) ? $this->services[ $this->by_id[ $id ] ] : null;
	}

	/**
	 * All services.
	 *
	 * @return array
	 */
	public function services(): array {
		return array_values( $this->services );
	}

	/**
	 * Match a cookie or storage key. Exact beats prefix; a longer prefix beats a shorter one.
	 *
	 * @param string $name Cookie or storage key name.
	 * @param string $type "cookie" or "storage".
	 * @return array|null array( 'service' => array, 'cookie' => array ).
	 */
	public function match_cookie( string $name, string $type = 'cookie' ): ?array {
		$key = $type . "\0" . $name;
		if ( isset( $this->exact[ $key ] ) ) {
			return $this->hit( $this->exact[ $key ][0], $this->exact[ $key ][1] );
		}
		foreach ( $this->prefixes as $prefix ) {
			if ( $prefix[0] === $type && 0 === strpos( $name, $prefix[1] ) ) {
				return $this->hit( $prefix[2], $prefix[3] );
			}
		}
		return null;
	}

	/**
	 * Match a script by src and/or inline body (case-insensitive substring, longest pattern wins).
	 *
	 * @param string|null $src    Script src, if any.
	 * @param string      $inline Inline script body.
	 * @return array|null Service.
	 */
	public function match_script( ?string $src, string $inline ): ?array {
		$best     = null;
		$best_len = 0;
		$src      = null === $src ? '' : self::normalise_url( $src );
		$inline   = strtolower( $inline );
		foreach ( $this->services as $service ) {
			$checks = array(
				array( $src, $service['scripts'] ),
				array( $inline, $service['scripts'] ),
				array( $inline, $service['inline'] ),
			);
			foreach ( $checks as $check ) {
				$len = self::longest_hit( $check[0], $check[1] );
				if ( $len > $best_len ) {
					$best     = $service;
					$best_len = $len;
				}
			}
		}
		return $best;
	}

	/**
	 * Match an iframe src.
	 *
	 * @param string $src Iframe src.
	 * @return array|null Service.
	 */
	public function match_iframe( string $src ): ?array {
		$src      = self::normalise_url( $src );
		$best     = null;
		$best_len = 0;
		foreach ( $this->services as $service ) {
			$len = self::longest_hit( $src, $service['iframes'] );
			if ( $len > $best_len ) {
				$best     = $service;
				$best_len = $len;
			}
		}
		return $best;
	}

	/**
	 * Match a host: equal to a service domain or a subdomain of it.
	 *
	 * @param string $host Host name.
	 * @return array|null Service.
	 */
	public function match_host( string $host ): ?array {
		$host = strtolower( rtrim( $host, '.' ) );
		if ( '' === $host ) {
			return null;
		}
		foreach ( $this->services as $service ) {
			foreach ( $service['domains'] as $domain ) {
				$domain = strtolower( (string) $domain );
				if ( '' === $domain ) {
					continue;
				}
				if ( $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) {
					return $service;
				}
			}
		}
		return null;
	}

	/**
	 * Purpose text in a language, falling back to English.
	 *
	 * @param array  $cookie Cookie entry.
	 * @param string $lang   Language code (sv or en).
	 * @return string
	 */
	public function purpose( array $cookie, string $lang ): string {
		$purpose = isset( $cookie['purpose'] ) && is_array( $cookie['purpose'] ) ? $cookie['purpose'] : array();
		if ( ! empty( $purpose[ $lang ] ) ) {
			return (string) $purpose[ $lang ];
		}
		return isset( $purpose['en'] ) ? (string) $purpose['en'] : '';
	}

	/**
	 * Build a cookie match result.
	 *
	 * @param int $i Service index.
	 * @param int $j Cookie index.
	 * @return array
	 */
	private function hit( int $i, int $j ): array {
		return array(
			'service' => $this->services[ $i ],
			'cookie'  => $this->services[ $i ]['cookies'][ $j ],
		);
	}

	/**
	 * Lowercase a URL and strip the scheme and leading slashes.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalise_url( string $url ): string {
		$url = strtolower( trim( $url ) );
		$url = (string) preg_replace( '#^(?:[a-z][a-z0-9+.-]*:)?//#', '', $url );
		return $url;
	}

	/**
	 * Length of the longest pattern found in the haystack, 0 if none.
	 *
	 * @param string $haystack Lowercase haystack.
	 * @param array  $patterns Patterns.
	 * @return int
	 */
	private static function longest_hit( string $haystack, array $patterns ): int {
		$best = 0;
		if ( '' === $haystack ) {
			return 0;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( (string) $pattern );
			$len     = strlen( $pattern );
			if ( $len > $best && false !== strpos( $haystack, $pattern ) ) {
				$best = $len;
			}
		}
		return $best;
	}
}
