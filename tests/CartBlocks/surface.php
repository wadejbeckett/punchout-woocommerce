<?php
/** Isolated Cart surface checks: php tests/CartBlocks/surface.php. No WordPress bootstrap. @package POW */
declare( strict_types = 1 );

namespace POW\Cart {

	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['pow_blocks_hooks']['action'][$hook][] = $callback; }
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['pow_blocks_hooks']['filter'][$hook][] = $callback; }
	function add_shortcode( string $name, callable $callback ): void { $GLOBALS['pow_blocks_hooks']['shortcode'][$name] = $callback; }
	function wp_script_is( string $handle, string $state = 'enqueued' ): bool {
		$scripts = $GLOBALS['pow_blocks_scripts'];
		return 'registered' === $state ? isset( $scripts->registered[$handle] ) : in_array( $handle, $scripts->{$state} ?? [], true );
	}
	function wp_scripts(): object { return $GLOBALS['pow_blocks_scripts']; }
	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], mixed $version = false, mixed $footer = false ): void {
		$scripts = wp_scripts();
		if ( '' !== $src && ! isset( $scripts->registered[$handle] ) ) { $scripts->registered[$handle] = (object) compact( 'src', 'deps', 'version', 'footer' ); }
		if ( ! in_array( $handle, $scripts->enqueued, true ) ) { $scripts->enqueued[] = $handle; }
	}
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		if ( ! wp_script_is( $handle, 'registered' ) ) { return false; }
		wp_scripts()->inline[$handle][$position][] = $data;
		return true;
	}
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function wp_create_nonce( string $action ): string { return 'fixture-' . $action; }
	function wc_get_checkout_url(): string { return 'https://shop.example.test/checkout/'; }
	function remove_action( string $hook, mixed $callback, int $priority = 10 ): void { $GLOBALS['pow_blocks_removed'][] = [ $hook, $callback, $priority ]; }
}

namespace {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	if ( ! defined( 'POW_PLUGIN_URL' ) ) { define( 'POW_PLUGIN_URL', 'https://shop.example.test/wp-content/plugins/punchout-woocommerce/' ); }
	if ( ! function_exists( 'sanitize_html_class' ) ) { function sanitize_html_class( string $class ): string { return preg_replace( '/[^A-Za-z0-9_-]/', '', $class ); } }

	/**
	 * Only the Registry database boundary is replaced; its real reader runs.
	 *
	 * The statement decides the answer. Two connections are bound to the same
	 * customer account, so answering every lookup with one row would let the
	 * surface ask about the account, or about the wrong connection, and still
	 * pass — which is exactly the mistake a visit-scoped surface can make when
	 * one account holds every buyer's login.
	 */
	final class CartBlocksDatabase {
		public string $prefix = 'blocks_fixture_';
		public string $usermeta = 'blocks_fixture_usermeta';
		public string $last_error = '';
		/** Two connections, both bound to customer account 20; one visit of each. */
		public array $partners = [
			7 => [ 'id' => 7, 'status' => 'active', 'owner_user_id' => 20, 'exit_policy' => 'punchout_only' ],
			9 => [ 'id' => 9, 'status' => 'active', 'owner_user_id' => 20, 'exit_policy' => 'punchout_only' ],
		];
		public bool $partner_error = false;
		public int $partner_reads = 0;
		/** The connection ids the surface actually asked about, in order. */
		public array $partner_lookups = [];
		private array $queries = [];
		public function prepare( string $sql, mixed ...$args ): string { $key = 'query-' . count( $this->queries ); $this->queries[$key] = [ $sql, $args ]; return $key; }
		public function get_row( string $key, mixed $format ): ?array {
			++$this->partner_reads;
			$this->last_error = $this->partner_error ? 'Invented partner read failure' : '';
			[ $sql, $args ] = $this->queries[$key];
			if ( ! str_contains( $sql, 'pow_partners' ) || ! str_contains( $sql, 'WHERE id = %d' ) ) { throw new LogicException( 'Unexpected row query: ' . $sql ); }
			$id = (int) $args[0];
			$this->partner_lookups[] = $id;
			return $this->partners[ $id ] ?? null;
		}
		/** Nothing in the cart surface reads user meta: one bound account answers no question. */
		public function get_results( string $key, mixed $format ): ?array { return []; }
	}

