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
 * entitlement: punchout is the only exit a visit has, so nothing here
 * decides what a visit may reach.
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

	/**
	 * What the visit stylesheet hides with display:none, each selector
	 * ending in the class it hides. Cart\Surface emits exactly these, and
	 * the final class of each is reserved (reserved_button_classes()): an
	 * operator's extra class on the exit would otherwise hide the visit's
	 * only way out. One list, so the two can never drift apart.
	 */
	public const VISIT_HIDDEN_SELECTORS = [ '.checkout-button', '.widget_shopping_cart .buttons .checkout', '.wc-block-mini-cart__footer-checkout' ];

	/** At most this many extra classes are kept. */
	private const MAX_BUTTON_CLASSES = 20;

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

		// Buyer-facing labels for the two session exit controls. Empty
		// means "use the translated default" — the defaults themselves
		// cannot live in a constant because they are translatable. The
		// pow_return_button_label / pow_abandon_button_label filters still
		// run last, so code can override either one.
		'return_button_label'  => '',
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

		// The site's own button classes, space-separated, added to the exit
		// controls and the review page's primary actions so they match the
		// theme without the plugin naming it. Normalised on save and on read
		// by button_class_tokens(); empty adds nothing.
		'extra_button_classes' => '',

		// The review page's optional file field (0.4.14): on by default; the
		// label and help text are blank for the translated defaults
		// ("Attachment", "Optional: a sheet or document for this order.").
		// The punchout_attachment_{enabled,label,help,types,max_bytes}
		// filters run last, in Orders\Attachment.
		'attachment_enabled'   => 'yes',
		'attachment_label'     => '',
		'attachment_help'      => '',

		// 0.4.19: the sentence shown with every delivery estimate and sent with the returned cart. Blank = the translated default.
		'delivery_estimate_note' => '',

		// 0.4.22: the title the theme-drawn review answers with while it poses as the Cart page (title bar, last
		// breadcrumb, document title). Blank = the translated default "Review"; filter punchout_review_title.
		'review_title'         => '',

		// 0.4.23: the review page's Submit button, the heading over its item lines and the label of its total.
		// Blank = the translated defaults "Submit", "Items" and "Total"; filters punchout_review_submit_label,
		// punchout_review_items_heading and punchout_review_total_label run last (Addresses\ReviewLabels).
		'review_submit_label'  => '',
		'review_items_heading' => '',
		'review_total_label'   => '',
		// 0.4.23: 'yes' adds "Amounts exclude tax." under the review's totals while the connection sends the delivery
		// line. Off by default, so the review names tax nowhere (Addresses\ReviewLabels::TAX_NOTE).
		'review_tax_note'      => 'no',

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
	 * results, plugin classes (`pow-…`, any case), the reserved classes the
	 * visit stylesheet hides and repeats are dropped, and at most 20 are kept.
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
			if ( '' === $token || 0 === stripos( $token, 'pow-' ) || in_array( $token, self::reserved_button_classes(), true ) || in_array( $token, $tokens, true ) ) {
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
	 * Classes an operator may not add: the class each VISIT_HIDDEN_SELECTORS
	 * selector ends in, in the same order.
	 *
	 * @return list<string>
	 */
	public static function reserved_button_classes(): array {
		return array_map( static fn( string $selector ): string => substr( $selector, (int) strrpos( $selector, '.' ) + 1 ), self::VISIT_HIDDEN_SELECTORS );
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

	/** True when the review shows its one tax sentence under the totals (0.4.23; off by default, and never while the connection sends no delivery line). */
	public function review_tax_note(): bool {
		return 'yes' === $this->get( 'review_tax_note', 'no' );
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
