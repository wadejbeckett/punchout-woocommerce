<?php
/**
 * /punchout/start/{token} — one-time auto-login. A GET or HEAD shows a page that posts itself back; only the POST
 * redeems (0.4.23).
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Http;

use POW\Support\Transport;

use POW\Audit\Log;
use POW\Cart\SessionKey;
use POW\Partners\Registry;
use POW\Sessions\Store;
use POW\Sessions\Tokens;
use POW\Settings;
use POW\Support\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * Redeems the one-time StartPage token and logs the buyer's browser in
 * (scope §3/§5).
 *
 * The token IS the credential: 256-bit random, single-use (atomic status
 * flip), short TTL, stored hashed. The login request NEVER renders
 * content — cookies and nonces are only valid on the next request, so the
 * handler always 302s (wp-implementation M8).
 *
 * Only a POST is a login request (0.4.23). Mail scanners fetch every link in a
 * message, and a GET used to redeem the token before the buyer ever saw it. A
 * GET or HEAD now answers with a small page — no lookup, no write, no audit
 * row — whose form posts back to the same address: by script at once, or on a
 * real click when the "Login link needs a click" setting is on. A token that is
 * not even well-formed is refused for every method before anything is read.
 *
 * That page sends its form once (a double click is one POST), runs only its own
 * inline script and style (named by hash in its Content-Security-Policy), cannot
 * be framed, and its button label is a setting. A repeat POST from the browser
 * that redeemed the link, still signed in to that visit, is sent to the landing
 * page again instead of the expired page; every other repeat stays refused.
 *
 * The login is the connection's own customer account, so a redeem creates
 * nothing and owns nothing on that account: it mints a visit. Every
 * PunchOutSetupRequest gets its own WordPress session token, its own auth
 * cookie and its own WooCommerce session key, and those three are the only
 * things that distinguish two employees shopping at the same moment. The
 * account's role and capabilities distinguish nothing, which is why no
 * authorisation question here is asked of the user.
 */
final class StartEndpoint {

	/** The setting holding the start page's button label (0.4.23); blank uses the translated default. */
	public const BUTTON_LABEL = 'start_link_button_label';

	/** Runs last on the resolved button label; a blank result keeps the default. */
	public const BUTTON_LABEL_FILTER = 'punchout_start_link_button_label';

	/** The page's only style, named by its hash in the page's policy. */
	private const STYLE = 'body{font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em}';

	public function __construct(
		private Store $sessions,
		private Registry $registry,
		private Settings $settings,
		private Log $audit,
		private ?AnonymousAudit $anonymous = null,
	) {}

	public function handle( string $token ): void {
		Transport::require_https();
		// Junk that does not even look like a token gets the same refusal as an expired one, whatever the method,
		// and never reaches the sessions table (scope §9.2).
		if ( ! Tokens::looks_valid( $token ) ) { $this->deny(); return; }
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( 'GET' === $method || 'HEAD' === $method ) { $this->interstitial( $token, 'HEAD' === $method ); return; }
		if ( 'POST' !== $method ) { $this->not_allowed(); return; }
		$this->redeem( $token );
	}

	/**
	 * The headers of the page a GET or HEAD gets (0.4.23): HTML, never cached, transformed, indexed, sniffed or
	 * framed, and no referrer, so the address carrying the token is not passed on to anything the page leads to.
	 * No proxy may rewrite the page (no-transform), because its policy names the inline script and style by hash:
	 * nothing else runs, and the form posts only back to this site.
	 *
	 * @return list<string>
	 */
	public static function interstitial_headers(): array {
		$security = array_map( static fn( string $header ): string => str_starts_with( $header, 'Cache-Control:' ) ? $header . ', no-transform' : $header, Router::security_headers() );
		return [ 'Content-Type: text/html; charset=utf-8', ...$security, 'Referrer-Policy: no-referrer', 'X-Frame-Options: DENY', 'Content-Security-Policy: ' . self::policy() ];
	}

	/** The page's policy: nothing but its own inline script and style, a form that posts to this site, no framing and no base URL. */
	public static function policy(): string {
		$hash   = static fn( string $source ): string => "'sha256-" . base64_encode( hash( 'sha256', $source, true ) ) . "'";
		$script = self::script();
		return "default-src 'none'; script-src " . ( '' !== $script ? $hash( $script ) : "'none'" ) . '; style-src ' . $hash( self::STYLE ) . "; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";
	}

	/**
	 * The page's own script (assets/js/start-link.js), inlined: it sends the form once, by itself when the form says
	 * data-pow-start="auto", otherwise on the buyer's press. '' when the file cannot be read; the button still works.
	 */
	public static function script(): string {
		$file   = dirname( __DIR__, 2 ) . '/assets/js/start-link.js';
		$source = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		return str_contains( strtolower( $source ), '</script' ) ? '' : $source;
	}

