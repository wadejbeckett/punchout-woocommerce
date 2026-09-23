<?php
/**
 * Punchout session route scoping.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

use POW\Account\VisitEndpoints;
use POW\Orders\QuoteOrder;
use POW\Partners\Registry;
use POW\Sessions\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a punchout visit on the surfaces it is entitled to and keeps every
 * other visit's basket, order and account out of its reach.
 *
 * The login is the customer's own WooCommerce account, so nothing about
 * the *user* distinguishes a buyer punched in from a purchasing system
 * from the same company signing in with its password: the only proof is
 * the visit row for this request's WP session token. Every decision here
 * therefore asks one question — is this request inside a visit, and is
 * the thing it is reaching for that same visit's? Ordinary shoppers,
 * including the bound account outside a visit, keep native behaviour.
 *
 * A request whose visit cannot be proved (the session store could not
 * answer) is refused rather than treated as an ordinary shopper.
 *
 * The one per-connection question here is which My Account pages a visit
 * may open. It is the connection's `visit_endpoints` column: the pages an
 * administrator ticked, `dashboard` standing for the account page itself.
 */
final class RouteGuard {

	/** Whether this request's visit has already been torn down, once and for all. */
	private bool $visit_ended = false;

	/** @var array<int, list<string>> Each connection's endpoint list, read once per request. */
	private array $visit_endpoints = [];

	/** Whether this request has already recorded a refused user creation. */
	private bool $user_create_recorded = false;

