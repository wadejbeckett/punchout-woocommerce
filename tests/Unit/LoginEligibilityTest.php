<?php
/**
 * Which accounts may be a connection's login (finding PO-01, 0.4.9).
 *
 * Every buyer a purchasing system authorises is signed in as the bound
 * account on a token that came from that system, so the account must be an
 * ordinary shopper: no shop administration, and no content authoring either
 * (a Contributor, Author or Editor can write and publish through the native
 * REST API). The test is capability-based, never a role name, so a custom or
 * mixed role is judged by what it can do.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\Registry;

final class LoginEligibilityTest extends TestCase {

	/** @param list<string> $roles @param list<string> $caps */
	private static function user( array $roles, array $caps ): object {
		$all = [];
		foreach ( $roles as $role ) { $all[ $role ] = true; }
		foreach ( $caps as $cap ) { $all[ $cap ] = true; }
		return (object) [ 'ID' => 5, 'roles' => $roles, 'allcaps' => $all ];
	}

	public function test_an_ordinary_customer_is_eligible(): void {
		$customer = self::user( [ 'customer' ], [ 'read', 'level_0' ] );
		self::assertFalse( Registry::privileged( $customer ) );
		self::assertTrue( Registry::eligible_login( $customer ) );
	}

	public function test_a_customer_with_a_harmless_extra_role_stays_eligible(): void {
		$customer = self::user( [ 'customer', 'b2b_buyer' ], [ 'read' ] );
		self::assertTrue( Registry::eligible_login( $customer ), 'A role name that grants nothing is not authority.' );
	}

	/** @return array<string, array{list<string>, list<string>}> */
	public static function authoring_accounts(): array {
		return [
			'contributor'               => [ [ 'contributor' ], [ 'read', 'edit_posts', 'delete_posts', 'level_1', 'level_0' ] ],
			'author'                    => [ [ 'author' ], [ 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'upload_files', 'level_2' ] ],
			'editor'                    => [ [ 'editor' ], [ 'read', 'edit_posts', 'edit_others_posts', 'edit_pages', 'publish_pages', 'moderate_comments', 'unfiltered_html', 'level_7' ] ],
			'custom post type author'   => [ [ 'catalogue_writer' ], [ 'read', 'edit_products' ] ],
			'unknown management grant'  => [ [ 'customer' ], [ 'read', 'manage_sales_territories' ] ],
			'upload only'               => [ [ 'customer' ], [ 'read', 'upload_files' ] ],
			'woocommerce dashboard'     => [ [ 'customer' ], [ 'read', 'view_admin_dashboard' ] ],
			'legacy user level'         => [ [ 'customer' ], [ 'read', 'level_1' ] ],
			'mixed customer and author' => [ [ 'customer', 'author' ], [ 'read', 'publish_posts' ] ],
		];
	}

	public function test_an_account_that_can_author_or_administer_is_not_eligible(): void {
		foreach ( self::authoring_accounts() as $name => [ $roles, $caps ] ) {
			self::assertTrue( Registry::privileged( self::user( $roles, $caps ) ), $name . ' is privileged.' );
			self::assertFalse( Registry::eligible_login( self::user( $roles, $caps ) ), $name . ' must not be a connection login.' );
		}
	}

	public function test_a_missing_or_unreadable_account_is_not_eligible(): void {
		self::assertFalse( Registry::eligible_login( false ) );
		self::assertFalse( Registry::eligible_login( self::user( [ 'customer' ], [] ) ), 'An account that cannot read cannot shop.' );
	}

	public function test_the_named_list_carries_the_native_authoring_primitives(): void {
		foreach ( [ 'edit_posts', 'upload_files', 'publish_posts', 'edit_pages', 'unfiltered_html', 'view_admin_dashboard' ] as $cap ) {
			self::assertContains( $cap, Registry::PRIVILEGED_CAPABILITIES, $cap . ' is checked through user_can() so filter grants count.' );
		}
	}
}
