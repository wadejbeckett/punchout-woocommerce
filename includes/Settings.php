<?php
/**
 * Settings storage.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessor over the plugin's single option row.
 *
 * Per-partner configuration lives in the partners table (Partners\Registry);
 * this option holds only the global defaults and operational knobs.
 *
 * The sodium key that seals partner secrets is NOT a setting: it must live
 * in wp-config.php as POW_SECRET_KEY so a database dump alone cannot
 * decrypt the registry (scope §7). See Partners\Secrets.
 */
final class Settings {

	public const OPTION_KEY = 'pow_settings';

	private const DEFAULTS = [
		// Master switch. Off by default so a fresh install exposes no
		// pre-auth XML endpoint until an operator has configured at least
		// one trading partner and flipped it on.
		'enabled'             => 'no',

		// Default TTLs; each partner row can override its own.
		'token_ttl'           => 300,     // StartPage token, seconds (~5 min).
		'session_ttl'         => 14400,   // Punchout login, seconds (4 h).

		// /punchout/setup rate limit: requests per rolling minute for one
		// (partner|unknown-sender, IP) pair.
		'rate_limit_per_min'  => 30,

		// Audit-table retention. The scope treats the log as dispute
		// evidence, so the default keeps a year-plus before cron trims.
		'log_retention_days'  => 400,

		// Days without a punchout login before a buyer user is flagged
		// inactive by cron (flagged, never deleted — order attribution).
		'buyer_inactive_days' => 90,

		// Page the buyer lands on after auto-login, and is 302'd back to by
		// the route guard. 0 = the shop page.
		'landing_page_id'     => 0,

		// Classification fallback when a cart line has no SKU-map row.
		// The DTD requires at least one Classification; D365 only appends
		// it to the item description (scope §4.3).
		'default_unspsc'      => '',

		'log_level'           => 'info',
	];

	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_KEY, [] );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : [], self::DEFAULTS );
		}

		return $this->cache;
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->all();

		return $all[ $key ] ?? $default ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	public function int( string $key ): int {
		return (int) $this->get( $key, 0 );
	}

	/**
	 * Persist a full settings array (already sanitised by the caller).
	 *
	 * @param array<string, mixed> $values Sanitised values.
	 */
	public function save( array $values ): void {
		$this->cache = wp_parse_args( $values, self::DEFAULTS );
		update_option( self::OPTION_KEY, $this->cache, false );
	}

	/**
	 * URL of the punchout landing page (auto-login redirect target).
	 */
	public function landing_url(): string {
		$page_id = $this->int( 'landing_page_id' );

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );

			if ( is_string( $shop ) && '' !== $shop ) {
				return $shop;
			}
		}

		return home_url( '/' );
	}
}
