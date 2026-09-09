<?php
/**
 * Execute the endpoint with real parser/registry/limiters and recorded I/O boundaries. Removing or moving the edge check must expose body reads, parsing, audit rows and downstream counter charges.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Buyers\Provisioner;
use POW\Cxml\Builder;
use POW\Cxml\Parser;
use POW\Http\RateLimiter;
use POW\Http\SetupEndpoint;
use POW\Logger;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\Store;

final class SetupEdgeLimitTest extends TestCase {
	private array $saved_globals = [];
	private array $server = [];
	private array $downstream = [];
	private bool $libxml_errors = false;
	private SetupEdgeAudit $audit;
	private SetupEdgeDatabase $database;

	protected function setUp(): void {
		if ( ! class_exists( DOMDocument::class ) ) {
			self::markTestSkipped( 'ext-dom required for real endpoint responses and parsing' );
		}
		$this->server = $_SERVER;
		$this->libxml_errors = libxml_use_internal_errors( false );
		foreach ( [ 'wpdb', 'pow_test_setup_io', 'pow_test_status_headers', 'pow_test_filters' ] as $key ) {
			$this->saved_globals[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
		}
		$this->database = new SetupEdgeDatabase();
		$GLOBALS['wpdb'] = $this->database;
		$GLOBALS['pow_test_setup_io'] = [ 'body' => '', 'reads' => 0, 'parses' => 0, 'headers' => [] ];
		$GLOBALS['pow_test_status_headers'] = [];
		$GLOBALS['pow_test_filters'] = [];
		$this->audit = new SetupEdgeAudit();
		$this->downstream = [];
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		libxml_use_internal_errors( $this->libxml_errors );
		foreach ( $this->saved_globals as $key => [ $existed, $value ] ) {
			if ( $existed ) {
				$GLOBALS[ $key ] = $value;
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
	}

	private function endpoint( int $edge_limit, int $downstream_limit = 10 ): SetupEndpoint {
		$edge = [];
		$logger = new Logger( new \POW\Settings() );
		$store = new Store();
		return new SetupEndpoint(
			new Registry( new Secrets( str_repeat( 't', 32 ) ) ),
			$store,
			new Provisioner( $store, $this->audit, $logger ),
			new Parser(),
			new Builder(),
			new RateLimiter( $downstream_limit, fn( string $key ): int => $this->downstream[ $key ] ?? 0, function ( string $key, int $count ): void { $this->downstream[ $key ] = $count; } ),
			$this->audit,
			new RateLimiter( $edge_limit, static function ( string $key ) use ( &$edge ): int { return $edge[ $key ] ?? 0; }, static function ( string $key, int $count ) use ( &$edge ): void { $edge[ $key ] = $count; } )
		);
	}

	private function request( SetupEndpoint $endpoint, string $body, string $ip = '203.0.113.9', string $method = 'POST', string $type = 'text/xml' ): int {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['CONTENT_TYPE'] = $type;
		$GLOBALS['pow_test_setup_io']['body'] = $body;
		ob_start();
		try {
			$endpoint->handle();
			$xml = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$doc = new DOMDocument();
		self::assertTrue( $doc->loadXML( $xml ) );
		self::assertSame( 200, end( $GLOBALS['pow_test_status_headers'] ) );
		self::assertSame( 'Content-Type: text/xml; charset=utf-8', end( $GLOBALS['pow_test_setup_io']['headers'] ) );
		return (int) $doc->getElementsByTagName( 'Status' )->item( 0 )->getAttribute( 'code' );
	}

	private function valid_body( string $sender = 'unknown' ): string {
		return '<cXML version="1.2.008" payloadID="edge-test"><Header><From><Credential domain="NetworkID"><Identity>buyer</Identity></Credential></From><To><Credential domain="NetworkID"><Identity>shop</Identity></Credential></To><Sender><Credential domain="NetworkID"><Identity>' . $sender . '</Identity><SharedSecret>fixture-secret</SharedSecret></Credential></Sender></Header><Request><PunchOutSetupRequest operation="create"><BuyerCookie>cookie</BuyerCookie><BrowserFormPost><URL>https://buyer.example.test/return</URL></BrowserFormPost></PunchOutSetupRequest></Request></cXML>';
	}

	public function test_edge_accepts_exactly_n_then_rejects_without_read_parse_archive_lookup_or_downstream_charge(): void {
		$endpoint = $this->endpoint( 3 );
		for ( $i = 0; $i < 3; $i++ ) {
			self::assertSame( 401, $this->request( $endpoint, $this->valid_body( 'sender-' . $i ) ) );
		}
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['parses'] );
		self::assertCount( 3, $this->database->lookups );
		self::assertSame( [ 'setup_rx', 'setup_fail', 'setup_rx', 'setup_fail', 'setup_rx', 'setup_fail' ], array_column( $this->audit->rows, 0 ) );
		self::assertSame( [ 3 ], array_values( $this->downstream ) );
		$rows = $this->audit->rows;
		$counters = $this->downstream;
		// A new credential cannot evade the per-IP budget; malformed and oversized input must not even reach Parser::parse or read_body.
		foreach ( [ $this->valid_body( 'another-sender' ), '<invalid', str_repeat( 'x', SetupEndpoint::MAX_BODY_BYTES + 1 ) ] as $body ) {
			self::assertSame( 550, $this->request( $endpoint, $body ) );
		}
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['parses'] );
		self::assertCount( 3, $this->database->lookups );
		self::assertSame( $rows, $this->audit->rows );
		self::assertSame( $counters, $this->downstream );
		self::assertSame( 401, $this->request( $endpoint, $this->valid_body(), '203.0.113.10' ) );
		self::assertSame( [ 3, 1 ], array_values( $this->downstream ) );
	}

	public function test_edge_rejection_never_enters_provisioning_for_an_authenticated_sender(): void {
		$secret = ( new Secrets( str_repeat( 't', 32 ) ) )->seal( 'fixture-secret' );
		$this->database->partner = [
			'id' => 7, 'name' => 'Test buyer', 'status' => 'active',
			'from_domain' => 'NetworkID', 'from_identity' => 'buyer',
			'sender_domain' => 'NetworkID', 'sender_identity' => 'known',
			'to_domain' => 'NetworkID', 'to_identity' => 'shop',
			'secret_current' => $secret, 'secret_previous' => '', 'secret_rotated_at' => null,
			'cxml_version' => '1.2.008', 'deployment_mode' => 'test', 'return_encoding' => 'base64',
			'mode' => 'requisition_only', 'allow_reentry' => 0, 'allcaps_transform' => 0,
			'gateway_allowlist' => null, 'company_profile' => null, 'ip_allowlist' => null,
			'session_ttl' => 14400, 'token_ttl' => 300, 'owner_user_id' => 0,
		];
		$provisions = 0;
		$GLOBALS['pow_test_filters']['pow_buyer_identity'] = static function ( $identity ) use ( &$provisions ) {
			$provisions++;
			// Stop at the real Provisioner's first external boundary, before user mutation.
			throw new \RuntimeException( 'Test stopped at buyer identity resolution' );
		};
		$endpoint = $this->endpoint( 1 );
		self::assertSame( 500, $this->request( $endpoint, $this->valid_body( 'known' ) ) );
		self::assertSame( 1, $provisions );
		self::assertSame( 'Request processing failed', $this->audit->rows[1][1]['detail']['error'] );
		self::assertCount( 2, $this->database->lookups ); // Partner resolution and replay lookup both executed.
		$rows = $this->audit->rows;
		$counters = $this->downstream;
		self::assertSame( 550, $this->request( $endpoint, $this->valid_body( 'known' ) ) );
		self::assertSame( 1, $provisions );
		self::assertSame( 1, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 1, $GLOBALS['pow_test_setup_io']['parses'] );
		self::assertCount( 2, $this->database->lookups );
		self::assertSame( $rows, $this->audit->rows );
		self::assertSame( $counters, $this->downstream );
	}

	public function test_edge_counts_invalid_method_and_content_type_before_body_or_audit(): void {
		$endpoint = $this->endpoint( 2 );
		self::assertSame( 406, $this->request( $endpoint, '', method: 'GET' ) );
		self::assertSame( 406, $this->request( $endpoint, '', type: 'text/plain' ) );
		$rows = $this->audit->rows;
		self::assertSame( 550, $this->request( $endpoint, '', method: 'GET' ) );
		self::assertSame( 550, $this->request( $endpoint, $this->valid_body() ) );
		self::assertSame( 0, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 0, $GLOBALS['pow_test_setup_io']['parses'] );
		self::assertSame( $rows, $this->audit->rows );
		self::assertSame( [], $this->database->lookups );
		self::assertSame( [], $this->downstream );
	}

	public function test_downstream_and_parser_rules_still_run_below_the_edge_threshold(): void {
		$endpoint = $this->endpoint( 3, 1 );
		self::assertSame( 401, $this->request( $endpoint, $this->valid_body() ) );
		self::assertSame( 550, $this->request( $endpoint, $this->valid_body() ) );
		self::assertSame( 406, $this->request( $endpoint, '<invalid' ) );
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['parses'] );
		self::assertCount( 2, $this->database->lookups );
		self::assertSame( [ 1 ], array_values( $this->downstream ) );
		self::assertSame( 550, $this->request( $endpoint, '<invalid' ) );
	}
}

final class SetupEdgeAudit extends Log {
	public array $rows = [];
	public function __construct() {}
	public function write_checked( string $event, array $context = [] ): bool { $this->write( $event, $context ); return true; }
	public function write( string $event, array $context = [] ): void {
		$this->rows[] = [ $event, $context ];
	}
}

final class SetupEdgeDatabase {
	public string $prefix = 'wp_';
	public ?array $partner = null;
	public array $lookups = [];
	public function prepare( string $sql, mixed ...$args ): array {
		return [ $sql, $args ];
	}
	public function get_row( array $query, string $format ): ?array {
		$this->lookups[] = $query;
		return str_contains( $query[0], 'wp_pow_partners' ) ? $this->partner : null;
	}
}
