<?php
/** Focused account suite; no native WordPress/WooCommerce runtime. */
declare( strict_types = 1 );
require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/Unit/AccountIntegrationTest.php';
$failed = 0;
$passed = 0;
foreach ( ( new ReflectionClass( AccountIntegrationTest::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
	if ( ! str_starts_with( $method->name, 'test' ) ) { continue; }
	try {
		( new AccountIntegrationTest() )->runBare( $method->name );
		++$passed;
		echo "PASS {$method->name}\n";
	} catch ( Throwable $e ) {
		++$failed;
		echo "FAIL {$method->name}: {$e->getMessage()}\n";
	}
}
echo "Passed: {$passed} Failed: {$failed}\n";
exit( $failed ? 1 : 0 );
