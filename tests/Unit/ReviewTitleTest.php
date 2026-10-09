<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Addresses\ReviewTitle;
use POW\Settings;

require_once dirname( __DIR__, 2 ) . '/includes/Settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/Addresses/ReviewTitle.php';

/**
 * The title the theme-drawn review answers with while it poses as the Cart page (0.4.22). The filters are
 * called directly: the unit suite has no hook runtime, and what matters is what each one returns for which id.
 */
final class ReviewTitleTest extends TestCase {
	private const CART = 12;
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ 'pow_test_filters', 'pow_test_options' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}
		ReviewTitle::reset();
	}

	protected function tearDown(): void {
		ReviewTitle::reset();
		foreach ( $this->saved as $key => [ $existed, $value ] ) {
			if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
		}
	}

	private function settings( string $title ): Settings {
		$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ ReviewTitle::SETTING => $title ];
		return new Settings();
	}

	/** A relabel of the Cart page during a request whose queried object is $queried. */
	private function relabel( int $queried = self::CART, string $label = 'Review' ): ReviewTitle {
		return new ReviewTitle( self::CART, $label, 'Cart', static fn(): int => $queried );
	}

	public function test_the_default_is_review(): void {
		self::assertSame( 'Review', ReviewTitle::text() );
		self::assertSame( 'Review', ReviewTitle::text( $this->settings( '' ) ) );
		self::assertSame( 'Review', ReviewTitle::text( $this->settings( '   ' ) ) );
	}

	public function test_the_setting_names_the_page(): void {
		self::assertSame( 'Check and send', ReviewTitle::text( $this->settings( 'Check and send' ) ) );
	}

	public function test_the_filter_has_the_last_word_and_blank_turns_it_off(): void {
		$GLOBALS['pow_test_filters'][ ReviewTitle::FILTER ] = static fn( string $title ): string => $title . ' order';
		self::assertSame( 'Check and send order', ReviewTitle::text( $this->settings( 'Check and send' ) ) );

		$GLOBALS['pow_test_filters'][ ReviewTitle::FILTER ] = static fn(): string => '';
		self::assertSame( '', ReviewTitle::text( $this->settings( 'Check and send' ) ) );
		self::assertNull( ReviewTitle::relabel( (object) [ 'ID' => self::CART, 'post_title' => 'Cart' ], $this->settings( 'Check and send' ) ), "'' leaves the page's own title" );
		self::assertNull( ReviewTitle::current() );
	}

	public function test_the_saved_setting_is_plain_bounded_text(): void {
		self::assertSame( 'Review', ReviewTitle::sanitise( '  <b>Review</b> ' ) );
		self::assertSame( '', ReviewTitle::sanitise( [ 'x' ] ) );
		self::assertSame( ReviewTitle::MAX_LENGTH, mb_strlen( ReviewTitle::sanitise( str_repeat( 'é', 300 ) ) ) );
	}

	public function test_only_the_posed_page_reads_as_the_review_title_and_only_while_it_is_the_queried_page(): void {
		$relabel = $this->relabel();
		self::assertSame( 'Review', $relabel->the_title( 'Cart', self::CART ) );
		self::assertSame( 'Review', $relabel->the_title( 'Cart', (string) self::CART ) );
		self::assertSame( 'Shop', $relabel->the_title( 'Shop', 13 ), 'Any other page keeps its title' );
		self::assertSame( 'Cart', $relabel->the_title( 'Cart' ), 'A title read without an id is not the page' );
		self::assertSame( 'Review', $relabel->single_post_title( 'Cart', (object) [ 'ID' => self::CART ] ) );
		self::assertSame( 'Shop', $relabel->single_post_title( 'Shop', (object) [ 'ID' => 13 ] ) );

		// The same page id on a request whose queried page is another one (WooCommerce's endpoint-title rule).
		self::assertSame( 'Cart', $this->relabel( 77 )->the_title( 'Cart', self::CART ) );
		self::assertSame( 'R&amp;D review', $this->relabel( self::CART, 'R&D review' )->the_title( 'Cart', self::CART ), 'The label is escaped like a title' );
	}

	public function test_the_plain_cart_request_and_the_dedicated_document_register_nothing(): void {
		// Nothing relabels until the pose has happened on a theme-drawn review; /cart/ never poses.
		self::assertNull( ReviewTitle::current() );
		self::assertNull( ReviewTitle::relabel( null ), 'No page posed, no relabel' );
		self::assertNull( ReviewTitle::relabel( (object) [ 'ID' => 0 ] ) );
		self::assertSame( 'Review your cart — Example shop', ReviewTitle::document_title( 'Example shop', 'Review your cart' ) );

		$active = ReviewTitle::relabel( (object) [ 'ID' => self::CART, 'post_title' => 'Cart' ] );
		self::assertInstanceOf( ReviewTitle::class, $active );
		self::assertSame( self::CART, $active->page_id() );
		self::assertSame( 'Review', $active->label() );
		self::assertSame( $active, ReviewTitle::relabel( (object) [ 'ID' => 99, 'post_title' => 'Other' ] ), 'A second pose in the same request keeps the first' );
		self::assertSame( 'Review — Example shop', ReviewTitle::document_title( 'Example shop', 'Review your cart' ) );
	}

	public function test_a_cart_menu_item_without_a_custom_label_keeps_the_pages_own_title(): void {
		$relabel = $this->relabel();
		// wp_setup_nav_menu_item() ran the_title with the Cart page's id, so the item first read as the review.
		$GLOBALS['pow_test_filters']['the_title'] = [ $relabel, 'the_title' ];
		$item = (object) [ 'type' => 'post_type', 'object_id' => self::CART, 'post_title' => '', 'title' => $relabel->the_title( 'Cart', self::CART ) ];
		self::assertSame( 'Review', $item->title );
		self::assertSame( 'Cart', $relabel->menu_item( $item )->title );
		self::assertSame( 'Review', $relabel->the_title( 'Cart', self::CART ), 'The page title itself is still relabelled after the menu' );
	}

	public function test_a_cart_menu_item_with_a_custom_label_and_other_items_are_untouched(): void {
		$relabel = $this->relabel();
		$custom = (object) [ 'type' => 'post_type', 'object_id' => self::CART, 'post_title' => 'Basket', 'title' => 'Basket' ];
		self::assertSame( 'Basket', $relabel->menu_item( $custom )->title );
		$other = (object) [ 'type' => 'post_type', 'object_id' => 13, 'post_title' => '', 'title' => 'Shop' ];
		self::assertSame( 'Shop', $relabel->menu_item( $other )->title );
		$link = (object) [ 'type' => 'custom', 'object_id' => self::CART, 'post_title' => '', 'title' => 'Cart' ];
		self::assertSame( 'Cart', $relabel->menu_item( $link )->title );
	}

	public function test_the_last_breadcrumb_that_still_names_the_page_reads_as_the_review_title(): void {
		$relabel = $this->relabel();
		$crumbs = [ [ 'Home', 'https://shop.example.test/' ], [ 'Cart', 'https://shop.example.test/cart/' ] ];
		self::assertSame( [ [ 'Home', 'https://shop.example.test/' ], [ 'Review', 'https://shop.example.test/cart/' ] ], $relabel->breadcrumb( $crumbs ) );
		$already = [ [ 'Home', '/' ], [ 'Review', '/cart/' ] ];
		self::assertSame( $already, $relabel->breadcrumb( $already ) );
		$cart_not_last = [ [ 'Cart', '/cart/' ], [ 'Item', '' ] ];
		self::assertSame( $cart_not_last, $relabel->breadcrumb( $cart_not_last ) );
		self::assertSame( 'junk', $relabel->breadcrumb( 'junk' ) );
		self::assertSame( [], $relabel->breadcrumb( [] ) );
	}

	public function test_the_review_steps_keep_their_own_cart_step(): void {
		// The stepper's "Cart" is the template's own string, never the page title the relabel changes.
		$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/delivery-confirmation.php' );
		self::assertStringContainsString( "<li><?php echo esc_html__( 'Cart', 'punchout-woocommerce' ); ?></li>", $template );
		self::assertStringNotContainsString( 'the_title', $template );
		self::assertStringNotContainsString( 'get_the_title', $template );
	}
}
