<?php
/**
 * Audit / compliance trail.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Audit;

use POW\Installer;
use POW\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Writer + reader for wp_pow_log (scope §4.4).
 *
 * Punchout is a financial integration: the archived POOM XML is the
 * dispute evidence (prices quoted to a named buyer at a timestamp), so it
 * lands here — never only in rotating log files. Shared secrets are
 * redacted from BOTH the detail JSON and any stored XML before the row is
 * written; there is no code path that persists one.
 */
final class Log {

	public function __construct( private Logger $logger ) {}

	private function table(): string {
		return Installer::log_table();
	}

	/**
	 * Append an audit row and mirror it to the operational log.
	 *
	 * @param string               $event   Event key (setup_rx, token_redeem, return_sent, ...).
	 * @param array<string, mixed> $context partner_id, session_id, user_id, order_id,
	 *                                      direction (in|out|internal), payload_id,
	 *                                      result, detail (array), xml, ip.
	 */
	public function write( string $event, array $context = [] ): void {
		global $wpdb;

		$detail = $context['detail'] ?? [];
		$detail = is_array( $detail ) ? Logger::redact( $detail ) : [];

		$row = [
			'ts'         => gmdate( 'Y-m-d H:i:s' ) . '.' . sprintf( '%03d', (int) ( fmod( microtime( true ), 1 ) * 1000 ) ),
			'partner_id' => (int) ( $context['partner_id'] ?? 0 ),
			'session_id' => (int) ( $context['session_id'] ?? 0 ),
			'user_id'    => (int) ( $context['user_id'] ?? 0 ),
			'order_id'   => (int) ( $context['order_id'] ?? 0 ),
			'event'      => substr( $event, 0, 40 ),
			'direction'  => in_array( $context['direction'] ?? '', [ 'in', 'out' ], true ) ? $context['direction'] : 'internal',
			'payload_id' => substr( (string) ( $context['payload_id'] ?? '' ), 0, 190 ),
			'result'     => substr( (string) ( $context['result'] ?? 'ok' ), 0, 20 ),
			'detail'     => [] !== $detail ? (string) wp_json_encode( $detail ) : null,
			'xml'        => isset( $context['xml'] ) && '' !== (string) $context['xml'] ? self::redact_xml( (string) $context['xml'] ) : null,
			'ip'         => substr( (string) ( $context['ip'] ?? '' ), 0, 45 ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $row );

		$this->logger->info( $event, [ 'result' => $row['result'], 'partner' => $row['partner_id'], 'session' => $row['session_id'] ] );
	}

	/**
	 * Redact SharedSecret content in stored XML: the inbound setup body is
	 * archived as evidence, but the partner credential inside it must not
	 * be (scope §7 "secrets redacted always").
	 */
	public static function redact_xml( string $xml ): string {
		return (string) preg_replace(
			'#(<SharedSecret[^>]*>).*?(</SharedSecret>)#s',
			'$1[redacted]$2',
			$xml
		);
	}

	/**
	 * Paged read for the admin viewer.
	 *
	 * @param array{partner_id?: int, event?: string, session_id?: int} $filters Filters.
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function query( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['partner_id'] ) ) {
			$where[]  = 'partner_id = %d';
			$values[] = (int) $filters['partner_id'];
		}

		if ( ! empty( $filters['event'] ) ) {
			$where[]  = 'event = %s';
			$values[] = (string) $filters['event'];
		}

		if ( ! empty( $filters['session_id'] ) ) {
			$where[]  = 'session_id = %d';
			$values[] = (int) $filters['session_id'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		$count_sql = 'SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$where_sql}";
		$rows_sql  = 'SELECT * FROM ' . $this->table() . " WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$row_values   = $values;
		$row_values[] = $per_page;
		$row_values[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = [] === $values ? (int) $wpdb->get_var( $count_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$row_values ), ARRAY_A );

		return [
			'rows'  => $rows ?: [],
			'total' => $total,
		];
	}

	/**
	 * Retention trim (cron): delete rows older than N days.
	 */
	public function trim( int $days ): int {
		global $wpdb;

		if ( $days < 1 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE ts < %s LIMIT 5000', $cutoff )
		);
	}
}
