<?php
/**
 * Duration tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Duration;

/**
 * Verifies labels and the DB encoding.
 */
class TestDuration extends WP_UnitTestCase {

	/**
	 * Build a duration array.
	 *
	 * @param int    $n    Amount.
	 * @param string $unit Unit.
	 * @return array
	 */
	private static function d( int $n, string $unit ): array {
		return array(
			'n'    => $n,
			'unit' => $unit,
		);
	}

	/**
	 * Labels.
	 */
	public function test_duration_labels() {
		$this->assertSame( '2 years', Duration::label( self::d( 2, 'year' ) ) );
		$this->assertSame( '1 year', Duration::label( self::d( 1, 'year' ) ) );
		$this->assertSame( '30 minutes', Duration::label( self::d( 30, 'minute' ) ) );
		$this->assertSame( '1 day', Duration::label( self::d( 1, 'day' ) ) );
		$this->assertSame( '3 months', Duration::label( self::d( 3, 'month' ) ) );
		$this->assertSame( '2 hours', Duration::label( self::d( 2, 'hour' ) ) );
		$this->assertSame( 'Session', Duration::label( 'session' ) );
		$this->assertSame( 'Persistent', Duration::label( 'persistent' ) );
		$this->assertSame( '', Duration::label( 'garbage' ) );
	}

	/**
	 * Encode and decode are inverse.
	 */
	public function test_duration_encode_roundtrip() {
		$this->assertSame( 'session', Duration::encode( 'session' ) );
		$this->assertSame( 'persistent', Duration::encode( 'persistent' ) );
		$this->assertSame( '2:year', Duration::encode( self::d( 2, 'year' ) ) );
		foreach ( array( 'session', 'persistent', self::d( 2, 'year' ), self::d( 30, 'minute' ) ) as $d ) {
			$this->assertSame( $d, Duration::decode( Duration::encode( $d ) ) );
		}
		$this->assertNull( Duration::decode( null ) );
		$this->assertNull( Duration::decode( '' ) );
		$this->assertNull( Duration::decode( '2:decade' ) );
		$this->assertNull( Duration::decode( 'x:year' ) );
		$this->assertNull( Duration::decode( '0:year' ) );
	}
}
