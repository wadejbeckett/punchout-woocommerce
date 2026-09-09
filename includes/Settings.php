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
class Settings {

	public const OPTION_KEY = 'pow_settings';

	private const DEFAULTS = [
		// Master switch. Off by default so a fresh install exposes no
		// pre-auth XML endpoint until an operator has configured at least
		// one customer connection and flipped it on.
		'enabled'              => 'no',

		// Default TTLs; each partner row can override its own.
		'token_ttl'            => 300,     // StartPage token, seconds (~5 min).
		'session_ttl'          => 14400,   // Punchout login, seconds (4 h).

		// /punchout/setup downstream limit per (partner|unknown-sender, IP). Public construction uses 30 for nonpositive settings.
		'rate_limit_per_min'   => 30,

		// Per IP, pre-resolution: bounds body reads, parsing and audit storage.
		'edge_rate_limit_per_min' => 120,

		// Audit-table retention. The scope treats the log as dispute
		// evidence, so the default keeps a year-plus before cron trims.
		'log_retention_days'   => 400,

		// Days without a punchout login before a buyer user is flagged
		// inactive by cron (flagged, never deleted — order attribution).
		'buyer_inactive_days'  => 90,

		// Page the buyer lands on after auto-login, and is 302'd back to by
		// the route guard. 0 = the shop page.
		'landing_page_id'      => 0,

		// Buyer-facing labels for the two session exit controls. Empty
		// means "use the translated default" — the defaults themselves
		// cannot live in a constant because they are translatable. The
		// pow_return_button_label / pow_abandon_button_label filters still
		// run last, so code can override either one.
		'return_button_label'  => '',
		'abandon_button_label' => '',

		// Classification fallback when a cart line has no SKU-map row.
		// The DTD requires at least one Classification; D365 only appends
		// it to the item description (scope §4.3).
		'default_unspsc'       => '',

		// Status a Punchout Quote order moves to when an operator converts
		// it into a real order, and how long an unconverted quote is kept
		// before the housekeeping job cancels it (0 = keep for ever).
		'quote_convert_status' => 'pending',
		'quote_retention_days' => 90,

		'log_level'            => 'info',
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
	 * The saved label for a buyer-facing control, or the supplied default.
	 *
	 * The defaults are translatable, so they are passed in by the caller
	 * rather than held in DEFAULTS. Callers apply their label filter to
	 * the result, never before it, so filtered code always wins.
	 *
	 * @param string $key     Setting key holding the operator's override.
	 * @param string $default Translated fallback label.
	 */
	public function button_label( string $key, string $default ): string {
		return self::resolve_label( $this->get( $key, '' ), $default );
	}

	/**
	 * Resolution rule for a label setting: a non-blank saved value wins,
	 * anything else (unset, empty, whitespace, non-string) falls back.
	 *
	 * @param mixed  $stored  Raw saved value.
	 * @param string $default Translated fallback label.
	 */
	public static function resolve_label( mixed $stored, string $default ): string {
		$stored = is_string( $stored ) ? trim( $stored ) : '';

		return '' !== $stored ? $stored : $default;
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
