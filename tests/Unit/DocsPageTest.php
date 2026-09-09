<?php
/**
 * The documentation page's renderer: the machine tokens Samples hands it
 * are worded here, everything an attacker can reach is escaped on the way
 * out, and the self-test only runs on a nonced POST.
 *
 * The templates are rendered through Support\Templates with fabricated
 * data rather than through Docs\Page, so the escaping rules are proved on
 * a stock PHP with no ext-dom, no database and no WordPress.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Cxml\Parser;
use POW\Docs\Page;
use POW\Docs\Reference;
use POW\Docs\SelfTest;
use POW\Http\RateLimiter;
use POW\Logger;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Settings;
use POW\Support\Templates;

final class DocsPageTest extends TestCase {

	/** A paste that carries both a live secret and an injection attempt. */
	private const HOSTILE_XML = '<cXML><SharedSecret>hunter2</SharedSecret><script>alert(1)</script></cXML>';

	protected function tearDown(): void {
		unset( $GLOBALS['pow_test_translations'], $GLOBALS['pow_test_valid_nonce'], $GLOBALS['pow_test_nocache_headers'] );
	}

	/**
	 * Samples::annotations() reports ShipTo as the machine token
	 * 'present'/'absent'. The token is not English the page may print: it
	 * goes through __() here or it is a bug.
	 */
	public function test_ship_to_token_is_worded_through_translation(): void {
		$GLOBALS['pow_test_translations'] = [
			'present' => 'aanwesig',
			'absent'  => 'afwesig',
		];

		self::assertSame( 'aanwesig', Page::annotation_value( 'ShipTo', 'present' ) );
		self::assertSame( 'afwesig', Page::annotation_value( 'ShipTo', 'absent' ) );
	}

	/**
	 * Every other annotation is a value read off the parsed document —
	 * data, not vocabulary — so it is passed through unchanged.
	 */
	public function test_other_annotations_are_not_translated(): void {
		$GLOBALS['pow_test_translations'] = [ 'create' => 'skep' ];

		self::assertSame( 'create', Page::annotation_value( 'PunchOutSetupRequest/@operation', 'create' ) );
	}

	/* ---------------------------------------------------------------------
	 * The self-test only runs on a nonced POST
	 * ------------------------------------------------------------------ */

	public function test_a_get_is_never_a_self_test_submission(): void {
		$GLOBALS['pow_test_valid_nonce'] = 'good';

		self::assertFalse(
			Page::is_self_test_request(
				'GET',
				[
					'pow_docs_xml' => '<cXML/>',
					'_wpnonce'     => 'good',
				]
			)
		);
	}

	public function test_a_post_without_a_valid_nonce_is_refused(): void {
		$GLOBALS['pow_test_valid_nonce'] = 'good';

		self::assertFalse(
			Page::is_self_test_request(
				'POST',
				[
					'pow_docs_xml' => '<cXML/>',
					'_wpnonce'     => 'forged',
				]
			)
		);

		self::assertFalse( Page::is_self_test_request( 'POST', [ 'pow_docs_xml' => '<cXML/>' ] ) );
	}

	public function test_a_nonced_post_is_a_submission(): void {
		$GLOBALS['pow_test_valid_nonce'] = 'good';

		self::assertTrue(
			Page::is_self_test_request(
				'POST',
				[
					'pow_docs_xml' => '<cXML/>',
					'_wpnonce'     => 'good',
				]
			)
		);
	}

	/**
	 * An expired or forged nonce must not be a silent no-op: the visitor
	 * is told why the page came back unchanged.
	 */
	public function test_an_expired_nonce_says_so(): void {
		$GLOBALS['pow_test_valid_nonce'] = 'good';

		$notice = Page::expired_nonce_notice(
			'POST',
			[
				'pow_docs_xml' => '<cXML/>',
				'_wpnonce'     => 'stale',
			]
		);

		self::assertNotSame( '', $notice );
		self::assertStringContainsString( 'expired', strtolower( $notice ) );
	}

	public function test_a_valid_submission_and_a_plain_get_get_no_expiry_notice(): void {
		$GLOBALS['pow_test_valid_nonce'] = 'good';

		self::assertSame(
			'',
			Page::expired_nonce_notice(
				'POST',
				[
					'pow_docs_xml' => '<cXML/>',
					'_wpnonce'     => 'good',
				]
			)
		);

		self::assertSame( '', Page::expired_nonce_notice( 'GET', [] ) );
		self::assertSame( '', Page::expired_nonce_notice( 'POST', [] ) );
	}

	/**
	 * The notice reaches the page, escaped like everything else.
	 */
	public function test_the_expiry_notice_is_rendered(): void {
		$GLOBALS['pow_test_valid_nonce'] = 'good';

		$notice = Page::expired_nonce_notice(
			'POST',
			[
				'pow_docs_xml' => '<cXML/>',
				'_wpnonce'     => 'stale',
			]
		);

		self::assertStringContainsString( $notice, $this->self_test_html( null, '', $notice ) );
	}

	/**
	 * pow_docs_xml[]=x makes $_POST[ FIELD ] an array, and casting an
	 * array to string is a PHP warning printed on a public page. Both
	 * gates take a document or they take nothing.
	 */
	public function test_an_array_in_the_document_field_is_not_a_submission(): void {
		$GLOBALS['pow_test_valid_nonce'] = 'good';

		$post = [
			'pow_docs_xml' => [ '<cXML/>' ],
			'_wpnonce'     => 'good',
		];

		self::assertFalse( Page::is_self_test_post( 'POST', $post ) );
		self::assertFalse( Page::is_self_test_request( 'POST', $post ) );
		self::assertSame( '', Page::expired_nonce_notice( 'POST', $post ) );
	}

	/* ---------------------------------------------------------------------
	 * The public self-test is never unthrottled, and never cached
	 * ------------------------------------------------------------------ */

	/**
	 * 0 means "no limit" for the setup endpoint, which is the store's
	 * choice to make about its own endpoint. The self-test is an
	 * anonymous XML parser and a credential oracle on a public page, so
	 * it keeps a floor whatever the setting says.
	 */
	public function test_the_self_test_bucket_has_a_floor(): void {
		self::assertSame( 10, Page::self_test_limit( 0 ) );
		self::assertSame( 10, Page::self_test_limit( -5 ) );
		self::assertSame( 10, Page::self_test_limit( 3 ) );
		self::assertSame( 60, Page::self_test_limit( 60 ) );
	}

	/**
	 * A privileged render carries the partner roster, and a self-test POST
	 * carries a report about a stranger's paste. Neither may be stored by
	 * a page cache and handed to the next anonymous visitor.
	 */
	public function test_a_privileged_render_is_never_page_cached(): void {
		self::assertTrue( Page::suppress_page_cache( true, 'GET', [] ) );
		self::assertSame( 1, $GLOBALS['pow_test_nocache_headers'] ?? 0 );
	}

	public function test_a_self_test_post_is_never_page_cached(): void {
		self::assertTrue( Page::suppress_page_cache( false, 'POST', [ 'pow_docs_xml' => '<cXML/>' ] ) );
		self::assertSame( 1, $GLOBALS['pow_test_nocache_headers'] ?? 0 );
	}

	/**
	 * The public page itself is identical for every visitor, so an
	 * anonymous GET stays cacheable.
	 */
	public function test_an_anonymous_get_stays_cacheable(): void {
		self::assertFalse( Page::suppress_page_cache( false, 'GET', [] ) );
		self::assertSame( 0, $GLOBALS['pow_test_nocache_headers'] ?? 0 );
	}

	/* ---------------------------------------------------------------------
	 * The self-test is never built half-wired
	 * ------------------------------------------------------------------ */

	/**
	 * A Registry means the partner stage runs, and the partner stage is
	 * only safe throttled and logged. This asserts the object the page
	 * actually builds carries all four collaborators — it fails if the
	 * limiter or the log is dropped from that constructor call.
	 */
	public function test_the_self_test_is_built_with_a_real_limiter_and_log(): void {
		$registry = new Registry( new Secrets( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$limiter  = new RateLimiter( 30, static fn ( string $k ): int => 0, static function ( string $k, int $c ): void {} );
		$log      = new Log( new Logger( new Settings() ) );

		$built = Page::self_test( $registry, $limiter, $log );

		self::assertInstanceOf( Parser::class, self::collaborator( $built, 'parser' ) );
		self::assertSame( $registry, self::collaborator( $built, 'registry' ) );
		self::assertSame( $limiter, self::collaborator( $built, 'limiter' ), 'a registry without a limiter sells unmetered credential guesses' );
		self::assertSame( $log, self::collaborator( $built, 'log' ), 'an oracle nobody can see being used is worse than no oracle' );
	}

	/**
	 * The three are required, so a half-wired self-test cannot be
	 * expressed at the call site at all.
	 */
	public function test_the_collaborators_cannot_be_omitted(): void {
		$parameters = ( new ReflectionMethod( Page::class, 'self_test' ) )->getParameters();

		self::assertCount( 3, $parameters );

		foreach ( $parameters as $parameter ) {
			self::assertFalse( $parameter->isOptional(), $parameter->getName() . ' must be required' );
			self::assertFalse( $parameter->allowsNull(), $parameter->getName() . ' must not be nullable' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Output escaping
	 * ------------------------------------------------------------------ */

	/**
	 * The render guard logs whatever went wrong, and what went wrong may
	 * quote the visitor's paste — which may carry a live secret.
	 */
	public function test_a_render_failure_is_logged_redacted(): void {
		$detail = Page::failure_detail(
			new RuntimeException( 'Parse failed near <SharedSecret>hunter2</SharedSecret>' )
		);

		self::assertStringNotContainsString( 'hunter2', $detail );
		self::assertStringContainsString( '[redacted]', $detail );
	}

	/**
	 * Every self-test detail is attacker-controlled: the parser's own
	 * message, the sender identity, the BuyerCookie, the punchback URL.
	 */
	public function test_self_test_results_are_escaped(): void {
		$html = $this->self_test_html(
			[
				'checks'       => [
					[
						'label'  => 'BuyerCookie',
						'result' => 'info',
						'detail' => '<script>alert(1)</script>',
					],
					[
						'label'  => '<img src=x onerror=alert(2)>',
						'result' => 'fail',
						'detail' => 'Attribute "on\'error" is not allowed',
					],
				],
				'verdict'      => 406,
				'verdict_text' => 'Invalid document',
			]
		);

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * The paste is echoed back into the textarea so the buyer can correct
	 * it — through redact() first, because it may carry a live secret.
	 */
	public function test_the_pasted_document_is_redacted_and_escaped(): void {
		$html = $this->self_test_html( null, self::HOSTILE_XML );

		self::assertStringNotContainsString( 'hunter2', $html );
		self::assertStringContainsString( '[redacted]', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}

	public function test_the_self_test_form_is_a_nonced_post(): void {
		$html = $this->self_test_html();

		self::assertStringContainsString( 'method="post"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
		self::assertStringContainsString( 'name="pow_docs_xml"', $html );
	}

	/**
	 * A notice (punchout switched off, or the throttle) replaces the run,
	 * and is never a place to smuggle markup either.
	 */
	public function test_a_notice_replaces_the_report(): void {
		$html = $this->self_test_html( null, '', 'Punchout is currently switched off.' );

		self::assertStringContainsString( 'Punchout is currently switched off.', $html );
	}

	/**
	 * The page prints annotation values read off a document a stranger
	 * sent us; the fixture is ours today, the escaping is not optional.
	 */
	public function test_page_escapes_annotation_values(): void {
		$html = Templates::render( 'docs/page', $this->page_vars() );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * The samples are XML: they must reach the page as text inside <pre>,
	 * not as markup the browser tries to run.
	 */
	public function test_page_escapes_the_sample_documents(): void {
		$html = Templates::render( 'docs/page', $this->page_vars() );

		self::assertStringContainsString( '&lt;cXML&gt;', $html );
		self::assertStringNotContainsString( '<cXML>', $html );
	}

	/**
	 * The connection block is admin-only: a public visitor never learns
	 * which buyers are connected.
	 */
	public function test_connection_block_is_privileged_only(): void {
		$admin  = $this->page_vars();
		$public = $this->page_vars();

		$admin['privileged']   = true;
		$admin['connections']  = [
			[
				'name'            => 'Example Buyer',
				'sender'          => 'NetworkId / AN01000000123-T',
				'cxml_version'    => '1.2.008',
				'deployment_mode' => 'test',
				'return_encoding' => 'cxml-base64',
			],
		];
		$public['connections'] = $admin['connections'];

		self::assertStringContainsString( 'Example Buyer', Templates::render( 'docs/page', $admin ) );
		self::assertStringNotContainsString( 'Example Buyer', Templates::render( 'docs/page', $public ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * One of SelfTest's private collaborators, read back off the built
	 * object: the wiring is the thing under test, and SelfTest exposes no
	 * getters (nor should it).
	 *
	 * @return mixed
	 */
	private static function collaborator( SelfTest $self_test, string $property ) {
		$reflected = new ReflectionProperty( SelfTest::class, $property );
		$reflected->setAccessible( true );

		return $reflected->getValue( $self_test );
	}

	/**
	 * @param ?array<string, mixed> $report Self-test report, or null.
	 */
	private function self_test_html( ?array $report = null, string $xml = '', string $notice = '' ): string {
		return Templates::render(
			'docs/self-test',
			[
				'report'     => $report,
				'xml'        => $xml,
				'notice'     => $notice,
				'privileged' => false,
			]
		);
	}

	/**
	 * The page's variable contract, with markup planted in every value the
	 * page takes from outside itself.
	 *
	 * @return array<string, mixed>
	 */
	private function page_vars(): array {
		return [
			'reference'      => new Reference( 'https://shop.example.com', 'DUNS', 'SUPPLIER-DUNS', false ),
			'annotations'    => [
				'BuyerCookie' => '<script>alert(1)</script>',
				'ShipTo'      => 'present',
			],
			'setup_request'  => '<cXML>setup</cXML>',
			'setup_response' => '<cXML>response</cXML>',
			'poom'           => '<cXML>poom</cXML>',
			'poom_total'     => 'R 599.00',
			'cxml_version'   => '1.2.008',
			'rate_limit'     => 30,
			'connections'    => [],
			'self_test'      => '',
			'privileged'     => false,
		];
	}
}
