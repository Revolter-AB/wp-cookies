<?php
/**
 * Blocking rules.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Patterns that decide which scripts and iframes wait for consent.
 */
class Blocking_Rules {

	const CATEGORIES = array( 'necessary', 'preferences', 'statistics', 'marketing', 'unclassified' );

	const KINDS = array( 'script', 'iframe', 'inline', 'host' );

	/**
	 * Rules grouped by kind: kind => list of array( pattern (lowercase), category, label, original pattern ).
	 *
	 * @var array
	 */
	private $by_kind = array(
		'script' => array(),
		'iframe' => array(),
		'inline' => array(),
		'host'   => array(),
	);

	/**
	 * Rules in insertion order.
	 *
	 * @var array
	 */
	private $rules = array();

	/**
	 * Constructor.
	 *
	 * @param array $rules List of array( 'pattern', 'category', 'label', 'kind' ).
	 */
	public function __construct( array $rules ) {
		$seen = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['pattern'], $rule['category'], $rule['kind'] ) ) {
				continue;
			}
			$pattern  = trim( (string) $rule['pattern'] );
			$category = (string) $rule['category'];
			$kind     = (string) $rule['kind'];
			if ( '' === $pattern || 'necessary' === $category || ! in_array( $category, self::CATEGORIES, true ) || ! in_array( $kind, self::KINDS, true ) ) {
				continue;
			}
			$lower = strtolower( $pattern );
			if ( 'host' === $kind ) {
				$lower = rtrim( (string) preg_replace( '#^(?:[a-z][a-z0-9+.-]*:)?//#', '', $lower ), '/.' );
				if ( '' === $lower ) {
					continue;
				}
			}
			$key = $kind . "\0" . $lower;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$label        = isset( $rule['label'] ) && '' !== (string) $rule['label'] ? (string) $rule['label'] : $pattern;
			$entry        = array(
				'pattern'  => $pattern,
				'category' => $category,
				'label'    => $label,
				'kind'     => $kind,
				'lower'    => $lower,
			);

			$this->rules[]            = $entry;
			$this->by_kind[ $kind ][] = $entry;
		}
	}

	/**
	 * Build rules from the library, settings and reviewed domain items.
	 *
	 * @param Library $lib              Library.
	 * @param array   $settings         Settings (gtm_category, manual_rules).
	 * @param array   $reviewed_domains Domain item rows (name, category, status).
	 * @return Blocking_Rules
	 */
	public static function build( Library $lib, array $settings, array $reviewed_domains ): Blocking_Rules {
		$rules = array();

		$manual = isset( $settings['manual_rules'] ) && is_array( $settings['manual_rules'] ) ? $settings['manual_rules'] : array();
		foreach ( $manual as $row ) {
			if ( ! is_array( $row ) || empty( $row['pattern'] ) || empty( $row['category'] ) ) {
				continue;
			}
			foreach ( array( 'script', 'iframe', 'host' ) as $kind ) {
				$rules[] = array(
					'pattern'  => (string) $row['pattern'],
					'category' => (string) $row['category'],
					'label'    => (string) $row['pattern'],
					'kind'     => $kind,
				);
			}
		}

		$gtm_category = isset( $settings['gtm_category'] ) ? (string) $settings['gtm_category'] : 'statistics';
		foreach ( $lib->services() as $service ) {
			$category = isset( $service['category'] ) ? (string) $service['category'] : '';
			if ( 'google-tag-manager' === $service['id'] ) {
				$category = $gtm_category;
			}
			if ( '' === $category || 'necessary' === $category ) {
				continue;
			}
			$label = isset( $service['label'] ) ? (string) $service['label'] : (string) $service['id'];
			$kinds = array(
				'scripts' => 'script',
				'iframes' => 'iframe',
				'inline'  => 'inline',
				'domains' => 'host',
			);
			foreach ( $kinds as $key => $kind ) {
				foreach ( $service[ $key ] as $pattern ) {
					$rules[] = array(
						'pattern'  => (string) $pattern,
						'category' => $category,
						'label'    => $label,
						'kind'     => $kind,
					);
				}
			}
		}

		foreach ( $reviewed_domains as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || empty( $row['category'] ) ) {
				continue;
			}
			if ( isset( $row['status'] ) && 'declared' !== $row['status'] ) {
				continue;
			}
			$label = (string) $row['name'];
			if ( ! empty( $row['service_id'] ) ) {
				$service = $lib->service( (string) $row['service_id'] );
				if ( $service && ! empty( $service['label'] ) ) {
					$label = (string) $service['label'];
				}
			}
			$rules[] = array(
				'pattern'  => (string) $row['name'],
				'category' => (string) $row['category'],
				'label'    => $label,
				'kind'     => 'host',
			);
		}

		return new self( $rules );
	}

	/**
	 * Match a script by src and/or inline text. Longest pattern wins.
	 *
	 * @param string|null $src    Script src.
	 * @param string      $inline Inline body.
	 * @return array|null array( 'category', 'label' ).
	 */
	public function match_script( ?string $src, string $inline ): ?array {
		$best = null;
		$len  = 0;
		if ( null !== $src && '' !== trim( $src ) ) {
			$url = self::normalise_url( $src );
			$this->best_substring( $url, $this->by_kind['script'], $best, $len );
			$this->best_host( self::host_of( $src ), $best, $len );
		}
		if ( '' !== trim( $inline ) ) {
			$text = strtolower( str_replace( '\\/', '/', $inline ) );
			$this->best_substring( $text, $this->by_kind['inline'], $best, $len );
			foreach ( $this->by_kind['script'] as $rule ) {
				$plen = strlen( $rule['lower'] );
				if ( $plen > $len && self::contains_as_url( $text, $rule['lower'] ) ) {
					$best = $rule;
					$len  = $plen;
				}
			}
		}
		return self::result( $best );
	}

	/**
	 * Match an iframe src. Longest pattern wins.
	 *
	 * @param string $src Iframe src.
	 * @return array|null array( 'category', 'label' ).
	 */
	public function match_iframe( string $src ): ?array {
		if ( '' === trim( $src ) ) {
			return null;
		}
		$best = null;
		$len  = 0;
		$this->best_substring( self::normalise_url( $src ), $this->by_kind['iframe'], $best, $len );
		$this->best_host( self::host_of( $src ), $best, $len );
		return self::result( $best );
	}

	/**
	 * Rules for the client-side guard: kinds script, iframe and host.
	 *
	 * Key k is 'host' (URL host equals p or ends in .p) or 'sub' (substring of the normalised URL).
	 *
	 * @return array List of array( 'p', 'c', 'l', 'k' ).
	 */
	public function for_js(): array {
		$out  = array();
		$seen = array();
		foreach ( $this->rules as $rule ) {
			if ( 'inline' === $rule['kind'] ) {
				continue;
			}
			$k   = 'host' === $rule['kind'] ? 'host' : 'sub';
			$key = $k . "\0" . $rule['lower'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'p' => $rule['lower'],
				'c' => $rule['category'],
				'l' => $rule['label'],
				'k' => $k,
			);
		}
		return $out;
	}

	/**
	 * All patterns, for noscript stripping.
	 *
	 * @return string[]
	 */
	public function signatures(): array {
		$out = array();
		foreach ( $this->rules as $rule ) {
			$out[ $rule['lower'] ] = true;
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Keep the longest substring hit.
	 *
	 * @param string     $haystack Lowercase haystack.
	 * @param array      $rules    Rules.
	 * @param array|null $best     Best rule so far (by reference).
	 * @param int        $len      Best length so far (by reference).
	 */
	private function best_substring( string $haystack, array $rules, ?array &$best, int &$len ): void {
		if ( '' === $haystack ) {
			return;
		}
		foreach ( $rules as $rule ) {
			$plen = strlen( $rule['lower'] );
			if ( $plen > $len && false !== strpos( $haystack, $rule['lower'] ) ) {
				$best = $rule;
				$len  = $plen;
			}
		}
	}

	/**
	 * Keep the longest host hit: equal to the domain or a subdomain of it.
	 *
	 * @param string     $host Lowercase host.
	 * @param array|null $best Best rule so far (by reference).
	 * @param int        $len  Best length so far (by reference).
	 */
	private function best_host( string $host, ?array &$best, int &$len ): void {
		if ( '' === $host ) {
			return;
		}
		foreach ( $this->by_kind['host'] as $rule ) {
			$domain = $rule['lower'];
			$plen   = strlen( $domain );
			if ( $plen > $len && ( $host === $domain || substr( $host, -$plen - 1 ) === '.' . $domain ) ) {
				$best = $rule;
				$len  = $plen;
			}
		}
	}

	/**
	 * Whether the text contains the pattern as part of a URL: preceded by "//" or ".".
	 * JSON-escaped slashes are unescaped by the caller first.
	 *
	 * @param string $text    Lowercase text.
	 * @param string $pattern Lowercase pattern.
	 * @return bool
	 */
	private static function contains_as_url( string $text, string $pattern ): bool {
		$offset = 0;
		while ( false !== ( $pos = strpos( $text, $pattern, $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( $pos >= 1 && '.' === $text[ $pos - 1 ] ) {
				return true;
			}
			if ( $pos >= 2 && '//' === substr( $text, $pos - 2, 2 ) ) {
				return true;
			}
			$offset = $pos + 1;
		}
		return false;
	}

	/**
	 * Public shape of a matched rule.
	 *
	 * @param array|null $rule Rule.
	 * @return array|null
	 */
	private static function result( ?array $rule ): ?array {
		if ( null === $rule ) {
			return null;
		}
		return array(
			'category' => $rule['category'],
			'label'    => $rule['label'],
		);
	}

	/**
	 * Lowercase a URL and strip the scheme and leading slashes.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalise_url( string $url ): string {
		return (string) preg_replace( '#^(?:[a-z][a-z0-9+.-]*:)?//#', '', strtolower( trim( $url ) ) );
	}

	/**
	 * Lowercase host of an absolute or protocol-relative URL, '' for relative URLs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( string $url ): string {
		$url = trim( $url );
		if ( ! preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url ) ) {
			return '';
		}
		$host = wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( rtrim( $host, '.' ) ) : '';
	}
}
