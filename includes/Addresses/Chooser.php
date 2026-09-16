<?php
/** Checkout-like delivery review, shared by cart placements and the optional shortcode. @package POW @license AGPL-3.0-or-later */
declare( strict_types = 1 );
namespace POW\Addresses;

use POW\Support\Transport;

use POW\Http\ReturnEndpoint;
use POW\Partners\Registry;
use POW\Plugin;
use POW\Sessions\{Session, Store};
use POW\Sessions\ConsentFence;
use POW\Support\Templates;
use POW\Cart\NativeSessionGuard;

defined( 'ABSPATH' ) || exit;

final class Chooser {
	public function __construct( private Plugin $plugin, private Registry $registry, private Store $sessions, private Confirmation $confirmation, private ReturnEndpoint $return_endpoint, private ?NativeSessionGuard $native = null ) {}

	public function register(): void {
		add_shortcode( 'punchout_delivery_confirmation', [ $this, 'markup' ] );
		// cart_updated also fires when an unchanged cart is loaded or recalculated; use actual mutations instead.
		foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_cart_emptied', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_shipping_method_chosen', 'woocommerce_customer_save_address', 'woocommerce_calculated_shipping', 'woocommerce_checkout_update_order_review' ] as $hook ) { add_action( $hook, [ $this, 'invalidate_changed_cart' ], 99, 0 ); }
		foreach ( [ 'wc_ajax_update_shipping_method', 'wp_ajax_woocommerce_update_shipping_method' ] as $hook ) { add_action( $hook, [ $this, 'invalidate_shipping_ajax' ], 0, 0 ); }
		add_filter( 'rest_request_after_callbacks', [ $this, 'invalidate_store_api' ], 99, 3 );
	}

	public static function request_allowed( string $method, array $post ): bool {
		return 'POST' === strtoupper( $method ) && isset( $post['pow_nonce'] ) && is_string( $post['pow_nonce'] ) && false !== wp_verify_nonce( wp_unslash( $post['pow_nonce'] ), 'pow_confirm_delivery' );
	}

	/** An identifier selects only a complete server-produced option; collisions refuse. */
	public function choose( array $choices, string $identifier ): ?array {
		$found = null;
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) || ! isset( $choice['provider'], $choice['key'] ) || ! is_string( $choice['provider'] ) || ! is_string( $choice['key'] ) ) { continue; }
			if ( $choice['provider'] . ':' . $choice['key'] !== $identifier ) { continue; }
			if ( null !== $found ) { return null; }
			$found = $choice;
		}
		return $found;
	}

	/** The shortcode renders the same review; its forms always post to the dedicated no-store route. */
	public function markup(): string {
		if ( ! Transport::request_allowed() ) { return Transport::notice(); }
		self::headers();
		try { [ $session, $partner ] = $this->context(); return $this->render( $this->confirmation->prepare( $session, $partner ), false ); }
		catch ( \Throwable $error ) { return $this->render( [ 'error' => self::expired() ], false ); }
	}

	public function handle(): void {
		Transport::require_https();
		self::headers();
		try {
			[ $session, $partner ] = $this->context();
			$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
			if ( 'GET' === $method ) { echo $this->render( $this->confirmation->prepare( $session, $partner ), true ); return; }
			if ( ! self::request_allowed( $method, $_POST ) ) { status_header( 403 ); echo $this->render( [ 'error' => new \WP_Error( 'delivery_nonce', __( 'This form has expired. Open the cart and review your delivery again.', 'punchout-woocommerce' ) ) ], true ); return; }
			$action = $_POST['pow_delivery_action'] ?? 'review';
			if ( ! is_string( $action ) || ! in_array( $action, [ 'review', 'submit', 'back' ], true ) ) { throw new \DomainException(); }
			if ( 'back' === $action ) {
				if ( ! $this->invalidate( $session ) ) { throw new \DomainException(); }
				wp_safe_redirect( wc_get_cart_url(), 303 ); return;
			}
			$input = [];
			foreach ( [ 'rates', 'notes', 'review_digest', 'acknowledge_unknown' ] as $field ) { if ( array_key_exists( $field, $_POST ) ) { $input[$field] = wp_unslash( $_POST[$field] ); } }
			if ( isset( $_POST['choice'] ) ) {
				if ( ! is_string( $_POST['choice'] ) ) { throw new \DomainException(); }
				$parts = explode( ':', wp_unslash( $_POST['choice'] ), 2 );
				if ( count( $parts ) !== 2 ) { throw new \DomainException(); }
				[ $input['provider'], $input['key'] ] = $parts;
			}
			if ( 'submit' === $action ) {
				$return_nonce = $_POST['pow_return_nonce'] ?? null;
				if ( ! is_string( $return_nonce ) || ! wp_verify_nonce( wp_unslash( $return_nonce ), 'pow_return' ) ) { throw new \DomainException(); }
				$view = $this->confirmation->confirm( $session, $partner, $input );
				if ( ! $view instanceof \WP_Error ) {
					// Preserve the existing single winner and handoff. This request carries independent nonces for consent and return.
					$_POST['pow_nonce'] = $return_nonce; $_POST['pow_mode'] = 'cart';
					$this->return_endpoint->handle(); return;
				}
			} else {
				$view = $this->confirmation->preview( $session, $partner, $input );
				if ( $view instanceof \WP_Error && 'delivery_rate_invalid' === $view->get_error_code() ) {
					// An address change submits the previous address's checked method too. Review the new native offers/default before asking for consent; final submit keeps strict rate validation.
					$refreshed = $input;
					unset( $refreshed['rates'] );
					$view = $this->confirmation->preview( $session, $partner, $refreshed );
				}
			}
			if ( $view instanceof \WP_Error ) {
				$error = $view; $view = $this->confirmation->prepare( $session, $partner ); $view['error'] = $error;
				// Only valid bounded plain notes survive a refused form; no document/cents/address POST is reflected.
				if ( isset( $input['notes'] ) && is_string( $input['notes'] ) && strlen( $input['notes'] ) <= 8000 && 1 === preg_match( '//u', $input['notes'] ) ) { $view['notes'] = sanitize_textarea_field( $input['notes'] ); }
				$view['can_confirm'] = false;
			}
			echo $this->render( $view, true );
		} catch ( \Throwable $error ) { status_header( 403 ); echo $this->render( [ 'error' => self::expired() ], true ); }
	}

	/** Native mutations invalidate consent even when a theme never renders our cart button. */
	public function invalidate_changed_cart(): void {
		if ( $this->confirmation->is_refreshing() ) { return; }
		$this->native?->mark_changed();
		try { [ $session ] = $this->context(); $this->invalidate( $session ); }
		catch ( \Throwable $error ) { /* No eligible current session means there is no buyer consent to mutate. */ }
	}

	public function invalidate_shipping_ajax(): void {
		$nonce = $_REQUEST['security'] ?? null;
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && is_string( $nonce ) && wp_verify_nonce( wp_unslash( $nonce ), 'update-shipping-method' ) ) { $this->invalidate_changed_cart(); }
	}

	public static function is_store_cart_mutation( string $method, string $route ): bool {
		return in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) && 1 === preg_match( '#\A/wc/store/v[1-9][0-9]*/cart/(?:select-shipping-rate|update-customer)/?\z#', $route );
	}

	/** These Store API setters do not emit a classic cart mutation action. Preserve their native response. */
	public function invalidate_store_api( mixed $response, mixed $handler, \WP_REST_Request $request ): mixed {
		if ( self::is_store_cart_mutation( $request->get_method(), $request->get_route() ) ) { $this->invalidate_changed_cart(); }
		return $response;
	}

	private function invalidate( Session $session ): bool {
		$native = $this->native?->prepare( $session );
		return $this->registry->with_partner_lock( $session->partner_id, function () use ( $session, $native ) {
			$fresh = $this->sessions->find( $session->id );
			if ( ! $fresh || Session::ACTIVE !== $fresh->status || $fresh->user_id !== get_current_user_id() || $fresh->partner_id !== $session->partner_id || ! hash_equals( $fresh->wp_session_token, wp_get_session_token() ) ) { return false; }
			if ( null !== $native && ! $this->native->check_locked( $fresh, $native ) ) { return false; }
			return ( new ConsentFence( $this->sessions, $this->registry ) )->clear_locked( $fresh );
		} );
	}

	private function context(): array {
		if ( ! $this->plugin->enabled() || ! is_user_logged_in() ) { throw new \DomainException(); }
		$session = $this->sessions->find_for_login( get_current_user_id(), wp_get_session_token(), [ Session::ACTIVE ] );
		$partner = $session ? $this->registry->find( $session->partner_id ) : null;
		if ( ! $session || ! $partner || ! $partner->is_active() ) { throw new \DomainException(); }
		return [ $session, $partner ];
	}

	private function render( array $view, bool $document ): string {
		return Templates::render( 'delivery-confirmation', [ 'view' => $view, 'action_url' => Transport::supplier_url( home_url( '/punchout/confirm' ) ), 'cart_url' => wc_get_cart_url(), 'nonce' => wp_create_nonce( 'pow_confirm_delivery' ), 'return_nonce' => wp_create_nonce( 'pow_return' ), 'stylesheet_url' => plugins_url( 'assets/css/delivery-confirmation.css', POW_PLUGIN_FILE ), 'shop_name' => get_bloginfo( 'name' ), 'document' => $document ] );
	}
	private static function expired(): \WP_Error { return new \WP_Error( 'delivery_unavailable', __( 'Delivery could not be verified. Return to your purchasing system and open the catalog again if your session has expired.', 'punchout-woocommerce' ) ); }
	private static function headers(): void { if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } nocache_headers(); if ( ! headers_sent() ) { header( 'Cache-Control: private, no-store' ); header( 'X-Robots-Tag: noindex, nofollow' ); } }
}
