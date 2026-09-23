<?php
/**
 * The connection's My Account list: what the form may send, what is stored,
 * how the form groups the pages, and the two rules no setting changes.
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

	/** WooCommerce's own endpoint map, as WC_Query::init_query_vars() builds it. */
	private const WOOCOMMERCE_MAP = [
		'order-pay', 'order-received', 'orders', 'view-order', 'downloads', 'edit-account', 'edit-address', 'payment-methods',
		'lost-password', 'customer-logout', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method',
	];

	public function test_the_dashboard_is_the_account_page_itself_and_a_new_connection_starts_with_it(): void {
		self::assertSame( 'dashboard', VisitEndpoints::DASHBOARD );
		self::assertSame( VisitEndpoints::DASHBOARD, VisitEndpoints::NEW_CONNECTION, 'A new connection opens the dashboard and nothing else' );
		self::assertTrue( VisitEndpoints::allows( [ 'dashboard' ], '' ), 'The account page with no endpoint opens when the dashboard is listed' );
		self::assertFalse( VisitEndpoints::allows( [ 'bulkorder' ], '' ), 'and stays closed when it is not' );
		self::assertFalse( VisitEndpoints::allows( [], '' ) );
		self::assertFalse( VisitEndpoints::allows( [ 'dashboard' ], 'orders' ), 'The dashboard does not open the pages it links to' );
		self::assertSame( [ 'dashboard', 'bulkorder' ], VisitEndpoints::parse( 'dashboard,bulkorder' )['endpoints'] );
	}

	/** The spec's rule for the defaults: WooCommerce's own set is exactly its default endpoint map. */
	public function test_woocommerce_own_set_is_its_default_endpoint_map(): void {
		$own = VisitEndpoints::WOOCOMMERCE;
		sort( $own );
		$map = self::WOOCOMMERCE_MAP;
		sort( $map );
		self::assertSame( $map, $own );
		self::assertSame( [], array_diff( VisitEndpoints::PAYMENT, VisitEndpoints::WOOCOMMERCE ) );
		self::assertSame( [ 'customer-logout', 'lost-password', 'order-pay', 'order-received', IntegrationTab::ENDPOINT ], VisitEndpoints::NEVER_OPEN );
		self::assertSame( [ 'view-order' ], VisitEndpoints::FOLDED, 'A single order\'s page is folded into the Orders row' );
		self::assertSame( 'punchout-integration', IntegrationTab::ENDPOINT );
	}

	/**
	 * Every page can be stored, the shared login's own included; the form
	 * says what sharing it means. Two groups never reach a visit's list: the
	 * pages that never open in a visit (logout, lost password, order-pay,
	 * order-received, the integration tab), and the payment-method pages
	 * unless the exit policy allows WooCommerce's checkout, which no
	 * connection's does.
	 */
	public function test_every_page_can_be_stored_but_pages_that_never_open_are_not_listed(): void {
		$all    = array_merge( [ 'dashboard' ], VisitEndpoints::WOOCOMMERCE, [ IntegrationTab::ENDPOINT ] );
		$parsed = VisitEndpoints::parse( implode( ',', $all ) );

		self::assertSame( $all, $parsed['endpoints'] );
		self::assertSame( [], $parsed['rejected'] );
		self::assertFalse( $parsed['too_long'], 'Every WooCommerce page, the dashboard and the tab fit the column together' );
		self::assertSame( [ 'dashboard', 'orders', 'view-order', 'downloads', 'edit-address', 'edit-account' ], VisitEndpoints::listed( implode( ',', $all ) ), 'Without native checkout neither the payment pages nor the pages that never open are in the list a visit reads' );
		self::assertSame( array_values( array_diff( $all, VisitEndpoints::NEVER_OPEN ) ), VisitEndpoints::listed( implode( ',', $all ), true ), 'With native checkout the payment pages are listed; the pages that never open still are not' );

		$partner = Partner::from_row( [ 'id' => 1, 'visit_endpoints' => 'dashboard,payment-methods,orders,add-payment-method,customer-logout,punchout-integration', 'exit_policy' => 'punchout_and_other' ] );
		self::assertFalse( $partner->allows_native_checkout(), 'Every connection reads as punchout-only' );
		self::assertSame( 'dashboard,payment-methods,orders,add-payment-method,customer-logout,punchout-integration', $partner->visit_endpoints, 'The stored value is kept as written' );
		self::assertSame( [ 'dashboard', 'orders' ], $partner->visit_endpoint_list(), 'but a visit never opens a payment page, logout or the integration tab' );
	}

	public function test_lowercase_names_are_accepted_in_order_without_duplicates(): void {
		$parsed = VisitEndpoints::parse( " bulkorder, purchase-lists ,purchase_list2,bulkorder,\nnew-list, " );

		self::assertSame( [ 'bulkorder', 'purchase-lists', 'purchase_list2', 'new-list' ], $parsed['endpoints'] );
		self::assertSame( [], $parsed['rejected'] );
		self::assertFalse( $parsed['too_long'] );
		self::assertSame( 'bulkorder,purchase-lists,purchase_list2,new-list', VisitEndpoints::normalise( " bulkorder, purchase-lists ,purchase_list2,bulkorder,\nnew-list, " ) );
		self::assertSame( [ 'endpoints' => [], 'rejected' => [], 'too_long' => false ], VisitEndpoints::parse( '' ), 'Empty is valid and opens nothing' );
	}

	public function test_anything_that_is_not_a_lowercase_name_is_rejected_and_named(): void {
		$parsed = VisitEndpoints::parse( 'bulkorder,BulkOrder,orders,-lead,bulk order,a/b,<b>,dashboard,orders' );

		self::assertSame( [ 'bulkorder', 'orders', 'dashboard' ], $parsed['endpoints'] );
		self::assertSame( [ 'BulkOrder', '-lead', 'bulk order', 'a/b', '<b>' ], $parsed['rejected'], 'Each rejected entry is named once; a space inside an entry is not a separator' );
		self::assertSame( 'bulkorder,orders', VisitEndpoints::normalise( 'bulkorder,BulkOrder,orders,-lead,bulk order' ), 'The stored form keeps only accepted entries' );
	}

	/** The form's checkboxes arrive one entry per element; an element is never split, and anything that is not a string is skipped. */
	public function test_checkbox_entries_are_read_one_per_element(): void {
		$parsed = VisitEndpoints::parse( [ '', 'dashboard', ' bulkorder ', 'a,b', 5, [ 'orders' ], null, 'bulkorder', 'Orders' ] );

		self::assertSame( [ 'dashboard', 'bulkorder' ], $parsed['endpoints'] );
		self::assertSame( [ 'a,b', 'Orders' ], $parsed['rejected'] );
		self::assertSame( [ 'endpoints' => [], 'rejected' => [], 'too_long' => false ], VisitEndpoints::parse( [ '' ] ), 'The hidden empty entry alone is an empty list' );
	}

	public function test_a_list_longer_than_the_column_is_flagged_and_stored_to_the_last_whole_entry(): void {
		$entries = array_map( static fn( int $i ): string => 'endpoint-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ), range( 1, 30 ) );
		$parsed  = VisitEndpoints::parse( $entries );

		self::assertTrue( $parsed['too_long'] );
		$stored = VisitEndpoints::normalise( implode( ',', $entries ) );
		self::assertTrue( strlen( $stored ) <= VisitEndpoints::MAX_LENGTH, 'The stored list fits the column' );
		self::assertSame( array_slice( $entries, 0, count( explode( ',', $stored ) ) ), explode( ',', $stored ), 'Cut at a whole entry, never mid-name' );
	}

	/** A 0.4.5 row reads back exactly as stored: no dashboard is added, nothing is dropped. */
	public function test_an_older_row_reads_back_unchanged_and_opens_no_dashboard(): void {
		self::assertSame( [], Partner::from_row( [ 'id' => 1 ] )->visit_endpoint_list(), 'A row from before schema 8 opens nothing' );
		self::assertSame( '', Partner::from_row( [ 'id' => 1 ] )->visit_endpoints );

		$partner = Partner::from_row( [ 'id' => 1, 'visit_endpoints' => 'bulkorder,purchase-lists,purchase-list,new-list' ] );
		self::assertSame( 'bulkorder,purchase-lists,purchase-list,new-list', $partner->visit_endpoints );
		self::assertSame( [ 'bulkorder', 'purchase-lists', 'purchase-list', 'new-list' ], $partner->visit_endpoint_list() );
		self::assertTrue( VisitEndpoints::allows( $partner->visit_endpoint_list(), 'bulkorder' ) );
		self::assertFalse( VisitEndpoints::allows( $partner->visit_endpoint_list(), '' ), 'Without dashboard in the row the account page stays closed' );
		self::assertFalse( VisitEndpoints::allows( $partner->visit_endpoint_list(), 'subaccounts' ) );

		$edited = Partner::from_row( [ 'id' => 1, 'visit_endpoints' => 'bulkorder, orders,EDIT,purchase-lists' ] );
		self::assertSame( 'bulkorder,orders,purchase-lists', $edited->visit_endpoints, 'A hand-edited row keeps only well-formed names' );
	}

	/**
	 * The form's groups: WooCommerce's own pages behind the dashboard in a
	 * fixed order (a single order's page folded into Orders), then pages a
	 * WooCommerce feature added, the plugin's tab, every other registered
	 * page alphabetically, and listed entries not registered right now.
	 */
	public function test_the_form_groups_woocommerce_pages_first_then_other_plugins_then_stored_entries(): void {
		$registered = array_merge( [ 'subaccounts', 'bulkorder', 'order-withdrawal' ], self::WOOCOMMERCE_MAP, [ IntegrationTab::ENDPOINT, 'purchase-lists', 'Bad Name', 'dashboard', 'bulkorder', 'new-list' ] );
		$groups     = VisitEndpoints::groups( $registered, [ 'dashboard', 'bulkorder', 'late-page', 'orders', 'company-credit' ], [ 'order-withdrawal', 'orders', 'not-registered', 'dashboard' ] );

		self::assertSame( array_merge( [ 'dashboard' ], array_values( array_diff( VisitEndpoints::WOOCOMMERCE, [ 'view-order' ] ) ), [ 'order-withdrawal' ] ), $groups['woocommerce'], 'view-order has no row of its own; a registered page WooCommerce provides comes after its standard set' );
		self::assertSame( [ IntegrationTab::ENDPOINT ], $groups['plugin'] );
		self::assertSame( [ 'bulkorder', 'new-list', 'purchase-lists', 'subaccounts' ], $groups['other'], 'Anything else, once, alphabetically; a name that could not be stored, and a page registered as dashboard, are left out' );
		self::assertSame( [ 'late-page', 'company-credit' ], $groups['stored'], 'Listed but not registered keeps a row of its own, in stored order' );

		$bare = VisitEndpoints::groups( [], [] );
		self::assertSame( [ 'woocommerce' => [ 'dashboard' ], 'plugin' => [], 'other' => [], 'stored' => [] ], $bare, 'Without WooCommerce the form still offers the dashboard' );
		self::assertSame( [ 'dashboard', 'orders' ], VisitEndpoints::groups( [ 'orders' ], [] )['woocommerce'], 'Only WooCommerce pages that are registered are offered' );
		self::assertSame( [ 'order-withdrawal' ], VisitEndpoints::groups( [ 'order-withdrawal' ], [] )['other'], 'Without proof that WooCommerce provides it, a page is shown with the other plugins\' pages' );
	}

	/**
	 * Every customer difference is a column: no hook widens or narrows the
	 * list, nothing asks which other plugin is installed, and no other
	 * plugin's page is named in the shipped code.
	 */
	public function test_the_list_is_a_column_with_no_hook_and_no_plugin_branch(): void {
		$root = dirname( __DIR__, 2 );
		self::assertStringNotContainsString( 'apply_filters', (string) file_get_contents( $root . '/includes/Account/VisitEndpoints.php' ) );
		foreach ( [ 'includes/Account/VisitEndpoints.php', 'includes/Account/VisitDashboard.php', 'templates/account/dashboard.php', 'includes/RouteGuard.php', 'includes/Admin/Page.php', 'includes/Admin/Actions.php' ] as $file ) {
			$source = (string) file_get_contents( $root . '/' . $file );
			foreach ( [ "apply_filters( 'pow_visit", "do_action( 'pow_", 'is_plugin_active', 'pow_route_guard', 'bulkorder', 'subaccount', 'purchase-list' ] as $needle ) {
				self::assertStringNotContainsString( $needle, $source, $file . ' names ' . $needle );
			}
		}
	}
}
