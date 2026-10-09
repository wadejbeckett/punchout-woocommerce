<?php
/**
 * The state page (0.5.0, ticket 77). A visit ends on a page the site chooses in the settings: one shortcode,
 * [punchout_state], draws Sending, Sent, Not sent, Link expired and Session ended inside the theme, with stable classes
 * (pow-state plus one modifier, and the children pow-state__title, __text, __actions and __button) that the site
 * styles. Titles and text are neutral translatable defaults a layout overrides as shortcode attributes; the button's
 * label is the one setting. The page carries no cXML and no secret, and its no-store, noindex and nosniff headers go
 * out on template_redirect, before anything renders. Without a chosen page there is no address: callers keep their
 * plain pages.
 *
 * @package POW
 */

declare( strict_types = 1 );

namespace POW\Http {
	// The CLI runner has printed long before a test runs, so the native answer would always be "sent".
	if ( ! function_exists( __NAMESPACE__ . '\headers_sent' ) ) {
		function headers_sent(): bool { return isset( $GLOBALS['pow_state_page_test'] ) ? (bool) ( $GLOBALS['pow_state_page_test']['headers_sent'] ?? false ) : \headers_sent(); }
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use POW\Http\StatePage;
	use POW\Settings;

	final class StatePageTest extends TestCase {
		private const PAGE = 314;
		private const GLOBALS = [ 'pow_test_options', 'pow_test_posts', 'pow_test_query', 'pow_test_filters', 'pow_test_setup_io', 'pow_test_nocache_headers', 'pow_state_page_test' ];
		private array $saved = [];
		private array $get = [];

		protected function setUp(): void {
			foreach ( self::GLOBALS as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$this->get = $_GET;
			$_GET      = [];
			$GLOBALS['pow_state_page_test'] = [];
			$GLOBALS['pow_test_posts'][ self::PAGE ] = [ 'status' => 'publish', 'permalink' => 'https://shop.example.test/?page_id=314', 'content' => '[punchout_state sent_title="Done"]' ];
		}

		protected function tearDown(): void {
			$_GET = $this->get;
			foreach ( $this->saved as $key => [ $existed, $value ] ) {
				if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
			}
		}

		private function page( array $saved = [ 'state_page_id' => self::PAGE ] ): StatePage {
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = $saved;
			return new StatePage( new Settings() );
		}

		/** @return array<string, string> class => text of each element carrying a pow-state class. */
		private static function parts( string $html ): array {
			preg_match_all( '/<(\w+)[^>]*class="(pow-state[^"]*)"[^>]*>([^<]*)/', $html, $found, PREG_SET_ORDER );
			$parts = [];
			foreach ( $found as [ , , $class, $text ] ) { $parts[ $class ] = trim( html_entity_decode( $text, ENT_QUOTES ) ); }
			return $parts;
		}

		public function test_each_state_has_its_modifier_title_and_text_and_nothing_else(): void {
			$expected = [
				'sending'  => [ 'Sending your cart', 'Taking you back to your purchasing system.' ],
				'sent'     => [ 'Sent', 'Your cart has been sent to your purchasing system. Return there to continue.' ],
				'not-sent' => [ 'Not sent', 'Your cart was not sent to your purchasing system. Return there to continue.' ],
				'expired'  => [ 'Link expired', 'This catalog link has expired. Return to your purchasing system and open the catalog again.' ],
				'ended'    => [ 'Session ended', 'Your catalog session has ended. Return to your purchasing system and open the catalog again.' ],
			];
			self::assertSame( array_keys( $expected ), StatePage::STATES );
			$page = $this->page();
			foreach ( $expected as $state => [ $title, $text ] ) {
				$_GET = [ StatePage::QUERY => $state ];
				self::assertSame( [ 'pow-state pow-state--' . $state => '', 'pow-state__title' => $title, 'pow-state__text' => $text ], self::parts( $page->shortcode( [] ) ), $state );
			}
		}

		public function test_no_default_text_names_approval_tax_or_a_price(): void {
			$page = $this->page();
			foreach ( StatePage::STATES as $state ) {
				$_GET = [ StatePage::QUERY => $state ];
				self::assertSame( 0, preg_match( '/approv|vat|tax|price|estimate|delivery/i', strip_tags( $page->shortcode( [] ) ) ), $state );
			}
		}

		public function test_titles_and_text_are_shortcode_attributes_escaped_and_an_empty_one_is_left_out(): void {
			$page = $this->page();
			$_GET = [ StatePage::QUERY => 'not-sent' ];
			$html = $page->shortcode( [ 'not_sent_title' => '<b>Not sent</b>', 'not_sent_text' => '', 'sent_title' => 'Ignored here' ] );
			self::assertSame( [ 'pow-state pow-state--not-sent' => '', 'pow-state__title' => '<b>Not sent</b>' ], self::parts( $html ) );
			self::assertStringNotContainsString( '<b>', $html, 'Escaped' );
			self::assertStringNotContainsString( 'pow-state__text', $html, 'An empty text is not drawn' );
		}

		public function test_the_address_names_the_state_and_an_unknown_or_missing_one_shows_the_attribute_or_ended(): void {
			$page = $this->page();
			foreach ( [ [], [ StatePage::QUERY => 'approved' ], [ StatePage::QUERY => [ 'sent' ] ] ] as $query ) {
				$_GET = $query;
				self::assertStringContainsString( 'pow-state--ended"', $page->shortcode( '' ), 'Nothing a link can carry shows anything but the five states' );
				self::assertStringContainsString( 'pow-state--sending"', $page->shortcode( [ 'state' => 'sending' ] ), 'A layout can preview a state' );
			}
			$_GET = [ StatePage::QUERY => 'expired' ];
			self::assertStringContainsString( 'pow-state--expired"', $page->shortcode( [ 'state' => 'sending' ] ), 'The address wins over the preview attribute' );
		}

		public function test_the_button_shows_only_with_somewhere_to_go_and_carries_the_setting(): void {
			$_GET = [ StatePage::QUERY => 'sent' ];
			self::assertStringNotContainsString( 'pow-state__actions', $this->page()->shortcode( [] ) );
			self::assertStringNotContainsString( 'pow-state__button', $this->page()->shortcode( [ 'button_url' => 'javascript:alert(1)' ] ), 'Only a web address' );
			$html = $this->page( [ 'state_page_id' => self::PAGE, 'state_button_label' => 'Continue' ] )->shortcode( [ 'button_url' => 'https://shop.example.test/contact/' ] );
			self::assertMatchesRegularExpression( '#<div class="pow-state__actions"><a class="pow-state__button" href="https://shop\.example\.test/contact/">Continue</a></div>#', $html );
		}

		public function test_the_state_address_is_the_chosen_published_page_with_the_state(): void {
			self::assertSame( 'https://shop.example.test/?page_id=314&pow_state=sent', $this->page()->url( 'sent' ) );
			$GLOBALS['pow_test_posts'][ self::PAGE ]['permalink'] = 'https://shop.example.test/visit-ended/';
			self::assertSame( 'https://shop.example.test/visit-ended/?pow_state=not-sent', $this->page()->url( 'not-sent' ) );
			self::assertNull( $this->page()->url( 'approved' ), 'Only the five states' );
			self::assertNull( $this->page( [] )->url( 'sent' ), 'No page chosen: the caller keeps its plain page' );
			$GLOBALS['pow_test_posts'][ self::PAGE ]['status'] = 'draft';
			self::assertNull( $this->page()->url( 'sent' ), 'A page nobody can open is never used' );
			self::assertSame( 0, $this->page()->page_id() );
		}

		public function test_the_static_link_reads_the_saved_settings_each_time(): void {
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [];
			self::assertNull( StatePage::link( 'ended' ) );
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'state_page_id' => self::PAGE ];
			self::assertSame( 'https://shop.example.test/?page_id=314&pow_state=ended', StatePage::link( 'ended' ) );
		}

		public function test_a_query_value_is_replaced_and_the_rest_of_the_address_kept_byte_for_byte(): void {
			self::assertSame( 'https://a.test/p/?x=%2F&pow_state=sent#top', StatePage::with_query( 'https://a.test/p/?pow_state=old&x=%2F#top', 'pow_state', 'sent' ) );
			self::assertSame( 'https://a.test/?pow_statex=1&pow_state=ended', StatePage::with_query( 'https://a.test/?pow_statex=1&pow_state', 'pow_state', 'ended' ) );
		}

		public function test_no_store_noindex_and_nosniff_go_out_on_template_redirect_for_the_chosen_page_only(): void {
			$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
			$page = $this->page();
			$GLOBALS['pow_test_query'] = [ 'page' => 99 ];
			$page->send_headers();
			self::assertSame( [], $GLOBALS['pow_test_setup_io']['headers'], 'Another page is left alone' );
			self::assertFalse( array_key_exists( 'pow_test_nocache_headers', $GLOBALS ) );

			$GLOBALS['pow_test_query'] = [ 'page' => self::PAGE ];
			$page->send_headers();
			self::assertSame( [ 'Cache-Control: private, no-store', 'X-Robots-Tag: noindex, nofollow', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: no-referrer' ], $GLOBALS['pow_test_setup_io']['headers'] );
			self::assertSame( 1, $GLOBALS['pow_test_nocache_headers'] );
			self::assertTrue( defined( 'DONOTCACHEPAGE' ) );
			self::assertSame( [ 'noindex' => true, 'nofollow' => true ], $page->robots( [] ) );
		}

		public function test_any_page_drawing_the_shortcode_gets_the_same_headers(): void {
			$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
			$GLOBALS['pow_test_posts'][77] = [ 'status' => 'publish', 'content' => "<!-- wp:shortcode -->\n[punchout_state]\n<!-- /wp:shortcode -->" ];
			$GLOBALS['pow_test_query'] = [ 'page' => 77 ];
			$this->page( [] )->send_headers();
			self::assertContains( 'Cache-Control: private, no-store', $GLOBALS['pow_test_setup_io']['headers'] );
			$GLOBALS['pow_test_posts'][77]['content'] = '[punchout_state_of_mind]';
			self::assertFalse( $this->page( [] )->is_state_request() );
			self::assertSame( [ 'follow' => true ], $this->page( [] )->robots( [ 'follow' => true ] ) );
		}

		public function test_after_output_has_started_only_the_cache_rules_are_asked_for(): void {
			$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
			$GLOBALS['pow_state_page_test']['headers_sent'] = true;
			$GLOBALS['pow_test_query'] = [ 'page' => self::PAGE ];
			$this->page()->send_headers();
			self::assertSame( [], $GLOBALS['pow_test_setup_io']['headers'] );
			self::assertSame( 1, $GLOBALS['pow_test_nocache_headers'] );
		}
	}
}
