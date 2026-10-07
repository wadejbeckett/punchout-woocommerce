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
		return self::decide( $method, $block, (bool) apply_filters( self::FILTER, true ) );
	}

	/** Pure rule: only a GET, only in a classic theme, only when the site has not opted out. */
	public static function decide( string $method, bool $block_theme, bool $enabled ): bool {
		return 'GET' === strtoupper( $method ) && ! $block_theme && $enabled;
	}
}
