<?php
/**
 * Punchout session route scoping.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

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
 */
final class RouteGuard {

	/**
	 * @param Plugin   $plugin   Container, asked for the request's visit.
	 * @param Registry $registry Connection registry. Nothing here reads it
	 *                           any longer — the visit row is the whole
	 *                           proof — but it stays in the signature so
	 *                           the container and the cart surface keep
	 *                           constructing the guard the same way.
	 * @param Settings $settings Operator settings: landing page and labels.
	 */
	public function __construct(
		private Plugin $plugin,
		private Registry $registry,
		private Settings $settings,
	) {}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'guard' ], 1 );

		// template_redirect runs for front-end page requests only. wp-admin,
		// admin-ajax.php and the REST API never reach it, so the shared
		// login's three remaining doors need their own guards.
		add_action( 'admin_init', [ $this, 'guard_admin' ], 1 );
		add_filter( 'rest_pre_dispatch', [ $this, 'guard_rest' ], -99, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'deny_application_passwords' ], PHP_INT_MAX, 2 );

		add_action( 'woocommerce_checkout_process', [ $this, 'block_checkout_process' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'block_store_api_checkout' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'enforce_order' ], PHP_INT_MAX );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'enforce_payment_context' ], -9999 );
		add_action( 'woocommerce_before_pay_action', [ $this, 'enforce_pay_action' ], -9999 );
		add_action( 'login_init', [ $this, 'guard_login_screen' ] );
	}

	public function guard(): void {
		$visit = $this->plugin->current_session();

		// Account surfaces (order history, addresses, downloads, account
		// details, password changes, the integration tab) are outside a
		// visit's remit. This fires on template_redirect only, so it never
		// sees admin-ajax.php, the REST API or wp-admin — guard_admin() and
		// guard_rest() hold those. The bound account outside a visit keeps
		// its whole account area.
		if ( null !== $visit && function_exists( 'is_account_page' ) && is_account_page() ) {
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
	 * wp-admin during a visit: the shared login is an ordinary customer
	 * account, so WordPress would happily serve it the profile screen.
	 *
	 * admin_init also fires for admin-ajax.php, which a front-end cart can
	 * legitimately call inside a visit; redirecting that would break the
	 * basket, so AJAX is left to the guards that do apply to it.
	 */
	public function guard_admin(): void {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		if ( ! $this->inside_visit() ) {
			return;
		}

		$this->redirect_to_landing();
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
	 * Logout destroys this request's WP session token only, so it ends the
	 * one visit that asked for it and leaves a colleague signed in to the
	 * same account with her own visit and her own basket.
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
