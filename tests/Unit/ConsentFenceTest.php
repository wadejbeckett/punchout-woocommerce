<?php
/** The one recovery path after consent cannot be verifiably removed. */
declare( strict_types = 1 );

namespace POW\Tests\ConsentFence {
use POW\Partners\Partner;
use POW\Sessions\Session;

final class Registry {
	public int $fences = 0;
	public ?array $last = null;
	public function transition_status( int $id, string $expected, array $fields ): bool { ++$this->fences; $this->last = [ $id, $expected, $fields ]; return true; }
}
final class Store {
	public ?Session $session = null;
	public bool $invalidate = true;
	public bool $throw_invalidate = false;
	public bool $expire = true;
	public ?Session $after_expire = null;
	public bool $swap_after_expire = false;
	public int $invalidations = 0;
	public int $expirations = 0;
	public int $reads = 0;
	public function find( int $id ): ?Session { ++$this->reads; return $this->session && $id === $this->session->id ? $this->session : null; }
	public function invalidate_delivery( int $id, int $buyer, string $token ): bool { ++$this->invalidations; if ( $this->throw_invalidate ) { throw new \RuntimeException( 'db' ); } return $this->invalidate; }
	public function expire_active_login_locked( Session $s ): bool { ++$this->expirations; if ( $this->swap_after_expire ) { $this->session = $this->after_expire; } return $this->expire; }
}
function load(): void {
	if ( class_exists( __NAMESPACE__ . '\\ConsentFence', false ) ) { return; }
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Sessions/ConsentFence.php' );
	$source = str_replace( 'namespace POW\\Sessions;', 'namespace ' . __NAMESPACE__ . '; use POW\\Sessions\\Session;', $source );
	$source = str_replace( 'use POW\\Partners\\{Partner, Registry};', 'use POW\\Partners\\Partner;', $source );
	eval( substr( $source, 5 ) );
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Tests\ConsentFence\{Registry, Store};

final class ConsentFenceTest extends TestCase {
	private Store $store;
	private Registry $registry;
	private Session $session;
	protected function setUp(): void {
		\POW\Tests\ConsentFence\load();
		$this->store = new Store();
		$this->registry = new Registry();
		$this->session = $this->login();
		$this->store->session = $this->session;
	}
	private function login( array $over = [] ): Session {
		return new Session( ...array_replace( [ 'id' => 7, 'partner_id' => 3, 'buyer_cookie' => 'c', 'operation' => 'create', 'browser_form_post_url' => 'https://buyer.example/return', 'selected_item' => null, 'ship_to' => null, 'user_id' => 42, 'wp_session_token' => 'tok', 'one_time_token_hash' => 'h', 'status' => Session::ACTIVE, 'order_id' => 0, 'payload_id' => 'p', 'body_hash' => 'b', 'response_xml' => null, 'cxml_version' => '1.2.014', 'deployment_mode' => 'test', 'extrinsics' => null, 'itemout_lines' => null, 'cart_ready' => false, 'created' => null, 'expires' => null, 'buyer_identity' => null, 'buyer_name' => null, 'buyer_identity_hash' => null, 'wc_session_key' => null ], $over ) );
	}
	private function fence(): \POW\Tests\ConsentFence\ConsentFence { return new \POW\Tests\ConsentFence\ConsentFence( $this->store, $this->registry ); }

	public function test_verified_clear_touches_nothing_else(): void {
		self::assertTrue( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( [ 1, 0, 0, 0 ], [ $this->store->invalidations, $this->store->expirations, $this->registry->fences, $this->store->reads ] );
	}
	public function test_unverified_clear_expires_the_exact_active_login(): void {
		$this->store->invalidate = false;
		self::assertFalse( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( [ 1, 1, 0 ], [ $this->store->invalidations, $this->store->expirations, $this->registry->fences ] );
	}
	public function test_throwing_clear_is_treated_as_unverified(): void {
		$this->store->throw_invalidate = true;
		self::assertFalse( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( 1, $this->store->expirations );
	}
	public function test_failed_expiry_disables_the_company(): void {
		$this->store->invalidate = false;
		$this->store->expire = false;
		self::assertFalse( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( 1, $this->registry->fences );
		self::assertSame( [ 3, Partner::STATUS_ACTIVE, [ 'status' => Partner::STATUS_DISABLED ] ], $this->registry->last );
	}
	public function test_replacement_login_is_never_expired_or_fenced(): void {
		$this->store->invalidate = false;
		$this->store->session = $this->login( [ 'wp_session_token' => 'newer' ] );
		self::assertFalse( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( [ 0, 0 ], [ $this->store->expirations, $this->registry->fences ] );
	}
	public function test_completed_winner_is_never_expired_or_fenced(): void {
		$this->store->invalidate = false;
		$this->store->session = $this->login( [ 'status' => Session::ORDERED ] );
		self::assertFalse( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( [ 0, 0 ], [ $this->store->expirations, $this->registry->fences ] );
	}
	public function test_winner_that_beats_a_lost_expiry_is_not_fenced(): void {
		$this->store->invalidate = false;
		$this->store->expire = false;
		$this->store->swap_after_expire = true;
		$this->store->after_expire = $this->login( [ 'status' => Session::ORDERED ] );
		self::assertFalse( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( [ 1, 0 ], [ $this->store->expirations, $this->registry->fences ] );
	}
	public function test_missing_session_grants_no_authority(): void {
		$this->store->invalidate = false;
		$this->store->session = null;
		self::assertFalse( $this->fence()->clear_locked( $this->session ) );
		self::assertSame( [ 0, 0 ], [ $this->store->expirations, $this->registry->fences ] );
	}
}
}
