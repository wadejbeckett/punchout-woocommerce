<?php
/**
 * The old review path is gone (0.5.0).
 *
 * A visit leaves through WooCommerce's classic checkout, where PunchOut is the only payment method. One
 * regression suite for what retiring the plugin's own review page has to keep true: the review's route, page,
 * template, script and style, its chooser, consent fence, its title and word settings, its Cart-page pose, the
 * estimate-note setting, the plugin's own attachment field, buyer-added addresses, the address resolver and the
 * return button's template and setting are absent, and nothing shipped names them. What stays for history stays:
 * the Punchout Quote status, the "Bought by" block, convert and expire, the compatibility reader and its decoders,
 * the old preferred-date read, read-only display and download of old attachments, and the address migration
 * command. The records tell the truth: the retired settings are kept as stored, the changelog lists every retired
 * setting and shortcode, the public plugin page never says "approval", no current document keeps the old "no
 * checkout" rule, and the test fixtures use neutral names.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace {
	if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) { require_once dirname( __DIR__ ) . '/bootstrap.php'; }
	require_once dirname( __DIR__ ) . '/Admin/doubles.php';

	use POW\Admin\Page;
	use POW\Partners\{Registry, Secrets};
	use POW\Settings;

	final class ReviewRetirementTest extends PHPUnit\Framework\TestCase {

		/** Files of the review path that 0.5.0 removes. */
		private const REMOVED_FILES = [
			'/includes/Addresses/Chooser.php',
			'/includes/Addresses/Confirmation.php',
			'/includes/Addresses/ReturnConfirmation.php',
			'/includes/Addresses/ReviewChrome.php',
			'/includes/Addresses/ReviewTitle.php',
			'/includes/Addresses/ReviewLabels.php',
			'/includes/Addresses/ReviewFormat.php',
			'/includes/Addresses/DeliveryEstimate.php',
			'/includes/Addresses/EstimateNote.php',
			'/includes/Addresses/Resolver.php',
			'/includes/Addresses/NativeProvider.php',
			'/includes/Addresses/Provider.php',
			'/includes/Addresses/QuoteAddress.php',
			'/includes/Sessions/ConsentFence.php',
			'/templates/delivery-confirmation.php',
			'/templates/return-button.php',
			'/assets/css/delivery-confirmation.css',
			'/assets/js/delivery-review.js',
		];

		/** Files that stay for history. */
		private const KEPT_FILES = [
			'/includes/Orders/Status.php',
			'/includes/Orders/QuoteOrder.php',
			'/includes/Orders/QuoteCompatibility.php',
			'/includes/Orders/Attachment.php',
			'/includes/Addresses/DeliveryData.php',
			'/includes/Addresses/CompanyBook.php',
			'/includes/Addresses/AccountBook.php',
			'/includes/Addresses/AccountCodes.php',
			'/includes/CLI/Command.php',
		];

		/** What no shipped file may name once the review is gone. */
		private const FORBIDDEN = [
			'/punchout/confirm',
			'punchout_delivery_confirmation',
			'pow_confirm_delivery',
			'Addresses\\Chooser',
			'Addresses\\Confirmation',
			'ReturnConfirmation',
			'ReviewChrome',
			'ReviewTitle',
			'ReviewLabels',
			'ReviewFormat',
			'DeliveryEstimate',
			'EstimateNote',
			'ConsentFence',
			'Addresses\\Resolver',
			'NativeProvider',
			'QuoteAddress',
			'delivery-confirmation',
			'delivery-review',
			"'return-button'",
			'pow-return-button',
			'pow_return_button_label',
			'punchout_review_title',
			'punchout_review_page_id',
			'punchout_review_in_theme',
			'punchout_review_submit_label',
			'punchout_review_items_heading',
			'punchout_review_total_label',
			'punchout_attachment_enabled',
			'punchout_attachment_types',
			'punchout_address_provider',
			'create_for_session',
			'attach_poom',
			'VISIT_HIDDEN_SELECTORS',
			'reserved_button_classes',
		];

		private array $saved = [];

		protected function setUp(): void {
			foreach ( [ 'wpdb', 'pow_admin_test', 'pow_test_current_user_id', 'pow_test_users', 'pow_test_options', '_SERVER', '_POST', '_GET' ] as $key ) {
				$this->saved[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
				unset( $GLOBALS[ $key ] );
			}
			$GLOBALS['pow_admin_test']           = [ 'headers' => [] ];
			$GLOBALS['pow_test_current_user_id'] = 1;
			$GLOBALS['pow_test_users']           = [ 1 => (object) [ 'ID' => 1, 'roles' => [ 'administrator' ], 'allcaps' => [ 'manage_woocommerce' => true, 'read' => true ] ] ];
			$GLOBALS['pow_test_options']         = [];
			$_SERVER                             = [ 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on' ];
			$_POST                               = [];
			$_GET                                = [];
			$GLOBALS['wpdb']                     = new AdminDatabase();
		}

		protected function tearDown(): void {
			foreach ( $this->saved as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); } }
		}

		private static function root(): string { return dirname( __DIR__, 2 ); }

		/** @return array<string, string> Path relative to the plugin => source, for every shipped file. */
		private static function shipped(): array {
			$root  = self::root();
			$files = [ $root . '/punchout-woocommerce.php', $root . '/uninstall.php' ];
			foreach ( [ 'includes', 'templates', 'assets' ] as $dir ) {
				$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );
				foreach ( $iterator as $file ) { if ( $file->isFile() ) { $files[] = $file->getPathname(); } }
			}
			sort( $files );
			$sources = [];
			foreach ( $files as $file ) { $sources[ substr( $file, strlen( $root ) + 1 ) ] = (string) file_get_contents( $file ); }
			return $sources;
		}

		private static function readme_section( string $heading ): string {
			$readme = (string) file_get_contents( self::root() . '/readme.txt' );
			$start  = strpos( $readme, $heading );
			self::assertTrue( false !== $start, 'readme.txt has ' . $heading );
			$end = strpos( $readme, "\n= ", $start + strlen( $heading ) );
			return substr( $readme, $start, false === $end ? null : $end - $start );
		}

		public function test_the_review_files_are_gone_and_the_history_files_stay(): void {
			foreach ( self::REMOVED_FILES as $file ) { self::assertFalse( is_file( self::root() . $file ), $file . ' still exists' ); }
			foreach ( self::KEPT_FILES as $file ) { self::assertTrue( is_file( self::root() . $file ), $file . ' is missing' ); }
		}

		public function test_no_shipped_file_names_the_review_path(): void {
			foreach ( self::shipped() as $name => $source ) {
				foreach ( self::FORBIDDEN as $needle ) {
					self::assertStringNotContainsString( $needle, $source, $name . ' names ' . $needle );
				}
			}
		}

		/** The one thing /punchout/return does now: no cart mode, no confirmation, no quote. */
		public function test_the_return_endpoint_is_cancel_and_return_only(): void {
			$source = self::shipped()['includes/Http/ReturnEndpoint.php'];
			foreach ( [ "'cart' === \$mode", 'confirmation', 'QuoteOrder', 'PoomMapper', 'review_page' ] as $needle ) {
				self::assertStringNotContainsString( $needle, $source, $needle );
			}
			self::assertStringContainsString( "Session::CLOSED", $source );
			$router = self::shipped()['includes/Http/Router.php'];
			self::assertStringNotContainsString( 'Chooser', $router );
			self::assertStringContainsString( "'/punchout/handoff' === \$path", $router );
		}

		/** The retired button renders nothing for this release, wherever a layout still places it. */
		public function test_the_return_button_shortcode_and_helper_stay_and_render_nothing(): void {
			$source = self::shipped()['includes/Cart/Surface.php'];
			self::assertStringContainsString( "add_shortcode( 'punchout_return_button', [ \$this, 'shortcode' ] )", $source );
			self::assertStringNotContainsString( 'woocommerce_after_cart_totals', $source, 'No placement of its own on the classic cart' );
			$main = self::shipped()['punchout-woocommerce.php'];
			self::assertStringContainsString( 'function pow_return_button(', $main );
			self::assertStringContainsString( 'return_button_markup()', $main );
			self::assertStringContainsString( "public function return_button_markup(): string {\n\t\treturn '';", self::shipped()['includes/Plugin.php'] );
		}

		/** The old attachment field and its settings are gone; the read-only parts stay. */
		public function test_the_attachment_is_read_only(): void {
			$source = self::shipped()['includes/Orders/Attachment.php'];
			foreach ( [ 'take_upload', 'move_uploaded_file', 'function claim', 'view_vars', 'CARRY', 'DEFAULT_TYPES' ] as $needle ) { self::assertStringNotContainsString( $needle, $source, $needle ); }
			foreach ( [ 'function for_order', 'function download', 'function render_admin', 'function sweep', 'function contained_path' ] as $needle ) { self::assertStringContainsString( $needle, $source, $needle ); }
		}

		/** The retired settings have no field and no default, and nothing reads them. */
		public function test_the_retired_settings_have_no_field_and_no_default(): void {
			$fields = ( new ReflectionMethod( Page::class, 'fields' ) )->invoke( ( new ReflectionClass( Page::class ) )->newInstanceWithoutConstructor() );
			$defaults = ( new ReflectionClassConstant( Settings::class, 'DEFAULTS' ) )->getValue();
			foreach ( Settings::RETIRED as $key => $label ) {
				self::assertFalse( array_key_exists( $key, $fields ), $key . ' still has a field' );
				self::assertFalse( array_key_exists( $key, $defaults ), $key . ' still has a default' );
				foreach ( self::shipped() as $name => $source ) {
					if ( 'includes/Settings.php' === $name ) { continue; }
					self::assertStringNotContainsString( "'" . $key . "'", $source, $name . ' names the retired setting ' . $key );
				}
			}
			self::assertSame( 'Punchout button label', Settings::RETIRED['return_button_label'] );
			self::assertCount( 10, Settings::RETIRED );
		}

		/**
		 * A save on the 0.5.0 screen keeps each retired value as it was stored, so the old review gets its words back
		 * if the shop goes back to 0.4.23; the screen never takes a new value for one.
		 */
		public function test_a_settings_save_keeps_the_retired_values_as_stored_for_a_rollback(): void {
			$GLOBALS['pow_test_options'][ Settings::OPTION_KEY ] = [ 'enabled' => 'yes', 'review_items_heading' => 'Items on order', 'review_submit_label' => 'Submit', 'return_button_label' => 'Punchout', 'attachment_enabled' => 'no' ];
			$page  = new Page( new Settings(), new Registry( new Secrets( str_repeat( 'x', 32 ) ) ), new AdminAudit() );
			$saved = $page->sanitize_settings( [ 'enabled' => 'yes', 'review_items_heading' => 'Changed', 'review_title' => 'New', 'checkout_button_label' => 'Review' ] );
			self::assertSame( 'Items on order', $saved['review_items_heading'] );
			self::assertSame( 'Submit', $saved['review_submit_label'] );
			self::assertSame( 'Punchout', $saved['return_button_label'] );
			self::assertSame( 'no', $saved['attachment_enabled'] );
			self::assertFalse( array_key_exists( 'review_title', $saved ), 'A retired setting that was never stored is not created' );
			self::assertSame( 'Review', $saved['checkout_button_label'], 'A current setting saves as posted' );
			self::assertSame( 'yes', $saved['enabled'] );
		}

		public function test_the_changelog_lists_every_retired_setting_and_shortcode(): void {
			$section = self::readme_section( '= 0.5.0 =' );
			foreach ( Settings::RETIRED as $key => $label ) {
				self::assertStringContainsString( '`' . $key . '`', $section, 'The 0.5.0 changelog names the retired setting ' . $key );
				self::assertStringContainsString( $label, $section, 'The 0.5.0 changelog names the retired setting ' . $label );
			}
			foreach ( [ '[punchout_delivery_confirmation]', '[punchout_return_button]', 'pow_return_button()', '/punchout/confirm', 'pow_return_button_label', 'punchout_address_provider' ] as $retired ) {
				self::assertStringContainsString( $retired, $section, 'The 0.5.0 changelog names ' . $retired );
			}
			// And every new setting, shortcode and filter.
			foreach ( [ 'Checkout button label', 'State page', 'State page button label', 'Ask before leaving', 'Leave button label', 'Stay button label', '[punchout_state]', '[punchout_abandon_button]', 'question', 'punchout_checkout_button_label', 'punchout_state_button_label', 'punchout_leave_confirm_label', 'punchout_leave_stay_label', 'punchout_line_description_limit', 'punchout_order_details_sent', 'punchout_delivery_estimate_note', 'pow_template_state', 'pow_handoff_copy', 'cart and checkout blocks', 'Schema 10' ] as $new ) {
				self::assertStringContainsString( $new, $section, 'The 0.5.0 changelog names ' . $new );
			}
		}

		/** 0.5.2 is the release now (VisitScopeSettingsTest checks it); the 0.5.1 and 0.5.0 entries stay, newest first. */
		public function test_the_0_5_1_entry_stays_below_the_release(): void {
			$readme = (string) file_get_contents( self::root() . '/readme.txt' );
			$changelog = substr( $readme, (int) strpos( $readme, '== Changelog ==' ) );
			self::assertMatchesRegularExpression( '/\A== Changelog ==\s+= 0\.5\.3 =.*?= 0\.5\.2 =.*?= 0\.5\.1 =.*?= 0\.5\.0 =/s', $changelog, 'The 0.5.2, 0.5.1 and 0.5.0 entries stay, in order' );
		}

		/** The public plugin page never says "approval": the purchasing system approves, not the shop (its history too). */
		public function test_the_readme_never_says_approval(): void {
			$readme = (string) file_get_contents( self::root() . '/readme.txt' );
			self::assertSame( 0, preg_match_all( '/approv/i', $readme ), 'readme.txt still says approval' );
		}

		/** The binding rule changed: a visit leaves through checkout, so no current document says checkout is blocked in a visit. */
		public function test_no_current_document_keeps_the_old_no_checkout_rule(): void {
			$root    = self::root();
			$files   = array_merge( array_filter( [ $root . '/AGENTS.md' ], 'is_file' ), [ $root . '/README.md', $root . '/tests/README.md', $root . '/includes/Docs/Reference.php', $root . '/templates/docs/page.php' ], glob( $root . '/docs/*.md' ) ?: [] );
			$readme  = (string) file_get_contents( $root . '/readme.txt' );
			$current = [ 'readme.txt (description and FAQ)' => substr( $readme, 0, (int) strpos( $readme, '== Changelog ==' ) ) ];
			foreach ( $files as $file ) { $current[ substr( $file, strlen( $root ) + 1 ) ] = (string) file_get_contents( $file ); }
			foreach ( $current as $name => $text ) {
				// The single-login spec keeps its 0.4.0 wording as history under a 0.5.0 note; its first paragraphs must carry that note.
				if ( 'docs/single-login-mode.md' === $name ) { self::assertStringContainsString( '0.5.0 (October 2026) changed the way out of a visit', $text ); continue; }
				foreach ( [ '/PunchOut-only exit/i', '/\bno checkout\b/i', '/checkout is blocked/i', '/checkout is not available inside/i', '/checkout stays refused/i', '/\bPunchOut only\.\*\*/', '/\[punchout_return_button\][^.\n]*(placement|placing|cart button)/i' ] as $pattern ) {
					self::assertSame( 0, preg_match( $pattern, $text, $match ), $name . ' still says: ' . ( $match[0] ?? '' ) );
				}
			}
			self::assertStringContainsString( 'Does it change my checkout?', $readme );
			self::assertMatchesRegularExpression( '/= Does it change my checkout\? =\s*\n\s*\nOnly inside a punchout visit\./', $readme );
		}

		/**
		 * The test fixtures use neutral names: every e-mail address under tests/ is on a reserved domain (RFC 2606,
		 * RFC 6761), and no fixture carries a name from the maintainer's private list. That list never lives in this
		 * repository: it is read from POW_PRIVATE_NAMES (comma-separated) or from tests/.private-names (one per
		 * line, ignored by git), and with neither the second check has nothing to look for.
		 */
		public function test_the_test_fixtures_use_neutral_names(): void {
			$private = getenv( 'POW_PRIVATE_NAMES' );
			$needles = false === $private ? [] : explode( ',', $private );
			$listed  = dirname( __DIR__ ) . '/.private-names';
			if ( is_file( $listed ) ) { $needles = array_merge( $needles, (array) file( $listed, FILE_IGNORE_NEW_LINES ) ); }
			$needles  = array_values( array_filter( array_map( 'trim', $needles ), static fn( string $n ): bool => '' !== $n && '#' !== $n[0] ) );
			$reserved = '/(^|\.)(example|test|invalid|localhost)$|(^|\.)example\.(com|net|org)$/i';
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || ! in_array( $file->getExtension(), [ 'php', 'py', 'js', 'md', 'json', 'xml' ], true ) ) { continue; }
				$source = (string) file_get_contents( $file->getPathname() );
				preg_match_all( '/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})\b/', $source, $found );
				foreach ( $found[1] as $i => $domain ) {
					self::assertSame( 1, preg_match( $reserved, $domain ), $file->getFilename() . ' uses an e-mail address off a reserved domain: ' . $found[0][ $i ] );
				}
				foreach ( $needles as $needle ) {
					self::assertSame( false, stripos( $source, $needle ), $file->getFilename() . ' names a client or person from the private list' );
				}
			}
		}

		/** Since 0.5.0: the cancel control's default label is "Cancel and return", everywhere it is stated. */
		public function test_the_cancel_controls_default_label_is_cancel_and_return(): void {
			$root = self::root();
			self::assertStringContainsString( "__( 'Cancel and return', 'punchout-woocommerce' )", (string) file_get_contents( $root . '/includes/Cart/Surface.php' ) );
			foreach ( [ 'includes/Cart/Surface.php', 'includes/Admin/Page.php', 'README.md', 'docs/delivery.md' ] as $file ) {
				self::assertStringNotContainsString( 'Return without a cart', (string) file_get_contents( $root . '/' . $file ), $file );
			}
			self::assertStringContainsString( '“Cancel and return”', (string) file_get_contents( $root . '/includes/Admin/Page.php' ) );
			self::assertStringContainsString( '| Cancel button label | "Cancel and return" |', (string) file_get_contents( $root . '/README.md' ) );
		}

		/** Uninstall removes the payment method's own option with the rest. */
		public function test_uninstall_deletes_the_payment_methods_settings(): void {
			$uninstall = (string) file_get_contents( self::root() . '/uninstall.php' );
			self::assertStringContainsString( "delete_option( 'woocommerce_punchout_settings' );", $uninstall );
			self::assertTrue( strpos( $uninstall, "delete_option( 'woocommerce_punchout_settings' );" ) > strpos( $uninstall, 'POW_KEEP_DATA' ), 'Kept with the rest when data is kept' );
		}

		/** The troubleshooting page says what a checkout with no payment method at all means. */
		public function test_the_troubleshooting_page_explains_a_checkout_with_no_payment_method(): void {
			$doc = (string) file_get_contents( self::root() . '/docs/troubleshooting.md' );
			self::assertMatchesRegularExpression( '/\| Checkout offers no payment method at all[^|]*\|[^|]*wp_pow_sessions/', $doc );
		}
	}
}
