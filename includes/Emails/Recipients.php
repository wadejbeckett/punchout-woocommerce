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

	/** One valid Reply-To address from a setting, or '' when blank or invalid. */
	public static function reply_to( string $setting ): string {
		$candidate = strtolower( trim( $setting ) );
		return '' !== $candidate && filter_var( $candidate, FILTER_VALIDATE_EMAIL ) ? $candidate : '';
	}

	/**
	 * WooCommerce's header block with its Reply-to line replaced by $reply_to; unchanged when $reply_to is ''.
	 * WC_Email already writes a Reply-to of the sender, so a second line would leave two.
	 */
	public static function with_reply_to( string $headers, string $reply_to ): string {
		if ( '' === $reply_to ) { return $headers; }
		$lines = array_filter( preg_split( '/\r\n|\n|\r/', $headers ) ?: [], static fn( string $line ): bool => '' !== trim( $line ) && 0 !== stripos( ltrim( $line ), 'reply-to:' ) );
		$lines[] = 'Reply-to: ' . $reply_to;
		return implode( "\r\n", $lines ) . "\r\n";
	}
}
