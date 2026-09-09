<?php
/**
 * Template name resolution: subdirectories allowed, traversal is not.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Support\Templates;

final class TemplateNameTest extends TestCase {

	public function test_flat_names_are_unchanged(): void {
		self::assertSame( 'return-button', Templates::normalise_name( 'return-button' ) );
		self::assertSame( 'handoff', Templates::normalise_name( 'handoff' ) );
	}

	public function test_one_subdirectory_level_survives(): void {
		self::assertSame( 'docs/page', Templates::normalise_name( 'docs/page' ) );
		self::assertSame( 'account/integration', Templates::normalise_name( 'account/integration' ) );
	}

	public function test_traversal_segments_are_dropped(): void {
		self::assertSame( 'wp-config', Templates::normalise_name( '../../wp-config' ) );
		self::assertSame( 'docs/page', Templates::normalise_name( 'docs/./page' ) );
		self::assertSame( 'docs/page', Templates::normalise_name( '//docs//page//' ) );
	}

	public function test_unsafe_characters_are_stripped(): void {
		self::assertSame( 'docspage', Templates::normalise_name( 'docs<>page' ) );
		self::assertSame( 'a_b.c-d', Templates::normalise_name( 'a_b.c-d' ) );
	}
}
