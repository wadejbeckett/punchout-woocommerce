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
	function remove_action( string $hook, mixed $callback, int $priority = 10 ): void { $GLOBALS['pow_blocks_removed'][] = [ $hook, $callback, $priority ]; }
}

namespace {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	if ( ! defined( 'POW_PLUGIN_URL' ) ) { define( 'POW_PLUGIN_URL', 'https://shop.example.test/wp-content/plugins/punchout-woocommerce/' ); }
	if ( ! function_exists( 'sanitize_html_class' ) ) { function sanitize_html_class( string $class ): string { return preg_replace( '/[^A-Za-z0-9_-]/', '', $class ); } }

	/** Only the Registry/ExitPolicy database boundary is replaced; their real readers run. */
	final class CartBlocksDatabase {
		public string $prefix = 'blocks_fixture_';
		public string $usermeta = 'blocks_fixture_usermeta';
		public string $last_error = '';
		public ?array $partner = [ 'id' => 7, 'status' => 'active', 'owner_user_id' => 20, 'exit_policy' => 'punchout_only' ];
		public array $meta = [ '_pow_partner_id' => '7' ];
		public bool $partner_error = false;
		public bool $meta_error = false;
		public mixed $after_partner_read = null;
		private array $queries = [];
		public function prepare( string $sql, mixed ...$args ): string { $key = 'query-' . count( $this->queries ); $this->queries[$key] = [ $sql, $args ]; return $key; }
		public function get_row( string $key, mixed $format ): ?array {
			$this->last_error = $this->partner_error ? 'Invented partner read failure' : '';
			$row = $this->partner;
			if ( $this->after_partner_read ) { $callback = $this->after_partner_read; $this->after_partner_read = null; $callback(); }
			return $row;
		}
		public function get_results( string $key, mixed $format ): ?array {
			$this->last_error = $this->meta_error ? 'Invented metadata read failure' : '';
			if ( $this->meta_error ) { return null; }
			[ , $args ] = $this->queries[$key];
			if ( 30 !== $args[0] ) { return []; }
			return isset( $this->meta[$args[1]] ) ? [ [ 'meta_value' => $this->meta[$args[1]] ] ] : [];
		}
	}

