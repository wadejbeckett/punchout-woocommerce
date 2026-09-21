<?php
/**
 * Plugin container and bootstrap.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW;

use POW\Admin\Actions as AdminActions;
use POW\Admin\Details as AdminDetails;
use POW\Admin\Page as AdminPage;
use POW\Audit\Log;
use POW\Cart\Guard;
use POW\Cart\PoomMapper;
use POW\Cart\Surface;
use POW\CLI\Command;
use POW\Cxml\Builder;
use POW\Cxml\Parser;
use POW\Http\RateLimiter;
use POW\Http\ReturnEndpoint;
use POW\Http\Router;
use POW\Http\SetupEndpoint;
use POW\Http\StartEndpoint;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\Session;
use POW\Sessions\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 *
 * Intentionally a thin service locator rather than a DI container: the
 * object graph is small and shallow, and a container library would mean a
 * Composer dependency the deploy target cannot guarantee.
 *
 * The master switch (Settings 'enabled') gates every buyer-facing runtime
 * surface — endpoints, cart surfaces, guards. Admin, schema upgrades and
 * housekeeping stay registered so the site cleans up after itself even
 * while the feature is off.
 */
final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	private ?Settings $settings = null;

	private ?Logger $logger = null;

	private ?Registry $registry = null;

	private ?Store $sessions = null;

	private ?Log $audit = null;


	private ?Surface $surface = null;

	private ?Session $current_session = null;

	private bool $session_resolved = false;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'punchout-woocommerce', false, dirname( plugin_basename( POW_PLUGIN_FILE ) ) . '/languages' );

		$this->settings = new Settings();
		$this->logger   = new Logger( $this->settings );

		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', [ $this, 'render_missing_woocommerce_notice' ] );
			return;
		}

		$secrets        = new Secrets( $this->sealing_key() );
		// Refuse insecure buyer traffic before Woo initializes carts, even with new sessions switched off.
		\POW\Support\Transport::register();
		$this->registry = new Registry( $secrets );
		$this->sessions = new Store();
		// Select the guarded native cookie handler before Woo hydrates any buyer cart, including Store API requests.
		$native_sessions = new Cart\NativeSessionGuard( $this->registry, $this->sessions, $this->logger );
		$native_sessions->register();
		$this->audit    = new Log( $this->logger );
		$registration  = new \POW\Partners\Registration( $this->registry, $this->sessions, $this->audit );

		$parser      = new Parser();
		$builder     = new Builder();
		$mapper      = new PoomMapper( $this->settings, $this->logger );
		// One resolver for "is this request inside a visit", shared by the
		// book's editor gate and the address resolver: with a single bound
		// login, that question is the only thing that still separates the
		// customer managing their delivery book from an employee shopping.
		$visits = new Sessions\Current( $this->sessions );
		$address_book = new Addresses\CompanyBook( $this->registry, $this->audit, $visits );
		$address_resolver = new Addresses\Resolver( $this->registry, $address_book, $visits );
		$address_fields = new Addresses\Fields( $this->registry, $address_book, new Addresses\NativeImport( $this->registry, $address_book ) );

		// Built before the master-switch gate because housekeeping needs it:
		// the retention sweep that cancels unconverted quotes runs in the
		// hourly job, which is registered whatever the switch says.
		$quotes = new \POW\Orders\QuoteOrder( $this->sessions, $this->audit, $this->settings, $this->logger );
		// Historical quotes still need exact compatibility copies when new Punchout sessions are disabled.
		( new \POW\Orders\QuoteCompatibility( $this->audit, $this->logger ) )->register();

		// Admin, schema upgrade, CLI and housekeeping run regardless of the
		// master switch.
		// One editor instance retains same-request validation feedback across
		// the admin screen's POST handling and its rendering. It registers the
		// same way whatever the switch says: the delivery book is an
		// administrator surface with no front-end route to gate.
		$address_fields->register();
		$admin_page = new AdminPage( $this->settings, $this->registry, $this->audit, $address_fields );
		$admin_page->register();
		( new AdminActions( $this->registry, $this->audit, $registration ) )->register();
		( new \POW\Account\IntegrationTab( $this, $this->registry, $this->audit ) )->register();
		( new AdminDetails() )->register();
		( new Cron( $this->sessions, $this->audit, $this->settings, $quotes ) )->register();

		// Outside the master-switch gate: a shop that switches punchout off
		// must still render and list the Punchout Quote orders it already
		// has, or they drop out of the orders screen entirely — and must
		// still be able to convert them, which is the same argument.
		( new \POW\Orders\Status() )->register();

		if ( is_admin() ) {
			$quotes->register_admin();
		}

		// The [punchout_docs] page is registered before the master-switch
		// gate on purpose: a buyer reads the integration documentation
		// precisely while punchout is still switched off, and the page
		// exposes no session surface. Its self-test is the one gated part
		// — with punchout off it says so instead of running.
		( new Docs\Page( $this->settings, $this->registry, new RateLimiter( Docs\Page::self_test_limit( $this->settings->int( 'rate_limit_per_min' ) ) ), $this->audit ) )->register();

		add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );
		add_action( 'admin_notices', [ $this, 'render_key_notice' ] );
		// Unlike the sealing-key notice, this one is not scoped to the
		// plugin's own screens: a connection with no usable store account
		// refuses every setup request, so the operator must meet it wherever
		// they are in wp-admin rather than only where they went looking.
		add_action( 'admin_notices', [ $admin_page, 'render_unbound_notice' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Command::register( $this );
		}

		// Always-on teardown — deliberately registered BEFORE the master
		// switch gate so it holds even while punchout is disabled: flipping
		// the switch off must end live visits, not strand them logged in
		// with every guard unhooked.
		//
		// Nothing here touches the password door. A connection's buyers
		// shop as an ordinary WooCommerce customer account, and that same
		// account signs in with its own password to read its My Account
		// screens, so it must be able to authenticate and to reset its
		// password like any other customer. What a visit may reach is
		// decided per request by the visit row, not by the account.
		add_action( 'update_option_' . Settings::OPTION_KEY, [ $this, 'on_settings_updated' ], 10, 2 );

		( new RouteGuard( $this, $this->registry, $this->settings ) )->register();
		// Basket isolation has the same lifetime as visit resolution, and for
		// the same reason. NativeSessionGuard above keeps resolving visits and
		// keeps binding each one its own `pow_` session row whatever the switch
		// says; Cart\Guard is what turns WooCommerce's persistent cart off for
		// a visit and what hides and keeps the saved-cart merge flag. A visit
		// that outlived on_settings_updated()'s sweep — one contended
		// connection lock is enough — would otherwise run with that one shared
		// user-meta basket fully live: every cart mutation would overwrite the
		// bound account's own saved basket, and an emptied visit would merge a
		// colleague's lines into its own on the next hydration. The resolver
		// and the filters that consume it are registered together or not at all.
		( new Guard( $this, $this->sessions, $this->audit, $this->logger ) )->register();
		$this->surface = new Surface( $this, $this->registry );
		$this->surface->register_cart_exits_shortcode();

		if ( ! $this->enabled() ) {
			return;
		}

		// Buyer-facing runtime.
		$rate_limiter    = new RateLimiter( RateLimiter::public_limit( $this->settings->int( 'rate_limit_per_min' ), 30 ) );
		$edge_limiter    = new RateLimiter( RateLimiter::public_limit( $this->settings->int( 'edge_rate_limit_per_min' ), 120 ) );
		$setup_endpoint  = new SetupEndpoint( $this->registry, $this->sessions, $parser, $builder, $rate_limiter, $this->audit, $edge_limiter );
		$start_endpoint  = new StartEndpoint( $this->sessions, $this->registry, $this->settings, $this->audit );
		$confirmation = new Addresses\Confirmation( $this->registry, $this->sessions, new Addresses\QuoteAddress( $address_resolver ), new Addresses\DeliveryEstimate( $this->settings ), $address_resolver, $mapper, $native_sessions );
		$return_endpoint = new ReturnEndpoint( $this->sessions, $this->registry, $mapper, $builder, $this->audit, $quotes, $confirmation );
		$chooser = new Addresses\Chooser( $this, $this->registry, $this->sessions, $confirmation, $return_endpoint, $native_sessions );

		( new Router( $setup_endpoint, $start_endpoint, $return_endpoint, $chooser ) )->register();
		$chooser->register();
		$return_endpoint->register();

		$this->surface->register_runtime();
	}

	/**
	 * The punchout visit this request is inside, or null.
	 *
	 * Delegates to Sessions\Current — the one resolver — and memoises its
	 * answer for the request. Kept as a method on the container because
	 * every guard and surface already asks the question here.
	 *
	 * The memo is three-state on purpose: "no visit" is cached only once a
	 * lookup has actually returned it. The first caller can be WooCommerce
	 * building its session handler at 'init' priority 0, long before any
	 * caller of today; a resolution that could not run (no store yet, a
	 * failed query) must be retried by the next caller, or one early miss
	 * would silently disarm cart isolation, the route guard and the
	 * checkout block for the whole request.
	 *
	 * Deliberately not gated on the master switch. The guards that ask
	 * this question are registered before the switch is consulted, so an
	 * already-authenticated visit must keep resolving after the switch
	 * flips — otherwise it would get an unguarded cart. Ending live visits
	 * is on_settings_updated()'s job, not this resolver's.
	 */
	public function current_session(): ?Session {
		if ( $this->session_resolved ) {
			return $this->current_session;
		}

		if ( null === $this->sessions ) {
			return null;
		}

		// Order is load-bearing: the memo closes only after a lookup
		// returned an answer, so a throw leaves the request unresolved
		// rather than remembered as "no visit".
		$this->current_session  = ( new Sessions\Current( $this->sessions ) )->visit();
		$this->session_resolved = true;

		return $this->current_session;
	}

	/**
	 * The RFQ exit button markup for the current session ('' outside one).
	 */
	public function return_button_markup(): string {
		return null !== $this->surface ? $this->surface->markup() : '';
	}

	/**
	 * The "return without a cart" markup for the current session ('' outside one).
	 */
	public function abandon_button_markup(): string {
		return null !== $this->surface ? $this->surface->abandon_markup() : '';
	}

	public function enabled(): bool {
		return 'yes' === (string) $this->settings()->get( 'enabled', 'no' );
	}

	public function settings(): Settings {
		return $this->settings ??= new Settings();
	}

	public function logger(): Logger {
		return $this->logger ??= new Logger( $this->settings() );
	}

	public function registry(): ?Registry {
		return $this->registry;
	}

	public function sessions(): ?Store {
		return $this->sessions;
	}

	public function audit(): ?Log {
		return $this->audit;
	}

	public function woocommerce_active(): bool {
		return class_exists( \WooCommerce::class ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Master switch turned off: expire every open session and destroy its
	 * recorded login, so "disabled" means disabled immediately rather than
	 * at each session's TTL.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public function on_settings_updated( $old, $new ): void {
		$was = is_array( $old ) && 'yes' === ( $old['enabled'] ?? '' );
		$now = is_array( $new ) && 'yes' === ( $new['enabled'] ?? '' );

		if ( ! $was || $now || null === $this->sessions ) {
			return;
		}

		$ok = null !== $this->registry;
		try {
			foreach ( $this->registry?->all() ?? [] as $partner ) {
				// One try per connection, because the lock is per connection: a
				// setup request already holding X's lock makes
				// with_partner_lock() throw for X, and a single try around the
				// whole loop would let that throw abandon every connection
				// after it — leaving those visits active and logged in for the
				// rest of their TTL, none of them swept and none of them even
				// counted. The incomplete sweep is still recorded below.
				try {
					$clean = $this->registry->with_partner_lock( $partner->id, function () use ( $partner ) {
						$after = 0;
						$clean = true;
						while ( $rows = $this->sessions->revocation_batch( $partner->id, $after ) ) {
							foreach ( $rows as $row ) {
								if ( $row->id <= $after ) { return false; }
								$after = $row->id;
								$clean = $this->sessions->expire_locked( $row ) && $clean;
							}
						}
						return $clean;
					} );
				} catch ( \Throwable $e ) { $clean = false; }
				$ok = $clean && $ok;
			}
			$ok = [] === $this->sessions->all_open( 1 ) && $ok;
		} catch ( \Throwable $e ) { $ok = false; }
		$this->audit?->write_checked( 'master_disabled', [ 'result' => $ok ? 'sessions_swept' : 'cleanup_incomplete' ] );
	}

	/**
	 * Whether POW_SECRET_KEY provides the sealing key (recommended) or the
	 * plugin is running on the salt-derived fallback.
	 */
	public function key_from_constant(): bool {
		return defined( 'POW_SECRET_KEY' ) && null !== Secrets::decode_key( (string) POW_SECRET_KEY );
	}

	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'PunchOut for WooCommerce requires WooCommerce to be installed and active.', 'punchout-woocommerce' )
		);
	}

	/**
	 * Recommend the wp-config sealing key when running on the fallback.
	 */
	public function render_key_notice(): void {
		if ( $this->key_from_constant() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || false === strpos( (string) $screen->id, 'punchout' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <code>wp punchout generate-key</code></p></div>',
			esc_html__( 'Partner secrets are currently sealed with a key derived from this site\'s auth salt. For stronger isolation, define POW_SECRET_KEY in wp-config.php — generate one with:', 'punchout-woocommerce' )
		);
	}

	/**
	 * Sealing key: POW_SECRET_KEY (wp-config) preferred; otherwise derived
	 * from the auth salt — which also lives in wp-config, not the
	 * database, so a DB dump alone still cannot decrypt the registry.
	 * Rotating salts invalidates stored secrets on the fallback; the admin
	 * notice nudges toward the constant.
	 */
	private function sealing_key(): string {
		if ( defined( 'POW_SECRET_KEY' ) ) {
			$key = Secrets::decode_key( (string) POW_SECRET_KEY );

			if ( null !== $key ) {
				return $key;
			}

			$this->logger->error( 'POW_SECRET_KEY is defined but not a valid base64 32-byte key; using derived fallback' );
		}

		// Fallback derivation. wp_salt('auth') is only config-backed when
		// AUTH_KEY/AUTH_SALT are defined in wp-config; on installs missing
		// them WordPress auto-generates DB-stored salts, and the sealed
		// registry then offers no protection against a DB dump. Surfaced
		// as an error so the operator knows the stated guarantee is absent.
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			$this->logger->error( 'Sealing-key fallback is derived from DATABASE-stored salts (AUTH_KEY/AUTH_SALT missing from wp-config.php); a DB dump can decrypt stored customer secrets. Define POW_SECRET_KEY.' );
		}

		return hash( 'sha256', 'pow-sealing-key|' . wp_salt( 'auth' ), true );
	}
}
