<?php
/** Exit entitlement hierarchy and private, checked buyer restrictions. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Checkout;

use POW\Installer;
use POW\Partners\{Partner, Registry};
use POW\Settings;

defined( 'ABSPATH' ) || exit;

final class ExitPolicy {
	public const INHERIT = 'inherit';
	public const ONLY = 'punchout_only';
	public const CHECKOUT = 'punchout_and_checkout';
	private static array $saving = [];

	public function __construct( private Settings $settings, private Registry $registry ) {}

	public static function valid( mixed $value ): bool { return in_array( $value, [ self::INHERIT, self::ONLY, self::CHECKOUT ], true ); }
	public static function normalise( mixed $value ): string { return self::valid( $value ) ? $value : self::ONLY; }
	public static function labels(): array { return [ self::INHERIT => __( 'Inherit', 'punchout-woocommerce' ), self::ONLY => __( 'Punchout only', 'punchout-woocommerce' ), self::CHECKOUT => __( 'Punchout and checkout', 'punchout-woocommerce' ) ]; }

	public static function resolve( string $global, string $company, string $buyer ): string {
		if ( ! self::valid( $global ) || ! self::valid( $company ) || ! self::valid( $buyer ) ) { return self::ONLY; }
		$cap = self::INHERIT === $company ? ( self::INHERIT === $global ? self::ONLY : $global ) : $company;
		return self::ONLY === $cap || self::ONLY === $buyer ? self::ONLY : self::CHECKOUT;
	}

	/** Never authorize using a caller's stale Partner snapshot or cached user metadata. */
	public function effective( Partner $partner, int $buyer_user_id ): string {
		try {
			$fresh = $this->registry->find( $partner->id );
			if ( ! $fresh || ! $fresh->is_active() || ! $this->member( $fresh->id, $buyer_user_id ) ) { return self::ONLY; }
			return self::resolve( $this->settings->exit_policy(), $fresh->exit_policy, $this->buyer_value( $fresh->id, $buyer_user_id ) );
		} catch ( \Throwable $e ) { return self::ONLY; }
	}

	/** A stored buyer is an automatically provisioned member, never a posted owner or editable email. */
	public function member( int $partner_id, int $buyer_id ): bool {
		$user = $buyer_id > 0 ? get_userdata( $buyer_id ) : false;
		return $partner_id > 0 && $user && user_can( $user, 'read' ) && in_array( Installer::ROLE, (array) $user->roles, true ) && $this->meta( $buyer_id, '_pow_partner_id' ) === (string) $partner_id && in_array( $this->meta( $buyer_id, '_pow_deactivated' ), [ null, '', '0' ], true );
	}

	/** Caller catches storage errors: corruption must never display or resolve as Inherit. */
	public function buyer_value( int $partner_id, int $buyer_id ): string { return self::normalise( $this->meta( $buyer_id, self::meta_key( $partner_id ) ) ?? self::INHERIT ); }
	public static function meta_key( int $partner_id ): string { return '_pow_exit_policy_' . $partner_id; }

	public static function administrator( int $actor ): bool {
		$user = $actor > 0 && $actor === get_current_user_id() ? get_userdata( $actor ) : false;
		return $user && user_can( $user, 'manage_woocommerce' ) && ! in_array( Installer::ROLE, (array) $user->roles, true ) && ! get_user_meta( $actor, '_pow_partner_id', true );
	}

	/** HTTP handlers own POST/nonce; this service independently verifies the actual actor under the partner mutex. */
	public function save_buyer( int $partner_id, int $actor, int $buyer_id, string $value ): bool|\WP_Error {
		if ( ! self::valid( $value ) || ! self::administrator( $actor ) || isset( self::$saving[$partner_id] ) ) { return self::refused(); }
		self::$saving[$partner_id] = true;
		try {
			return $this->registry->with_partner_lock( $partner_id, function () use ( $partner_id, $actor, $buyer_id, $value ) {
				$partner = $this->registry->find( $partner_id );
				if ( ! self::administrator( $actor ) || ! $partner || ! $partner->is_active() || ! $this->member( $partner_id, $buyer_id ) ) { return self::refused(); }
				if ( self::CHECKOUT === $value && self::CHECKOUT !== self::resolve( $this->settings->exit_policy(), $partner->exit_policy, self::INHERIT ) ) { return self::refused(); }
				$key = self::meta_key( $partner_id );
				$old = $this->meta( $buyer_id, $key );
				if ( $old === $value ) { return true; }
				$ok = null === $old ? add_user_meta( $buyer_id, $key, $value, true ) : update_user_meta( $buyer_id, $key, $value, $old );
				if ( ! $ok || $this->meta( $buyer_id, $key ) !== $value ) { return self::unavailable(); }
				return true;
			} );
		} catch ( \Throwable $e ) { return self::unavailable(); }
		finally { unset( self::$saving[$partner_id] ); }
	}

	/** Bound duplicate detection and bypass both WP's metadata cache and short-circuit filters. */
	private function meta( int $user_id, string $key ): ?string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id = %d AND meta_key = %s LIMIT 2', $user_id, $key ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== ( $wpdb->last_error ?? '' ) || count( $rows ) > 1 ) { throw new \RuntimeException( 'Exit policy state unavailable.' ); }
		if ( [] === $rows ) { return null; }
		if ( ! isset( $rows[0]['meta_value'] ) || ! is_string( $rows[0]['meta_value'] ) ) { throw new \RuntimeException( 'Exit policy state unavailable.' ); }
		return $rows[0]['meta_value'];
	}

	private static function refused(): \WP_Error { return new \WP_Error( 'pow_exit_forbidden', __( 'Only a shop administrator can set restrictions for an existing company buyer within the company permission.', 'punchout-woocommerce' ) ); }
	private static function unavailable(): \WP_Error { return new \WP_Error( 'pow_exit_unavailable', __( 'The exit policy change could not be verified. Reload before trying again.', 'punchout-woocommerce' ) ); }
}
