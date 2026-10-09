<?php
/** The title the review answers with while it poses as another page (0.4.22). @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Since 0.4.18 the theme-drawn review poses as the shop's Cart page so the theme draws that page's layout
 * (ReviewChrome::pose_as_page_id()). Themes then read the posed page's title for their title bar and
 * breadcrumbs, so the review called itself "Cart". For that request only, the posed page's title reads as
 * the review title instead ("Review" by default; setting "Review page title", filter `punchout_review_title`,
 * '' turns the relabel off).
 *
 * It works through WordPress's own title filters, so it reaches anything that reads the page title with
 * get_the_title() or single_post_title(): WooCommerce breadcrumbs, most classic themes' title bars and
 * breadcrumbs, and page-builder breadcrumbs and title elements that read the current page's title. Breadcrumbs
 * that keep their own stored titles (some SEO plugins) are not reached.
 *
 * WooCommerce's breadcrumb already reads the title through `the_title`; `woocommerce_get_breadcrumb` also relabels
 * a last crumb that still carries the page's stored title (a theme that read it before the filter ran). The
 * document title of the theme-drawn review reads "{title} — {shop}" (Chooser::handle_in_theme()).
 *
 * Left alone: every other page id; a menu item that points at the posed page (wp_setup_nav_menu_item runs
 * `the_title` with that page's id, so the item is put back to the page's own title unless it has a custom
 * label); the dedicated no-theme document and the posed page itself on any other request, because nothing is
 * registered there.
 */
final class ReviewTitle {
	public const SETTING = 'review_title';
	public const FILTER  = 'punchout_review_title';
	/** The longest label kept from the setting. */
	public const MAX_LENGTH = 100;

	private static ?self $current = null;

	/** True while a menu item asks for the posed page's own title. */
	private bool $suspended = false;

	/**
	 * @param int           $page_id  The posed page.
	 * @param string        $label    The review title, already resolved and non-empty.
	 * @param string        $original The posed page's stored title, for menu items.
	 * @param \Closure|null $queried  fn(): int, the request's queried object id (get_queried_object_id()).
	 */
	public function __construct( private int $page_id, private string $label, private string $original = '', private ?\Closure $queried = null ) {}

	/** The site's review title: the saved setting, else the translated default; '' after the filter means "no relabel". */
	public static function text( ?Settings $settings = null ): string {
		$default = function_exists( '__' ) ? __( 'Review', 'punchout-woocommerce' ) : 'Review';
		$saved   = null !== $settings ? $settings->get( self::SETTING, '' ) : '';
		$title   = Settings::resolve_label( $saved, $default );
		if ( function_exists( 'apply_filters' ) ) { $title = apply_filters( self::FILTER, $title ); }
		return is_string( $title ) ? trim( $title ) : '';
	}

