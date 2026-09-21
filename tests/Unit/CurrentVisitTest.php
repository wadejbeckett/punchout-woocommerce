<?php
/** The request-scoped visit resolver: the row is the proof, not the role. @package POW */
declare( strict_types = 1 );

/** Serves session rows from memory and can make the lookup unreachable, as a missing table does. */
final class CurrentVisitStore extends POW\Sessions\Store {
	/** @var array<int, array<string, mixed>> */
	public array $rows = [];
	public int $lookups = 0;
	public bool $unreachable = false;

	public function find_for_login( int $user_id, string $wp_session_token, array $statuses = [ POW\Sessions\Session::ACTIVE ] ): ?POW\Sessions\Session {
		++$this->lookups;

		if ( $this->unreachable ) {
			throw new RuntimeException( 'Session lookup failed.' );
		}

		if ( 0 === $user_id || '' === $wp_session_token || [] === $statuses ) {
			return null;
		}

		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] === $user_id
				&& (string) $row['wp_session_token'] === $wp_session_token
				&& in_array( (string) $row['status'], $statuses, true ) ) {
				return POW\Sessions\Session::from_row( $row );
			}
		}

		return null;
	}
}

final class CurrentVisitTest extends PHPUnit\Framework\TestCase {
	private array $saved = [];
	private CurrentVisitStore $store;

	protected function setUp(): void {
		foreach ( [ 'pow_test_current_user_id', 'pow_test_login_token', 'pow_test_roles', 'pow_test_options' ] as $key ) {
			$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
			unset( $GLOBALS[ $key ] );
		}

		$this->store = new CurrentVisitStore();
		// Two employees of one customer: one login, two visits, two rows.
		$this->store->rows = [
			$this->row( 44, 'token-alice' ),
			$this->row( 45, 'token-bob' ),
		];

		// The bound login is an ordinary WooCommerce customer, nothing more.
		$GLOBALS['pow_test_roles']           = [ 'customer' ];
		$GLOBALS['pow_test_current_user_id'] = 30;
		$GLOBALS['pow_test_login_token']     = 'token-alice';
		$GLOBALS['pow_test_options']         = [ POW\Settings::OPTION_KEY => [ 'enabled' => 'yes' ] ];
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $key => [ $exists, $value ] ) {
			if ( $exists ) {
				$GLOBALS[ $key ] = $value;
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
	}

	/** @return array<string, mixed> */
	private function row( int $id, string $token, string $status = 'active' ): array {
		return [
			'id'               => $id,
			'partner_id'       => 12,
			'user_id'          => 30,
			'wp_session_token' => $token,
			'status'           => $status,
			'expires'          => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		];
	}

	private function resolver(): POW\Sessions\Current {
		self::assertTrue( class_exists( POW\Sessions\Current::class ), 'The visit resolver must exist' );
		return new POW\Sessions\Current( $this->store );
	}

	private function plugin( string $enabled = 'yes' ): POW\Plugin {
		$GLOBALS['pow_test_options'][ POW\Settings::OPTION_KEY ]['enabled'] = $enabled;
		$plugin = ( new ReflectionClass( POW\Plugin::class ) )->newInstanceWithoutConstructor();
		foreach ( [ 'sessions' => $this->store, 'settings' => new POW\Settings() ] as $key => $value ) {
			( new ReflectionProperty( POW\Plugin::class, $key ) )->setValue( $plugin, $value );
		}
		return $plugin;
	}

	public function test_visit_resolves_for_a_plain_customer_account(): void {
		$visit = $this->resolver()->visit();
		self::assertNotNull( $visit, 'An ordinary customer account must resolve its own visit' );
		self::assertSame( 44, $visit->id );
		self::assertSame( 44, $this->resolver()->visit_id() );
	}

	public function test_two_concurrent_visits_of_one_user_resolve_to_their_own_row(): void {
		self::assertSame( 44, $this->resolver()->visit()?->id );
		$GLOBALS['pow_test_login_token'] = 'token-bob';
		self::assertSame( 45, $this->resolver()->visit()?->id );
		// A superseded cookie for the same account matches no row.
		$GLOBALS['pow_test_login_token'] = 'token-stale';
		self::assertNull( $this->resolver()->visit() );
	}

	public function test_unrelated_and_signed_out_requests_resolve_to_nothing(): void {
		$GLOBALS['pow_test_current_user_id'] = 21;
		$GLOBALS['pow_test_login_token']     = 'token-of-21';
		self::assertNull( $this->resolver()->visit() );
		self::assertSame( 0, $this->resolver()->visit_id() );

		$GLOBALS['pow_test_current_user_id'] = 0;
		$this->store->lookups                = 0;
		self::assertNull( $this->resolver()->visit() );
		self::assertSame( 0, $this->store->lookups, 'A signed-out request must not query the store' );
	}

	public function test_ordered_visit_still_resolves(): void {
		$this->store->rows = [ $this->row( 46, 'token-alice', 'ordered' ) ];
		self::assertSame( 46, $this->resolver()->visit()?->id );
		$this->store->rows = [ $this->row( 47, 'token-alice', 'closed' ) ];
		self::assertNull( $this->resolver()->visit() );
	}

	public function test_plugin_delegates_and_memoises_one_lookup(): void {
		$plugin = $this->plugin();
		self::assertSame( 44, $plugin->current_session()?->id );
		self::assertSame( 44, $plugin->current_session()?->id );
		self::assertSame( 1, $this->store->lookups, 'The visit is resolved once per request' );
	}

	public function test_resolver_still_answers_with_the_master_switch_off(): void {
		$plugin = $this->plugin( 'no' );
		self::assertFalse( $plugin->enabled() );
		self::assertSame( 44, $plugin->current_session()?->id, 'A live visit must stay visible to the guards after the switch flips' );
	}

	public function test_unresolvable_request_is_not_cached_as_no_visit(): void {
		$plugin                   = $this->plugin();
		$this->store->unreachable = true;

		try {
			$plugin->current_session();
			self::fail( 'An unreachable store must not answer "no visit"' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Session lookup failed.', $e->getMessage() );
		}

		$this->store->unreachable = false;
		self::assertSame( 44, $plugin->current_session()?->id, 'The next caller must retry the lookup' );
	}

	public function test_injected_memo_short_circuits_the_store(): void {
		$plugin = $this->plugin();
		( new ReflectionProperty( POW\Plugin::class, 'session_resolved' ) )->setValue( $plugin, true );
		( new ReflectionProperty( POW\Plugin::class, 'current_session' ) )->setValue( $plugin, POW\Sessions\Session::from_row( $this->row( 99, 'token-alice' ) ) );
		self::assertSame( 99, $plugin->current_session()?->id );
		self::assertSame( 0, $this->store->lookups );
	}

	public function test_resolver_asks_no_question_about_the_user(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Sessions/Current.php' );
		self::assertNotSame( '', $source, 'The visit resolver must exist' );
		foreach ( [ 'roles', 'Installer::ROLE', 'get_user_meta', 'user_meta', 'current_user_can' ] as $needle ) {
			self::assertStringNotContainsString( $needle, $source, 'The visit is proved by the session row alone' );
		}
	}
}
