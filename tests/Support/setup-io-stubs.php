<?php
/**
 * Native I/O seams for the real setup endpoint and parser. Only active while a test sets pow_test_setup_io; other calls delegate to PHP. No plugin class or method is replaced.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http {
	function fopen( string $filename, string $mode ) { // phpcs:ignore
		if ( 'php://input' !== $filename || ! isset( $GLOBALS['pow_test_setup_io'] ) ) {
			return \fopen( $filename, $mode );
		}
		$GLOBALS['pow_test_setup_io']['reads']++;
		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, $GLOBALS['pow_test_setup_io']['body'] );
		\rewind( $stream );
		return $stream;
	}

	function header( string $header, bool $replace = true, int $response_code = 0 ): void {
		if ( isset( $GLOBALS['pow_test_setup_io'] ) ) {
			$GLOBALS['pow_test_setup_io']['headers'][] = $header;
			return;
		}
		\header( $header, $replace, $response_code );
	}
}

namespace POW\Cxml {
	function libxml_use_internal_errors( ?bool $use_errors = null ): bool {
		// The real parser enables error capture immediately before creating/loading its DOM document.
		if ( true === $use_errors && isset( $GLOBALS['pow_test_setup_io'] ) ) {
			$GLOBALS['pow_test_setup_io']['parses']++;
		}
		return \libxml_use_internal_errors( $use_errors );
	}
}
