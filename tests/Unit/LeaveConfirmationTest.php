<?php
/**
 * Cancel and return asks first (0.5.0, ticket 77): when the site turns on "Ask before leaving" and the visit's cart has
 * items, the cancel control does not leave at once. With the plugin's own script the question opens in a dialog on
 * the page; without it, the server sends the browser back to the page it came from with the dialog open
 * (?pow_leave=ask). Either way, only a post carrying the answer (pow_leave=confirmed) leaves. An empty cart leaves at
 * once, and so does every cancel while the setting is off.
 *
 * @package POW
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Cart\LeaveConfirmation;
use POW\Settings;

final class LeaveConfirmationTest extends TestCase {
	private array $saved = [];
	private array $get = [];

	protected function setUp(): void {
		foreach ( [ 'pow_test_options', 'pow_test_wc' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		$this->get = $_GET;
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = $this->get;
		foreach ( $this->saved as $key => [ $existed, $value ] ) {
			if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	private function settings( string $on ): Settings {
		$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'leave_confirmation' => $on ];
		return new Settings();
	}

	public function test_it_asks_only_when_on_with_items_and_not_yet_answered(): void {
		self::assertTrue( LeaveConfirmation::must_ask( $this->settings( 'yes' ), 2, null ) );
		self::assertTrue( LeaveConfirmation::must_ask( $this->settings( 'yes' ), 1, 'ask' ), 'Only the answer leaves' );
		self::assertTrue( LeaveConfirmation::must_ask( $this->settings( 'yes' ), 1, [ 'confirmed' ] ) );
		self::assertFalse( LeaveConfirmation::must_ask( $this->settings( 'yes' ), 1, 'confirmed' ) );
		self::assertFalse( LeaveConfirmation::must_ask( $this->settings( 'yes' ), 0, null ), 'An empty cart leaves at once' );
		self::assertFalse( LeaveConfirmation::must_ask( $this->settings( 'no' ), 3, null ), 'Off: every cancel leaves at once' );
		self::assertSame( [ 'pow_leave', 'confirmed', 'ask' ], [ LeaveConfirmation::FIELD, LeaveConfirmation::CONFIRMED, LeaveConfirmation::ASK ] );
	}

	public function test_the_cart_has_items_when_it_holds_a_line(): void {
		self::assertSame( 0, LeaveConfirmation::cart_items(), 'No cart' );
		$GLOBALS['pow_test_wc'] = new POW_Test_WC();
		$GLOBALS['pow_test_wc']->cart = new class { public array $lines = []; public function get_cart(): array { return $this->lines; } };
		self::assertSame( 0, LeaveConfirmation::cart_items() );
		$GLOBALS['pow_test_wc']->cart->lines = [ 'a' => [ 'quantity' => 2 ], 'b' => [ 'quantity' => 1 ] ];
		self::assertSame( 2, LeaveConfirmation::cart_items() );
	}

	public function test_without_the_script_the_browser_goes_back_to_its_page_to_be_asked(): void {
		self::assertSame( 'https://shop.example.test/products/?page=2&pow_leave=ask', LeaveConfirmation::ask_url( 'https://shop.example.test/products/?page=2', 'https://shop.example.test/shop/' ) );
		self::assertSame( 'https://shop.example.test/shop/?pow_leave=ask', LeaveConfirmation::ask_url( false, 'https://shop.example.test/shop/' ), 'No page to go back to: the landing page' );
		self::assertSame( 'https://shop.example.test/cart/?pow_leave=ask', LeaveConfirmation::ask_url( 'https://shop.example.test/cart/?pow_leave=ask', '' ), 'Asked once' );
	}

	public function test_the_page_knows_it_was_sent_back_to_ask(): void {
		self::assertFalse( LeaveConfirmation::asked() );
		$_GET = [ 'pow_leave' => 'ask' ];
		self::assertTrue( LeaveConfirmation::asked() );
		$_GET = [ 'pow_leave' => [ 'ask' ] ];
		self::assertFalse( LeaveConfirmation::asked() );
	}
}
