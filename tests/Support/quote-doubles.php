<?php
/**
 * The service doubles Orders\QuoteOrder is constructed with.
 *
 * They live here rather than beside one test class because two suites now
 * build a QuoteOrder (QuoteConvertTest and QuoteOrderCreateTest) and the
 * standalone runner requires each file only when it reaches it: doubles
 * declared inside one test file do not exist yet for the file that sorts
 * before it. Loading them from the bootstrap makes the order irrelevant
 * under both the runner and PHPUnit.
 *
 * Each double replaces a service that talks to the database or the log —
 * Store, Audit\Log, Settings, Logger — and records instead. Settings is
 * the one with production-shaped values (the shipped defaults); a suite
 * that needs different ones subclasses Settings itself, as
 * QuoteConvertTest does.
 *
 * Requires the autoloader, so the bootstrap loads this file last.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

final class QuoteOrderTestStore extends \POW\Sessions\Store {

	/** @var array<int, array<string, mixed>> */
	public static array $updates = [];

	public function __construct() {}

	/**
	 * @param array<string, mixed> $data Column values.
	 */
	public function update( int $id, array $data ): bool {
		self::$updates[ $id ] = $data;

		return true;
	}
	public function link_quote_if_empty( int $id, int $order_id ): string {
		self::$updates[ $id ] = [ 'order_id' => $order_id ];
		return 'linked';
	}

}

final class QuoteOrderTestLog extends \POW\Audit\Log {

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	public static array $written = [];

	public function __construct() {}

	/**
	 * @param array<string, mixed> $context Audit context.
	 */
	public function write( string $event, array $context = [] ): void {
		self::$written[] = [ $event, $context ];
	}
	public function write_checked( string $event, array $context = [] ): bool {
		$this->write( $event, $context );
		return true;
	}

}

final class QuoteOrderTestSettings extends \POW\Settings {

	public function __construct() {}

	public function int( string $key ): int {
		return 'quote_retention_days' === $key ? 90 : 0;
	}

	/**
	 * @param mixed $default Fallback when unset.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return 'quote_convert_status' === $key ? 'pending' : $default;
	}
}

final class QuoteOrderTestLogger extends \POW\Logger {

	public function __construct() {}

	/**
	 * @param array<string, mixed> $context Log context.
	 */
	public function info( string $message, array $context = [] ): void {
		if ( isset( $GLOBALS['pow_test_logger_error'] ) ) { throw $GLOBALS['pow_test_logger_error']; }
	}
	public function error( string $message, array $context = [] ): void {
		$GLOBALS['pow_test_errors'][] = [ $message, $context ];
		if ( isset( $GLOBALS['pow_test_logger_error'] ) ) { throw $GLOBALS['pow_test_logger_error']; }
	}
}
