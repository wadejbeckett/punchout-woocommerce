<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The buyer's purchasing system does the approving (0.4.23). The words the store owner reads about the returned cart
 * say where the cart went, not that it waits for an approval: the plugin's own description, the readme's description
 * and the "PunchOut order received" e-mail. The admin screens' connection approval (pending rows) is a different
 * thing and is not scanned; neither is the changelog, which records what earlier versions said.
 */
final class StoreWordingTest extends TestCase {
	private static function source( string $path ): string {
		$file = dirname( __DIR__, 2 ) . '/' . $path;
		self::assertTrue( is_file( $file ), $path );
		return (string) file_get_contents( $file );
	}

	public function test_the_plugin_description_says_where_the_cart_goes(): void {
		self::assertSame( 1, preg_match( '/^ \* Description:\s*(.+)$/m', self::source( 'punchout-woocommerce.php' ), $m ) );
		self::assertStringNotContainsString( 'approv', strtolower( $m[1] ) );
		self::assertStringContainsString( 'sends the cart to the buyer’s purchasing system', $m[1] );
	}

	public function test_the_readme_description_says_no_approval(): void {
		$readme = self::source( 'readme.txt' );
		$description = substr( $readme, 0, (int) strpos( $readme, '== Changelog ==' ) );
		self::assertNotSame( '', $description );
		self::assertSame( 0, preg_match( '/approv/i', $description, $m ), 'readme.txt says "' . ( $m[0] ?? '' ) . '"' );
		self::assertStringContainsString( 'Buyers send the cart to their purchasing system;', $description );
	}

	public function test_the_readme_introductions_say_no_approval(): void {
		foreach ( [ 'README.md', 'docs/README.md' ] as $path ) {
			$text = self::source( $path );
			$intro = substr( $text, 0, (int) strpos( $text, "\n## " ) ?: strlen( $text ) );
			self::assertSame( 0, preg_match( '/approv/i', $intro, $m ), $path . ' says "' . ( $m[0] ?? '' ) . '"' );
		}
	}

	public function test_the_store_e_mail_says_the_cart_was_sent_to_the_purchasing_system(): void {
		foreach ( [ 'templates/emails/pow-quote-received.php', 'templates/emails/plain/pow-quote-received.php', 'includes/Emails/QuoteReceived.php' ] as $path ) {
			$source = self::source( $path );
			self::assertSame( 0, preg_match( '/approv/i', $source, $m ), $path . ' says "' . ( $m[0] ?? '' ) . '"' );
		}
		foreach ( [ 'templates/emails/pow-quote-received.php', 'templates/emails/plain/pow-quote-received.php' ] as $path ) {
			self::assertStringContainsString( 'A cart from a buyer at %1$s has been sent to the buyer’s purchasing system. %2$s The order is waiting here as a Punchout Quote.', self::source( $path ), $path );
		}
	}
}
