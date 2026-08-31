<?php
/**
 * Minimal PHPUnit-compatible TestCase shim.
 *
 * Loaded only when PHPUnit itself is not installed (see bootstrap.php), so
 * the suite runs with plain `php tests/run-tests.php` on hosts without
 * Composer. Under real PHPUnit this file defines nothing.
 *
 * Only the assertions this suite uses are implemented.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PHPUnit\Framework;

if ( class_exists( TestCase::class ) ) {
	return;
}

class AssertionFailedError extends \Exception {}

class SkippedTestError extends \Exception {}

abstract class TestCase {

	private ?string $expected_exception = null;

	protected function setUp(): void {}

	protected function tearDown(): void {}

	/**
	 * Run one test method with setUp/tearDown and expectException handling.
	 */
	public function runBare( string $method ): void {
		$this->expected_exception = null;
		$this->setUp();

		try {
			$this->$method();

			if ( null !== $this->expected_exception ) {
				throw new AssertionFailedError( "Expected exception {$this->expected_exception} was not thrown" );
			}
		} catch ( SkippedTestError | AssertionFailedError $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			if ( null === $this->expected_exception || ! ( $e instanceof $this->expected_exception ) ) {
				throw $e;
			}
		} finally {
			$this->tearDown();
		}
	}

	public function expectException( string $class ): void {
		$this->expected_exception = $class;
	}

	public static function markTestSkipped( string $message = '' ): void {
		throw new SkippedTestError( $message );
	}

	public static function fail( string $message = '' ): void {
		throw new AssertionFailedError( $message ?: 'fail() called' );
	}

	private static function check( bool $ok, string $message ): void {
		if ( ! $ok ) {
			throw new AssertionFailedError( $message );
		}
	}

	private static function export( mixed $value ): string {
		return is_string( $value ) ? "'" . ( strlen( $value ) > 200 ? substr( $value, 0, 200 ) . '…' : $value ) . "'" : var_export( $value, true );
	}

	public static function assertTrue( mixed $condition, string $message = '' ): void {
		self::check( true === $condition, $message ?: 'Failed asserting that ' . self::export( $condition ) . ' is true' );
	}

	public static function assertFalse( mixed $condition, string $message = '' ): void {
		self::check( false === $condition, $message ?: 'Failed asserting that ' . self::export( $condition ) . ' is false' );
	}

	public static function assertSame( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check(
			$expected === $actual,
			$message ?: 'Failed asserting that ' . self::export( $actual ) . ' is identical to ' . self::export( $expected )
		);
	}

	public static function assertNotSame( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $expected !== $actual, $message ?: 'Failed asserting values are not identical' );
	}

	public static function assertEquals( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $expected == $actual, $message ?: 'Failed asserting that ' . self::export( $actual ) . ' equals ' . self::export( $expected ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	public static function assertNull( mixed $actual, string $message = '' ): void {
		self::check( null === $actual, $message ?: 'Failed asserting that ' . self::export( $actual ) . ' is null' );
	}

	public static function assertNotNull( mixed $actual, string $message = '' ): void {
		self::check( null !== $actual, $message ?: 'Failed asserting that value is not null' );
	}

	public static function assertCount( int $expected, mixed $haystack, string $message = '' ): void {
		self::check( is_countable( $haystack ) && count( $haystack ) === $expected, $message ?: 'Failed asserting count of ' . $expected );
	}

	public static function assertInstanceOf( string $class, mixed $actual, string $message = '' ): void {
		self::check( $actual instanceof $class, $message ?: 'Failed asserting instance of ' . $class );
	}

	public static function assertGreaterThan( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $actual > $expected, $message ?: 'Failed asserting ' . self::export( $actual ) . ' > ' . self::export( $expected ) );
	}

	public static function assertStringContainsString( string $needle, string $haystack, string $message = '' ): void {
		self::check( str_contains( $haystack, $needle ), $message ?: 'Failed asserting that string contains ' . self::export( $needle ) );
	}

	public static function assertStringNotContainsString( string $needle, string $haystack, string $message = '' ): void {
		self::check( ! str_contains( $haystack, $needle ), $message ?: 'Failed asserting that string does NOT contain ' . self::export( $needle ) );
	}

	public static function assertMatchesRegularExpression( string $pattern, string $string, string $message = '' ): void {
		self::check( 1 === preg_match( $pattern, $string ), $message ?: 'Failed asserting that string matches ' . $pattern );
	}
}
