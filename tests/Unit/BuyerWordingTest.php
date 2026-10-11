<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The buyer's purchasing system does the approving, not the shop (0.4.23). No page, control or script a buyer
 * meets in a visit says "approval": a source scan over every buyer-facing template and script. The store's own e-mail and the admin screens are not buyer-facing and are not scanned.
 */
final class BuyerWordingTest extends TestCase {
	/** @return array<string, string> Path relative to the plugin => source. */
	private static function buyer_facing(): array {
		$root = dirname( __DIR__, 2 );
		$files = array_merge( glob( $root . '/templates/*.php' ) ?: [], glob( $root . '/templates/account/*.php' ) ?: [], glob( $root . '/assets/js/*.js' ) ?: [] );
		sort( $files );
		$sources = [];
		foreach ( $files as $file ) { $sources[ substr( $file, strlen( $root ) + 1 ) ] = (string) file_get_contents( $file ); }
		return $sources;
	}

	public function test_the_scan_reaches_the_handoff_the_state_page_and_the_visit_controls(): void {
		$scanned = array_keys( self::buyer_facing() );
		foreach ( [ 'templates/handoff.php', 'templates/state.php', 'templates/abandon-button.php', 'assets/js/leave-confirmation.js', 'assets/js/start-link.js' ] as $file ) {
			self::assertContains( $file, $scanned, $file );
		}
	}

	public function test_no_buyer_facing_template_or_script_says_approval(): void {
		foreach ( self::buyer_facing() as $file => $source ) {
			self::assertSame( 0, preg_match( '/approv/i', $source, $m ), $file . ' says "' . ( $m[0] ?? '' ) . '"' );
		}
	}

}
