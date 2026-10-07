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
