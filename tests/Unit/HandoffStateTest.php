<?php
/**
 * The plain page that posts a cart (or the empty cancel message) to the purchasing system carries the state classes
 * too (0.5.0, ticket 77): Sending after Submit, Not sent after Cancel and return. It stays theme-free, because it holds
 * the cXML and the visit's BuyerCookie; its fallback button (pressed only when the page cannot post by itself) is
 * the "State page button label" setting, and the pow_handoff_copy filter still has the last word.
 *
 * @package POW
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Http\ReturnEndpoint;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Settings;

final class HandoffStateTest extends TestCase {
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'pow_test_options', 'pow_test_filters' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $existed, $value ] ) {
			if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	private function page( string $state = 'sending' ): string {
		$visit   = Session::from_row( [ 'id' => 42, 'partner_id' => 5, 'browser_form_post_url' => 'https://buyer.example.com/punchout/return', 'status' => 'active' ] );
		$partner = Partner::from_row( [ 'id' => 5, 'name' => 'Example', 'return_encoding' => 'base64' ] );
		return ReturnEndpoint::handoff_markup( $visit, $partner, '<cXML/>', [], $state );
	}

	/** @return array<string, string> class => the element's own text. */
	private static function parts( string $html ): array {
		preg_match_all( '/<(\w+)[^>]*class="([^"]*pow-state[^"]*)"[^>]*>([^<]*)/', $html, $found, PREG_SET_ORDER );
		$parts = [];
		foreach ( $found as [ , , $class, $text ] ) { $parts[ $class ] = trim( $text ); }
		return $parts;
	}

	public function test_the_page_after_submit_is_the_sending_state_with_the_auto_posting_form_as_its_actions(): void {
		$html  = $this->page();
		$parts = self::parts( $html );
		self::assertArrayHasKey( 'pow-state pow-state--sending', $parts );
		self::assertSame( 'Returning your cart…', $parts['pow-state__title'] );
		self::assertStringStartsWith( 'Your cart is being sent back to your purchasing system.', $parts['pow-state__text'] );
		self::assertMatchesRegularExpression( '#<form method="post" action="https://buyer\.example\.com/punchout/return" id="pow-handoff-form" class="pow-state__actions">#', $html );
		self::assertSame( 'Continue to your purchasing system', $parts['pow-handoff-submit pow-state__button'] );
		self::assertStringContainsString( 'name="cxml-base64"', $html, 'The page still posts the message' );
	}

	public function test_the_page_after_cancel_and_return_is_the_not_sent_state(): void {
		$parts = self::parts( $this->page( 'not-sent' ) );
		self::assertArrayHasKey( 'pow-state pow-state--not-sent', $parts );
		self::assertSame( 'Not sent', $parts['pow-state__title'] );
		self::assertSame( 'Your cart was not sent. Taking you back to your purchasing system.', $parts['pow-state__text'] );
	}

	public function test_the_button_is_the_state_page_button_setting_and_the_copy_filter_runs_last(): void {
		$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'state_button_label' => 'Continue' ];
		self::assertSame( 'Continue', self::parts( $this->page() )['pow-handoff-submit pow-state__button'] );
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = static fn( array $copy, Session $visit, string $state ): array => [ 'button' => 'Go' ] + $copy + [ 'state' => $state ];
		$parts = self::parts( $this->page( 'not-sent' ) );
		self::assertSame( 'Go', $parts['pow-handoff-submit pow-state__button'] );
		self::assertSame( 'Not sent', $parts['pow-state__title'], 'The filter is told which state it words' );
	}

	public function test_anything_but_not_sent_draws_the_sending_state(): void {
		self::assertArrayHasKey( 'pow-state pow-state--sending', self::parts( $this->page( 'sent' ) ) );
	}
}
