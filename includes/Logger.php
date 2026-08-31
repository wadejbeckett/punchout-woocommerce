<?php
/**
 * Operational logging facade.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over wc_get_logger(), channel `punchout-woocommerce`.
 *
 * This is the OPERATIONAL log (rotated by Woo's housekeeping). The
 * COMPLIANCE trail — every transaction, full POOM XML, dispute evidence —
 * is the custom audit table written by Audit\Log; the two are deliberately
 * separate (scope §4.4).
 */
final class Logger {

	public const SOURCE = 'punchout-woocommerce';

	private const LEVELS = [
		'debug'   => 10,
		'info'    => 20,
		'notice'  => 30,
		'warning' => 40,
		'error'   => 50,
	];

	public function __construct( private Settings $settings ) {}

	public function debug( string $message, array $context = [] ): void {
		$this->log( 'debug', $message, $context );
	}

	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param string               $level   One of self::LEVELS.
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Extra data, appended as JSON.
	 */
	public function log( string $level, string $message, array $context = [] ): void {
		$threshold = self::LEVELS[ (string) $this->settings->get( 'log_level', 'info' ) ] ?? 20;

		if ( ( self::LEVELS[ $level ] ?? 20 ) < $threshold ) {
			return;
		}

		if ( [] !== $context ) {
			$message .= ' ' . wp_json_encode( self::redact( $context ) );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, [ 'source' => self::SOURCE ] );
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[' . self::SOURCE . '] ' . $level . ': ' . $message );
		}
	}

	/**
	 * Strip anything credential-shaped before it reaches a log file.
	 *
	 * The shared secret is the partner credential; a log line carrying it is
	 * a live leak (scope §7: "shared secrets never logged").
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	public static function redact( array $context ): array {
		$secret_keys = [ 'secret', 'shared_secret', 'sharedsecret', 'SharedSecret', 'password', 'token', 'authorization', 'Authorization' ];

		foreach ( $context as $key => $value ) {
			if ( in_array( (string) $key, $secret_keys, true ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			}
		}

		return $context;
	}
}