	final class CartBlocksSurfaceTest extends PHPUnit\Framework\TestCase {
		private const BLOCK = '<div data-block-name="woocommerce/proceed-to-checkout-block" class="wp-block-woocommerce-proceed-to-checkout-block"><a href="/checkout/">Proceed to Checkout</a></div>';
		/** One bound customer account, two connections that name it, one visit of each. */
		private const ACCOUNT = 20;
		private const PARTNER_A = 7;
		private const PARTNER_B = 9;
		private const VISIT_A = 42;
		private const VISIT_B = 77;
		private const KEY_A = 'pow_aaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		private const KEY_B = 'pow_bbbbbbbbbbbbbbbbbbbbbbbbbbbb';
		private array $saved = [];
		private CartBlocksDatabase $db;
		private POW\Plugin $plugin;
		private POW\Cart\Surface $surface;

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_options', 'pow_test_filters', 'pow_blocks_scripts', 'pow_blocks_removed', 'pow_blocks_hooks' ] as $key ) { $this->saved[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
			$GLOBALS['wpdb'] = $this->db = new CartBlocksDatabase();
			// Every buyer of the connection is signed in as this one account.
			$GLOBALS['pow_test_current_user_id'] = self::ACCOUNT;
			$GLOBALS['pow_test_users'] = [ self::ACCOUNT => (object) [ 'ID' => self::ACCOUNT, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ] ];
			$GLOBALS['pow_test_options'] = [ POW\Settings::OPTION_KEY => [ 'return_button_label' => 'Send requisition' ] ];
			$GLOBALS['pow_test_filters'] = [];
			$GLOBALS['pow_blocks_removed'] = [];
			$GLOBALS['pow_blocks_hooks'] = [];
			$GLOBALS['pow_blocks_scripts'] = (object) [
				'registered' => [ 'wc-blocks-checkout' => (object) [ 'deps' => [] ], 'wc-cart-block-frontend' => (object) [ 'deps' => [ 'wc-blocks-checkout' ] ] ],
				'enqueued' => [ 'wc-cart-block-frontend' ], 'done' => [], 'inline' => [],
			];
			$this->plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			foreach ( [ 'settings' => new POW\Settings(), 'session_resolved' => true, 'current_session' => $this->visit() ] as $key => $value ) { ( new ReflectionProperty( $this->plugin, $key ) )->setValue( $this->plugin, $value ); }
			$this->surface = new POW\Cart\Surface( $this->plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'f', 32 ) ) ) );
			$this->surface->register();
		}
		protected function tearDown(): void { foreach ( $this->saved as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } } }
		/** One visit of the bound account: its own row, its own per-visit basket key, its own connection. */
		private function visit( int $id = self::VISIT_A, string $key = self::KEY_A, string $status = 'active', int $partner_id = self::PARTNER_A ): POW\Sessions\Session {
			return POW\Sessions\Session::from_row( [ 'id' => $id, 'partner_id' => $partner_id, 'user_id' => self::ACCOUNT, 'wc_session_key' => $key, 'status' => $status, 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
		}
		private function render(): string {
			$html = self::BLOCK;
			foreach ( $GLOBALS['pow_blocks_hooks']['filter']['render_block_woocommerce/proceed-to-checkout-block'] ?? [] as $callback ) { $html = $callback( $html ); }
			foreach ( $GLOBALS['pow_blocks_hooks']['action']['woocommerce_blocks_cart_enqueue_data'] ?? [] as $callback ) { $callback(); }
			return $html;
		}
		private function config(): array {
			$inline = $GLOBALS['pow_blocks_scripts']->inline['pow-cart-blocks']['before'] ?? [];
			self::assertTrue( count( $inline ) > 0, 'Restricted render must provide non-secret filter configuration before its script' );
			$last = end( $inline );
			self::assertTrue( 1 === preg_match( '/^window\.powCartBlocks = (.+);$/s', $last, $matches ), 'Expected one JSON configuration assignment' );
			return json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );
		}
		private function set_session( ?POW\Sessions\Session $session ): void { ( new ReflectionProperty( $this->plugin, 'current_session' ) )->setValue( $this->plugin, $session ); }

		public function test_restricted_keeps_native_wrapper_without_an_extra_button(): void {
			self::assertSame( self::BLOCK, $this->render() );
			self::assertSame( [], $GLOBALS['pow_blocks_hooks']['filter'] ?? [], 'Cart recreates its button in React: no PHP filter may rewrite the wrapper' );
			self::assertSame( [ 'restricted' => true, 'label' => 'Send requisition', 'confirmUrl' => 'https://shop.example.test/punchout/confirm' ], $this->config() );
		}
		public function test_dependency_graph_places_filters_after_api_before_cart_hydration(): void {
			$this->render(); $scripts = $GLOBALS['pow_blocks_scripts'];
			self::assertContains( 'pow-cart-blocks', $scripts->registered['wc-cart-block-frontend']->deps );
			self::assertSame( [ 'wc-blocks-checkout' ], $scripts->registered['pow-cart-blocks']->deps );
			self::assertSame( 'https://shop.example.test/wp-content/plugins/punchout-woocommerce/assets/js/cart-blocks.js', $scripts->registered['pow-cart-blocks']->src );
			self::assertTrue( $scripts->registered['pow-cart-blocks']->footer );
			$this->render();
			self::assertSame( 1, count( array_keys( $scripts->registered['wc-cart-block-frontend']->deps, 'pow-cart-blocks', true ) ) );
		}
		public function test_first_cart_render_registers_parent_assets_after_the_inner_block(): void {
			$scripts = $GLOBALS['pow_blocks_scripts'];
			unset( $scripts->registered['wc-cart-block-frontend'] ); $scripts->enqueued = [];
			// WP_Block renders children first. The Cart frontend is not registered yet.
			self::assertSame( [], $scripts->inline );
			// AbstractBlock::render_callback registers parent assets, then Cart::enqueue_data fires this hook.
			$scripts->registered['wc-cart-block-frontend'] = (object) [ 'deps' => [ 'wc-blocks-checkout' ] ];
			self::assertSame( self::BLOCK, $this->render() );
			POW\Cart\wp_enqueue_script( 'wc-cart-block-frontend' );
			self::assertSame( [ 'restricted' => true, 'label' => 'Send requisition', 'confirmUrl' => 'https://shop.example.test/punchout/confirm' ], $this->config() );
			self::assertContains( 'pow-cart-blocks', $scripts->registered['wc-cart-block-frontend']->deps );
			self::assertSame( [ 'wc-blocks-checkout' ], $scripts->registered['pow-cart-blocks']->deps );
		}
		public function test_ordinary_shopper_has_no_markup_or_script_changes(): void {
			$this->set_session( null );
			$before = serialize( $GLOBALS['pow_blocks_scripts'] );
			self::assertSame( self::BLOCK, $this->render() );
			self::assertSame( $before, serialize( $GLOBALS['pow_blocks_scripts'] ) );
			self::assertSame( 0, $this->db->partner_reads, 'An ordinary cart render asks the registry nothing' );
		}
		public function test_missing_partner_is_restricted(): void { $this->db->partners = []; self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] ); }
		public function test_failed_initial_partner_read_is_restricted_without_fatal(): void { $this->db->partner_error = true; self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] ); }
		public function test_label_filter_is_encoded_as_data_and_no_nonce_or_token_is_exported(): void {
			$label = '</script><script>example & "label"</script>';
			$GLOBALS['pow_test_filters']['pow_return_button_label'] = static fn( string $saved ): string => $label;
			$this->render(); $config = $this->config();
			self::assertSame( [ 'restricted', 'label', 'confirmUrl' ], array_keys( $config ) ); self::assertSame( $label, $config['label'] );
			self::assertStringNotContainsString( '</script', implode( '', $GLOBALS['pow_blocks_scripts']->inline['pow-cart-blocks']['before'] ) );
			self::assertStringNotContainsString( self::KEY_A, implode( '', $GLOBALS['pow_blocks_scripts']->inline['pow-cart-blocks']['before'] ), 'The per-visit key is a bearer credential and is never exported' );
		}
		public function test_missing_blocks_handle_preserves_wrapper_without_broken_dependency(): void {
			unset( $GLOBALS['pow_blocks_scripts']->registered['wc-blocks-checkout'] );
			self::assertSame( self::BLOCK, $this->render() );
			self::assertSame( [], $GLOBALS['pow_blocks_scripts']->inline );
		}
		public function test_classic_and_shortcode_keep_mains_confirmation_route(): void {
			$html = $this->surface->shortcode();
			self::assertStringContainsString( '/punchout/confirm', $html ); self::assertStringContainsString( 'fixture-pow_confirm_delivery', $html );
			$this->surface->maybe_unhook_checkout_button();
			self::assertSame( [ [ 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 ] ], $GLOBALS['pow_blocks_removed'] );
		}
		public function test_an_ordinary_shopper_keeps_woos_own_proceed_button(): void {
			$this->set_session( null );
			$this->surface->maybe_unhook_checkout_button();
			self::assertSame( [], $GLOBALS['pow_blocks_removed'] );
		}
		public function test_cart_exits_shortcode_is_registered_and_a_visit_has_no_checkout_link(): void {
			self::assertSame( [ $this->surface, 'cart_exits_shortcode' ], $GLOBALS['pow_blocks_hooks']['shortcode']['punchout_cart_exits'] ?? null );
			$html = $this->surface->cart_exits_shortcode();
			self::assertSame( 1, substr_count( $html, 'class="pow-return-form"' ) );
			self::assertStringNotContainsString( 'https://shop.example.test/checkout/', $html );
		}
		public function test_connection_switched_off_mid_visit_renders_no_exit(): void {
			self::assertSame( 1, substr_count( $this->surface->cart_exits_shortcode(), 'class="pow-return-form"' ) );
			$this->db->partners[ self::PARTNER_A ]['status'] = 'inactive';
			self::assertSame( '', $this->surface->cart_exits_shortcode(), 'A connection switched off mid-visit renders neither exit' );
			self::assertSame( 2, $this->db->partner_reads, 'Every render re-reads the connection' );
			self::assertSame( [ self::PARTNER_A, self::PARTNER_A ], $this->db->partner_lookups, 'Both renders asked about this visit\'s own connection' );
		}
		public function test_cart_exits_shortcode_gives_checkout_only_to_an_ordinary_shopper_with_no_session(): void {
			$this->set_session( null );
			$GLOBALS['pow_test_current_user_id'] = 31;
			$GLOBALS['pow_test_users'][31] = (object) [ 'ID' => 31, 'roles' => [ 'customer' ], 'allcaps' => [ 'read' => true ] ];
			$html = $this->surface->cart_exits_shortcode();
			self::assertSame( 1, substr_count( $html, 'href="https://shop.example.test/checkout/"' ) );
			self::assertStringNotContainsString( 'pow-return-form', $html );
		}
		public function test_the_bound_account_with_no_live_visit_gets_the_ordinary_checkout_link(): void {
			// Behaviour change (single-login mode): the account a connection is
			// bound to is an ordinary customer whenever no visit is live, so it
			// keeps the native checkout link. There are no orphaned buyers, and
			// nothing about the account — role included — blocks checkout: only
			// a live visit does. What separates this from the ordinary-shopper
			// case is the actor: the bound account itself is signed in, and the
			// surface still asks the registry nothing at all.
			$this->set_session( null );
			self::assertSame( self::ACCOUNT, $GLOBALS['pow_test_current_user_id'], 'The bound account is the one browsing' );
			$html = $this->surface->cart_exits_shortcode();
			self::assertSame( 1, substr_count( $html, 'href="https://shop.example.test/checkout/"' ) );
			self::assertStringNotContainsString( 'pow-return-form', $html );
			self::assertSame( [], $this->db->partner_lookups, 'No visit, no connection question — not even for the account that owns one' );
		}
		public function test_another_visit_of_the_same_account_renders_its_own_exit(): void {
			// Two visits share one account, so the account id discriminates
			// nothing and the rendered markup carries nothing of the visit in it.
			// What the surface must get right is which row it consults: this
			// visit's own connection, not the account and not the other visit's
			// connection. That is the assertion, because it is the only thing
			// here that can be wrong.
			$this->set_session( $this->visit( self::VISIT_B, self::KEY_B, 'active', self::PARTNER_B ) );
			$html = $this->surface->cart_exits_shortcode();
			self::assertSame( 1, substr_count( $html, 'class="pow-return-form"' ) );
			self::assertStringNotContainsString( 'https://shop.example.test/checkout/', $html );
			self::assertSame( [ self::PARTNER_B ], $this->db->partner_lookups, 'The surface resolved this visit\'s own connection' );
		}
		public function test_a_visit_of_a_switched_off_connection_is_fenced_while_its_siblings_visit_stays_live(): void {
			// One account, two connections, one visit each: switching one
			// connection off must fence its own visit and only its own. A
			// surface that looked the account up, or the first row it could
			// find, would fence both or neither.
			$this->db->partners[ self::PARTNER_B ]['status'] = 'inactive';
			$this->set_session( $this->visit( self::VISIT_B, self::KEY_B, 'active', self::PARTNER_B ) );
			self::assertSame( '', $this->surface->cart_exits_shortcode(), 'A visit of a disabled connection renders neither exit' );
			$this->set_session( $this->visit() );
			self::assertSame( 1, substr_count( $this->surface->cart_exits_shortcode(), 'class="pow-return-form"' ), 'The still-active connection keeps its own visit\'s return control' );
			self::assertSame( [ self::PARTNER_B, self::PARTNER_A ], $this->db->partner_lookups );
		}
		public function test_a_visit_that_has_left_active_renders_no_exit(): void {
			foreach ( [ 'returned', 'closed', 'expired', 'pending' ] as $status ) {
				$this->set_session( $this->visit( self::VISIT_A, self::KEY_A, $status ) );
				self::assertSame( '', $this->surface->cart_exits_shortcode(), "A {$status} visit renders no exit" );
			}
		}
		public function test_no_exit_policy_and_no_account_id_decide_anything_here(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Cart/Surface.php' );
			self::assertStringNotContainsString( 'ExitPolicy', $source, 'PunchOut is the only exit a visit has' );
			self::assertStringNotContainsString( 'get_current_user_id', $source, 'One account holds every buyer: its id answers nothing' );
		}
	}

	$passed = $failed = 0;
	foreach ( ( new ReflectionClass( CartBlocksSurfaceTest::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		if ( ! str_starts_with( $method->name, 'test_' ) ) { continue; }
		try { ( new CartBlocksSurfaceTest() )->runBare( $method->name ); ++$passed; echo "PASS {$method->name}\n"; }
		catch ( Throwable $error ) { ++$failed; echo "FAIL {$method->name}: {$error->getMessage()}\n"; }
	}
	echo "Passed: $passed Failed: $failed Skipped: 0\n";
	exit( $failed ? 1 : 0 );
}
