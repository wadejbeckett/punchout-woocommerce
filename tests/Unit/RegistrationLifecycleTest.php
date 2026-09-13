<?php
/** Lifecycle policy and actor boundary. @package POW */
declare( strict_types = 1 );
use PHPUnit\Framework\TestCase;
use POW\Partners\{Partner, Registration, Registry, Secrets};
use POW\Sessions\Store;

final class RegistrationLifecycleTest extends TestCase {
	public function test_approval_requires_complete_identity_and_valid_entitlements(): void {
		self::assertTrue( method_exists( Registration::class, 'valid_approval' ), 'Approval policy is missing' );
		$row = [ 'name' => 'Example', 'status' => 'pending', 'from_domain' => 'NetworkID', 'from_identity' => 'BUYER', 'sender_domain' => 'NetworkID', 'sender_identity' => 'BUYER', 'to_domain' => 'NetworkID', 'to_identity' => 'SUPPLIER' ];
		self::assertTrue( Registration::valid_approval( Partner::from_row( $row ) ) );
		self::assertSame( POW\Checkout\ExitPolicy::CHECKOUT, Partner::from_row( $row )->exit_policy );
		foreach ( [ 'to_domain' => '', 'to_identity' => str_repeat( 'x', 191 ), 'mode' => 'invalid', 'exit_policy' => 'inherit', 'return_encoding' => 'invalid', 'deployment_mode' => 'invalid', 'cxml_version' => 'invalid', 'secret_current' => 'unexpected', 'secret_previous' => 'unexpected' ] as $key => $value ) {
			self::assertFalse( Registration::valid_approval( Partner::from_row( array_replace( $row, [ $key => $value ] ) ) ), $key );
		}
	}

	public function test_lifecycle_refuses_anonymous_and_mismatched_actor_before_mutation(): void {
		self::assertTrue( method_exists( Registration::class, 'approve' ), 'Approval service is missing' );
		$before = $GLOBALS['pow_test_current_user_id'] ?? null;
		$GLOBALS['pow_test_current_user_id'] = 0;
		$audit = new class extends POW\Audit\Log { public function __construct() {} public function write_checked( string $event, array $context = [] ): bool { return true; } };
		$service = new Registration( new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), new Store(), $audit );
		try {
			self::assertSame( '', $service->approve( 7, 8 ) );
			self::assertSame( '', $service->reset( 7, [], 8 ) );
			self::assertFalse( $service->request_deactivation( 7, 8 ) );
		} finally {
			if ( null === $before ) { unset( $GLOBALS['pow_test_current_user_id'] ); } else { $GLOBALS['pow_test_current_user_id'] = $before; }
		}
	}
}
