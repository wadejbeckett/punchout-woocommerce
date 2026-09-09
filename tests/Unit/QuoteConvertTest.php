<?php
/**
 * The convert order action and the status it targets.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Orders\QuoteOrder;
use POW\Orders\Status;

final class QuoteConvertTest extends TestCase {

	private function quotes( string $configured ): QuoteOrder {
		$settings = new QuoteConvertTestSettings();

		$settings->convert_status = $configured;

		return new QuoteOrder( new QuoteOrderTestStore(), new QuoteOrderTestLog(), $settings, new QuoteOrderTestLogger() );
	}

	private function order( string $status ): WC_Order {
		$order = new WC_Order( 4321 );

		$order->set_status( $status );
		$order->update_meta_data( QuoteOrder::META_SESSION_ID, 42 );

		return $order;
	}

	public function test_action_is_offered_only_on_a_quote(): void {
		$quotes = $this->quotes( 'pending' );

		self::assertArrayHasKey( 'pow_convert_quote', $quotes->add_order_action( [], $this->order( Status::SLUG ) ) );
		self::assertSame( [], $quotes->add_order_action( [], $this->order( 'processing' ) ) );
		self::assertSame( [], $quotes->add_order_action( [], null ) );
	}

	public function test_action_key_survives_sanitize_title(): void {
		// class-wc-meta-box-order-actions.php:184 fires
		// woocommerce_order_action_{sanitize_title($action)}, so a key that
		// sanitize_title rewrites would register a hook nothing ever fires.
		self::assertSame( 'pow_convert_quote', sanitize_title( 'pow_convert_quote' ) );
	}

	public function test_convert_status_is_clamped(): void {
		self::assertSame( 'pending', $this->quotes( '' )->convert_status() );
		self::assertSame( 'pending', $this->quotes( 'completed' )->convert_status() );
		self::assertSame( 'processing', $this->quotes( 'processing' )->convert_status() );
		self::assertSame( 'on-hold', $this->quotes( 'on-hold' )->convert_status() );
	}

	public function test_convert_moves_a_quote_and_notes_it(): void {
		$order = $this->order( Status::SLUG );

		$this->quotes( 'processing' )->convert( $order );

		self::assertSame( 'processing', $order->get_status() );
		self::assertCount( 1, $order->notes );
		self::assertSame( 'quote_order_converted', QuoteOrderTestLog::$written[0][0] );
	}

	public function test_convert_leaves_a_non_quote_alone(): void {
		$order = $this->order( 'processing' );

		$this->quotes( 'pending' )->convert( $order );

		self::assertSame( 'processing', $order->get_status() );
		self::assertSame( [], $order->notes );
	}
}

final class QuoteConvertTestSettings extends \POW\Settings {

	public string $convert_status = 'pending';

	public function __construct() {}

	public function int( string $key ): int {
		return 0;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return 'quote_convert_status' === $key ? $this->convert_status : $default;
	}
}
