<?php
/**
 * Cookie lifetime helpers.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Labels and database encoding for cookie durations.
 *
 * A duration is "session", "persistent" or array( 'n' => int, 'unit' => unit ).
 */
class Duration {

	const UNITS = array( 'minute', 'hour', 'day', 'month', 'year' );

	/**
	 * Whether a value is a valid duration.
	 *
	 * @param mixed $duration Duration.
	 * @return bool
	 */
	public static function is_valid( $duration ): bool {
		if ( 'session' === $duration || 'persistent' === $duration ) {
			return true;
		}
		return is_array( $duration )
			&& isset( $duration['n'], $duration['unit'] )
			&& is_int( $duration['n'] )
			&& $duration['n'] > 0
			&& in_array( $duration['unit'], self::UNITS, true );
	}

	/**
	 * Human readable, translated label.
	 *
	 * @param mixed $duration Duration.
	 * @return string Empty string for an invalid duration.
	 */
	public static function label( $duration ): string {
		if ( ! self::is_valid( $duration ) ) {
			return '';
		}
		if ( 'session' === $duration ) {
			return __( 'Session', 'revolter-cookies' );
		}
		if ( 'persistent' === $duration ) {
			return __( 'Persistent', 'revolter-cookies' );
		}
		$n = $duration['n'];
		switch ( $duration['unit'] ) {
			case 'minute':
				/* translators: %d: number of minutes. */
				$format = _n( '%d minute', '%d minutes', $n, 'revolter-cookies' );
				break;
			case 'hour':
				/* translators: %d: number of hours. */
				$format = _n( '%d hour', '%d hours', $n, 'revolter-cookies' );
				break;
			case 'day':
				/* translators: %d: number of days. */
				$format = _n( '%d day', '%d days', $n, 'revolter-cookies' );
				break;
			case 'month':
				/* translators: %d: number of months. */
				$format = _n( '%d month', '%d months', $n, 'revolter-cookies' );
				break;
			default:
				/* translators: %d: number of years. */
				$format = _n( '%d year', '%d years', $n, 'revolter-cookies' );
		}
		return sprintf( $format, $n );
	}

	/**
	 * Encode for the database column: "session", "persistent" or "2:year".
	 *
	 * An invalid duration is stored as "persistent".
	 *
	 * @param mixed $duration Duration.
	 * @return string
	 */
	public static function encode( $duration ): string {
		if ( ! self::is_valid( $duration ) ) {
			return 'persistent';
		}
		if ( is_array( $duration ) ) {
			return $duration['n'] . ':' . $duration['unit'];
		}
		return $duration;
	}

	/**
	 * Decode a database value back to the JSON form.
	 *
	 * @param mixed $value Encoded value; null (an empty database column) and non-strings decode to null.
	 * @return string|array|null Null when not a valid encoding.
	 */
	public static function decode( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		if ( 'session' === $value || 'persistent' === $value ) {
			return $value;
		}
		if ( preg_match( '/^([1-9][0-9]*):(minute|hour|day|month|year)$/', $value, $m ) ) {
			return array(
				'n'    => (int) $m[1],
				'unit' => $m[2],
			);
		}
		return null;
	}
}