	/**
	 * @param Plugin   $plugin   Container, asked for the request's visit.
	 * @param Registry $registry Connection registry: the source of the My
	 *                           Account endpoints a visit's connection lists,
	 *                           and the holder of the connection lock every
	 *                           writer of the account's shared session_tokens
	 *                           row runs under, which is what ends a visit on
	 *                           logout. Whether a request is inside a visit
	 *                           is never asked of it — the visit row is the
	 *                           whole proof.
	 * @param Settings $settings Operator settings: landing page and labels.
	 */
	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private Settings $settings,
	) {}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'guard' ], 1 );
		// The account page again, last: content registered for an endpoint
		// after the first pass is judged before the page renders.
		add_action( 'template_redirect', [ $this, 'recheck_account_page' ], PHP_INT_MAX );

		// template_redirect runs for front-end page requests only. wp-admin,
		// admin-ajax.php and the REST API never reach it, so the shared
		// login's three remaining doors need their own guards.
		add_action( 'admin_init', [ $this, 'guard_admin' ], 1 );
		add_filter( 'rest_pre_dispatch', [ $this, 'guard_rest' ], -99, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'deny_application_passwords' ], PHP_INT_MAX, 2 );

		// Last, so items and user-data changes made by anything else are
		// judged too.
		add_filter( 'woocommerce_account_menu_items', [ $this, 'visit_menu_items' ], PHP_INT_MAX );
		add_filter( 'wp_pre_insert_user_data', [ $this, 'refuse_user_creation' ], PHP_INT_MAX, 4 );

		add_action( 'woocommerce_checkout_process', [ $this, 'block_checkout_process' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'block_store_api_checkout' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'enforce_payment_context' ], -9999 );
		add_action( 'woocommerce_before_pay_action', [ $this, 'enforce_pay_action' ], -9999 );
		add_action( 'login_init', [ $this, 'guard_login_screen' ] );
		add_action( 'wp_logout', [ $this, 'end_visit_on_logout' ], 0 );
	}

	public function guard(): void {
		$visit = $this->plugin->current_session();

		// My Account opens inside a visit only where the connection ticked
		// the page: the dashboard, a third-party quick-order screen, and
		// only those. This fires on template_redirect only, so it never sees
		// admin-ajax.php, the REST API or wp-admin — guard_admin() and
		// guard_rest() hold those. The bound account outside a visit keeps
		// its whole account area.
		if ( $this->account_page_refused( $visit ) ) {
			$this->redirect_to_landing();
			return;
		}

		// A visit may inspect only the quote order it created. Ordinary
		// order keys, login prompts and email verification remain
		// WooCommerce's.
		$endpoint_order_id = $this->endpoint_order_id();

		if ( $endpoint_order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $endpoint_order_id );
			$order = $order instanceof \WC_Order ? $order : null;

			$wrong_visit_order = null !== $visit && ! $this->order_belongs_to_visit( $order, $visit );
			$payment_denied    = is_wc_endpoint_url( 'order-pay' ) && $this->checkout_blocked_for_visit( $order );

			if ( $wrong_visit_order || $payment_denied ) {
				$this->redirect_to_landing();
				return;
			}
		}

		// The visible checkout block. It runs for every request, not only
		// inside a visit, so an ordinary shopper — and the bound account on
		// its own password login — still reaches /checkout.
		if ( function_exists( 'is_checkout' ) && is_checkout() && 0 === $endpoint_order_id && $this->checkout_blocked_for_visit() ) {
			$target = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : $this->settings->landing_url();
			wp_safe_redirect( $target, 302 );
			exit;
		}
	}

	/**
	 * The account-page check once more, at the end of template_redirect.
	 *
	 * guard() runs at priority 1, before WooCommerce's own form handlers, and
	 * reads which endpoints have account content at that moment. Content a
	 * plugin registers later in template_redirect, for a query var
	 * WooCommerce does not map, would otherwise render unjudged. Content
	 * registered while the page itself renders is beyond any redirect.
	 */
	public function recheck_account_page(): void {
		if ( $this->account_page_refused( $this->plugin->current_session() ) ) {
			$this->redirect_to_landing();
		}
	}

	/** Whether this request is an account page inside a visit that must not open. */
	private function account_page_refused( ?Session $visit ): bool {
		return null !== $visit && function_exists( 'is_account_page' ) && is_account_page() && ! $this->account_endpoint_open( $visit );
	}

	/**
	 * Whether every My Account endpoint this request names is one the
	 * visit's connection lists and has account content of its own. An
	 * account page naming no endpoint is the dashboard, which opens only
	 * when the connection lists `dashboard`. Any failure to read the
	 * connection keeps the account area closed.
	 *
	 * The content check is what keeps a ticked name from showing another
	 * page. WooCommerce renders the first query var that has an
	 * account-endpoint action and falls back to the dashboard when none has.
	 * Every query var with such an action is among the requested endpoints,
	 * so when each requested endpoint has one, the page WooCommerce renders
	 * is one of them, and so a listed one. A listed endpoint without content
	 * stays closed even when the dashboard is ticked, so what opens is always
	 * the page that was asked for.
	 */
	private function account_endpoint_open( Session $visit ): bool {
		try {
			$allowed = $this->visit_endpoints( $visit );

			if ( [] === $allowed ) {
				return false;
			}

			$requested = $this->requested_account_endpoints();

			if ( null === $requested ) {
				return false;
			}

			foreach ( $requested as $endpoint ) {
				if ( ! VisitEndpoints::allows( $allowed, $endpoint ) ) {
					return false;
				}

				if ( '' !== $endpoint && ! self::has_account_content( $endpoint ) ) {
					return false;
				}
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether WooCommerce's account page has content for $endpoint: an action
	 * on woocommerce_account_<endpoint>_endpoint. Without one, the page shows
	 * the dashboard instead. That covers a checkout endpoint such as order-pay
	 * and a third-party endpoint whose plugin registers the query var for
	 * everyone but the content only for some users.
	 */
	private static function has_account_content( string $endpoint ): bool {
		return '' !== $endpoint && function_exists( 'has_action' ) && false !== has_action( 'woocommerce_account_' . $endpoint . '_endpoint' );
	}

	/**
	 * The pages the visit's connection lists: none unless the connection is
	 * active, and never a payment-method page unless its exit policy allows
	 * WooCommerce's checkout. A lookup that throws is not remembered, so the
	 * next caller asks again.
	 *
	 * @return list<string>
	 * @throws \RuntimeException When the connection cannot be read.
	 */
	private function visit_endpoints( Session $visit ): array {
		if ( ! array_key_exists( $visit->partner_id, $this->visit_endpoints ) ) {
			$partner = $this->registry->find( $visit->partner_id );

			$this->visit_endpoints[ $visit->partner_id ] = null !== $partner && $partner->is_active() ? $partner->visit_endpoint_list() : [];
		}

		return $this->visit_endpoints[ $visit->partner_id ];
	}

	/**
	 * Every My Account endpoint this request names; [''] for the dashboard;
	 * null when that cannot be proved.
	 *
	 * WooCommerce's current endpoint is the first of its own endpoint list
	 * the request sets, while the page renders the first query var that has
	 * an account-endpoint action — two different orders. So this is not one
	 * answer but all of them: the current endpoint, every WooCommerce
	 * endpoint the request sets (an ?orders= added to an allowed URL
	 * included), and every query var an account-endpoint action would
	 * render. The caller requires every one of them to be open.
	 *
	 * Before WordPress has parsed the request there are no query vars to
	 * read; the request path and query string, matched against WooCommerce's
	 * endpoint slugs, stand in for them. A path naming none of them cannot be
	 * told apart from a page WooCommerce does not map, so it proves nothing
	 * and the parsed request decides. Without WooCommerce's endpoint map
	 * nothing can be proved at all.
	 *
	 * @return list<string>|null
	 */
	private function requested_account_endpoints(): ?array {
		global $wp;

		$query = function_exists( 'WC' ) ? ( WC()->query ?? null ) : null;

		if ( ! is_object( $query ) || ! method_exists( $query, 'get_query_vars' ) ) {
			return null;
		}

		$slugs = (array) $query->get_query_vars();
		$vars  = is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ? $wp->query_vars : [];

		if ( [] === $vars ) {
			$endpoints = self::endpoints_in_request( $slugs );

			if ( [] === $endpoints ) {
				return null;
			}
		} else {
			$endpoints = is_object( $query ) && method_exists( $query, 'get_current_endpoint' ) ? [ (string) $query->get_current_endpoint() ] : [];

			foreach ( array_keys( $slugs ) as $key ) {
				if ( isset( $vars[ $key ] ) ) {
					$endpoints[] = (string) $key;
				}
			}

			foreach ( array_keys( $vars ) as $key ) {
				if ( 'pagename' !== $key && function_exists( 'has_action' ) && has_action( 'woocommerce_account_' . $key . '_endpoint' ) ) {
					$endpoints[] = (string) $key;
				}
			}
		}

		$endpoints = array_values( array_unique( array_filter( $endpoints, static fn( string $endpoint ): bool => '' !== $endpoint ) ) );

		return [] === $endpoints ? [ '' ] : $endpoints;
	}

	/**
	 * WooCommerce endpoints named by the raw request: a path segment or a
	 * query-string key equal to an endpoint's slug, reported by the
	 * endpoint's own name.
	 *
	 * @param array<string, string> $slugs Endpoint name => slug, as WooCommerce maps them.
	 * @return list<string>
	 */
	private static function endpoints_in_request( array $slugs ): array {
		$path     = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against known slugs, never output.
		$segments = array_values( array_filter( explode( '/', is_string( $path ) ? $path : '' ), static fn( string $segment ): bool => '' !== $segment ) );
		$found    = [];

		foreach ( $slugs as $key => $slug ) {
			$slug = (string) $slug;

			if ( '' !== $slug && ( in_array( $slug, $segments, true ) || isset( $_GET[ $slug ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
				$found[] = (string) $key;
			}
		}

		return $found;
	}

	/**
	 * The account menu inside a visit: only the pages its connection lists,
	 * whoever added the item. The dashboard item stays when `dashboard` is
	 * listed; any other item also needs account content, since the guard
	 * refuses the rest. Outside a visit the menu is untouched. A visit that
	 * cannot be resolved, or whose connection cannot be read, gets an empty
	 * menu.
	 *
	 * @param mixed $items Menu items, endpoint => label.
	 */
	public function visit_menu_items( mixed $items ): mixed {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		try {
			$visit = $this->plugin->current_session();

			if ( null === $visit ) {
				return $items;
			}

			$allowed = $this->visit_endpoints( $visit );
		} catch ( \Throwable $e ) {
			return [];
		}

		return array_filter(
			$items,
			static fn( mixed $endpoint ): bool => VisitEndpoints::DASHBOARD === (string) $endpoint
				? VisitEndpoints::allows( $allowed, '' )
				: VisitEndpoints::allows( $allowed, (string) $endpoint ) && self::has_account_content( (string) $endpoint ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * No user is created from inside a visit through WordPress's user insert.
	 *
	 * A visit is a buyer shopping as the connection's one account, not a
	 * person with a login of their own, and the plugin never creates a user.
	 * admin-ajax.php stays open inside a visit because the front-end basket
	 * calls it, so any plugin action there that creates users — a
	 * sub-account form, a registration handler — would hand a buyer a lasting
	 * password login outside PunchOut. Registration forms, the users REST
	 * route and such AJAX actions all create users through core's one
	 * user-insert routine, and this filter is its earliest generic veto:
	 * given an empty user row, core returns a WP_Error before anything is
	 * written. Code that writes the users table itself, without that
	 * routine, is beyond any WordPress hook. Updates to an existing user pass
	 * untouched.
	 *
	 * A request whose visit cannot be proved is refused, like every other door.
	 *
	 * The audit row names the request, not the account: the script, the
	 * admin-ajax action, the wc-ajax action, the REST route and the path.
	 * One row per request, so a handler that retries in a loop records once;
	 * across requests the rows are trimmed with the rest of the audit log.
	 *
	 * @param mixed $data     The user row about to be written.
	 * @param mixed $update   Whether an existing user is being updated.
	 * @param mixed $user_id  The existing user's id on update, null on creation.
	 * @param mixed $userdata The raw array given to core's user insert, unused.
	 */
	public function refuse_user_creation( mixed $data, mixed $update = false, mixed $user_id = null, mixed $userdata = [] ): mixed {
		if ( true === $update || ! $this->inside_visit() ) {
			return $data;
		}

		if ( $this->user_create_recorded ) {
			return [];
		}

		$this->user_create_recorded = true;

		try {
			$visit = $this->plugin->current_session();
		} catch ( \Throwable $e ) {
			$visit = null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- recorded as reduced data, never acted on.
		$action  = $_REQUEST['action'] ?? '';
		$wc_ajax = $_GET['wc-ajax'] ?? '';
		$path    = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$script  = basename( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) );
		// phpcs:enable
		$rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';

		$this->plugin->audit()?->write_checked(
			'visit_user_create_refused',
			[
				'partner_id' => $visit?->partner_id ?? 0,
				'session_id' => $visit?->id ?? 0,
				'user_id'    => get_current_user_id(),
				'result'     => 'refused',
				'detail'     => [
					'script'     => self::route_text( $script, 64 ),
					'action'     => is_string( $action ) ? substr( sanitize_key( $action ), 0, 64 ) : '',
					'wc_ajax'    => is_string( $wc_ajax ) ? substr( sanitize_key( $wc_ajax ), 0, 64 ) : '',
					'rest_route' => is_string( $rest_route ) ? self::route_text( $rest_route, 128 ) : '',
					'path'       => is_string( $path ) ? self::route_text( $path, 128 ) : '',
				],
			]
		);

		return [];
	}

	/** A route or script name reduced to path characters and cut to $length, for the audit trail. */
	private static function route_text( string $text, int $length ): string {
		return substr( (string) preg_replace( '#[^A-Za-z0-9/_.\-]#', '', $text ), 0, $length );
	}

	/**
	 * wp-admin during a visit: the shared login is an ordinary customer
	 * account, so WordPress would happily serve it the profile screen — and
	 * profile.php asks only for `edit_user` on one's own id, i.e. the account
	 * password and e-mail address every colleague shares.
	 *
	 * admin_init also fires for admin-ajax.php, which a front-end cart can
	 * legitimately call inside a visit; redirecting that would break the
	 * basket, so that one entry point is exempt and left to the guards that
	 * do apply to it.
	 */
	public function guard_admin(): void {
		if ( self::is_admin_ajax() ) {
			return;
		}

		if ( ! $this->inside_visit() ) {
			return;
		}

		$this->redirect_to_landing();
	}

	/**
	 * Whether this request really is admin-ajax.php, proved by the script
	 * WordPress is running.
	 *
	 * wp_doing_ajax() cannot answer this. It reports the DOING_AJAX constant,
	 * and WooCommerce defines that constant from a query parameter — its
	 * `wc-ajax` endpoint is picked up at `init` priority 0 on every request,
	 * wp-admin page loads included, long before `admin_init` runs. Trusting
	 * it therefore let a buyer switch this refusal off with a query string of
	 * her own (`/wp-admin/profile.php?wc-ajax=1`) and take over the shared
	 * account's password. The script name is not hers to set.
	 *
	 * A request whose script cannot be read is not provably admin-ajax.php,
	 * so it keeps the refusal: this door fails closed like every other.
	 */
	private static function is_admin_ajax(): bool {
		$script = (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared as a basename, never used as a path.

		return 'admin-ajax.php' === basename( $script );
	}

	/**
	 * The user and application-password REST routes during a visit.
	 *
	 * @param mixed $result  Pre-dispatch result; returned untouched unless this request is refused.
	 * @param mixed $server  REST server, unused.
	 * @param mixed $request The request being dispatched.
	 */
	public function guard_rest( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		if ( ! self::locked_route( $route ) || ! $this->inside_visit() ) {
			return $result;
		}

		return new \WP_Error( 'pow_visit_locked', $this->locked_message(), [ 'status' => 403 ] );
	}

	/**
	 * Application passwords would outlive the visit that minted them and
	 * belong to the account, not the buyer who happened to be in it.
	 *
	 * @param mixed $available Native answer.
	 * @param mixed $user      User the question is about, unused: the visit is the request's, not the row's.
	 */
	public function deny_application_passwords( mixed $available, mixed $user = null ): bool {
		return (bool) $available && ! $this->inside_visit();
	}

	/**
	 * Only the routes the shared-login lockdown names, so a plugin or theme
	 * route a visit legitimately uses is never caught by accident.
	 */
	private static function locked_route( string $route ): bool {
		$route = '/' . ltrim( $route, '/' );

		foreach ( [ '/wp/v2/users', '/wp/v2/application-passwords' ] as $locked ) {
			if ( $route === $locked || str_starts_with( $route, $locked . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Second layer of the checkout block: even a crafted POST hard-fails.
	 *
	 * The blocks checkout never runs woocommerce_checkout_process — it
	 * posts to the Store API. Same policy, that route's own veto point.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When a visit tries to check out.
	 */
	public function block_store_api_checkout( $order = null ): void {
		if ( ! $this->checkout_blocked_for_visit( $order instanceof \WC_Order ? $order : null ) ) {
			return;
		}

		$message = $this->checkout_blocked_message();

		if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pow_requisition_only', esc_html( $message ), 403 );
		}

		wp_die( esc_html( $message ), '', [ 'response' => 403 ] ); // Unreachable on any Woo new enough to serve the Store API.
	}

	public function block_checkout_process(): void {
		if ( $this->checkout_blocked_for_visit() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $this->checkout_blocked_message(), 'error' );
		}
	}

	/**
	 * Two cases, two strings: a buyer inside a visit is pointed at the
	 * operator's configured and filtered return control, and anyone
	 * holding a punchout quote order outside its visit is told that order
	 * is not payable here.
	 */
	private function checkout_blocked_message(): string {
		if ( ! $this->inside_visit() ) {
			return __( 'This order belongs to a PunchOut catalog session and cannot be paid here.', 'punchout-woocommerce' );
		}

		$label = (string) apply_filters(
			'pow_return_button_label',
			$this->settings->button_label(
				'return_button_label',
				__( 'Punchout', 'punchout-woocommerce' )
			)
		);

		return sprintf(
			/* translators: %s: label of the punchout exit button */
			__( 'Checkout is not available in this catalog session. Please use "%s".', 'punchout-woocommerce' ),
			$label
		);
	}

	private function locked_message(): string {
		return __( 'This surface is not available in a PunchOut catalog session.', 'punchout-woocommerce' );
	}

	/**
	 * wp-login.php inside a punchout session: logout is allowed, anything
	 * else goes back to the landing page.
	 *
	 * Logout ends the one visit that asked for it and leaves a colleague
	 * signed in to the same account with her own visit and her own basket —
	 * but only because this guard ends it here, under the connection lock,
	 * before core's own unlocked teardown can run. See end_visit().
	 */
	public function guard_login_screen(): void {
		if ( ! $this->inside_visit() ) {
			return;
		}

		// Anything that is not a plain string (?action[]=logout) is not the
		// one action a visit is allowed, so it reads as 'login' and 302s.
		$requested = $_REQUEST['action'] ?? 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
		$action    = sanitize_key( is_string( $requested ) ? wp_unslash( $requested ) : 'login' );

		if ( 'logout' !== $action ) {
			wp_safe_redirect( $this->settings->landing_url(), 302 );
			exit;
		}

		// A logout wp-login.php will actually perform. Its own gate is
		// check_admin_referer( 'log-out' ), which runs after this hook, and
		// without a valid nonce core only offers its confirmation screen — a
		// visit that is not being ended must not be torn down, or any page
		// able to make this browser request a URL could empty a buyer's
		// basket. The nonce is minted against the request's own session
		// token, so it is this visit's and no colleague's.
		$nonce = $_REQUEST['_wpnonce'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line, and core verifies it again.

		if ( is_string( $nonce ) && wp_verify_nonce( wp_unslash( $nonce ), 'log-out' ) ) {
			$this->end_visit();
		}
	}

	/**
	 * Any other way out of a visit: WooCommerce's account logout, an admin-bar
	 * logout, wp_logout() called by something else.
	 *
	 * wp_logout() destroys the request's token before it fires this action and
	 * offers no earlier hook, so this cannot serialize that one write. What it
	 * does do is end the visit row and delete its basket, which nothing else
	 * does until the row's own expiry — and the open-visit cap is counted from
	 * those rows. It can only act on a visit this request already resolved:
	 * by now the cookie is cleared and the current user is 0, so there is
	 * nothing left to resolve from. The wp-login.php door above, which is the
	 * only logout a visit can actually reach, never depends on it.
	 */
	public function end_visit_on_logout(): void {
		$this->end_visit();
	}

	/**
	 * End this request's visit under the connection lock.
	 *
	 * `session_tokens` is ONE user-meta row that holds every concurrent
	 * visit's login token for the shared account, so every writer of it must
	 * be serialized on the connection lock or a colleague's token is lost:
	 * Http\StartEndpoint states the same invariant on the redeem side, and
	 * Store::expire_and_destroy() is the writer that obeys it here — it
	 * expires this row, destroys this login and deletes this visit's own
	 * basket row inside Registry::with_partner_lock().
	 *
	 * Core's wp_destroy_current_session() is the unlocked read-modify-write
	 * of that same row this exists to get in front of. It is skipped entirely
	 * when the request carries no token, because wp_get_session_token() reads
	 * the logged-in cookie and nothing else — so once the teardown above has
	 * confirmed this login is gone, forgetting that cookie costs this request
	 * nothing it still has and keeps core's write off a colleague's token. An
	 * unconfirmed teardown deliberately keeps the cookie: a logout must still
	 * reach core's best effort rather than leave a live token behind.
	 *
	 * A confirmed teardown is remembered, because wp_logout() fires its action
	 * after this has already run on the wp-login.php door and there is nothing
	 * left to do; an unconfirmed one is retried, in case the second attempt can
	 * reach the lock the first could not.
	 */
	private function end_visit(): bool {
		if ( $this->visit_ended ) {
			return true;
		}

		try {
			$visit = $this->plugin->current_session();
			$store = $this->plugin->sessions();

			if ( null === $visit || null === $store ) {
				return false;
			}

			if ( ! $store->expire_and_destroy( $visit, $this->registry ) ) {
				return false;
			}

			if ( defined( 'LOGGED_IN_COOKIE' ) ) {
				unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
			}

			$this->visit_ended = true;

			return true;
		} catch ( \Throwable $e ) {
			// A visit that could not be resolved or torn down is core's to
			// finish; nothing here may stop a logout.
			return false;
		}
	}

	/**
	 * Whether checkout must be refused for this request.
	 *
	 * Punchout is the only exit: a visit returns its basket to the
	 * purchasing system and never pays, so a live visit is refused
	 * unconditionally — no company setting, no per-buyer setting, nothing
	 * a request can carry makes it payable. Outside a visit this is an
	 * ordinary shopper (the bound customer account included) and the only
	 * refusal left is a punchout quote order, which belongs to the visit
	 * that created it and is not payable anywhere.
	 *
	 * There is deliberately no second session read at this boundary. The
	 * old one existed to re-prove a company's exit entitlement and the
	 * row's expiry before a payment; with punchout the only exit, neither
	 * can make a visit payable, and asking twice would only give the two
	 * answers a chance to disagree. The one resolver, memoised per request
	 * by Plugin::current_session(), caches nothing but a conclusive answer.
	 */
	public function checkout_blocked_for_visit( ?\WC_Order $order = null ): bool {
		try {
			if ( $this->inside_visit() ) {
				return true;
			}

			return null !== $order && '' !== $this->order_meta( $order, QuoteOrder::META_SESSION_ID ) . $this->order_meta( $order, QuoteOrder::META_PARTNER_ID );
		} catch ( \Throwable $e ) {
			// An order whose meta could not be read is not provably ordinary.
			return true;
		}
	}

	/**
	 * Whether the order the request is reaching for is this visit's own.
	 *
	 * The visit row id is the per-visit discriminator here: one setup
	 * request makes one row, one basket and one quote order, and
	 * QuoteOrder stamps that row's id on the order. customer_id cannot
	 * answer this — every visit of a connection shares one account.
	 */
	private function order_belongs_to_visit( ?\WC_Order $order, Session $visit ): bool {
		if ( null === $order ) {
			return false;
		}

		$stamped = $this->order_meta( $order, QuoteOrder::META_SESSION_ID );

		// An order with no punchout session on it was not made by this
		// visit, so it is not this visit's to read.
		return '' !== $stamped && (int) $stamped === $visit->id;
	}

	private function order_meta( \WC_Order $order, string $key ): string {
		return trim( (string) $order->get_meta( $key ) );
	}

	/**
	 * True when this request is inside a live visit — and also when that
	 * could not be established.
	 *
	 * A session store that cannot answer has not proved the request is an
	 * ordinary shopper's, and the resolver deliberately throws rather than
	 * reporting "no visit", so every door here fails closed.
	 */
	private function inside_visit(): bool {
		try {
			return null !== $this->plugin->current_session();
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/** Woo catches this exception in classic creation and both Store API submission paths, including zero totals. */
	public function enforce_order( $order ): void {
		if ( is_numeric( $order ) ) { $order = wc_get_order( (int) $order ); }
		if ( $this->checkout_blocked_for_visit( $order instanceof \WC_Order ? $order : null ) ) {
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pow_requisition_only', $this->checkout_blocked_message(), 403 );
			}
			throw new \Exception( $this->checkout_blocked_message() );
		}
	}

	/** This native hook is outside Woo's try/catch: terminate before any customer or gateway mutation. */
	public function enforce_pay_action( $order ): void {
		if ( $this->checkout_blocked_for_visit( $order instanceof \WC_Order ? $order : null ) ) {
			wp_die( esc_html( $this->checkout_blocked_message() ), '', [ 'response' => 403 ] );
		}
	}

	/** Store API payment integrations run after this early veto, without changing payment-complete callbacks. */
	public function enforce_payment_context( $context ): void { $this->enforce_order( $context->order ); }

	/**
	 * view-order is listed explicitly rather than left to the My Account
	 * redirect: the order fence must not depend on is_account_page()
	 * surviving a theme or a WooCommerce change.
	 */
	private function endpoint_order_id(): int {
		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			return 0;
		}

		global $wp;

		foreach ( [ 'order-pay', 'order-received', 'view-order' ] as $endpoint ) {
			if ( is_wc_endpoint_url( $endpoint ) ) {
				return absint( $wp->query_vars[ $endpoint ] ?? 0 );
			}
		}

		return 0;
	}

	private function redirect_to_landing(): void {
		wp_safe_redirect( $this->settings->landing_url(), 302 );
		exit;
	}
}
