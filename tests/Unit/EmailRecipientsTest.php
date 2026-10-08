<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Emails\Recipients;

require_once dirname( __DIR__, 2 ) . '/includes/Emails/Recipients.php';

/** Recipient rules for the "PunchOut order received" e-mail (0.4.13). */
final class EmailRecipientsTest extends TestCase {
	public function test_a_comma_separated_setting_becomes_a_clean_list(): void {
		self::assertSame( [ 'warren@example.com', 'sales@example.com' ], Recipients::resolve( ' Warren@example.com, sales@example.com ,, ', 'admin@example.com' ) );
	}

	public function test_an_empty_setting_falls_back_to_the_admin_address(): void {
		self::assertSame( [ 'admin@example.com' ], Recipients::resolve( '', 'Admin@example.com' ) );
	}

	public function test_invalid_addresses_are_dropped_and_duplicates_collapse(): void {
		self::assertSame( [ 'a@example.com' ], Recipients::resolve( 'a@example.com, not-an-address, A@example.com', 'admin@example.com' ) );
	}

	public function test_nothing_valid_anywhere_means_no_recipient(): void {
		self::assertSame( [], Recipients::resolve( 'nope', '' ) );
	}
}

/** The Reply-To setting of the "PunchOut order received" e-mail (0.4.14). */
final class EmailReplyToTest extends TestCase {
	public function test_a_valid_setting_becomes_the_reply_to_address(): void {
		self::assertSame( 'warren@example.com', Recipients::reply_to( ' Warren@example.com ' ) );
		self::assertSame( '', Recipients::reply_to( '' ) );
		self::assertSame( '', Recipients::reply_to( 'not an address' ) );
		self::assertSame( '', Recipients::reply_to( 'a@example.com, b@example.com' ) );
	}

	public function test_the_reply_to_replaces_the_sender_line_woocommerce_writes(): void {
		$headers = "Content-Type: text/html; charset=UTF-8\r\nReply-to: Shop <shop@example.com>\r\n";
		self::assertSame( "Content-Type: text/html; charset=UTF-8\r\nReply-to: warren@example.com\r\n", Recipients::with_reply_to( $headers, 'warren@example.com' ) );
	}

	public function test_a_blank_reply_to_leaves_the_headers_alone(): void {
		$headers = "Content-Type: text/html; charset=UTF-8\r\nReply-to: Shop <shop@example.com>\r\n";
		self::assertSame( $headers, Recipients::with_reply_to( $headers, '' ) );
	}

	public function test_headers_without_a_reply_to_line_gain_one(): void {
		self::assertSame( "Content-Type: text/plain\r\nReply-to: warren@example.com\r\n", Recipients::with_reply_to( "Content-Type: text/plain\r\n", 'warren@example.com' ) );
	}
}
