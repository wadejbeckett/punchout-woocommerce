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
	private static function physical( array $changes = [] ): array {
		$choice = [ 'provider' => 'native', 'key' => 'depot', 'label' => 'Depot', 'address' => [ 'city' => 'Pretoria', 'state' => 'GP', 'country' => 'ZA' ], 'code' => '' ];
		$package = [ 'package_key' => 0, 'label' => 'Parcel', 'selected_rate_id' => 'flat_rate:1', 'rates' => [ [ 'rate_id' => 'flat_rate:1', 'display_label' => 'Courier (3-5 working days)' ] ] ];
		return array_replace( [ 'choices' => [ $choice ], 'selected_choice' => $choice, 'delivery_destination' => $choice, 'packages' => [ $package ], 'delivery' => [ 'status' => 'quoted', 'amount_cents' => 5000, 'emit' => true ], 'preferred_delivery_date' => '2026-10-07', 'preferred_delivery_date_min' => '2026-09-24' ], $changes );
	}
	private static function date_input( string $html ): string {
		self::assertSame( 1, preg_match( '#<input type="date"[^>]*>#', $html, $m ), 'One native date input.' );
		return $m[0];
	}
	public function test_date_input_is_native_optional_with_a_tomorrow_minimum(): void {
		$html = $this->render( self::physical() );
		$input = self::date_input( $html );
		self::assertStringContainsString( 'name="preferred_delivery_date"', $input );
		self::assertStringContainsString( 'value="2026-10-07"', $input );
		self::assertStringContainsString( 'min="2026-09-24"', $input );
		self::assertStringNotContainsString( 'required', $input );
		self::assertStringNotContainsString( 'max=', $input );
		self::assertStringContainsString( '<label for="pow-preferred-date">Preferred delivery date</label>', $html );
		self::assertStringContainsString( 'it is not sent to your purchasing system', $html );
		self::assertStringNotContainsString( '<script', $html );
		// A cleared date renders empty; an absent minimum adds no attribute.
		$input = self::date_input( $this->render( self::physical( [ 'preferred_delivery_date' => null, 'preferred_delivery_date_min' => '' ] ) ) );
		self::assertStringContainsString( 'value=""', $input );
		self::assertStringNotContainsString( 'min=', $input );
		// A theme override fed an older view still renders.
		$view = self::physical(); unset( $view['preferred_delivery_date'], $view['preferred_delivery_date_min'] );
		self::assertStringContainsString( 'value=""', self::date_input( $this->render( $view ) ) );
	}
	public function test_collection_labels_the_method_section(): void {
		$html = $this->render( self::physical( [ 'collection' => true ] ) );
		self::assertStringContainsString( '<h2 id="pow-shipping-title">Collection</h2>', $html );
		self::assertStringContainsString( '<h2 id="pow-destination-title">Address for this order</h2>', $html );
		self::assertStringContainsString( 'Collection orders keep this address on the order', $html );
		self::assertStringContainsString( '<select id="pow-delivery-choice" name="choice" required>', $html );
		$html = $this->render( self::physical() );
		self::assertStringContainsString( '<h2 id="pow-shipping-title">Delivery method</h2>', $html );
		self::assertStringContainsString( '<h2 id="pow-destination-title">Delivery address</h2>', $html );
		self::assertStringNotContainsString( 'Collection orders keep', $html );
	}
	public function test_delivery_period_hint_is_shown_under_the_methods(): void {
		$hint = 'Delivery periods are shown with each delivery method.';
		$html = $this->render( self::physical() );
		self::assertStringContainsString( $hint, $html );
		self::assertStringContainsString( 'Courier (3-5 working days)', $html );
		self::assertStringNotContainsString( $hint, $this->render( self::physical( [ 'packages' => [] ] ) ) );
		self::assertStringNotContainsString( $hint, $this->render() );
	}
	public function test_virtual_basket_has_no_date_input(): void {
		$html = $this->render( [ 'preferred_delivery_date' => null ] );
		self::assertStringNotContainsString( 'type="date"', $html );
		self::assertStringNotContainsString( 'preferred_delivery_date', $html );
	}

	public function test_add_address_form_renders_only_when_offered(): void {
		$html = $this->render( self::physical() );
		self::assertStringNotContainsString( 'add_address', $html );
		self::assertStringNotContainsString( 'pow-add-address', $html );
		self::assertSame( 1, substr_count( $html, '<form' ) );
		self::assertSame( 3, substr_count( $html, 'type="hidden"' ) );
		$add = [ 'fields' => '<p class="form-row"><input type="text" name="shipping_city" id="pow_add_address_shipping_city" value=""></p>', 'label' => 'Site "B"', 'nonce' => 'add-nonce', 'notice' => null, 'open' => false ];
		$html = $this->render( self::physical(), [ 'add_address' => $add ] );
		// One form: the add fieldset sits inside the review form, after the review layout, so an add posts the review's live notes and date.
		self::assertSame( 1, substr_count( $html, '<form' ) );
		$fieldset = strpos( $html, '<fieldset class="pow-confirmation__add-set"' );
		self::assertTrue( is_int( $fieldset ), 'The add fieldset renders.' );
		self::assertGreaterThan( strpos( $html, '</aside></div>' ), $fieldset );
		self::assertGreaterThan( $fieldset, strpos( $html, '</form>' ) );
		self::assertSame( 4, substr_count( $html, 'type="hidden"' ), 'The review\'s three hidden inputs and the add nonce; nothing mirrors the notes or date.' );
		self::assertStringContainsString( '<button type="submit" name="pow_delivery_action" value="add_address" class="button wp-element-button pow-confirmation__secondary pow-confirmation__add-submit" formnovalidate>Add and use this address</button>', $html );
		self::assertStringContainsString( '<input type="hidden" name="pow_address_nonce" value="add-nonce">', $html );
		self::assertSame( 1, substr_count( $html, 'name="pow_nonce" value="confirmation-nonce"' ) );
		self::assertStringContainsString( 'name="pow_address_label" maxlength="190" value="Site &quot;B&quot;"', $html );
		self::assertStringContainsString( $add['fields'], $html );
		self::assertStringContainsString( 'Add a delivery address', $html );
		self::assertStringContainsString( 'href="#pow-add-address"', $html );
		self::assertStringContainsString( '<details class="pow-confirmation__add" id="pow-add-address">', $html );
		self::assertStringContainsString( 'name="pow_address_refresh" value="1"', $html );
		self::assertStringContainsString( 'Add and use this address', $html );
		self::assertStringContainsString( 'Only your company administrator can change or remove it.', $html );
		self::assertStringNotContainsString( 'pow_address_code', $html );
		self::assertStringNotContainsString( 'role="status"', $html );
		self::assertStringNotContainsString( '<script', $html );
		// A refused add reopens the form, and a saved one says so.
		$html = $this->render( self::physical(), [ 'add_address' => [ 'open' => true, 'notice' => 'Address added <b>now</b>' ] + $add ] );
		self::assertStringContainsString( '<details class="pow-confirmation__add" id="pow-add-address" open>', $html );
		self::assertStringContainsString( '<div class="pow-confirmation__notice" role="status">Address added &lt;b&gt;now&lt;/b&gt;</div>', $html );
		// An empty book points at the form instead of only at the administrator.
		$html = $this->render( self::physical( [ 'choices' => [], 'selected_choice' => null, 'delivery_destination' => null ] ), [ 'add_address' => $add ] );
		self::assertStringContainsString( 'No delivery address is available yet. Add one below, or ask your company administrator.', $html );
		self::assertStringContainsString( 'No delivery address is available. Ask your company administrator to add and enable one.', $this->render( self::physical( [ 'choices' => [], 'selected_choice' => null, 'delivery_destination' => null ] ) ) );
		// A cart that needs no delivery offers no address form.
		self::assertSame( 1, substr_count( $this->render( [], [ 'add_address' => $add ] ), '<form' ) );
	}
	/** The add rides the review form: the notes and date the buyer is typing are the very fields it posts. Its buttons skip the review's own required fields (the address select, the acknowledgement); the server validates the add. */
	public function test_add_posts_the_reviews_live_notes_and_date_and_skips_the_reviews_required_fields(): void {
		$add = [ 'fields' => '<p class="form-row"><input type="text" name="shipping_city" id="pow_add_address_shipping_city" value="" aria-required="true"></p>', 'label' => '', 'nonce' => 'add-nonce', 'notice' => null, 'open' => false ];
		$html = $this->render( self::physical( [ 'requires_unknown_acknowledgement' => true, 'delivery' => [ 'status' => 'unknown', 'amount_cents' => null, 'emit' => false ] ] ), [ 'add_address' => $add ] );
		self::assertSame( 1, substr_count( $html, 'name="notes"' ), 'No mirror: the one notes field posts with every button.' );
		self::assertSame( 1, substr_count( $html, 'name="preferred_delivery_date"' ) );
		self::assertStringContainsString( '<select id="pow-delivery-choice" name="choice" required>', $html );
		self::assertStringContainsString( 'name="acknowledge_unknown" value="1" required', $html );
		foreach ( [ 'value="add_address"', 'name="pow_address_refresh"' ] as $button ) {
			self::assertSame( 1, preg_match( '#<button[^>]*' . preg_quote( $button, '#' ) . '[^>]*>#', $html, $m ), $button );
			self::assertStringContainsString( ' formnovalidate', $m[0], $button );
		}
		// Submit for approval still validates the review, and the add adds no required attribute of its own that could block it.
		self::assertSame( 1, preg_match( '#<button[^>]*value="submit"[^>]*>#', $html, $m ) );
		self::assertStringNotContainsString( 'formnovalidate', $m[0] );
		$fieldset = substr( $html, strpos( $html, '<fieldset class="pow-confirmation__add-set"' ) );
		self::assertSame( 0, preg_match( '#\srequired[\s>=]#', substr( $fieldset, 0, strpos( $fieldset, '</fieldset>' ) ) ) );
		self::assertStringNotContainsString( '<script', $html );
	}
}
