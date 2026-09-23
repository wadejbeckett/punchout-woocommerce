<?php
/**
 * The My Account pages a connection lets its visits open.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Account;

defined( 'ABSPATH' ) || exit;

/**
 * One connection column, `visit_endpoints`: a comma-separated list of the
 * WooCommerce My Account pages a punchout visit may open. An entry is an
 * endpoint name as WooCommerce's endpoint map keys it, or `dashboard`, which
 * stands for the account page itself (/my-account/ with no endpoint), the
 * name WooCommerce's account menu gives that page. Empty opens nothing.
 *
 * An administrator ticks the pages on the connection form, which lists what
 * the site has registered at that moment. Every buyer of a connection is
 * signed in as the same account, so some pages belong to that one account
 * rather than to the visit — its order history, addresses, account details
 * and payment methods are every colleague's at once — and the form says so
 * beside them. What is ticked is the connection's decision, with three rules
 * no setting changes:
 *
 * - the payment-method pages open only for a connection whose exit policy
 *   allows WooCommerce's own checkout, and every connection is punchout-only
 *   in this release, so they never open (listed() leaves them out);
 * - logout, lost password, order-pay, order-received and the plugin's own
 *   integration tab never open in a visit (listed() leaves them out too):
 *   the first four have no account content, and the tab shows nothing to
 *   a visit, so ticking them could only mislead;
 * - RouteGuard opens a ticked page only when WooCommerce has account content
 *   for it on that request, so a ticked name never shows another page.
 *
 * A new connection lists the dashboard and nothing else. The column's own
 * default stays '', so a row written before 0.4.6, or by anything other than
 * the registry, keeps its value and opens nothing it does not name.
 *
 * Pure: no WordPress call, so the admin form, the registry and the route
 * guard all ask the same function the same question.
 */
final class VisitEndpoints {

	/** The account page itself, /my-account/ with no endpoint. */
	public const DASHBOARD = 'dashboard';

	/** What a new connection lists. */
	public const NEW_CONNECTION = self::DASHBOARD;

	/**
	 * WooCommerce's standard endpoint set (WC_Query::init_query_vars()), in
	 * the order the connection form shows it. A WooCommerce feature can add
	 * more at run time; the form recognises those by their content (see
	 * groups()). Anything else was added by another plugin.
	 *
	 * @var list<string>
	 */
	public const WOOCOMMERCE = [
		'orders',
		'view-order',
		'downloads',
		'edit-address',
		'payment-methods',
		'add-payment-method',
		'delete-payment-method',
		'set-default-payment-method',
		'edit-account',
		'customer-logout',
		'lost-password',
		'order-pay',
		'order-received',
	];

	/**
	 * The payment-method pages. They open only where the connection's exit
	 * policy allows WooCommerce's own checkout.
	 *
	 * @var list<string>
	 */
	public const PAYMENT = [
		'payment-methods',
		'add-payment-method',
		'delete-payment-method',
		'set-default-payment-method',
	];

	/**
	 * Pages that never open in a visit, ticked or not. WooCommerce has no
	 * account content for logout, lost password, order-pay and
	 * order-received (the last two are checkout pages, and checkout is refused
	 * in every visit), and the plugin's own integration tab shows nothing to a
	 * visit. The form shows them disabled, and listed() leaves them out.
	 *
	 * @var list<string>
	 */
	public const NEVER_OPEN = [
		'customer-logout',
		'lost-password',
		'order-pay',
		'order-received',
		IntegrationTab::ENDPOINT,
	];

	/**
	 * WooCommerce pages the form does not show as a row of their own. A
	 * single order's page belongs with the order list: the form's Orders row
	 * stands for both, and in a visit a single order opens only when it is
	 * that visit's own quote order (RouteGuard's order fence).
	 *
	 * @var list<string>
	 */
	public const FOLDED = [
		'view-order',
	];

	/** The column is VARCHAR(255). */
	public const MAX_LENGTH = 255;

	/** Lowercase letters, digits, hyphen and underscore, starting with a letter or digit. */
	private const SLUG = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

	/** Whether $entry can be stored: a lowercase endpoint name, or `dashboard`. */
	public static function is_name( string $entry ): bool {
		return 1 === preg_match( self::SLUG, $entry );
	}