	/** The setting as saved: plain text, at most MAX_LENGTH characters ('' keeps the default). */
	public static function sanitise( mixed $raw ): string {
		$text = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' ) : trim( is_scalar( $raw ) ? (string) $raw : '' );
		return mb_substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * Relabel $page for the rest of this request. Called once the pose has really happened (a published page with a
	 * positive id); a second call in the same request keeps the first. Returns the active relabel, or null when the
	 * page is not one or the title is ''.
	 */
	public static function relabel( mixed $page, ?Settings $settings = null ): ?self {
		if ( null !== self::$current ) { return self::$current; }
		if ( ! is_object( $page ) || (int) ( $page->ID ?? 0 ) <= 0 ) { return null; }
		$label = self::text( $settings );
		if ( '' === $label ) { return null; }
		$relabel = new self( (int) $page->ID, $label, (string) ( $page->post_title ?? '' ) );
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'the_title', [ $relabel, 'the_title' ], 20, 2 );
			add_filter( 'single_post_title', [ $relabel, 'single_post_title' ], 20, 2 );
			add_filter( 'wp_setup_nav_menu_item', [ $relabel, 'menu_item' ], 20, 1 );
			add_filter( 'woocommerce_get_breadcrumb', [ $relabel, 'breadcrumb' ], 20, 1 );
		}
		return self::$current = $relabel;
	}

	/** The relabel registered in this request, if any. */
	public static function current(): ?self {
		return self::$current;
	}

	/** Forget this request's relabel (a long-running process or a test that plays several requests). */
	public static function reset(): void {
		if ( null !== self::$current && function_exists( 'remove_filter' ) ) {
			remove_filter( 'the_title', [ self::$current, 'the_title' ], 20 );
			remove_filter( 'single_post_title', [ self::$current, 'single_post_title' ], 20 );
			remove_filter( 'wp_setup_nav_menu_item', [ self::$current, 'menu_item' ], 20 );
			remove_filter( 'woocommerce_get_breadcrumb', [ self::$current, 'breadcrumb' ], 20 );
		}
		self::$current = null;
	}

	public function page_id(): int {
		return $this->page_id;
	}

	public function label(): string {
		return $this->label;
	}

	/**
	 * `the_title`: the posed page's title reads as the review title, and only while that page is the request's queried
	 * object (WooCommerce's wc_page_endpoint_title() follows the same rule for account endpoints).
	 */
	public function the_title( mixed $title, mixed $id = null ): mixed {
		if ( $this->suspended || ! is_string( $title ) || ! is_numeric( $id ) || (int) $id !== $this->page_id || $this->queried_id() !== $this->page_id ) { return $title; }
		return function_exists( 'esc_html' ) ? esc_html( $this->label ) : htmlspecialchars( $this->label, ENT_QUOTES, 'UTF-8' );
	}

	/** `single_post_title`: the same rule for themes that title the page with single_post_title(). */
	public function single_post_title( mixed $title, mixed $post = null ): mixed {
		return is_object( $post ) ? $this->the_title( $title, $post->ID ?? null ) : $title;
	}

	/**
	 * `woocommerce_get_breadcrumb`: the last crumb, when it still names the posed page by its stored title, reads as
	 * the review title. Crumbs are [name, url] pairs; anything else is returned unchanged.
	 */
	public function breadcrumb( mixed $crumbs ): mixed {
		if ( ! is_array( $crumbs ) || [] === $crumbs ) { return $crumbs; }
		$last = array_key_last( $crumbs );
		if ( ! is_array( $crumbs[ $last ] ) || ! is_string( $crumbs[ $last ][0] ?? null ) ) { return $crumbs; }
		$name = html_entity_decode( $crumbs[ $last ][0], ENT_QUOTES, 'UTF-8' );
		if ( '' === $this->original || trim( $name ) !== trim( html_entity_decode( $this->original, ENT_QUOTES, 'UTF-8' ) ) ) { return $crumbs; }
		$crumbs[ $last ][0] = $this->label;
		return $crumbs;
	}

	/** The theme-drawn review's document title: "{title} — {shop}", or the fallback when no relabel is active. */
	public static function document_title( string $shop, string $fallback ): string {
		$label = null !== self::$current ? self::$current->label : '';
		return ( '' !== $label ? $label : $fallback ) . ' — ' . $shop;
	}

	/**
	 * `wp_setup_nav_menu_item`: a page-type menu item that points at the posed page took its title from `the_title`
	 * with that page's id, so it read as the review title. With no custom label it gets the page's own title back
	 * (through the other `the_title` filters, as WordPress would have drawn it); a custom label was never touched.
	 */
	public function menu_item( mixed $item ): mixed {
		if ( ! is_object( $item ) || 'post_type' !== ( $item->type ?? '' ) || (int) ( $item->object_id ?? 0 ) !== $this->page_id || '' !== (string) ( $item->post_title ?? '' ) ) { return $item; }
		$this->suspended = true;
		try {
			$title = function_exists( 'apply_filters' ) ? apply_filters( 'the_title', $this->original, $this->page_id ) : $this->original;
			$item->title = is_string( $title ) ? $title : $this->original;
		} finally {
			$this->suspended = false;
		}
		return $item;
	}

	private function queried_id(): int {
		if ( null !== $this->queried ) { return (int) ( $this->queried )(); }
		return function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
	}
}
