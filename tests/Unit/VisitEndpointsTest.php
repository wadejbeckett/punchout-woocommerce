<?php
/**
 * The connection's My Account endpoint list: what an administrator may type,
 * what is stored, and what can never be listed.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }

use PHPUnit\Framework\TestCase;
use POW\Account\IntegrationTab;
use POW\Account\VisitEndpoints;
use POW\Partners\Partner;

final class VisitEndpointsTest extends TestCase {

	/** The list the spec fixes, in its own words; the plugin's tab by its constant. */
	private const SPEC_HARD_DENY = [ '', 'orders', 'view-order', 'downloads', 'edit-address', 'edit-account', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'lost-password', 'customer-logout', IntegrationTab::ENDPOINT ];

	public function test_the_hard_deny_list_holds_every_shared_login_surface(): void {
		foreach ( self::SPEC_HARD_DENY as $endpoint ) {
			self::assertContains( $endpoint, VisitEndpoints::HARD_DENY, "'{$endpoint}' must be hard-denied" );
			self::assertFalse( VisitEndpoints::allows( [ $endpoint ], $endpoint ), "'{$endpoint}' never opens, even when listed" );
		}
		self::assertSame( 'punchout-integration', IntegrationTab::ENDPOINT );
		self::assertContains( 'dashboard', VisitEndpoints::HARD_DENY, "The account menu's name for the dashboard is denied with it" );
	}

	/**
	 * WooCommerce's checkout endpoints are in its endpoint map but have no
	 * account content, so on the account page they would show the dashboard.
	 * The form refuses them by name, and a row that names them reads back
	 * without them.
	 */
	public function test_the_checkout_endpoints_are_hard_denied_because_they_would_show_the_dashboard(): void {
		foreach ( [ 'order-pay', 'order-received' ] as $endpoint ) {
			self::assertContains( $endpoint, VisitEndpoints::HARD_DENY );
			self::assertFalse( VisitEndpoints::allows( [ $endpoint ], $endpoint ), "'{$endpoint}' never opens, even when listed" );
		}
		self::assertSame( [ 'order-pay', 'order-received' ], VisitEndpoints::parse( 'bulkorder,order-pay,order-received' )['rejected'] );
		self::assertSame( [ 'bulkorder' ], Partner::from_row( [ 'id' => 1, 'visit_endpoints' => 'order-pay,bulkorder,order-received' ] )->visit_endpoint_list() );
	}

	public function test_lowercase_slugs_are_accepted_in_order_without_duplicates(): void {
		$parsed = VisitEndpoints::parse( " bulkorder, purchase-lists ,purchase_list2,bulkorder,\nnew-list, " );

		self::assertSame( [ 'bulkorder', 'purchase-lists', 'purchase_list2', 'new-list' ], $parsed['endpoints'] );
		self::assertSame( [], $parsed['rejected'] );
		self::assertFalse( $parsed['too_long'] );
		self::assertSame( 'bulkorder,purchase-lists,purchase_list2,new-list', VisitEndpoints::normalise( " bulkorder, purchase-lists ,purchase_list2,bulkorder,\nnew-list, " ) );
		self::assertSame( [ 'endpoints' => [], 'rejected' => [], 'too_long' => false ], VisitEndpoints::parse( '' ), 'Empty is the default and is valid' );
	}

	public function test_anything_that_is_not_a_lowercase_slug_or_is_hard_denied_is_rejected_and_named(): void {
		$parsed = VisitEndpoints::parse( 'bulkorder,BulkOrder,orders,edit-account,-lead,bulk order,a/b,<b>,punchout-integration,dashboard,orders' );

		self::assertSame( [ 'bulkorder' ], $parsed['endpoints'] );
		self::assertSame( [ 'BulkOrder', 'orders', 'edit-account', '-lead', 'bulk order', 'a/b', '<b>', 'punchout-integration', 'dashboard' ], $parsed['rejected'], 'Each rejected entry is named once; a space inside an entry is not a separator' );
		self::assertSame( 'bulkorder', VisitEndpoints::normalise( 'bulkorder,BulkOrder,orders,edit-account,-lead,bulk order' ), 'The stored form keeps only accepted entries' );
	}

	public function test_a_list_longer_than_the_column_is_flagged_and_stored_to_the_last_whole_entry(): void {
		$entries = array_map( static fn( int $i ): string => 'endpoint-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ), range( 1, 30 ) );
		$parsed  = VisitEndpoints::parse( implode( ',', $entries ) );

		self::assertTrue( $parsed['too_long'] );
		$stored = VisitEndpoints::normalise( implode( ',', $entries ) );
		self::assertTrue( strlen( $stored ) <= VisitEndpoints::MAX_LENGTH, "The stored list fits the column" );
		self::assertSame( array_slice( $entries, 0, count( explode( ',', $stored ) ) ), explode( ',', $stored ), 'Cut at a whole entry, never mid-slug' );
	}

	public function test_a_partner_row_reads_back_normalised_and_an_older_row_lists_nothing(): void {
		self::assertSame( [], Partner::from_row( [ 'id' => 1 ] )->visit_endpoint_list(), 'A row from before schema 8 opens nothing' );
		self::assertSame( '', Partner::from_row( [ 'id' => 1 ] )->visit_endpoints );

		$partner = Partner::from_row( [ 'id' => 1, 'visit_endpoints' => 'bulkorder, orders,EDIT,purchase-lists' ] );
		self::assertSame( 'bulkorder,purchase-lists', $partner->visit_endpoints );
		self::assertSame( [ 'bulkorder', 'purchase-lists' ], $partner->visit_endpoint_list() );
		self::assertTrue( VisitEndpoints::allows( $partner->visit_endpoint_list(), 'bulkorder' ) );
		self::assertFalse( VisitEndpoints::allows( $partner->visit_endpoint_list(), 'subaccounts' ) );
	}

	/**
	 * Every customer difference is a column: no hook widens or narrows the
	 * list, and nothing asks which other plugin is installed.
	 */
	public function test_the_list_is_a_column_with_no_hook_and_no_plugin_branch(): void {
		$root = dirname( __DIR__, 2 );
		self::assertStringNotContainsString( 'apply_filters', (string) file_get_contents( $root . '/includes/Account/VisitEndpoints.php' ) );
		foreach ( [ 'includes/Account/VisitEndpoints.php', 'includes/RouteGuard.php' ] as $file ) {
			$source = (string) file_get_contents( $root . '/' . $file );
			foreach ( [ "apply_filters( 'pow_visit", "do_action( 'pow_", 'is_plugin_active', 'pow_route_guard' ] as $needle ) {
				self::assertStringNotContainsString( $needle, $source, $file . ' names ' . $needle );
			}
		}
	}
}
