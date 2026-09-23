<?php
/** Confirmation presentation and request boundaries; native authorization is verified separately. */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Addresses\Chooser;

final class DeliveryChooserTest extends TestCase {
	public function test_choice_requires_the_full_server_known_provider_and_key(): void {
		$chooser = ( new ReflectionClass( Chooser::class ) )->newInstanceWithoutConstructor();
		$choices = [ [ 'provider' => 'native', 'key' => 'depot', 'address' => [ 'city' => 'Pretoria' ] ], [ 'provider' => 'customer', 'key' => 'depot', 'address' => [ 'city' => 'Durban' ] ] ];
		self::assertSame( $choices[0], $chooser->choose( $choices, 'native:depot' ) );
		foreach ( [ 'depot', 'native:foreign', 'native:depot:extra', '' ] as $key ) { self::assertNull( $chooser->choose( $choices, $key ) ); }
		self::assertNull( $chooser->choose( [ $choices[0], $choices[0] ], 'native:depot' ) );
	}
	public function test_only_a_post_with_a_scalar_confirmation_nonce_can_mutate(): void {
		$before = $GLOBALS['pow_test_valid_nonce'] ?? null; $nonce = 'confirmation-test'; $GLOBALS['pow_test_valid_nonce'] = $nonce;
		try {
			self::assertTrue( Chooser::request_allowed( 'POST', [ 'pow_nonce' => $nonce ] ) );
			foreach ( [ [ 'GET', [ 'pow_nonce' => $nonce ] ], [ 'POST', [] ], [ 'POST', [ 'pow_nonce' => [ $nonce ] ] ], [ 'POST', [ 'pow_nonce' => 'expired' ] ] ] as [ $method, $post ] ) { self::assertFalse( Chooser::request_allowed( $method, $post ) ); }
		} finally { if ( null === $before ) { unset( $GLOBALS['pow_test_valid_nonce'] ); } else { $GLOBALS['pow_test_valid_nonce'] = $before; } }
	}
	private function render( array $changes = [], array $overrides = [] ): string {
		$view = array_replace( [ 'choices' => [], 'selected_choice' => null, 'delivery_destination' => null, 'packages' => [], 'delivery' => [ 'status' => 'not_required', 'amount_cents' => null, 'emit' => false ], 'notes' => '', 'currency' => 'ZAR', 'merchandise_total_cents' => 12300, 'total_cents' => 12300, 'items' => [], 'skipped' => [], 'can_confirm' => true, 'requires_unknown_acknowledgement' => false, 'review_digest' => str_repeat( 'a', 64 ), 'error' => null ], $changes );
		$vars = [ 'view' => $view, 'action_url' => 'https://shop.example.test/punchout/confirm', 'cart_url' => 'https://shop.example.test/cart/', 'nonce' => 'confirmation-nonce', 'return_nonce' => 'return-nonce', 'stylesheet_url' => 'https://shop.example.test/confirmation.css', 'shop_name' => 'Example shop', 'document' => false ];
		$vars = array_replace( $vars, $overrides );
		$file = dirname( __DIR__, 2 ) . '/templates/delivery-confirmation.php';
		self::assertTrue( is_file( $file ), 'Confirmation template must exist.' );
		ob_start();
		try { ( static function( string $file, array $vars ): void { extract( $vars, EXTR_SKIP ); include $file; } )( $file, $vars ); return (string) ob_get_clean(); }
		catch ( Throwable $error ) { ob_end_clean(); throw $error; }
	}
	public function test_store_api_read_and_unrelated_routes_do_not_invalidate_consent(): void {
		self::assertTrue( Chooser::is_store_cart_mutation( 'POST', '/wc/store/v1/cart/select-shipping-rate' ) );
		self::assertTrue( Chooser::is_store_cart_mutation( 'POST', '/wc/store/v1/cart/update-customer' ) );
		foreach ( [ [ 'GET', '/wc/store/v1/cart/update-customer' ], [ 'POST', '/wc/store/v1/cart' ], [ 'POST', '/wc/store/v1/checkout' ], [ 'POST', '/unknown/cart/update-customer' ] ] as [ $method, $route ] ) { self::assertFalse( Chooser::is_store_cart_mutation( $method, $route ) ); }
	}
	public function test_confirmation_has_edit_and_transfer_actions_and_no_payment_fields(): void {
		$html = $this->render();
		foreach ( [ 'Submit for approval', 'Back to cart', 'Review your cart', 'review_digest', 'pow_return_nonce' ] as $copy ) { self::assertStringContainsString( $copy, $html ); }
		self::assertStringNotContainsString( 'name="payment_method"', $html );
		self::assertStringNotContainsString( '<script', $html );
	}
	public function test_visitor_values_are_escaped_in_notes_destination_and_error(): void {
		$hostile = '</textarea><script>unsafe()</script>';
		$choice = [ 'provider' => 'native', 'key' => 'x" autofocus', 'label' => $hostile, 'address' => [ 'address_1' => $hostile, 'city' => 'Pretoria' ], 'code' => '' ];
		$html = $this->render( [ 'notes' => $hostile, 'choices' => [ $choice ], 'selected_choice' => $choice, 'delivery_destination' => $choice, 'error' => new WP_Error( 'test', $hostile ) ] );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringNotContainsString( 'key="x" autofocus', $html );
	}
	public function test_unknown_quote_has_explicit_acknowledgement_and_no_invented_zero(): void {
		$html = $this->render( [ 'delivery' => [ 'status' => 'unknown', 'amount_cents' => null, 'emit' => false ], 'requires_unknown_acknowledgement' => true ] );
		self::assertStringContainsString( 'name="acknowledge_unknown"', $html );
		self::assertStringContainsString( 'Delivery will be quoted separately', $html );
		self::assertStringNotContainsString( 'ZAR 0.00', $html );
	}
	public function test_virtual_basket_does_not_require_an_unused_company_address(): void {
		$html = $this->render( [ 'choices' => [ [ 'provider' => 'native', 'key' => 'depot', 'label' => 'Company depot' ] ] ] );
		self::assertStringContainsString( 'This cart does not need a delivery address.', $html );
		self::assertStringNotContainsString( 'name="choice"', $html );
		self::assertStringNotContainsString( 'saved with the local quote', $html );
	}
	private static function two_items(): array {
		return [ 'items' => [ [ 'description' => 'Crate', 'supplier_part_id' => 'CR-1', 'quantity' => 3, 'unit_price_cents' => 53995 ], [ 'description' => 'Label', 'supplier_part_id' => 'LB-1', 'quantity' => 1, 'unit_price_cents' => 1 ] ], 'merchandise_total_cents' => 161986, 'total_cents' => 161986 ];
	}
	private static function header_of( string $html ): string {
		self::assertMatchesRegularExpression( '#<header class="pow-confirmation__header">.*?</header>#s', $html );
		preg_match( '#<header class="pow-confirmation__header">.*?</header>#s', $html, $m );
		return $m[0];
	}
	public function test_amounts_use_the_store_price_format_and_items_show_line_totals(): void {
		$html = $this->render( self::two_items() );
		self::assertStringContainsString( 'Line total', $html );
		self::assertStringContainsString( "R\u{00A0}1 619,85", $html );
		self::assertStringContainsString( "R\u{00A0}1 619,86", $html );
		self::assertStringContainsString( "R\u{00A0}539,95", $html );
		self::assertStringNotContainsString( 'ZAR 1619', $html );
	}
	public function test_delivery_address_shows_province_and_country_names(): void {
		$before = $GLOBALS['pow_test_wc'] ?? null;
		$wc = new class() extends POW_Test_WC { public mixed $countries = null; };
		$wc->countries = new class() {
			public function get_states( string $country ): array|false { return 'ZA' === $country ? [ 'GP' => 'Gauteng' ] : false; }
			public function get_countries(): array { return [ 'ZA' => 'South Africa' ]; }
		}; $GLOBALS['pow_test_wc'] = $wc;
		try {
			$choice = [ 'provider' => 'native', 'key' => 'depot', 'label' => 'Depot', 'address' => [ 'address_1' => '1 Depot Road', 'city' => 'Pretoria', 'state' => 'GP', 'postcode' => '0157', 'country' => 'ZA', 'phone' => '0123456789' ], 'code' => '' ];
			$html = $this->render( [ 'choices' => [ $choice ], 'selected_choice' => $choice, 'delivery_destination' => $choice ] );
			self::assertStringContainsString( 'Gauteng', $html );
			self::assertStringContainsString( 'South Africa', $html );
			self::assertStringNotContainsString( '>GP<', $html );
			self::assertStringNotContainsString( 'GP<br>', $html );
			self::assertStringNotContainsString( '0123456789', $html );
		} finally { if ( null === $before ) { unset( $GLOBALS['pow_test_wc'] ); } else { $GLOBALS['pow_test_wc'] = $before; } }
	}
	public function test_document_header_shows_the_site_logo_and_shortcode_keeps_the_shop_name(): void {
		$logo = '<a href="https://shop.example.test/" class="custom-logo-link" rel="home"><img src="https://shop.example.test/logo.png" class="custom-logo" alt="Example shop"></a>';
		$GLOBALS['pow_test_custom_logo'] = $logo;
		try {
			$header = self::header_of( $this->render( [], [ 'document' => true ] ) );
			self::assertStringContainsString( '<img src="https://shop.example.test/logo.png"', $header );
			$header = self::header_of( $this->render( [], [ 'document' => false ] ) );
			self::assertStringContainsString( 'Example shop', $header );
			self::assertStringNotContainsString( 'custom-logo', $header );
		} finally { unset( $GLOBALS['pow_test_custom_logo'] ); }
		$header = self::header_of( $this->render( [], [ 'document' => true ] ) );
		self::assertStringContainsString( '>Example shop<', $header );
		self::assertStringNotContainsString( '<img', $header );
	}
	public function test_buttons_carry_the_woocommerce_button_classes(): void {
		$html = $this->render();
		self::assertStringContainsString( 'class="button alt wp-element-button pow-confirmation__submit"', $html );
		self::assertStringContainsString( 'class="button wp-element-button pow-confirmation__secondary"', $html );
	}
	public function test_presentation_adds_no_form_fields(): void {
		$choice = [ 'provider' => 'native', 'key' => 'depot', 'label' => 'Depot', 'address' => [ 'city' => 'Pretoria', 'state' => 'GP', 'country' => 'ZA' ], 'code' => '' ];
		$digest = str_repeat( 'b', 64 );
		$html = $this->render( self::two_items() + [ 'choices' => [ $choice ], 'selected_choice' => $choice, 'delivery_destination' => $choice, 'review_digest' => $digest ], [ 'document' => true ] );
		self::assertSame( 3, substr_count( $html, 'type="hidden"' ) );
		self::assertStringContainsString( 'name="review_digest" value="' . $digest . '"', $html );
		self::assertStringNotContainsString( '<script', $html );
	}
}
