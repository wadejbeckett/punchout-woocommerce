<?php
/** Recipient list rules for the plugin's e-mails. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Emails;

defined( 'ABSPATH' ) || exit;

final class Recipients {
	/**
	 * A comma-separated setting becomes a clean list of addresses; an empty or invalid setting
	 * falls back to the store's admin address. Duplicates and blanks are dropped.
	 *
	 * @return list<string>
	 */
	public static function resolve( string $setting, string $fallback ): array {
		$out = [];
		foreach ( explode( ',', $setting ) as $candidate ) {
			$candidate = strtolower( trim( $candidate ) );
			if ( '' === $candidate || ! filter_var( $candidate, FILTER_VALIDATE_EMAIL ) || in_array( $candidate, $out, true ) ) { continue; }
			$out[] = $candidate;
		}
		if ( ! $out ) {
			$fallback = strtolower( trim( $fallback ) );
			if ( '' !== $fallback && filter_var( $fallback, FILTER_VALIDATE_EMAIL ) ) { $out[] = $fallback; }
		}
		return $out;
	}
}
