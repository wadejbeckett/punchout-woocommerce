<?php
/**
 * Standalone test runner for hosts without PHPUnit/Composer.
 *
 *     php tests/run-tests.php
 *
 * Discovers tests/Unit/*Test.php, runs every public test* method through
 * the shim TestCase, and exits non-zero on any failure. With PHPUnit
 * installed, prefer: phpunit -c phpunit.xml.dist
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

require_once __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/Unit/*Test.php' ) ?: [];
sort( $files );

$passed  = 0;
$failed  = 0;
$skipped = 0;
$errors  = [];

foreach ( $files as $file ) {
	$before = get_declared_classes();
	require_once $file;
	$new = array_diff( get_declared_classes(), $before );

	foreach ( $new as $class ) {
		if ( ! is_subclass_of( $class, PHPUnit\Framework\TestCase::class ) || ! str_ends_with( $class, 'Test' ) ) {
			continue;
		}

		$reflection = new ReflectionClass( $class );

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( ! str_starts_with( $method->getName(), 'test' ) ) {
				continue;
			}

			$label = $reflection->getShortName() . '::' . $method->getName();

			try {
				$instance = new $class();
				$instance->runBare( $method->getName() );
				$passed++;
				echo "PASS  {$label}\n";
			} catch ( PHPUnit\Framework\SkippedTestError $e ) {
				$skipped++;
				echo "SKIP  {$label}" . ( '' !== $e->getMessage() ? ' (' . $e->getMessage() . ')' : '' ) . "\n";
			} catch ( Throwable $e ) {
				$failed++;
				$errors[] = "{$label}: " . $e->getMessage();
				echo "FAIL  {$label}\n";
			}
		}
	}
}

echo "\n" . str_repeat( '-', 60 ) . "\n";
echo sprintf( "Passed: %d  Failed: %d  Skipped: %d\n", $passed, $failed, $skipped );

foreach ( $errors as $error ) {
	echo "\nFAILURE: {$error}\n";
}

exit( $failed > 0 ? 1 : 0 );
