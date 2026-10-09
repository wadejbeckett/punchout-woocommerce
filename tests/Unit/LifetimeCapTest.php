<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Partners\Partner;

/**
 * StartPage login links and visits live at most 7 days (0.4.22, security audit AUTH-01): saved that way and,
 * for a longer value an earlier version stored, used that way.
 */
final class LifetimeCapTest extends TestCase {
	public function test_the_cap_is_seven_days(): void {
		self::assertSame( 604800, Partner::MAX_TTL );
	}

	public function test_a_lifetime_is_kept_between_its_floor_and_the_cap(): void {
		self::assertSame( 259200, Partner::clamp_ttl( 259200, 60 ), 'A 72-hour visit stays valid' );
		self::assertSame( 604800, Partner::clamp_ttl( 604800, 60 ) );
		self::assertSame( 604800, Partner::clamp_ttl( 604801, 60 ) );
		self::assertSame( 604800, Partner::clamp_ttl( PHP_INT_MAX, 30 ) );
		self::assertSame( 30, Partner::clamp_ttl( 5, 30 ) );
		self::assertSame( 60, Partner::clamp_ttl( -1, 60 ) );
	}

	public function test_a_connection_row_is_read_within_the_cap(): void {
		$long = Partner::from_row( [ 'id' => 1, 'session_ttl' => 30 * 86400, 'token_ttl' => 10 * 86400 ] );
		self::assertSame( 604800, $long->session_ttl );
		self::assertSame( 604800, $long->token_ttl );
		$kept = Partner::from_row( [ 'id' => 1, 'session_ttl' => 259200, 'token_ttl' => 259200 ] );
		self::assertSame( 259200, $kept->session_ttl );
		self::assertSame( 259200, $kept->token_ttl );
		$defaults = Partner::from_row( [ 'id' => 1 ] );
		self::assertSame( 14400, $defaults->session_ttl );
		self::assertSame( 300, $defaults->token_ttl );
	}

	public function test_the_registry_stores_at_most_the_cap(): void {
		$registry = new POW\Partners\Registry( new POW\Partners\Secrets( str_repeat( 'c', 32 ) ) );
		$sanitise = new ReflectionMethod( $registry, 'sanitise' );
		$clean = $sanitise->invoke( $registry, [ 'session_ttl' => 9999999, 'token_ttl' => '259200' ] );
		self::assertSame( 604800, $clean['session_ttl'] );
		self::assertSame( 259200, $clean['token_ttl'] );
		self::assertSame( 0, $sanitise->invoke( $registry, [ 'session_ttl' => -5 ] )['session_ttl'] );
	}

	public function test_the_plugin_defaults_are_saved_within_the_cap_and_the_review_title_as_plain_text(): void {
		$admin = ( new ReflectionClass( POW\Admin\Page::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( $admin, 'settings' ) )->setValue( $admin, new POW\Settings() );
		$clean = $admin->sanitize_settings( [ 'token_ttl' => 900000, 'session_ttl' => 259200, 'review_title' => ' <em>Check</em> and send ' ] );
		self::assertSame( 604800, $clean['token_ttl'] );
		self::assertSame( 259200, $clean['session_ttl'] );
		self::assertSame( 'Check and send', $clean['review_title'] );
		$floor = $admin->sanitize_settings( [ 'token_ttl' => 1, 'session_ttl' => 1 ] );
		self::assertSame( 30, $floor['token_ttl'] );
		self::assertSame( 300, $floor['session_ttl'] );
		self::assertSame( '', $admin->sanitize_settings( [] )['review_title'] );
	}
}
