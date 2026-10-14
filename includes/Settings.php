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
 * this option holds operational knobs only. There is no storefront-wide
 * entitlement: a visit leaves through the shop's checkout with the PunchOut
 * payment method or through Cancel and return. Since 0.5.2 one store-wide
 * choice decides which pages outside the shop a visit may open
 * (`visit_pages`, `visit_open_pages`; RouteGuard applies it), and another who
 * sees the My Account "Punchout integration" tab (`account_tab`).
 *
 * The sodium key that seals partner secrets is NOT a setting: it must live
 * in wp-config.php as POW_SECRET_KEY so a database dump alone cannot
 * decrypt the registry (scope §7). See Partners\Secrets.
 */
class Settings {

	public const OPTION_KEY = 'pow_settings';

	/** Filter over the cart's checkout-button label in a visit (0.5.0), applied to the saved setting or the default. */
	public const CHECKOUT_BUTTON_FILTER = 'punchout_checkout_button_label';

	/** Filter over the state page's button label (0.5.0), applied to the saved setting or the default. */
	public const STATE_BUTTON_FILTER = 'punchout_state_button_label';

	/** Filters over the leave-confirmation's two button labels (0.5.0), applied to the saved setting or the default. */
	public const LEAVE_CONFIRM_FILTER = 'punchout_leave_confirm_label';
	public const LEAVE_STAY_FILTER    = 'punchout_leave_stay_label';

	/** Filter over "Saved addresses only in a visit" (0.5.1), applied to the saved setting; it runs last. */
	public const SAVED_ADDRESSES_FILTER = 'punchout_visit_saved_addresses_only';

	/** Who sees the My Account "Punchout integration" tab (0.5.2): accounts bound to a connection (default), every signed-in account (0.5.1), nobody. */
	public const ACCOUNT_TAB_OWNERS   = 'owners';
	public const ACCOUNT_TAB_EVERYONE = 'everyone';
	public const ACCOUNT_TAB_OFF      = 'off';
	public const ACCOUNT_TAB_MODES    = [ self::ACCOUNT_TAB_OWNERS, self::ACCOUNT_TAB_EVERYONE, self::ACCOUNT_TAB_OFF ];

	/** Which pages outside the shop a visit opens (0.5.2): every page (default, 0.5.1 behaviour), or the shop's own pages plus the pages listed. */
	public const VISIT_PAGES_ALL  = 'all';
	public const VISIT_PAGES_SHOP = 'shop';
	public const VISIT_PAGE_MODES = [ self::VISIT_PAGES_ALL, self::VISIT_PAGES_SHOP ];

	/** At most this many extra classes are kept. */
	private const MAX_BUTTON_CLASSES = 20;

	/** The longest label a button setting keeps, in characters. */
	public const MAX_LABEL = 100;

	/**
	 * Settings 0.5.0 retired with the review page, each with the label it had on the settings screen. A save keeps a
	 * stored value of any of them as it was (never a new one), so a rollback to 0.4.23 finds its words again.
	 */
	public const RETIRED = [
		'return_button_label'    => 'Punchout button label',
		'attachment_enabled'     => 'Attachment field',
		'attachment_label'       => 'Attachment label',
		'attachment_help'        => 'Attachment help text',
		'delivery_estimate_note' => 'Delivery estimate note',
		'review_title'           => 'Review page title',
		'review_submit_label'    => 'Review Submit button',
		'review_items_heading'   => 'Review items heading',
		'review_total_label'     => 'Review total label',
		'review_tax_note'        => 'Review tax sentence',
	];

