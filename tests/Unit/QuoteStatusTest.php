<?php
/**
 * The punchout-quote order status: prefixed correctly for WooCommerce,
 * offered as a bulk action WooCommerce already knows how to service, and
 * kept out of Analytics revenue.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Orders\Status;

final class QuoteStatusTest extends TestCase {

	public function test_slug_and_internal_slug(): void {
		self::assertSame( 'punchout-quote', Status::SLUG );
		self::assertSame( 'wc-punchout-quote', Status::INTERNAL );
		self::assertSame( 'Punchout Quote', Status::label() );
	}

	/**
	 * wc_get_order_statuses() is keyed on the wc- prefixed slug
	 * (wc-order-functions.php:104), and both list tables gate the generic
	 * mark_<slug> bulk handler on isset( $statuses['wc-' . $slug] ).
	 */
	public function test_added_to_order_statuses_under_the_prefixed_key(): void {
		$statuses = Status::add_to_order_statuses( [ 'wc-pending' => 'Pending payment' ] );

		self::assertSame( 'Punchout Quote', $statuses[ Status::INTERNAL ] );
		self::assertArrayHasKey( 'wc-pending', $statuses );
	}

	public function test_post_status_args_are_private_and_countable(): void {
		$args = Status::post_status_args();

		self::assertFalse( $args['public'] );
		self::assertTrue( $args['exclude_from_search'] );
		self::assertTrue( $args['show_in_admin_all_list'] );
		self::assertTrue( $args['show_in_admin_status_list'] );
		self::assertTrue( $args['internal'] );
	}

	/**
	 * The filter carries UNPREFIXED slugs: WooCommerce seeds it from the
	 * woocommerce_excluded_report_order_statuses option, whose shipped
	 * default is [ 'pending', 'failed', 'cancelled' ].
	 */
	public function test_excluded_from_analytics_once(): void {
		$excluded = Status::exclude_from_analytics( [ 'auto-draft', 'trash', 'pending', 'failed', 'cancelled' ] );

		self::assertContains( Status::SLUG, $excluded );
		self::assertSame( 1, count( array_keys( $excluded, Status::SLUG, true ) ) );
		self::assertSame( $excluded, Status::exclude_from_analytics( $excluded ) );
	}

	public function test_bulk_action_uses_woocommerce_mark_convention(): void {
		$actions = Status::add_bulk_action( [ 'mark_processing' => 'Change status to processing' ] );

		self::assertArrayHasKey( 'mark_' . Status::SLUG, $actions );
		self::assertArrayHasKey( 'mark_processing', $actions );
	}
}
