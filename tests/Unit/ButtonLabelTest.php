<?php
/**
 * Exit-control label resolution (0.2.4): the operator's saved setting
 * wins over the translated default, and the label filter runs last so
 * code can always override either.
 *
 * Only the resolution rule is unit-testable here — Surface::markup()
 * itself needs WordPress. The filter step is modelled with the bootstrap's
 * apply_filters stub (tests/Support/wp-stubs.php), which runs one callback
 * per hook out of $GLOBALS['pow_test_filters'].
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Settings;

final class ButtonLabelTest extends TestCase {

	private const RETURN_DEFAULT  = 'Punchout';
	private const ABANDON_DEFAULT = 'Return without a cart';

	protected function setUp(): void {
		$GLOBALS['pow_test_filters'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['pow_test_filters'] = [];
	}

	public function test_saved_label_wins_over_the_default(): void {
		self::assertSame( 'Send to Coupa', Settings::resolve_label( 'Send to Coupa', self::RETURN_DEFAULT ) );
		self::assertSame( 'Cancel', Settings::resolve_label( 'Cancel', self::ABANDON_DEFAULT ) );
	}

	public function test_unset_or_blank_setting_falls_back_to_the_default(): void {
		self::assertSame( self::RETURN_DEFAULT, Settings::resolve_label( '', self::RETURN_DEFAULT ) );
		self::assertSame( self::RETURN_DEFAULT, Settings::resolve_label( null, self::RETURN_DEFAULT ) );
		self::assertSame( self::ABANDON_DEFAULT, Settings::resolve_label( '   ', self::ABANDON_DEFAULT ) );
		self::assertSame( self::ABANDON_DEFAULT, Settings::resolve_label( [ 'nonsense' ], self::ABANDON_DEFAULT ) );
	}

	public function test_saved_label_is_trimmed(): void {
		self::assertSame( 'Punch out', Settings::resolve_label( "  Punch out\n", self::RETURN_DEFAULT ) );
	}

	public function test_defaults_are_the_shipped_strings(): void {
		self::assertSame( 'Punchout', Settings::resolve_label( '', self::RETURN_DEFAULT ) );
		self::assertSame( 'Return without a cart', Settings::resolve_label( '', self::ABANDON_DEFAULT ) );
		self::assertStringNotContainsString( 'basket', self::ABANDON_DEFAULT );
	}

	/**
	 * The order Surface composes: filter( setting ?: default ), so a
	 * filter overrides both a saved label and the default.
	 */
	public function test_filter_runs_last(): void {
		$GLOBALS['pow_test_filters']['pow_return_button_label'] = static fn ( string $label ): string => 'Filtered';

		self::assertSame(
			'Filtered',
			apply_filters( 'pow_return_button_label', Settings::resolve_label( 'Saved label', self::RETURN_DEFAULT ) )
		);

		self::assertSame(
			'Filtered',
			apply_filters( 'pow_return_button_label', Settings::resolve_label( '', self::RETURN_DEFAULT ) )
		);
	}

	/**
	 * With no filter registered the saved value (or default) survives —
	 * the filter is an override, not a replacement of the setting.
	 */
	public function test_unfiltered_label_is_the_resolved_value(): void {
		self::assertSame(
			'Saved label',
			apply_filters( 'pow_abandon_button_label', Settings::resolve_label( 'Saved label', self::ABANDON_DEFAULT ) )
		);

		self::assertSame(
			self::ABANDON_DEFAULT,
			apply_filters( 'pow_abandon_button_label', Settings::resolve_label( '', self::ABANDON_DEFAULT ) )
		);
	}
}