	/**
	 * The start page's button label: the saved setting when it is not blank, else "Open the catalog", then the
	 * punchout_start_link_button_label filter. A blank filter result keeps the default.
	 */
	public static function button_label( ?Settings $settings = null ): string {
		$default = __( 'Open the catalog', 'punchout-woocommerce' );
		$label   = Settings::resolve_label( null !== $settings ? $settings->get( self::BUTTON_LABEL, '' ) : '', $default );
		if ( function_exists( 'apply_filters' ) ) { $label = apply_filters( self::BUTTON_LABEL_FILTER, $label ); }
		return Settings::resolve_label( $label, $default );
	}

	/**
	 * GET or HEAD of the StartPage link: a theme-free page whose form posts back to this address. Nothing is looked
	 * up or written, so a scanner's fetch leaves the link as it was, and an expired link is only reported to the POST.
	 *
	 * The script submits the form while the page is still loading, so the browser replaces this page in its history
	 * with where the POST leads: Back from the landing page does not come here again.
	 */
	private function interstitial( string $token, bool $head ): void {
		defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );
		nocache_headers();
		status_header( 200 );
		foreach ( self::interstitial_headers() as $header ) { header( $header ); }
		if ( $head ) { return; }

		$click  = $this->settings->start_link_click();
		$action = Transport::supplier_url( home_url( '/punchout/start/' . $token ) );
		$copy   = $click
			? __( 'Press the button to open the catalog.', 'punchout-woocommerce' )
			: __( 'If the catalog has not opened after a few seconds, press the button.', 'punchout-woocommerce' );
		$script = self::script();

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>';
		echo esc_html__( 'Open the catalog', 'punchout-woocommerce' );
		echo '</title><style>' . self::STYLE . '</style></head><body>';
		echo '<form method="post" action="' . esc_url( $action ) . '" id="pow-start-form" data-pow-start="' . ( $click ? 'click' : 'auto' ) . '"><p>' . esc_html( $copy ) . '</p>';
		echo '<button type="submit">' . esc_html( self::button_label( $this->settings ) ) . '</button></form>';
		if ( '' !== $script ) { echo '<script>' . $script . '</script>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own file, named by hash in the policy.
		echo '</body></html>';
	}

	/** Any method other than GET, HEAD or POST: refused, nothing read. */
	private function not_allowed(): void {
		status_header( 405 );
		header( 'Allow: GET, HEAD, POST' );
	}

