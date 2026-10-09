<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Addresses\ReviewChrome;

require_once dirname( __DIR__, 2 ) . '/includes/Addresses/ReviewChrome.php';

/** The theme-chrome rule for the delivery review (0.4.12). */
final class ReviewChromeTest extends TestCase {
	public function test_a_get_in_a_classic_theme_is_drawn_inside_the_theme(): void {
		self::assertTrue( ReviewChrome::decide( 'GET', false, true ) );
		self::assertTrue( ReviewChrome::decide( 'get', false, true ) );
	}

	public function test_a_post_keeps_the_dedicated_document(): void {
		self::assertFalse( ReviewChrome::decide( 'POST', false, true ) );
	}

	public function test_a_block_theme_keeps_the_dedicated_document(): void {
		self::assertFalse( ReviewChrome::decide( 'GET', true, true ) );
	}

	public function test_a_recalculate_or_add_address_post_is_drawn_inside_the_theme_too(): void {
		self::assertTrue( ReviewChrome::decide( 'POST', false, true, 'review' ) );
		self::assertTrue( ReviewChrome::decide( 'POST', false, true, 'add_address' ) );
	}

	public function test_submit_and_back_keep_the_dedicated_document(): void {
		self::assertFalse( ReviewChrome::decide( 'POST', false, true, 'submit' ) );
		self::assertFalse( ReviewChrome::decide( 'POST', false, true, 'back' ) );
		self::assertFalse( ReviewChrome::decide( 'POST', true, true, 'review' ) );
	}

	public function test_a_site_can_opt_out_with_the_filter(): void {
		self::assertFalse( ReviewChrome::decide( 'GET', false, false ) );
	}

	public function test_the_review_poses_as_the_cart_page_when_the_shop_has_one(): void {
		self::assertSame( 12, ReviewChrome::page_to_pose( 12 ) );
	}

	public function test_an_unset_or_invalid_page_means_no_posing(): void {
		self::assertSame( 0, ReviewChrome::page_to_pose( -1 ) );
		self::assertSame( 0, ReviewChrome::page_to_pose( 0 ) );
	}

	public function test_the_request_decision_reads_the_method_and_the_filter(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertTrue( ReviewChrome::wraps_request() );
		// 0.4.21: the cart's Punchout button posts no action; the handler reads that as a review, so it is drawn in the theme.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'pow_mode' => 'cart', 'pow_nonce' => 'x' ];
		self::assertTrue( ReviewChrome::wraps_request() );
		$_POST = [ 'pow_delivery_action' => 'submit' ];
		self::assertFalse( ReviewChrome::wraps_request() );
		$_POST = [ 'pow_delivery_action' => 'back' ];
		self::assertFalse( ReviewChrome::wraps_request() );
		$_POST = [ 'pow_address_refresh' => '1' ];
		self::assertTrue( ReviewChrome::wraps_request() );
		$_POST = [];
		unset( $_SERVER['REQUEST_METHOD'] );
	}
}
