<?php
/**
 * Explicit copies of the company owner's normal Woo address into the private delivery book.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Addresses;

use POW\Partners\Registry;
use WC_Customer;

defined( 'ABSPATH' ) || exit;

final class NativeImport {

	/** Project native billing/shipping onto Shape's shipping fields; billing email is never delivery data. */
	private const FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	public function __construct( private Registry $registry, private CompanyBook $book ) {}

	/**
	 * Management-only preview: {revision,source,label,address}. Source is woocommerce_shipping or woocommerce_billing; no source user ID is accepted or exposed. Nothing is persisted or enabled.
	 */
	public function preview( int $partner_id, int $actor, string $type ): array|\WP_Error {
		try {
			$prepared = $this->prepare( $partner_id, $actor, $type );
			if ( $prepared instanceof \WP_Error ) { return $prepared; }
			return $this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $actor, $prepared ) {
				$current = $this->context_locked( $partner_id, $actor );
				if ( $current instanceof \WP_Error ) { return $current; }
				if ( $current['owner'] !== $prepared['owner'] ) { return self::unavailable(); }
				return [ 'revision' => $current['revision'] ] + $prepared['preview'];
			} );
		} catch ( \Throwable $error ) { return self::unavailable(); }
	}

	/**
	 * Reread the native source for every command. Accept only unslashed label/code overrides; the HTTP boundary owns nonce/POST and unslashes once. Every successful call creates a new disabled entry; CompanyBook owns keys, code claims and expected-revision persistence. Explicit enablement is a separate book action.
	 *
	 * @return array|\WP_Error Company's save result {revision,key,entry,changed}, or a bounded refusal.
	 */
	public function copy( int $partner_id, int $actor, int $expected_revision, string $type, array $overrides ): array|\WP_Error {
		if ( array_diff_key( $overrides, array_flip( [ 'label', 'code' ] ) ) ) { return self::invalid(); }
		foreach ( $overrides as $value ) { if ( ! is_string( $value ) ) { return self::invalid(); } }
		try {
			$prepared = $this->prepare( $partner_id, $actor, $type );
			if ( $prepared instanceof \WP_Error ) { return $prepared; }
			return $this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $actor, $expected_revision, $overrides, $prepared ) {
				$current = $this->context_locked( $partner_id, $actor );
				if ( $current instanceof \WP_Error ) { return $current; }
				// Even a shop administrator must not copy a former owner's source to a newly assigned company owner.
				if ( $current['owner'] !== $prepared['owner'] ) { return self::unavailable(); }
				return $this->book->save( $partner_id, $actor, $expected_revision, null, [
					'label' => $overrides['label'] ?? $prepared['preview']['label'],
					'address' => $prepared['preview']['address'],
					'code' => $overrides['code'] ?? '',
					'use_for_punchout' => false,
				] );
			} );
		} catch ( \Throwable $error ) { return self::unavailable(); }
	}

	/** Native reads do not hold the partner mutex. Recheck the actor and owner under that mutex before exposing or copying their result. */
	private function prepare( int $partner_id, int $actor, string $type ): array|\WP_Error {
		if ( ! in_array( $type, [ 'shipping', 'billing' ], true ) ) { return self::invalid(); }
		$context = $this->registry->with_partner_lock( $partner_id, fn() => $this->context_locked( $partner_id, $actor ) );
		if ( $context instanceof \WP_Error ) { return $context; }
		$customer = new WC_Customer( $context['owner'] );
		// WC_Customer can swallow a read exception and reset its ID to zero. Never import defaults from that object.
		if ( $customer->get_id() !== $context['owner'] ) { return self::unavailable(); }
		$native = 'shipping' === $type ? $customer->get_shipping( 'edit' ) : $customer->get_billing( 'edit' );
		if ( ! is_array( $native ) ) { return self::invalid(); }
		$address = Shape::normalise( array_intersect_key( $native, array_flip( self::FIELDS ) ) );
		if ( $address instanceof \WP_Error ) { return $address; }
		return [ 'owner' => $context['owner'], 'preview' => [
			'source' => 'woocommerce_' . $type,
			'label' => 'shipping' === $type
				? __( 'Company WooCommerce shipping address', 'punchout-woocommerce' )
				: __( 'Company WooCommerce billing address', 'punchout-woocommerce' ),
			'address' => $address,
		] ];
	}

	/** Registry's existing mutex is reentrant; Book read/save retain their own actual-actor authorization and checked storage boundaries. */
	private function context_locked( int $partner_id, int $actor ): array|\WP_Error {
		$before = $this->registry->find( $partner_id );
		$book = $this->book->read( $partner_id, $actor );
		if ( $book instanceof \WP_Error ) { return $book; }
		$after = $this->registry->find( $partner_id );
		if ( ! $before || ! $after || $before->owner_user_id !== $after->owner_user_id ) { return self::unavailable(); }
		return [ 'owner' => $after->owner_user_id, 'revision' => $book['revision'] ];
	}

	private static function invalid(): \WP_Error { return new \WP_Error( 'address_import_invalid', __( 'Choose a normal company shipping or billing address and supply only a label and delivery code.', 'punchout-woocommerce' ) ); }
	private static function unavailable(): \WP_Error { return new \WP_Error( 'address_state_unavailable', __( 'The company address could not be verified. Reload it before trying again.', 'punchout-woocommerce' ) ); }
}