	final class CartBlocksSurfaceTest extends PHPUnit\Framework\TestCase {
		private const BLOCK = '<div data-block-name="woocommerce/proceed-to-checkout-block" class="wp-block-woocommerce-proceed-to-checkout-block"><a href="/checkout/">Proceed to Checkout</a></div>';
		private array $saved = [];
		private CartBlocksDatabase $db;
		private POW\Plugin $plugin;
		private POW\Cart\Surface $surface;

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_options', 'pow_test_filters', 'pow_blocks_scripts', 'pow_blocks_removed', 'pow_blocks_hooks' ] as $key ) { $this->saved[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
			$GLOBALS['wpdb'] = $this->db = new CartBlocksDatabase();
			$GLOBALS['pow_test_current_user_id'] = 30;
			$GLOBALS['pow_test_users'] = [ 30 => (object) [ 'ID' => 30, 'roles' => [ 'punchout_buyer' ], 'allcaps' => [ 'read' => true ] ] ];
			$GLOBALS['pow_test_options'] = [ POW\Settings::OPTION_KEY => [ 'exit_policy' => 'punchout_and_checkout', 'return_button_label' => 'Send requisition' ] ];
			$GLOBALS['pow_test_filters'] = [];
			$GLOBALS['pow_blocks_removed'] = [];
			$GLOBALS['pow_blocks_hooks'] = [];
			$GLOBALS['pow_blocks_scripts'] = (object) [
				'registered' => [ 'wc-blocks-checkout' => (object) [ 'deps' => [] ], 'wc-cart-block-frontend' => (object) [ 'deps' => [ 'wc-blocks-checkout' ] ] ],
				'enqueued' => [ 'wc-cart-block-frontend' ], 'done' => [], 'inline' => [],
			];
			$this->plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
			foreach ( [ 'settings' => new POW\Settings(), 'session_resolved' => true, 'current_session' => POW\Sessions\Session::from_row( [ 'id' => 42, 'partner_id' => 7, 'user_id' => 30, 'status' => 'active', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] ) ] as $key => $value ) { ( new ReflectionProperty( $this->plugin, $key ) )->setValue( $this->plugin, $value ); }
			$this->surface = new POW\Cart\Surface( $this->plugin, new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'f', 32 ) ) ) );
			$this->surface->register();
		}
		protected function tearDown(): void { foreach ( $this->saved as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } } }
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

		public function test_restricted_keeps_native_wrapper_without_an_extra_button(): void {
			self::assertSame( self::BLOCK, $this->render() );
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
			$html = self::BLOCK;
			// WP_Block renders children first. The Cart frontend is not registered yet.
			foreach ( $GLOBALS['pow_blocks_hooks']['filter']['render_block_woocommerce/proceed-to-checkout-block'] ?? [] as $callback ) { $html = $callback( $html ); }
			self::assertSame( self::BLOCK, $html );
			self::assertSame( [], $scripts->inline );
			// AbstractBlock::render_callback registers parent assets, then Cart::enqueue_data fires this hook.
			$scripts->registered['wc-cart-block-frontend'] = (object) [ 'deps' => [ 'wc-blocks-checkout' ] ];
			foreach ( $GLOBALS['pow_blocks_hooks']['action']['woocommerce_blocks_cart_enqueue_data'] ?? [] as $callback ) { $callback(); }
			POW\Cart\wp_enqueue_script( 'wc-cart-block-frontend' );
			self::assertSame( [ 'restricted' => true, 'label' => 'Send requisition', 'confirmUrl' => 'https://shop.example.test/punchout/confirm' ], $this->config() );
			self::assertContains( 'pow-cart-blocks', $scripts->registered['wc-cart-block-frontend']->deps );
			self::assertSame( [ 'wc-blocks-checkout' ], $scripts->registered['pow-cart-blocks']->deps );
		}
		public function test_dual_exit_preserves_checkout_and_one_additive_confirmation_form(): void {
			$this->db->partner['exit_policy'] = 'punchout_and_checkout';
			$html = $this->render();
			self::assertTrue( str_starts_with( $html, self::BLOCK ) );
			self::assertSame( 1, substr_count( $html, 'class="pow-return-form"' ) );
			self::assertStringContainsString( 'action="https://shop.example.test/punchout/confirm"', $html );
			self::assertStringContainsString( 'fixture-pow_confirm_delivery', $html );
			self::assertSame( [], $GLOBALS['pow_blocks_scripts']->inline );
		}
		public function test_ordinary_shopper_has_no_markup_or_script_changes(): void {
			( new ReflectionProperty( $this->plugin, 'current_session' ) )->setValue( $this->plugin, null );
			$before = serialize( $GLOBALS['pow_blocks_scripts'] );
			self::assertSame( self::BLOCK, $this->render() );
			self::assertSame( $before, serialize( $GLOBALS['pow_blocks_scripts'] ) );
		}
		public function test_missing_partner_is_restricted(): void { $this->db->partner = null; self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] ); }
		public function test_failed_initial_partner_read_is_restricted_without_fatal(): void { $this->db->partner_error = true; self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] ); }
		public function test_failed_buyer_read_is_restricted(): void { $this->db->partner['exit_policy'] = 'punchout_and_checkout'; $this->db->meta_error = true; self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] ); }
		public function test_invalid_global_policy_cannot_display_dual_exit(): void { $this->db->partner['exit_policy'] = 'punchout_and_checkout'; $GLOBALS['pow_test_options'][POW\Settings::OPTION_KEY]['exit_policy'] = 'invalid'; self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] ); }
		public function test_current_company_restriction_overrides_first_partner_snapshot(): void {
			$this->db->partner['exit_policy'] = 'punchout_and_checkout';
			$this->db->after_partner_read = function (): void { $this->db->partner['exit_policy'] = 'punchout_only'; };
			self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] );
		}
		public function test_current_buyer_restriction_overrides_company_checkout(): void { $this->db->partner['exit_policy'] = 'punchout_and_checkout'; $this->db->meta['_pow_exit_policy_7'] = 'punchout_only'; self::assertSame( self::BLOCK, $this->render() ); self::assertTrue( $this->config()['restricted'] ); }
		public function test_label_filter_is_encoded_as_data_and_no_nonce_or_token_is_exported(): void {
			$label = '</script><script>example & "label"</script>';
			$GLOBALS['pow_test_filters']['pow_return_button_label'] = static fn( string $saved ): string => $label;
			$this->render(); $config = $this->config();
			self::assertSame( [ 'restricted', 'label', 'confirmUrl' ], array_keys( $config ) ); self::assertSame( $label, $config['label'] );
			self::assertStringNotContainsString( '</script', implode( '', $GLOBALS['pow_blocks_scripts']->inline['pow-cart-blocks']['before'] ) );
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
