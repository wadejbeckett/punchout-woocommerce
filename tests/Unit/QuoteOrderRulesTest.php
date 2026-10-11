<?php
/**
 * The rules a Punchout Quote order is built from: line args taken from
 * the POOM lines (so order and basket cannot disagree), the shipping
 * address resolution order, and the retention cutoff.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Orders\QuoteOrder;

final class QuoteOrderRulesTest extends TestCase {

	public function test_meta_keys_are_protected(): void {
		foreach ( [ QuoteOrder::META_POOM_XML, QuoteOrder::META_SESSION_ID, QuoteOrder::META_PARTNER_ID, QuoteOrder::META_PARTNER_NAME, QuoteOrder::META_DELIVERY_CODE, QuoteOrder::META_BUYER_IDENTITY, QuoteOrder::META_BUYER_NAME, QuoteOrder::META_BUYER_COOKIE ] as $key ) {
			self::assertStringStartsWith( '_', $key, 'A leading underscore is what makes order meta protected' );
		}

		self::assertSame( '_pow_poom_xml', QuoteOrder::META_POOM_XML );
		self::assertSame( '_pow_session_id', QuoteOrder::META_SESSION_ID );
		// ORDER meta, not the buyer-to-connection USER meta of the same name: historical quotes are read through it.
		self::assertSame( '_pow_partner_id', QuoteOrder::META_PARTNER_ID );
		self::assertSame( '_pow_partner_name', QuoteOrder::META_PARTNER_NAME );
		self::assertSame( '_pow_delivery_code', QuoteOrder::META_DELIVERY_CODE );
		self::assertSame( '_pow_buyer_identity', QuoteOrder::META_BUYER_IDENTITY );
		self::assertSame( '_pow_buyer_name', QuoteOrder::META_BUYER_NAME );
		self::assertSame( '_pow_buyer_cookie', QuoteOrder::META_BUYER_COOKIE );
	}

	/**
	 * One sentence, three shapes, used verbatim in the order note and on the
	 * admin order screen. The anonymous case is worded rather than blank,
	 * because "Bought by  ()" reads as a bug in the shop.
	 */
	public function test_the_bought_by_sentence_names_whoever_the_purchasing_system_named(): void {
		self::assertSame( 'Bought by Zoë Buyer (zoe@buyer.example.test) via PunchOut (Example Buyer Company)', QuoteOrder::bought_by( 'Zoë Buyer', 'zoe@buyer.example.test', 'Example Buyer Company' ) );
		self::assertSame( 'Bought by zoe@buyer.example.test via PunchOut (Example Buyer Company)', QuoteOrder::bought_by( '', 'zoe@buyer.example.test', 'Example Buyer Company' ) );
		self::assertSame( 'Bought by Zoë Buyer via PunchOut (Example Buyer Company)', QuoteOrder::bought_by( 'Zoë Buyer', '', 'Example Buyer Company' ) );
		self::assertSame( 'Bought by an unnamed buyer via PunchOut (Example Buyer Company); the purchasing system sent no name or e-mail', QuoteOrder::bought_by( '', '', 'Example Buyer Company' ) );
	}

	/**
	 * The sentence is stored verbatim in a WooCommerce order note, and an
	 * order note is rendered through kses. Anything shaped like an HTML tag
	 * is dropped there, silently and in the ordinary fully-identified case:
	 * `<jane@buyer.test>` parses as an element named `jane`, which is not on
	 * the allow-list, so the address disappears from the one screen that
	 * exists to name the buyer. The wording therefore brackets the address
	 * with parentheses and the sentence carries no tag-shaped span at all.
	 */
	public function test_the_bought_by_sentence_carries_nothing_a_kses_pass_would_swallow(): void {
		foreach (
			[
				QuoteOrder::bought_by( 'Jane Doe', 'jane.doe@buyer.example.com', 'Acme Mining' ),
				QuoteOrder::bought_by( '', 'jane.doe@buyer.example.com', 'Acme Mining' ),
				QuoteOrder::bought_by( 'Jane Doe', '', 'Acme Mining' ),
				QuoteOrder::bought_by( '', '', 'Acme Mining' ),
			] as $sentence
		) {
			self::assertSame( 0, preg_match( '/<[^>]*>/', $sentence ), 'a tag-shaped span in the sentence is deleted when the order note is rendered' );
			self::assertStringNotContainsString( '<', $sentence );
			self::assertStringNotContainsString( '>', $sentence );
		}

		$full = QuoteOrder::bought_by( 'Jane Doe', 'jane.doe@buyer.example.com', 'Acme Mining' );
		self::assertStringContainsString( 'jane.doe@buyer.example.com', $full, 'the address is the whole point of the line' );
		self::assertStringContainsString( '(jane.doe@buyer.example.com)', $full );
	}

	/**
	 * The shipped documents state this wording as a specification, and the
	 * code is the only place it is produced. They drifted apart once
	 * already, so the agreement is pinned here rather than left to review.
	 */
	public function test_the_shipped_documents_state_the_wording_the_code_produces(): void {
		$root = dirname( __DIR__, 2 );

		foreach ( [ '/docs/single-login-mode.md', '/README.md' ] as $relative ) {
			$prose = (string) file_get_contents( $root . $relative );
			self::assertStringContainsString( 'Bought by {name} ({email}) via PunchOut ({connection', str_replace( '{e-mail}', '{email}', $prose ), $relative . ' must state the parenthesised wording' );
			self::assertStringNotContainsString( 'Bought by {name} <', $prose, $relative . ' must not state the angle-bracket wording kses deletes' );
		}
	}

	public function test_retention_cutoff(): void {
		$now = 1788858000; // 2026-09-08 09:00:00 UTC (gmmktime( 9, 0, 0, 9, 8, 2026 )).

		self::assertSame( '2026-06-10 09:00:00', QuoteOrder::retention_cutoff( 90, $now ) );
		self::assertSame( '', QuoteOrder::retention_cutoff( 0, $now ), 'Zero days disables retention' );
		self::assertSame( '', QuoteOrder::retention_cutoff( -5, $now ) );
	}
}