	private const DEFAULTS = [
		// Master switch. Off by default so a fresh install exposes no
		// pre-auth XML endpoint until an operator has configured at least
		// one customer connection and flipped it on.
		'enabled'              => 'no',

		// Default TTLs; each partner row can override its own. Both are capped at
		// Partners\Partner::MAX_TTL (7 days) wherever they are read or saved.
		'token_ttl'            => 300,     // StartPage token, seconds (~5 min).
		'session_ttl'          => 14400,   // Punchout login, seconds (4 h).

		// 0.4.23: a GET of the StartPage link shows a small page that posts itself back, and only that POST signs
		// the buyer in, so a mail scanner that fetches the link does not use it up. 'yes' leaves out the automatic
		// submit: the buyer presses the button once, for scanners that also run scripts.
		'start_link_click'     => 'no',
		// 0.4.23: the label of that page's button. Blank = the translated default "Open the catalog"; the filter
		// punchout_start_link_button_label runs last (Http\StartEndpoint::button_label()).
		'start_link_button_label' => '',

		// /punchout/setup downstream limit per (partner|unknown-sender, IP). Public construction uses 30 for nonpositive settings.
		'rate_limit_per_min'   => 30,

		// Per IP, pre-resolution: bounds body reads, parsing and audit storage.
		'edge_rate_limit_per_min' => 120,

		// Audit-table retention. The scope treats the log as dispute
		// evidence, so the default keeps a year-plus before cron trims.
		'log_retention_days'   => 400,

		// Page the buyer lands on after auto-login, and is 302'd back to by
		// the route guard. 0 = the shop page.
		'landing_page_id'      => 0,

		// The buyer-facing label of the cancel control. Empty means "use the
		// translated default" — the defaults themselves cannot live in a
		// constant because they are translatable. The pow_abandon_button_label
		// filter still runs last, so code can override it.
		'abandon_button_label' => '',

		// 0.5.0: the cart's proceed button and the Cart widget's checkout button in a visit, which lead to
		// WooCommerce's checkout. Blank = the translated default "Proceed to checkout"; filter
		// punchout_checkout_button_label runs last.
		'checkout_button_label' => '',

		// 0.5.0: the page holding [punchout_state], where a visit's browser is sent once its cart has gone and when
		// its link has expired or its session has ended, so those states draw inside the theme (Http\StatePage).
		// 0 = none: the plain pages are used. Its button's label is the one setting the states have: blank = the
		// translated default "Continue to your purchasing system"; filter punchout_state_button_label runs last.
		'state_page_id'        => 0,
		'state_button_label'   => '',

		// 0.5.0: 'yes' makes the cancel control ([punchout_abandon_button]) ask first when the cart has items. Its
		// two buttons are the only settings; the question is the shortcode's own attribute. Blank = the translated
		// defaults "Return without sending" and "Keep shopping"; filters punchout_leave_confirm_label and
		// punchout_leave_stay_label run last (Cart\LeaveConfirmation).
		'leave_confirmation'   => 'no',
		'leave_confirm_label'  => '',
		'leave_stay_label'     => '',

		// 0.5.1: 'yes' (the default) makes a visit's checkout choose from the bound account's saved delivery
		// addresses only: an address-book selector loses its "add new" choice, every other address field posts
		// hidden, and a posted delivery address that is not a saved entry is refused (Checkout\SavedAddresses).
		// The filter punchout_visit_saved_addresses_only runs last.
		'visit_saved_addresses_only' => 'yes',

		// 0.5.2: who sees the My Account "Punchout integration" tab (its setup-XML download and the opt-in reset):
		// 'owners' (the default) = an account bound to a connection, whatever the connection's status; 'everyone' =
		// every signed-in account, as in 0.5.1; 'off' = nobody (administrators hand out the setup XML from the
		// Integration docs tab). Never inside a visit, whatever this says (Account\IntegrationTab).
		'account_tab'          => self::ACCOUNT_TAB_OWNERS,

		// 0.5.2: which pages outside the shop a live visit opens. 'all' (the default) keeps 0.5.1: every page opens.
		// 'shop' opens the shop's own pages (products, the shop, product categories, tags, attributes and brands,
		// search, the cart, checkout, the visit's ticked My Account pages), the landing page, the state page, the
		// terms and privacy pages checkout links to, pages holding the visit's own shortcodes, and the pages listed in
		// 'visit_open_pages' (comma-separated page IDs); any other page or archive sends the buyer to the landing
		// page with the refusal notice (RouteGuard). The filter punchout_visit_page_open has the last word.
		'visit_pages'          => self::VISIT_PAGES_ALL,
		'visit_open_pages'     => '',

		// The site's own button classes, space-separated, added to the cancel
		// control so it matches the theme without the plugin naming it.
		// Normalised on save and on read by button_class_tokens(); empty adds
		// nothing.
		'extra_button_classes' => '',

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
	 * Normalise a list of extra button classes.
	 *
	 * Whitespace-separated; each token passes sanitize_html_class. Empty
	 * results, plugin classes (`pow-…`, any case) and repeats are dropped,
	 * and at most 20 are kept.
	 *
	 * @return list<string>
	 */
	public static function button_class_tokens( mixed $raw ): array {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return [];
		}
		$tokens = [];
		foreach ( preg_split( '/\s+/', trim( $raw ) ) ?: [] as $token ) {
			$token = sanitize_html_class( $token );
			if ( '' === $token || 0 === stripos( $token, 'pow-' ) || in_array( $token, $tokens, true ) ) {
				continue;
			}
			$tokens[] = $token;
			if ( count( $tokens ) >= self::MAX_BUTTON_CLASSES ) {
				break;
			}
		}
		return $tokens;
	}

