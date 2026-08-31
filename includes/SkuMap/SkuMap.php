<?php
/**
 * Per-partner SKU / UOM / UNSPSC mapping.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\SkuMap;

use POW\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * The outbound-cXML decoration table (scope §4.3).
 *
 * Ownership ruling: this table owns ONLY the partner-facing SKU override,
 * UOM and UNSPSC, and joins on the WooCommerce SKU. It never duplicates
 * any other system's identity mapping — the SupplierPartID emitted here
 * and whatever SKU other integrations push are both derived from the one
 * Woo SKU, so they cannot diverge.
 *
 * Maintained by CSV import (admin screen); an admin grid is a priced
 * option, not core.
 */
final class SkuMap {

	private function table(): string {
		return Installer::skumap_table();
	}

	/**
	 * Active mapping row for a (partner, sku) pair.
	 *
	 * @return array{partner_sku: ?string, uom_code: string, unspsc: ?string}|null
	 */
	public function find( int $partner_id, string $sku ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT partner_sku, uom_code, unspsc FROM ' . $this->table() . ' WHERE partner_id = %d AND sku = %s AND active = 1',
				$partner_id,
				$sku
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'partner_sku' => '' !== (string) ( $row['partner_sku'] ?? '' ) ? (string) $row['partner_sku'] : null,
			'uom_code'    => '' !== (string) ( $row['uom_code'] ?? '' ) ? (string) $row['uom_code'] : 'EA',
			'unspsc'      => '' !== (string) ( $row['unspsc'] ?? '' ) ? (string) $row['unspsc'] : null,
		];
	}

	/**
	 * Reverse lookup: partner SKU -> our SKU (inbound resolution for a
	 * future re-entry/PO path).
	 */
	public function find_by_partner_sku( int $partner_id, string $partner_sku ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$sku = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT sku FROM ' . $this->table() . ' WHERE partner_id = %d AND partner_sku = %s AND active = 1',
				$partner_id,
				$partner_sku
			)
		);

		return is_string( $sku ) && '' !== $sku ? $sku : null;
	}

	/**
	 * Paged listing for the admin screen.
	 *
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function all( int $partner_id, int $page = 1, int $per_page = 100 ): array {
		global $wpdb;

		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE partner_id = %d', $partner_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE partner_id = %d ORDER BY sku ASC LIMIT %d OFFSET %d',
				$partner_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return [
			'rows'  => $rows ?: [],
			'total' => $total,
		];
	}

	/**
	 * Upsert one mapping row.
	 */
	public function upsert( int $partner_id, string $sku, ?string $partner_sku, string $uom_code, ?string $unspsc, bool $active = true ): bool {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = [
			'partner_sku' => (string) $partner_sku,
			'uom_code'    => '' !== $uom_code ? substr( $uom_code, 0, 8 ) : 'EA',
			'unspsc'      => (string) $unspsc,
			'active'      => $active ? 1 : 0,
			'updated'     => $now,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $this->table() . ' WHERE partner_id = %d AND sku = %s', $partner_id, $sku )
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return false !== $wpdb->update( $this->table(), $data, [ 'id' => (int) $existing ] );
		}

		$data['partner_id'] = $partner_id;
		$data['sku']        = substr( $sku, 0, 190 );
		$data['created']    = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert( $this->table(), $data );
	}

	/**
	 * CSV import: sku,partner_sku,uom_code,unspsc[,active] with an
	 * optional header row. Returns counts for the admin notice.
	 *
	 * @param list<list<string>> $rows Parsed CSV rows.
	 * @return array{imported: int, skipped: int}
	 */
	public function import_rows( int $partner_id, array $rows ): array {
		$imported = 0;
		$skipped  = 0;

		foreach ( $rows as $index => $row ) {
			$sku = trim( (string) ( $row[0] ?? '' ) );

			// Skip a header row and blank lines.
			if ( '' === $sku || ( 0 === $index && 0 === strcasecmp( $sku, 'sku' ) ) ) {
				$skipped++;
				continue;
			}

			$ok = $this->upsert(
				$partner_id,
				$sku,
				trim( (string) ( $row[1] ?? '' ) ),
				strtoupper( trim( (string) ( $row[2] ?? 'EA' ) ) ),
				trim( (string) ( $row[3] ?? '' ) ),
				! isset( $row[4] ) || '' === trim( (string) $row[4] ) || '0' !== trim( (string) $row[4] )
			);

			$ok ? $imported++ : $skipped++;
		}

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
		];
	}
}
