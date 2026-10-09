<?php
/**
 * Cancel and return asks first while the cart has items (0.5.0).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Cart;

use POW\Http\StatePage;
use POW\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The rule behind the "Ask before leaving" setting. While it is on and the visit's cart holds a line, the cancel
 * control ([punchout_abandon_button]) does not leave at once:
 *
 * - with the plugin's own script (assets/js/leave-confirmation.js), pressing it opens the question in a dialog on the
 *   page, and only the dialog's leave button posts;
 * - without it, the post reaches /punchout/return without the answer, and the server sends the browser back to the
 *   page it came from with ?pow_leave=ask, where the shortcode draws the same dialog already open.
 *
 * Only a post carrying pow_leave=confirmed leaves, so the server's check is the rule and the dialog is its
 * presentation. An empty cart leaves at once, and so does every cancel while the setting is off. The question is the
 * shortcode's `question` attribute; the two buttons are settings (Settings::leave_confirm_label(), leave_stay_label()).
 */
final class LeaveConfirmation {

	/** The posted answer, and the query argument that opens the dialog without a script. */
	public const FIELD = 'pow_leave';

	/** The answer that leaves. */
	public const CONFIRMED = 'confirmed';

	/** The query value that opens the dialog. */
	public const ASK = 'ask';

	/** The plugin's script handle. */
	public const SCRIPT = 'pow-leave-confirmation';

	/** Whether this Cancel and return must ask first: on, the cart holds a line, and the post does not carry the answer. */
	public static function must_ask( Settings $settings, int $items, mixed $posted ): bool {
		return $settings->leave_confirmation() && $items > 0 && self::CONFIRMED !== $posted;
	}

	/** The cart's lines in this request (the visit's own basket), 0 when there is no cart. */
	public static function cart_items(): int {
		try {
			$cart = function_exists( 'WC' ) ? WC()->cart : null;
			return is_object( $cart ) && method_exists( $cart, 'get_cart' ) ? count( (array) $cart->get_cart() ) : 0;
		} catch ( \Throwable $error ) {
			return 0;
		}
	}

	/** Where a cancel that must ask first goes without the script: the page it came from, else $fallback, asking. */
	public static function ask_url( string|false $referer, string $fallback ): string {
		$page = is_string( $referer ) && '' !== $referer ? $referer : $fallback;
		return StatePage::with_query( $page, self::FIELD, self::ASK );
	}

	/** Whether this page was sent back to ask (the dialog is drawn open). */
	public static function asked(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation only: whether a dialog is drawn open.
		return self::ASK === ( $_GET[ self::FIELD ] ?? null );
	}
}