	/**
	 * Read what the connection form sent.
	 *
	 * A string is a comma- or line-separated list; an array holds one entry
	 * per element, as the form's checkboxes send them, so an element is never
	 * split. `endpoints` is what would be stored, in the order given, without
	 * duplicates. `rejected` names every entry that is not a lowercase
	 * endpoint name; `too_long` says the accepted list does not fit the
	 * column. A form save is refused unless both are empty.
	 *
	 * @param string|array<mixed> $raw
	 * @return array{endpoints: list<string>, rejected: list<string>, too_long: bool}
	 */
	public static function parse( string|array $raw ): array {
		$entries   = is_array( $raw )
			? array_map( static fn( mixed $entry ): string => is_string( $entry ) ? $entry : '', array_values( $raw ) )
			: ( preg_split( '/[,\r\n]+/', $raw ) ?: [] );
		$endpoints = [];
		$rejected  = [];

		foreach ( $entries as $entry ) {
			$entry = trim( $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( ! self::is_name( $entry ) ) {
				$rejected[] = $entry;
				continue;
			}

			if ( ! in_array( $entry, $endpoints, true ) ) {
				$endpoints[] = $entry;
			}
		}

		return [
			'endpoints' => $endpoints,
			'rejected'  => array_values( array_unique( $rejected ) ),
			'too_long'  => strlen( implode( ',', $endpoints ) ) > self::MAX_LENGTH,
		];
	}

	/**
	 * The stored form: accepted entries only, comma-joined, cut at the last
	 * whole entry that fits the column. Used on every write and every read,
	 * so a hand-edited row can hold nothing the form would refuse.
	 */
	public static function normalise( string $raw ): string {
		$stored = '';

		foreach ( self::parse( $raw )['endpoints'] as $endpoint ) {
			$next = '' === $stored ? $endpoint : $stored . ',' . $endpoint;

			if ( strlen( $next ) > self::MAX_LENGTH ) {
				break;
			}

			$stored = $next;
		}

		return $stored;
	}

	/**
	 * The column as a list, after the same normalisation as a write. The
	 * pages that never open are left out, and so are the payment-method pages
	 * without native checkout: whatever the row holds, they cannot open.
	 *
	 * @return list<string>
	 */
	public static function listed( string $stored, bool $native_checkout = false ): array {
		$normalised = self::normalise( $stored );
		$listed     = '' === $normalised ? [] : explode( ',', $normalised );

		return array_values( array_diff( $listed, self::NEVER_OPEN, $native_checkout ? [] : self::PAYMENT ) );
	}

	/**
	 * Whether a visit of a connection allowing $allowed may open $endpoint.
	 * '' is the account page with no endpoint, which `dashboard` allows.
	 *
	 * @param list<string> $allowed The connection's list, from listed().
	 */
	public static function allows( array $allowed, string $endpoint ): bool {
		return in_array( '' === $endpoint ? self::DASHBOARD : $endpoint, $allowed, true );
	}

	/**
	 * The connection form's rows, in four groups: WooCommerce's own pages
	 * (the dashboard first, then its standard set in a fixed order, then
	 * pages a WooCommerce feature added), the plugin's own tab, pages added by
	 * other plugins (alphabetical), and entries the connection lists that the
	 * site does not register right now. The last group exists because some
	 * plugins register a page only for some accounts: such an entry keeps a
	 * ticked row, so saving the form never drops it unseen.
	 *
	 * A registered name that could not be stored is left out, and so is a
	 * third-party page registered as `dashboard`, a name the account page
	 * itself holds here. view-order is folded into the Orders row.
	 *
	 * @param list<string> $registered  Account page names the site registers now.
	 * @param list<string> $stored      The connection's list.
	 * @param list<string> $woocommerce Registered names beyond the standard set whose content WooCommerce itself provides.
	 * @return array{woocommerce: list<string>, plugin: list<string>, other: list<string>, stored: list<string>}
	 */
	public static function groups( array $registered, array $stored, array $woocommerce = [] ): array {
		$registered = array_values( array_unique( array_filter( array_map( 'strval', $registered ), [ self::class, 'is_name' ] ) ) );
		$features   = array_values( array_intersect( array_diff( array_unique( array_map( 'strval', $woocommerce ) ), self::WOOCOMMERCE, [ IntegrationTab::ENDPOINT, self::DASHBOARD ] ), $registered ) );
		$other      = array_values( array_diff( $registered, self::WOOCOMMERCE, $features, [ IntegrationTab::ENDPOINT, self::DASHBOARD ] ) );
		sort( $features, SORT_STRING );
		sort( $other, SORT_STRING );

		return [
			'woocommerce' => array_merge( [ self::DASHBOARD ], array_values( array_diff( array_intersect( self::WOOCOMMERCE, $registered ), self::FOLDED ) ), $features ),
			'plugin'      => in_array( IntegrationTab::ENDPOINT, $registered, true ) ? [ IntegrationTab::ENDPOINT ] : [],
			'other'       => $other,
			'stored'      => array_values( array_diff( array_map( 'strval', $stored ), $registered, [ self::DASHBOARD ] ) ),
		];
	}
}