	/** The buyer's POST: redeem the token and sign this visit in, or refuse with the expired page. */
	private function redeem( string $token ): void {
		$session = null;
		$logged_in = false;
		$replay = null;
		try {
			$located = $this->sessions->find_by_token_hash( Tokens::hash( $token ) );
			if ( $located && $this->redeemed_by_this_browser( $located ) ) {
				$replay = $located;
			} elseif ( $located ) {
				$this->registry->with_partner_lock( $located->partner_id, function () use ( $token, $located, &$session, &$logged_in ) {
					$pending = $this->sessions->find_by_token_hash( Tokens::hash( $token ) );
					$partner = $this->registry->find( $located->partner_id );
					if ( ! $pending || $pending->partner_id !== $located->partner_id || $pending->status !== \POW\Sessions\Session::PENDING || ! $pending->expires || $pending->expires <= gmdate( 'Y-m-d H:i:s' ) || ! $partner || ! $partner->is_active() ) { return; }
					$user = get_userdata( $pending->user_id );
					if ( ! $this->is_bound_login( $user, $partner->owner_user_id, $pending->user_id ) ) { return; }
					$session = $this->sessions->redeem_token( Tokens::hash( $token ) );
					if ( ! $session ) { return; }
					try {
						$expiration = time() + $partner->session_ttl;
						$wp_token = wp_generate_password( 43, false, false );
						// The login and the basket commit together: bind_visit()
						// writes this visit's WP session token and its own
						// wc_session_key in one conditional UPDATE, so a crash can
						// never leave a live login with no basket to call its own,
						// and the failure path below tears down both.
						if ( ! $this->bind_visit( $session->id, $wp_token, gmdate( 'Y-m-d H:i:s', $expiration ) ) ) { throw new \RuntimeException( 'Login binding failed.' ); }
						$session = $this->sessions->find( $session->id );
						$info = apply_filters( 'attach_session_information', [], $user->ID );
						$info['expiration'] = $expiration;
						$info['login'] = time();
						if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) { $info['ip'] = $_SERVER['REMOTE_ADDR']; }
						if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) { $info['ua'] = wp_unslash( $_SERVER['HTTP_USER_AGENT'] ); }
						// The eviction and the update below are a read-modify-write
						// of ONE shared usermeta row: session_tokens holds every
						// concurrent visit's token for this account, so a lost
						// update logs a colleague out. It is safe only because the
						// whole block runs inside with_partner_lock() above, and
						// every other writer of that row must stay under the same
						// lock (ReturnEndpoint's winner transition,
						// Store::expire_locked's callers, Plugin's settings hook and
						// the consent fence). The eviction also invalidates the
						// account's meta cache for the rest of this request, which is
						// what makes login_valid_checked() a fresh read.
						wp_cache_delete( $user->ID, 'user_meta' );
						\WP_Session_Tokens::get_instance( $user->ID )->update( $wp_token, $info );
						if ( ! $this->sessions->login_valid_checked( $session ) ) { throw new \RuntimeException( 'Login persistence failed.' ); }
						// One request's cookie lifetime, not the site's: the filter
						// is installed for this call alone and removed in the
						// finally, because the account is a real customer whose
						// ordinary logins must not inherit a punchout TTL.
						$ttl_filter = static fn(): int => $partner->session_ttl;
						add_filter( 'auth_cookie_expiration', $ttl_filter, 999 );
						try {
							// From here the request IS the customer account.
							// Everything that used to be out of reach because the
							// punchout role was capability-poor — wp-admin, the users
							// REST routes, application passwords, account details —
							// is now reachable and must be refused by the
							// request-scoped guards instead.
							wp_set_current_user( $user->ID );
							// The token is handed to wp_set_auth_cookie() so WP
							// reuses the entry written above instead of writing the
							// shared session_tokens row a second time.
							wp_set_auth_cookie( $user->ID, false, '', $wp_token );
							$logged_in = true;
						} finally { remove_filter( 'auth_cookie_expiration', $ttl_filter, 999 ); }
					} catch ( \Throwable $e ) {
						if ( ! $this->sessions->expire_locked( $session ) ) {
							$this->registry->transition_status( $partner->id, \POW\Partners\Partner::STATUS_ACTIVE, [ 'status' => \POW\Partners\Partner::STATUS_DISABLED ] );
						}
						wp_clear_auth_cookie();
						wp_set_current_user( 0 );
					}
				} );
			}
		} catch ( \Throwable $e ) { /* No unconfirmed token is exposed; recorded references support recovery. */ }
		if ( null !== $replay ) {
			// Already this browser's visit: nothing is redeemed, written or logged again.
			$this->land( $replay );
			return;
		}
		try {
			// A refused token is an unauthenticated request: its row counts against the per-IP budget (0.4.22). A
			// redeemed login is always recorded.
			$ip = $this->client_ip();
			if ( $logged_in || null === $this->anonymous || $this->anonymous->allow( $ip ) ) {
				$this->audit->write_checked( $logged_in ? 'token_redeem' : 'token_reject', [ 'partner_id' => $session?->partner_id ?? 0, 'session_id' => $session?->id ?? 0, 'user_id' => $session?->user_id ?? 0, 'result' => $logged_in ? 'ok' : '403', 'ip' => $ip ] );
			}
		} catch ( \Throwable $e ) { /* Diagnostics cannot undo confirmed login. */ }
		finally { $this->anonymous?->done(); }
		if ( ! $logged_in || ! $session ) { $this->deny(); return; }

		$this->land( $session );
	}

	/**
	 * A repeat POST of a link this same browser redeemed (0.4.23): the visit is active and unexpired, and the
	 * request is signed in as its account with the visit's own login token, which only the redeem's cookie carries
	 * (the HandoffReplay idea). Another browser, a colleague's visit on the same account, a visit that has returned
	 * or expired, or a token that was never redeemed are all refused as before.
	 */
	private function redeemed_by_this_browser( \POW\Sessions\Session $visit ): bool {
		if ( \POW\Sessions\Session::ACTIVE !== $visit->status || '' === $visit->wp_session_token || ! $visit->expires || $visit->expires <= gmdate( 'Y-m-d H:i:s' ) ) { return false; }
		$user_id = (int) get_current_user_id();
		$login   = (string) wp_get_session_token();
		if ( $user_id <= 0 || $user_id !== $visit->user_id || '' === $login || ! hash_equals( $visit->wp_session_token, $login ) ) { return false; }
		return $this->sessions->login_valid_checked( $visit );
	}

	/** Send the visit's browser to where it shops: the SelectedItem deep link or the landing page, after the filter. */
	private function land( \POW\Sessions\Session $session ): void {
		$target = $this->redirect_target( $session->selected_item );

		/**
		 * Filter the post-login redirect target for a punchout session.
		 *
		 * A presentation filter, not site glue: it chooses where the buyer
		 * lands and grants no authority. It does hand out the visit row, which
		 * now carries the buyer's identity, so code reading it sees what the
		 * purchasing system supplied.
		 *
		 * @param string                $target     Destination URL.
		 * @param \POW\Sessions\Session $session    The activated session.
		 */
		$target = (string) apply_filters( 'pow_start_redirect', $target, $session );

		wp_safe_redirect( Transport::supplier_url( $target ), 302 );
	}

	/**
	 * Plain 403 page, deliberately detail-free, with the buyer-facing
	 * guidance copy (scope §9.2).
	 */
	public function deny(): void {
		status_header( 403 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$message = __(
			'This catalog link has expired. Please return to your purchasing system and open the catalog again.',
			'punchout-woocommerce'
		);

		/**
		 * Filter the expired/invalid StartPage token message.
		 *
		 * Buyer-facing copy, nothing else: this page confirms no token and
		 * exposes no session.
		 *
		 * @param string $message Buyer-facing copy.
		 */
		$message = (string) apply_filters( 'pow_expired_token_message', $message );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>';
		echo esc_html__( 'Link expired', 'punchout-woocommerce' );
		echo '</title></head><body style="font-family:sans-serif;max-width:36em;margin:4em auto;padding:0 1em"><p>';
		echo esc_html( $message );
		echo '</p></body></html>';
	}

	/**
	 * Re-prove at redemption that this claim's login is still the
	 * connection's bound customer account.
	 *
	 * The authority is the connection row — `partners.owner_user_id` — and
	 * nothing on the user, because the account is an ordinary customer of the
	 * shop: it carries no punchout role and no plugin user meta, so a role or
	 * meta test here would refuse every visit. Three things are still asked
	 * of the account itself: that it exists, that it can `read` (a disabled
	 * or capability-stripped account cannot shop), and that it holds none of
	 * the privileged capabilities a buyer must never inherit.
	 *
	 * `_pow_partner_id` is the one user meta that must be ABSENT. An account
	 * carrying it is one the registry would never have accepted as an owner
	 * and the company book does not treat as an ordinary user, so a claim
	 * pointing at such an account is not a binding this endpoint can prove.
	 *
	 * @param object|false $user            The account the claim names, as WordPress holds it.
	 * @param int          $owner_user_id   The connection's bound account.
	 * @param int          $claim_user_id   The account recorded on the claim row.
	 */
	private function is_bound_login( mixed $user, int $owner_user_id, int $claim_user_id ): bool {
		if ( $owner_user_id <= 0 || $claim_user_id !== $owner_user_id || ! $user || ! user_can( $user, 'read' ) ) { return false; }

		// Registry owns the list: it is what the bind screen refuses on, so a
		// visit and a binding cannot disagree about which accounts are safe.
		if ( Registry::privileged( $user ) ) { return false; }

		return ! get_user_meta( (int) $user->ID, '_pow_partner_id', true );
	}

	/**
	 * Bind this visit's login and mint its basket key, retrying once.
	 *
	 * `bind_login()` answers false for a refused binding and for a UNIQUE
	 * collision on `wc_session_key` alike, and the collision is the one of
	 * the two a second attempt can win: the key is 112 bits of randomness, so
	 * a retry is a fresh draw rather than the same claim again. A row that is
	 * not active, or whose login or key is already bound, refuses both
	 * attempts — the retry cannot turn a refusal into a binding.
	 */
	private function bind_visit( int $session_id, string $wp_token, string $expires ): bool {
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			if ( $this->sessions->bind_login( $session_id, $wp_token, $expires, SessionKey::mint() ) ) { return true; }
		}

		return false;
	}

	/**
	 * SelectedItem deep-link when the setup carried one (scope §3),
	 * otherwise the configured landing page.
	 */
	private function redirect_target( ?string $selected_item_json ): string {
		if ( null !== $selected_item_json && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$selected = json_decode( $selected_item_json, true );

			if ( is_array( $selected ) ) {
				$product_id = 0;

				// Our own SupplierPartAuxiliaryID scheme is
				// "{product_id}|{variation_id}" — exact when present.
				if ( ! empty( $selected['aux_id'] ) && preg_match( '/^(\d+)\|/', (string) $selected['aux_id'], $m ) ) {
					$product_id = (int) $m[1];
				}

				if ( 0 === $product_id && ! empty( $selected['supplier_part_id'] ) ) {
					$product_id = (int) wc_get_product_id_by_sku( (string) $selected['supplier_part_id'] );
				}

				if ( $product_id > 0 ) {
					$permalink = get_permalink( $product_id );

					if ( is_string( $permalink ) && '' !== $permalink ) {
						return $permalink;
					}
				}
			}
		}

		return $this->settings->landing_url();
	}

	private function client_ip(): string {
		return Ip::client();
	}
}
