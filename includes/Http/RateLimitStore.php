<?php
/**
 * Atomic database storage for setup rate limits.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes one fixed-window counter with a MariaDB/MySQL named lock. The value and timeout use WordPress's transient option names so native expired-transient cleanup removes both rows. Reads and writes deliberately use wpdb because the Transients API may use a non-shared runtime cache and set_transient() refreshes an existing expiry.
 */
final class RateLimitStore {

	/** @var callable(): int */
	private $clock;

	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/** Atomically consume one permit. Any lock, read, write or release failure refuses the request. */
	public function consume( string $key, int $limit, int $window_seconds ): bool {
		global $wpdb;
		if ( $limit <= 0 || $window_seconds <= 0 || '' === $key || strlen( $key ) > 172 || ! isset( $wpdb->options ) ) {
			return false;
		}
		$lock = $this->lock_name( $key );
		$previous = $wpdb->suppress_errors( true );
		$acquired = false;
		$allowed = false;
		try {
			if ( ! $this->cleanup_expired( ( $this->clock )() ) ) {
				return false;
			}
			$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 2 ) );
			if ( ! $acquired || '' !== ( $wpdb->last_error ?? '' ) ) {
				return false;
			}
			$allowed = $this->consume_locked( $key, $limit, $window_seconds, ( $this->clock )() );
		} catch ( \Throwable $error ) {
			$allowed = false;
		} finally {
			try {
				if ( $acquired && '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ) ) {
					$allowed = false;
				}
			} catch ( \Throwable $error ) {
				$allowed = false;
			} finally {
				$wpdb->suppress_errors( $previous );
			}
		}
		return $allowed;
	}

	/** Remove a bounded set of private expired counters even when core uses an external object cache. */
	private function cleanup_expired( int $now ): bool {
		global $wpdb;
		$prefix = '_transient_timeout_pow_rl_';
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d ORDER BY option_id ASC LIMIT 10',
			$wpdb->esc_like( $prefix ) . '%', $now
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== ( $wpdb->last_error ?? '' ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$timeout_name = (string) ( $row['option_name'] ?? '' );
			$key = str_starts_with( $timeout_name, '_transient_timeout_' ) ? substr( $timeout_name, 19 ) : '';
			if ( ! str_starts_with( $key, 'pow_rl_' ) || strlen( $key ) > 172 ) {
				return false;
			}
			$lock = $this->lock_name( $key );
			$acquired = false;
			$cleaned = true;
			try {
				$wpdb->last_error = '';
				$lock_result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 0 ) );
				$acquired = '1' === (string) $lock_result;
				if ( ! $acquired ) {
					if ( null === $lock_result || '' !== ( $wpdb->last_error ?? '' ) ) { $cleaned = false; }
				} else {
					$wpdb->last_error = '';
					$fresh_timeout = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s', $timeout_name ) );
					if ( '' !== ( $wpdb->last_error ?? '' ) ) {
						$cleaned = false;
					} elseif ( null !== $fresh_timeout && (int) $fresh_timeout <= $now ) {
						$value_name = '_transient_' . $key;
						$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->options . ' WHERE option_name IN (%s, %s)', $value_name, $timeout_name ) );
						if ( false === $deleted || '' !== ( $wpdb->last_error ?? '' ) ) {
							$cleaned = false;
						} else {
							$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->options . ' WHERE option_name IN (%s, %s)', $value_name, $timeout_name ) );
							$cleaned = '0' === (string) $remaining && '' === ( $wpdb->last_error ?? '' );
						}
					}
				}
			} catch ( \Throwable $error ) {
				$cleaned = false;
			} finally {
				try {
					if ( $acquired && '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ) ) {
						$cleaned = false;
					}
				} catch ( \Throwable $error ) {
					$cleaned = false;
				}
			}
			if ( ! $cleaned ) { return false; }
		}
		return true;
	}

	private function lock_name( string $key ): string {
		global $wpdb;
		return 'pow_rl_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->options . '|' . $key ), 0, 57 );
	}

	private function consume_locked( string $key, int $limit, int $window_seconds, int $now ): bool {
		global $wpdb;
		$value_name = '_transient_' . $key;
		$timeout_name = '_transient_timeout_' . $key;
		$state = $this->read( $value_name, $timeout_name );
		if ( null === $state ) {
			return $this->reset( $value_name, $timeout_name, $now + $window_seconds );
		}
		if ( ! array_key_exists( 'value', $state ) || ! array_key_exists( 'timeout', $state ) ) {
			return false;
		}
		$count = filter_var( $state['value'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] );
		$expires = filter_var( $state['timeout'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		if ( false === $count || false === $expires ) {
			return false;
		}
		if ( $expires <= $now ) {
			return $this->reset( $value_name, $timeout_name, $now + $window_seconds );
		}
		if ( $count >= $limit ) {
			return false;
		}
		$next = (string) ( $count + 1 );
		$affected = $wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->options . ' SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $next, $value_name, (string) $state['value'] ) );
		if ( 1 !== $affected || '' !== ( $wpdb->last_error ?? '' ) ) {
			return false;
		}
		$fresh = $this->read( $value_name, $timeout_name );
		return is_array( $fresh ) && ( $fresh['value'] ?? null ) === $next && ( $fresh['timeout'] ?? null ) === (string) $expires;
	}

	/** @return array{value?: string, timeout?: string}|null */
	private function read( string $value_name, string $timeout_name ): ?array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name IN (%s, %s)', $value_name, $timeout_name ), ARRAY_A );
		if ( '' !== ( $wpdb->last_error ?? '' ) || ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Rate-limit storage read failed.' );
		}
		if ( [] === $rows ) {
			return null;
		}
		$state = [];
		foreach ( $rows as $row ) {
			if ( $row['option_name'] === $value_name ) {
				$state['value'] = (string) $row['option_value'];
			} elseif ( $row['option_name'] === $timeout_name ) {
				$state['timeout'] = (string) $row['option_value'];
			}
		}
		return $state;
	}

	private function reset( string $value_name, string $timeout_name, int $expires ): bool {
		global $wpdb;
		if ( ! $this->upsert( $timeout_name, (string) $expires ) || ! $this->upsert( $value_name, '1' ) ) {
			return false;
		}
		$fresh = $this->read( $value_name, $timeout_name );
		return is_array( $fresh ) && ( $fresh['value'] ?? null ) === '1' && ( $fresh['timeout'] ?? null ) === (string) $expires;
	}

	private function upsert( string $name, string $value ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $wpdb->options . ' (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)', $name, $value, 'off' ) );
		return false !== $result && '' === ( $wpdb->last_error ?? '' );
	}
}
