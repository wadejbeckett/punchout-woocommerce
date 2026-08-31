<?php
/**
 * Shared-secret sealing and verification.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Partners;

defined( 'ABSPATH' ) || exit;

/**
 * Seals trading-partner shared secrets at rest with sodium_crypto_secretbox
 * and verifies inbound credentials in constant time.
 *
 * The sealing key is injected (resolved by the Plugin container from the
 * POW_SECRET_KEY wp-config constant, with a documented fallback), so a
 * database dump alone can never decrypt the registry, and this class stays
 * pure PHP + ext-sodium — unit-tested without WordPress.
 *
 * Rotation is dual-slot (scope §7): current and previous are both accepted
 * during an overlap window; verify() reports which slot matched so the
 * window can be closed confidently from the audit log.
 */
final class Secrets {

	public const SLOT_CURRENT  = 'current';
	public const SLOT_PREVIOUS = 'previous';

	/**
	 * @param string $key SODIUM_CRYPTO_SECRETBOX_KEYBYTES (32) of binary key material.
	 */
	public function __construct( private string $key ) {
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'Sealing key must be exactly 32 bytes' );
		}
	}

	/**
	 * Seal a plaintext secret for storage: base64( nonce . ciphertext ).
	 *
	 * Base64-armoured rather than raw binary because wpdb has no
	 * binary-safe placeholder (see Installer schema note).
	 */
	public function seal( string $secret ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, $this->key ) );
	}

	/**
	 * Open a sealed secret; null on any tamper/format/key failure.
	 */
	public function open( string $sealed ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( $sealed, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

		return false === $plain ? null : $plain;
	}

	/**
	 * Constant-time verification of an inbound secret against the two
	 * sealed slots.
	 *
	 * @param string  $candidate       Secret presented in the setup request.
	 * @param string  $sealed_current  Sealed current slot ('' when unset).
	 * @param ?string $sealed_previous Sealed previous slot ('' / null when unset).
	 * @return string|null SLOT_* that matched, or null.
	 */
	public function verify( string $candidate, string $sealed_current, ?string $sealed_previous = null ): ?string {
		if ( '' === $candidate ) {
			return null;
		}

		$current = '' !== $sealed_current ? $this->open( $sealed_current ) : null;

		if ( null !== $current && hash_equals( $current, $candidate ) ) {
			return self::SLOT_CURRENT;
		}

		$previous = ( null !== $sealed_previous && '' !== $sealed_previous ) ? $this->open( $sealed_previous ) : null;

		if ( null !== $previous && hash_equals( $previous, $candidate ) ) {
			return self::SLOT_PREVIOUS;
		}

		return null;
	}

	/**
	 * Generate a new partner shared secret: 32 random bytes, base64url —
	 * long enough that offline guessing is hopeless, ASCII-safe for the
	 * buyer admin to paste into their template.
	 */
	public static function generate_secret(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Generate sealing-key material for the POW_SECRET_KEY constant.
	 */
	public static function generate_key(): string {
		return base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a POW_SECRET_KEY constant value into binary key material.
	 */
	public static function decode_key( string $encoded ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( $encoded, true );

		return ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) ? $raw : null;
	}
}
