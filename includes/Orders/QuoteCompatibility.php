<?php
/**
 * Preserve confirmed Punchout data in WooCommerce's HPOS compatibility copy.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

namespace POW\Orders;

use Automattic\WooCommerce\Utilities\OrderUtil;
use POW\Addresses\DeliveryData;
use POW\Audit\Log;
use POW\Logger;

defined( 'ABSPATH' ) || exit;

/** The primary order remains authoritative; this handler never saves it or requests synchronization. */
final class QuoteCompatibility {

	private const KEYS = [ QuoteOrder::META_DELIVERY_CHOICE, QuoteOrder::META_DELIVERY_CONFIRMATION, QuoteOrder::META_DELIVERY_NOTES, QuoteOrder::META_POOM_XML ];
	private array $repairing = [];

	public function __construct( private Log $audit, private Logger $logger ) {}

	public function register(): void {
		add_action( 'woocommerce_hpos_post_record_backfilled', [ $this, 'repair' ], PHP_INT_MAX, 1 );
	}

	/**
	 * The supported action runs after both native metadata copy passes, including background synchronization.
	 * Exceptions deliberately reach the caller: optional creation already handles failures, and a background batch must not silently accept a broken copy.
	 */
	public function repair( mixed $order ): void {
		if ( ! $order instanceof \WC_Order || ! OrderUtil::custom_orders_table_usage_is_enabled() || 'shop_order' !== $order->get_type() ) { return; }
		$id = $order->get_id();
		if ( $id <= 0 || isset( $this->repairing[ $id ] ) ) { return; }
		$this->repairing[ $id ] = true;
		$filter = null;
		$reason = 'provenance_invalid';
		$context = [ 'order_id' => $id, 'direction' => 'internal', 'result' => 'error' ];
		try {
			$expected = $this->snapshot( $order );
			// Initial native insertion and older Quotes can have identity/XML but no confirmation at all.
			if ( null === $expected ) { return; }
			$context += [ 'session_id' => $expected['session_id'], 'partner_id' => $expected['partner_id'], 'user_id' => $expected['user_id'] ];
			$reason = 'post_state_invalid';
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || 'shop_order' !== $post->post_type || $this->post_status( $expected['status'] ) !== $post->post_status ) { throw new \RuntimeException(); }
			$dates = [ 'post_modified' => $post->post_modified, 'post_modified_gmt' => $post->post_modified_gmt ];
			$preserved = [ ...$dates, 'post_title' => wp_slash( $post->post_title ), 'post_excerpt' => wp_slash( $expected['note'] ) ];

			foreach ( $expected['meta'] as $key => $value ) {
				$reason = 'meta_read_failed';
				$existing = get_post_meta( $id, $key, false );
				$wanted = null === $value ? [] : [ $value ];
				if ( $existing === $wanted ) { continue; }
				if ( ! is_array( $existing ) ) { throw new \RuntimeException(); }
				// update_post_meta() updates duplicates rather than making a singleton. Delete only this owned key, without a value predicate (which itself unslashes).
				if ( [] !== $existing ) {
					$reason = 'meta_delete_failed';
					if ( ! delete_post_meta( $id, $key ) || [] !== get_post_meta( $id, $key, false ) ) { throw new \RuntimeException(); }
				}
				if ( null !== $value ) {
					$reason = 'meta_add_failed';
					if ( false === add_post_meta( $id, $key, wp_slash( $value ), true ) ) { throw new \RuntimeException(); }
				}
			}

			$reason = 'post_write_failed';
			if ( $post->post_excerpt !== $expected['note'] ) {
				// Earlier excerpt_save_pre/KSES filters can remove literal note text. Restore the exact primary excerpt at the same final slashed-data boundary used by Quote creation, preserving the synchronized dates/title as well.
				$filter = static function ( array $data, array $postarr ) use ( $id, $preserved ): array {
					if ( (int) ( $postarr['ID'] ?? 0 ) === $id ) { $data = array_replace( $data, $preserved ); }
					return $data;
				};
				add_filter( 'wp_insert_post_data', $filter, PHP_INT_MAX, 2 );
				$result = wp_update_post( [ 'ID' => $id, 'post_excerpt' => wp_slash( $expected['note'] ) ], true );
				if ( is_wp_error( $result ) || $result !== $id ) { throw new \RuntimeException(); }
				remove_filter( 'wp_insert_post_data', $filter, PHP_INT_MAX );
				$filter = null;
			}

			// Finish callback-bearing per-key reads, then prime a coherent physical metadata snapshot. Never accept the separately collected filtered values: a later XML read may have changed an earlier note row.
			$reason = 'readback_failed';
			foreach ( self::KEYS as $key ) { get_post_meta( $id, $key, false ); }
			wp_cache_delete( $id, 'post_meta' );
			update_meta_cache( 'post', [ $id ] );
			// A separately saved primary can change within the same timestamp second. Reload an independent instance through the native data store, not the factory/object cache or the supplied object's old properties.
			$current = $this->fresh_snapshot( $order );
			$saved = get_post( $id );
			// Core's post_meta cache holds one raw map; this public cache read invokes no metadata filter or unserialization callback. If later native work invalidated it, refuse rather than starting another read/retry cycle.
			$actual = wp_cache_get( $id, 'post_meta' );
			if ( ! is_array( $actual ) || $current !== $expected || ! $saved instanceof \WP_Post || $saved->post_type !== $post->post_type || $saved->post_status !== $post->post_status || $saved->post_title !== $post->post_title || $saved->post_excerpt !== $expected['note'] || $saved->post_modified !== $dates['post_modified'] || $saved->post_modified_gmt !== $dates['post_modified_gmt'] ) { throw new \RuntimeException(); }
			foreach ( $expected['meta'] as $key => $value ) { if ( ( $actual[ $key ] ?? [] ) !== ( null === $value ? [] : [ maybe_serialize( $value ) ] ) ) { throw new \RuntimeException(); } }
		} catch ( \Throwable $error ) {
			// Never chain a callback/native exception: it may contain the note, XML or credentials. Audit availability is checked independently of the operational logger.
			$context['detail'] = [ 'reason' => $reason ];
			$audited = false;
			try { $audited = $this->audit->write_checked( 'quote_compatibility_failed', $context ); } catch ( \Throwable $ignored ) {}
			try { $this->logger->error( 'quote_compatibility_failed', [ ...$context, 'audit_confirmed' => $audited ] ); } catch ( \Throwable $ignored ) {}
			throw new \RuntimeException( 'quote_compatibility_' . $reason . ( $audited ? '' : '_audit_failed' ) );
		} finally {
			if ( null !== $filter ) { remove_filter( 'wp_insert_post_data', $filter, PHP_INT_MAX ); }
			unset( $this->repairing[ $id ] );
		}
	}

	/** One independent native read, with request-local cache bypass and no possibility of importing the damaged CPT copy through optional sync-on-read. Neither filter changes persisted options or authority. */
	private function fresh_snapshot( \WC_Order $order ): ?array {
		$no_cache = static fn(): string => 'no';
		$no_sync = static fn(): bool => false;
		add_filter( 'pre_option_woocommerce_hpos_datastore_caching_enabled', $no_cache, PHP_INT_MAX );
		add_filter( 'woocommerce_hpos_enable_sync_on_read', $no_sync, PHP_INT_MAX );
		try {
			$fresh = clone $order;
			$fresh->get_data_store()->read( $fresh );
			if ( $fresh->get_id() !== $order->get_id() || 'shop_order' !== $fresh->get_type() ) { throw new \RuntimeException(); }
			return $this->snapshot( $fresh );
		} finally {
			remove_filter( 'woocommerce_hpos_enable_sync_on_read', $no_sync, PHP_INT_MAX );
			remove_filter( 'pre_option_woocommerce_hpos_datastore_caching_enabled', $no_cache, PHP_INT_MAX );
		}
	}

	/** Capture exact primary values, preserving absent optional XML separately from a present empty string. No current session, policy, book or status eligibility lookup may rewrite historical confirmation. */
	private function snapshot( \WC_Order $order ): ?array {
		$keys = [ ...self::KEYS, QuoteOrder::META_SESSION_ID, QuoteOrder::META_PARTNER_ID ];
		$rows = [];
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( in_array( $data['key'], $keys, true ) ) { $rows[ $data['key'] ][] = $data['value']; }
		}
		if ( ! array_intersect( [ QuoteOrder::META_DELIVERY_CHOICE, QuoteOrder::META_DELIVERY_CONFIRMATION, QuoteOrder::META_DELIVERY_NOTES ], array_keys( $rows ) ) ) { return null; }
		$values = [];
		foreach ( $keys as $key ) {
			if ( ! isset( $rows[ $key ] ) && QuoteOrder::META_POOM_XML === $key ) { $values[ $key ] = null; continue; }
			if ( ! isset( $rows[ $key ] ) || 1 !== count( $rows[ $key ] ) || ! is_string( $rows[ $key ][0] ) ) { throw new \RuntimeException(); }
			$values[ $key ] = $rows[ $key ][0];
		}
		$session_id = $this->identity( $values[ QuoteOrder::META_SESSION_ID ] );
		$partner_id = $this->identity( $values[ QuoteOrder::META_PARTNER_ID ] );
		// The consenting buyer as the stored confirmation records it. It is
		// the connection's own customer account, which is exactly why this
		// still matches on a historical quote: quote orders keep
		// customer_id = that account on every visit. Anything that ever
		// stamps a per-buyer customer id breaks every historical quote here,
		// silently, at read time.
		$customer_id = $order->get_customer_id( 'edit' );
		$choice = 'null' === $values[ QuoteOrder::META_DELIVERY_CHOICE ] ? null : DeliveryData::choice( $values[ QuoteOrder::META_DELIVERY_CHOICE ], $partner_id );
		$confirmation = DeliveryData::confirmation( $values[ QuoteOrder::META_DELIVERY_CONFIRMATION ], $session_id, $customer_id, $choice );
		if ( $confirmation['notes'] !== $values[ QuoteOrder::META_DELIVERY_NOTES ] ) { throw new \RuntimeException(); }
		return [ 'meta' => array_intersect_key( $values, array_flip( self::KEYS ) ), 'note' => $order->get_customer_note( 'edit' ), 'status' => $order->get_status( 'edit' ), 'session_id' => $session_id, 'partner_id' => $partner_id, 'user_id' => $customer_id ];
	}

	/** Match the native CPT data store's status mapping, not an unconditional Woo prefix. Historical core statuses remain unprefixed. */
	private function post_status( string $status ): string {
		if ( '' === $status ) { throw new \RuntimeException(); }
		return ! in_array( $status, [ 'auto-draft', 'draft', 'trash' ], true ) && in_array( 'wc-' . $status, get_post_stati(), true ) ? 'wc-' . $status : $status;
	}

	private function identity( string $value ): int {
		$id = (int) $value;
		if ( $id <= 0 || (string) $id !== $value ) { throw new \RuntimeException(); }
		return $id;
	}
}