	/**
	 * A label setting as saved: plain text (tags stripped), one line, trimmed, at most MAX_LABEL characters;
	 * anything that is not a string saves as blank (the default).
	 */
	public static function sanitise_label( mixed $raw ): string {
		if ( ! is_string( $raw ) ) { return ''; }
		$label = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $raw ) ) );
		return mb_strlen( $label ) > self::MAX_LABEL ? rtrim( mb_substr( $label, 0, self::MAX_LABEL ) ) : $label;
	}

	/**
	 * The operator's extra button classes, sanitised again on read.
	 *
	 * @return list<string>
	 */
	public function extra_button_classes(): array {
		return self::button_class_tokens( $this->get( 'extra_button_classes', '' ) );
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

	/** The visit's checkout-button label: a non-blank saved value, else "Proceed to checkout"; the filter runs last and a blank result keeps the default. */
	public function checkout_button_label(): string {
		return $this->filtered_label( 'checkout_button_label', __( 'Proceed to checkout', 'punchout-woocommerce' ), self::CHECKOUT_BUTTON_FILTER );
	}

	/** The state page's button (0.5.0): a non-blank saved value, else "Continue to your purchasing system"; the filter runs last. */
	public function state_button_label(): string {
		return $this->filtered_label( 'state_button_label', __( 'Continue to your purchasing system', 'punchout-woocommerce' ), self::STATE_BUTTON_FILTER );
	}

	/** True when the cancel control asks first while the cart has items (0.5.0; off by default). */
	public function leave_confirmation(): bool {
		return 'yes' === $this->get( 'leave_confirmation', 'no' );
	}

	/** The leave-confirmation's button that leaves without sending (0.5.0): saved value, else "Return without sending"; the filter runs last. */
	public function leave_confirm_label(): string {
		return $this->filtered_label( 'leave_confirm_label', __( 'Return without sending', 'punchout-woocommerce' ), self::LEAVE_CONFIRM_FILTER );
	}

	/** The leave-confirmation's button that stays (0.5.0): saved value, else "Keep shopping"; the filter runs last. */
	public function leave_stay_label(): string {
		return $this->filtered_label( 'leave_stay_label', __( 'Keep shopping', 'punchout-woocommerce' ), self::LEAVE_STAY_FILTER );
	}

	/** A button label: a non-blank saved value, else the default; then the filter, whose blank result keeps the default. */
	private function filtered_label( string $key, string $default, string $filter ): string {
		$label = trim( (string) apply_filters( $filter, $this->button_label( $key, $default ) ) );
		return '' !== $label ? $label : $default;
	}

	/** True when a visit's checkout offers the account's saved delivery addresses only (0.5.1; on by default); the filter runs last. */
	public function visit_saved_addresses_only(): bool {
		return (bool) apply_filters( self::SAVED_ADDRESSES_FILTER, 'yes' === $this->get( 'visit_saved_addresses_only', 'yes' ) );
	}

	/** Who sees the My Account "Punchout integration" tab (0.5.2): one of ACCOUNT_TAB_MODES; anything else reads as the default, 'owners'. */
	public function account_tab(): string {
		$mode = $this->get( 'account_tab', self::ACCOUNT_TAB_OWNERS );
		return is_string( $mode ) && in_array( $mode, self::ACCOUNT_TAB_MODES, true ) ? $mode : self::ACCOUNT_TAB_OWNERS;
	}

	/** Which pages outside the shop a visit opens (0.5.2): 'shop' when saved exactly so, else 'all' (the default). */
	public function visit_pages(): string {
		return self::VISIT_PAGES_SHOP === $this->get( 'visit_pages', self::VISIT_PAGES_ALL ) ? self::VISIT_PAGES_SHOP : self::VISIT_PAGES_ALL;
	}

	/**
	 * The pages a visit may also open while only shop pages open (0.5.2), as saved, read again.
	 *
	 * @return list<int>
	 */
	public function visit_open_pages(): array {
		return self::page_ids( $this->get( 'visit_open_pages', '' ) );
	}

	/**
	 * A list of page IDs as typed: separated by commas or white space (or already a list); each a positive whole
	 * number, kept once, in the order given; anything else is dropped (page_id_entries() names what).
	 *
	 * @return list<int>
	 */
	public static function page_ids( mixed $raw ): array {
		return self::page_id_entries( $raw )['ids'];
	}

	/**
	 * page_ids() and the entries it drops (0.5.3): `ids` as page_ids() reads them, `rejected` every non-empty entry
	 * that is not a positive whole number, trimmed, once each, in the order given.
	 *
	 * @return array{ids: list<int>, rejected: list<string>}
	 */
	public static function page_id_entries( mixed $raw ): array {
		$parts    = is_array( $raw ) ? $raw : ( is_string( $raw ) ? ( preg_split( '/[\s,]+/', $raw ) ?: [] ) : [] );
		$ids      = [];
		$rejected = [];
		foreach ( $parts as $part ) {
			if ( is_int( $part ) ) { $part = (string) $part; }
			$part = is_string( $part ) ? trim( $part ) : '';
			if ( '' === $part ) { continue; }
			if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/', $part ) ) {
				if ( ! in_array( $part, $rejected, true ) ) { $rejected[] = $part; }
				continue;
			}
			$id = (int) $part;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) { $ids[] = $id; }
		}
		return [ 'ids' => $ids, 'rejected' => $rejected ];
	}

	/** True when the StartPage link waits for one real click instead of submitting itself (0.4.23; off by default). */
	public function start_link_click(): bool {
		return 'yes' === $this->get( 'start_link_click', 'no' );
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
	 * The retired settings (0.5.0) as the option still stores them, so a save carries them over unchanged and a
	 * rollback to 0.4.23 finds the review's words again. Never a value from the screen: the screen has no field.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored_retired(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return is_array( $stored ) ? array_intersect_key( $stored, self::RETIRED ) : [];
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
