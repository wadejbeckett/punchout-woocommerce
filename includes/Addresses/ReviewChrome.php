<?php
/** Whether the delivery review is drawn inside the active theme. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

defined( 'ABSPATH' ) || exit;

/**
 * The review page (/punchout/confirm) is a plugin route, not a WordPress page. Since 0.4.12 a GET of
 * it is drawn inside the theme's header and footer, so the buyer stays in the store's chrome
 * (layouts, menus, conditions). A POST (the review's own forms) keeps the dedicated no-store
 * document, as does any theme without header/footer templates (a block theme), and any site that
 * turns it off with the `punchout_review_in_theme` filter.
 */
final class ReviewChrome {
	public const FILTER = 'punchout_review_in_theme';

	/** The decision for this request. */
	public static function wraps_request(): bool {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		$block  = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; the handler verifies the nonce.
		$action = self::post_action( $_POST );
		return self::decide( $method, $block, (bool) apply_filters( self::FILTER, true ), $action );
	}

	/**
	 * The review action a POST asks for, read exactly as the handler reads it (0.4.21): the named action, else
	 * "add_address" for the country refresh, else "review" (the cart's Punchout button posts no action).
	 */
	public static function post_action( array $post ): string {
		if ( isset( $post['pow_delivery_action'] ) ) { return is_string( $post['pow_delivery_action'] ) ? $post['pow_delivery_action'] : ''; }
		return isset( $post['pow_address_refresh'] ) ? 'add_address' : 'review';
	}

	/**
	 * Pure rule: a classic theme, the site has not opted out, and either a GET or (0.4.20) a POST that redraws the
	 * review (recalculate, add address). Submit (the handoff document) and back (a redirect) are never wrapped.
	 */
	public static function decide( string $method, bool $block_theme, bool $enabled, ?string $action = null ): bool {
		if ( $block_theme || ! $enabled ) { return false; }
		$method = strtoupper( $method );
		return 'GET' === $method || ( 'POST' === $method && in_array( $action, [ 'review', 'add_address' ], true ) );
	}

	public const PAGE_FILTER = 'punchout_review_page_id';

	/**
	 * The page the review poses as while the theme draws it (0.4.18). WordPress parses the plugin route as
	 * "nothing found"; themes that pick a layout, header and footer by the current page then draw their
	 * bare fallback. Posing as the shop's cart page (the step the review follows) makes those themes treat the
	 * review as that page. 0 keeps the request as it is. Filter `punchout_review_page_id` to choose another
	 * page or 0.
	 */
	public static function pose_as_page_id(): int {
		$cart = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0;
		return self::page_to_pose( (int) apply_filters( self::PAGE_FILTER, $cart ) );
	}

	/** A positive page id, or 0 for "do not pose". WooCommerce answers -1 for an unset page. */
	public static function page_to_pose( int $id ): int {
		return $id > 0 ? $id : 0;
	}
}
