<?php
/**
 * Execute the endpoint with real parser/registry/limiters and recorded I/O boundaries. Removing or moving the edge check must expose body reads, parsing, audit rows and downstream counter charges.
 *
 * The same harness proves the single-login setup rules, because they need the
 * real endpoint too: an unbound connection is refused before any visit row
 * exists, a ProfileRequest still answers without a login, two buyer
 * identities of one connection hold two independent visits, the same identity
 * supersedes only its own, and the per-connection cap refuses the one visit
 * past the limit.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Cxml\Builder;
use POW\Cxml\Parser;
use POW\Http\RateLimiter;
use POW\Http\SetupEndpoint;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\Session;
use POW\Sessions\Store;

final class SetupEdgeLimitTest extends TestCase {
	private const OWNER_ID = 501;

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
		foreach ( [ 'wpdb', 'pow_test_setup_io', 'pow_test_status_headers', 'pow_test_filters', 'pow_test_environment_type', 'pow_test_users' ] as $key ) {
			$this->saved_globals[ $key ] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ];
		}
		$this->database = new SetupEdgeDatabase();
		$GLOBALS['wpdb'] = $this->database;
		$GLOBALS['pow_test_setup_io'] = [ 'body' => '', 'reads' => 0, 'parses' => 0, 'headers' => [] ];
		$GLOBALS['pow_test_status_headers'] = [];
		$GLOBALS['pow_test_filters'] = [];
		$GLOBALS['pow_test_users'] = [];
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
		return new SetupEndpoint(
			new Registry( new Secrets( str_repeat( 't', 32 ) ) ),
			new Store(),
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

	/**
	 * @param array<string, string> $extrinsics Buyer identity extrinsics.
	 */
	private function valid_body( string $sender = 'unknown', string $payload_id = 'edge-test', array $extrinsics = [] ): string {
		$nodes = '';
		foreach ( $extrinsics as $name => $value ) {
			$nodes .= '<Extrinsic name="' . $name . '">' . $value . '</Extrinsic>';
		}
		return '<cXML version="1.2.008" payloadID="' . $payload_id . '"><Header><From><Credential domain="NetworkID"><Identity>buyer</Identity></Credential></From><To><Credential domain="NetworkID"><Identity>shop</Identity></Credential></To><Sender><Credential domain="NetworkID"><Identity>' . $sender . '</Identity><SharedSecret>fixture-secret</SharedSecret></Credential></Sender></Header><Request><PunchOutSetupRequest operation="create"><BuyerCookie>cookie</BuyerCookie>' . $nodes . '<BrowserFormPost><URL>https://buyer.example.test/return</URL></BrowserFormPost></PunchOutSetupRequest></Request></cXML>';
	}

	private function profile_body( string $sender = 'known' ): string {
		return '<cXML version="1.2.008" payloadID="profile-test"><Header><From><Credential domain="NetworkID"><Identity>buyer</Identity></Credential></From><To><Credential domain="NetworkID"><Identity>shop</Identity></Credential></To><Sender><Credential domain="NetworkID"><Identity>' . $sender . '</Identity><SharedSecret>fixture-secret</SharedSecret></Credential></Sender></Header><Request><ProfileRequest/></Request></cXML>';
	}

	/** The authenticated connection fixture. owner_user_id 0 is an unbound connection. */
	private function connect( int $owner_user_id = self::OWNER_ID ): void {
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
			'session_ttl' => 14400, 'token_ttl' => 300, 'owner_user_id' => $owner_user_id,
		];
	}

	/**
	 * The connection's own customer account. Its capability reads are counted,
	 * which is the boundary the edge limiter must never let a request reach —
	 * the seam the removed provisioner's identity filter used to provide.
	 *
	 * @param array<string, bool> $caps
	 */
	private function bind_account( array $caps = [ 'read' => true ], int $id = self::OWNER_ID ): SetupEdgeAccount {
		$account = new SetupEdgeAccount( $id, $caps );
		$GLOBALS['pow_test_users'][ $id ] = $account;
		return $account;
	}

	/** @return list<array<string, mixed>> */
	private function visits(): array {
		return array_values( $this->database->sessions );
	}

	public function test_production_http_refuses_before_body_parse_audit_or_state_even_with_test_deployment(): void {
		$GLOBALS['pow_test_environment_type'] = 'production';
		$_SERVER['HTTPS'] = 'off'; $_SERVER['SERVER_PORT'] = '80';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$xml = str_replace( '<cXML ', '<cXML deploymentMode="test" ', $this->valid_body() );
		self::assertSame( 401, $this->request( $this->endpoint( 10 ), $xml ) );
		self::assertSame( 0, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 0, $GLOBALS['pow_test_setup_io']['parses'] );
		self::assertSame( [], $this->database->lookups );
		self::assertSame( [], $this->audit->rows );
		self::assertSame( [], $this->downstream );
	}

	public function test_production_setup_refuses_http_receiver_before_any_visit_row_but_local_accepts(): void {
		$this->connect();
		$account = $this->bind_account();
		$GLOBALS['pow_test_environment_type']='production'; $_SERVER['HTTPS']='on';
		$body=str_replace('https://buyer.example.test/return','http://buyer.example.test/return',$this->valid_body('known'));
		self::assertSame(406,$this->request($this->endpoint(10),$body));
		self::assertSame([],$this->visits(),'A refused punchback target leaves no visit behind');
		self::assertCount(1,$this->database->lookups); // Authentication only; no replay/session read.
		$reads = $account->reads;
		self::assertGreaterThan( 0, $reads, 'The bound account is proved before the punchback target is judged' );
		$GLOBALS['pow_test_environment_type']='local'; $_SERVER['HTTPS']='off';
		self::assertSame(200,$this->request($this->endpoint(10),$body));
		self::assertCount(1,$this->visits());
		self::assertGreaterThan( $reads, $account->reads );
		self::assertGreaterThan( 1, count( $this->database->lookups ) );
	}

	public function test_native_https_and_explicit_local_http_reach_normal_setup_processing(): void {
		foreach ( [ ['production', 'on'], ['local', 'off'], ['development', 'off'] ] as [$environment, $https] ) {
			$GLOBALS['pow_test_environment_type'] = $environment;
			$_SERVER['HTTPS'] = $https;
			self::assertSame( 401, $this->request( $this->endpoint( 10 ), $this->valid_body() ) );
		}
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 3, $GLOBALS['pow_test_setup_io']['parses'] );
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

	public function test_edge_rejection_never_reaches_the_bound_account_for_an_authenticated_sender(): void {
		$this->connect();
		$account = $this->bind_account();
		$endpoint = $this->endpoint( 1 );
		self::assertSame( 200, $this->request( $endpoint, $this->valid_body( 'known' ) ) );
		self::assertGreaterThan( 0, $account->reads );
		$reads = $account->reads;
		$lookups = $this->database->lookups;
		self::assertGreaterThan( 3, count( $lookups ) ); // Resolution, fresh authorization and exact claim reads executed.
		$rows = $this->audit->rows;
		$counters = $this->downstream;
		$visits = $this->visits();
		self::assertSame( 550, $this->request( $endpoint, $this->valid_body( 'known', 'second-payload' ) ) );
		self::assertSame( $reads, $account->reads, 'The refused request never resolved the bound account' );
		self::assertSame( 1, $GLOBALS['pow_test_setup_io']['reads'] );
		self::assertSame( 1, $GLOBALS['pow_test_setup_io']['parses'] );
		self::assertSame( $lookups, $this->database->lookups );
		self::assertSame( $rows, $this->audit->rows );
		self::assertSame( $counters, $this->downstream );
		self::assertSame( $visits, $this->visits() );
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

	/* ---------------------------------------------------------------------
	 * The bound login
	 * ------------------------------------------------------------------ */

	public function test_a_connection_with_no_account_bound_is_refused_with_a_no_login_row_and_no_visit(): void {
		$this->connect( 0 );
		self::assertSame( SetupEndpoint::STATUS_INTERNAL, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known' ) ) );
		self::assertSame( [ 'setup_rx', 'setup_no_login' ], array_column( $this->audit->rows, 0 ) );
		self::assertSame( 'no_login', $this->audit->rows[1][1]['result'] );
		self::assertSame( 7, $this->audit->rows[1][1]['partner_id'] );
		self::assertSame( [], $this->visits(), 'No claim row is created for an unbound connection' );
		// Authentication itself is charged before the connection can be judged; nothing beyond it is.
		self::assertSame( [ 1 ], array_values( $this->downstream ) );
		self::assertCount( 1, $this->database->lookups );
	}

	public function test_a_deleted_or_privileged_account_is_treated_as_no_login_at_all(): void {
		$this->connect();
		// Bound to an id no account answers for.
		self::assertSame( SetupEndpoint::STATUS_INTERNAL, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known' ) ) );
		self::assertSame( 'setup_no_login', $this->audit->rows[1][0] );
		self::assertSame( [], $this->visits() );

		foreach ( [ 'manage_options', 'manage_woocommerce', 'edit_users', 'install_plugins' ] as $capability ) {
			$this->audit->rows = [];
			$this->bind_account( [ 'read' => true, $capability => true ] );
			self::assertSame( SetupEndpoint::STATUS_INTERNAL, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known' ) ), $capability );
			self::assertSame( 'setup_no_login', $this->audit->rows[1][0], $capability );
			self::assertSame( [], $this->visits(), $capability );
		}

		// An account that cannot even read is no login either.
		$this->audit->rows = [];
		$this->bind_account( [] );
		self::assertSame( SetupEndpoint::STATUS_INTERNAL, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known' ) ) );
		self::assertSame( 'setup_no_login', $this->audit->rows[1][0] );
		self::assertSame( [], $this->visits() );
	}

	public function test_a_profile_request_still_answers_for_an_unbound_connection(): void {
		$this->connect( 0 );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $this->endpoint( 10 ), $this->profile_body() ) );
		self::assertSame( [ 'setup_rx', 'profile_rx' ], array_column( $this->audit->rows, 0 ) );
		self::assertSame( [], $this->visits(), 'A ProfileRequest creates no visit' );
	}

	public function test_a_committed_visit_carries_the_bound_account_and_the_buyer_identity(): void {
		$this->connect();
		$this->bind_account();
		self::assertSame(
			SetupEndpoint::STATUS_OK,
			$this->request( $this->endpoint( 10 ), $this->valid_body( 'known', 'p1', [ 'UserEmail' => 'JDoe@Example.TEST', 'UserPrintableName' => 'J Doe' ] ) )
		);
		$visit = $this->visits()[0];
		self::assertSame( self::OWNER_ID, (int) $visit['user_id'], 'The visit commits against the connection\'s own account' );
		self::assertSame( 'jdoe@example.test', $visit['buyer_identity'] );
		self::assertSame( 'J Doe', $visit['buyer_name'] );
		self::assertSame( hash( 'sha256', '7|jdoe@example.test' ), $visit['buyer_identity_hash'] );
		self::assertSame( Session::PENDING, $visit['status'] );

		$ok = $this->audit->rows[ count( $this->audit->rows ) - 1 ];
		self::assertSame( 'setup_ok', $ok[0] );
		self::assertSame( substr( hash( 'sha256', '7|jdoe@example.test' ), 0, 12 ), $ok[1]['detail']['buyer_hash'] );
		self::assertSame( 'J Doe', $ok[1]['detail']['buyer_name'] );
		self::assertStringNotContainsString( 'jdoe@example.test', (string) wp_json_encode( array_column( $this->audit->rows, 1 ) ), 'The raw buyer e-mail is never logged' );
	}

	public function test_an_anonymous_visit_commits_with_no_identity_and_says_so_in_the_log(): void {
		$this->connect();
		$this->bind_account();
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known', 'p1' ) ) );
		$visit = $this->visits()[0];
		self::assertSame( '', $visit['buyer_identity'] );
		self::assertSame( '', $visit['buyer_name'] );
		self::assertNull( $visit['buyer_identity_hash'], 'An unidentified buyer stores no hash to be superseded by' );
		self::assertSame( 'none', $this->audit->rows[ count( $this->audit->rows ) - 1 ][1]['detail']['buyer'] );
	}

	public function test_two_buyer_identities_of_one_connection_hold_two_live_visits(): void {
		$this->connect();
		$this->bind_account();
		$endpoint = $this->endpoint( 10 );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $this->valid_body( 'known', 'p1', [ 'UserEmail' => 'first@example.test' ] ) ) );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $this->valid_body( 'known', 'p2', [ 'UserEmail' => 'second@example.test' ] ) ) );

		$visits = $this->visits();
		self::assertCount( 2, $visits );
		self::assertSame( [ Session::PENDING, Session::PENDING ], array_column( $visits, 'status' ), 'A colleague punching in must not end the first visit' );
		self::assertSame( [ self::OWNER_ID, self::OWNER_ID ], array_map( 'intval', array_column( $visits, 'user_id' ) ) );
		self::assertFalse( in_array( 'session_expired', array_column( $this->audit->rows, 0 ), true ) );
	}

	public function test_two_anonymous_visits_of_one_connection_never_supersede_each_other(): void {
		$this->connect();
		$this->bind_account();
		$endpoint = $this->endpoint( 10 );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $this->valid_body( 'known', 'p1' ) ) );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $this->valid_body( 'known', 'p2' ) ) );
		self::assertSame( [ Session::PENDING, Session::PENDING ], array_column( $this->visits(), 'status' ) );
		self::assertFalse( in_array( 'session_expired', array_column( $this->audit->rows, 0 ), true ) );
	}

	public function test_the_same_buyer_identity_supersedes_only_its_own_earlier_visit(): void {
		$this->connect();
		$this->bind_account();
		$endpoint = $this->endpoint( 10 );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $this->valid_body( 'known', 'p1', [ 'UserEmail' => 'first@example.test' ] ) ) );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $this->valid_body( 'known', 'p2', [ 'UserEmail' => 'colleague@example.test' ] ) ) );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $this->valid_body( 'known', 'p3', [ 'UserEmail' => 'first@example.test' ] ) ) );

		$statuses = [];
		foreach ( $this->database->sessions as $row ) {
			$statuses[ $row['payload_id'] ] = $row['status'];
		}
		self::assertSame( [ 'p1' => Session::EXPIRED, 'p2' => Session::PENDING, 'p3' => Session::PENDING ], $statuses );

		$expired = array_values( array_filter( $this->audit->rows, static fn( array $row ): bool => 'session_expired' === $row[0] ) );
		self::assertCount( 1, $expired );
		self::assertSame( 'superseded', $expired[0][1]['result'] );
		self::assertSame( substr( hash( 'sha256', '7|first@example.test' ), 0, 12 ), $expired[0][1]['detail']['buyer_hash'] );
		self::assertStringNotContainsString( 'first@example.test', (string) wp_json_encode( $expired[0][1] ) );
	}

	public function test_the_connection_cap_refuses_the_visit_past_the_limit_and_creates_no_row(): void {
		$this->connect();
		$this->bind_account();
		$this->database->fill_open_visits( 7, Store::MAX_OPEN_VISITS );
		self::assertSame( SetupEndpoint::STATUS_INTERNAL, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known', 'over-cap' ) ) );

		$cap = array_values( array_filter( $this->audit->rows, static fn( array $row ): bool => 'setup_visit_cap' === $row[0] ) );
		self::assertCount( 1, $cap );
		self::assertSame( 'cap', $cap[0][1]['result'] );
		self::assertSame( Store::MAX_OPEN_VISITS, $cap[0][1]['detail']['limit'] );
		self::assertCount( Store::MAX_OPEN_VISITS, $this->database->sessions, 'The refused visit inserts nothing' );
		self::assertSame( [], array_filter( $this->database->sessions, static fn( array $row ): bool => 'over-cap' === $row['payload_id'] ) );
	}

	public function test_expired_visits_are_swept_before_the_cap_is_compared(): void {
		$this->connect();
		$this->bind_account();
		$this->database->fill_open_visits( 7, Store::MAX_OPEN_VISITS, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known', 'after-sweep' ) ) );
		self::assertCount( Store::MAX_OPEN_VISITS + 1, $this->database->sessions );
		self::assertSame(
			[ Session::PENDING ],
			array_values( array_unique( array_column( array_filter( $this->database->sessions, static fn( array $row ): bool => 'after-sweep' === $row['payload_id'] ), 'status' ) ) )
		);
		self::assertSame( [ Session::EXPIRED ], array_values( array_unique( array_column( array_filter( $this->database->sessions, static fn( array $row ): bool => 'after-sweep' !== $row['payload_id'] ), 'status' ) ) ) );
	}

	public function test_another_connections_expired_visits_are_left_to_their_own_lock(): void {
		$this->connect();
		$this->bind_account();
		$this->database->fill_open_visits( 9, 3, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known', 'mine' ) ) );
		$others = array_filter( $this->database->sessions, static fn( array $row ): bool => 9 === (int) $row['partner_id'] );
		self::assertCount( 3, $others );
		self::assertSame( [ Session::ACTIVE ], array_values( array_unique( array_column( $others, 'status' ) ) ) );
	}

	public function test_a_replay_answers_from_the_stored_response_even_at_the_cap(): void {
		$this->connect();
		$this->bind_account();
		$endpoint = $this->endpoint( 10 );
		$body = $this->valid_body( 'known', 'p1', [ 'UserEmail' => 'first@example.test' ] );
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $body ) );
		$this->database->fill_open_visits( 7, Store::MAX_OPEN_VISITS );

		// The cap guards new visits, not the re-delivery of one already answered:
		// a buyer whose response was lost in transit must still get it back.
		self::assertSame( SetupEndpoint::STATUS_OK, $this->request( $endpoint, $body ) );
		self::assertSame( 'replay', $this->audit->rows[ count( $this->audit->rows ) - 1 ][1]['result'] );
		self::assertCount( Store::MAX_OPEN_VISITS + 1, $this->database->sessions );
		self::assertFalse( in_array( 'setup_visit_cap', array_column( $this->audit->rows, 0 ), true ) );
	}

	public function test_a_connection_repointed_between_claim_and_commit_does_not_commit_the_visit(): void {
		$this->connect();
		$this->bind_account();
		// The claim is written, then the connection loses its login before the commit re-proof.
		$this->database->after_insert = function ( SetupEdgeDatabase $database ): void {
			$database->partner['owner_user_id'] = 0;
			$database->after_insert = null;
		};
		self::assertSame( SetupEndpoint::STATUS_INTERNAL, $this->request( $this->endpoint( 10 ), $this->valid_body( 'known', 'repointed' ) ) );
		self::assertSame( [], $this->visits(), 'The abandoned claim is released' );
		self::assertFalse( in_array( 'setup_ok', array_column( $this->audit->rows, 0 ), true ) );
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

/**
 * The connection's customer account.
 *
 * Every capability read is counted through __get, which is what makes "the
 * edge limiter never let the request reach the bound account" provable now
 * that no user is ever created: the account resolution is the endpoint's
 * first step past authentication, and the only one with a user-side cost.
 */
final class SetupEdgeAccount {
	public int $reads = 0;

	/** @param array<string, bool> $caps */
	public function __construct( public int $ID, private array $caps = [ 'read' => true ] ) {}

	public function __get( string $name ): mixed {
		if ( 'allcaps' !== $name ) {
			return null;
		}
		++$this->reads;
		return $this->caps;
	}
}

/**
 * The database boundary: one connection row and a SET of visit rows.
 *
 * A single-row session fixture could not see the rules this endpoint now
 * carries — two colleagues of one connection holding two visits, the same
 * buyer superseding only its own, and the per-connection cap — so the table
 * is keyed by id, the (partner_id, payload_id) UNIQUE key is enforced on
 * insert, and the three statements the setup path issues against a visit
 * (claim completion, status transition, claim release) are interpreted from
 * their prepared arguments.
 */
final class SetupEdgeDatabase {
	/** @var list<string> Statuses every 'open visit' query counts. */
	private const OPEN = [ Session::PENDING, Session::ACTIVE, Session::ORDERED ];

	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public ?array $partner = null;
	/** @var array<int, array<string, mixed>> Visit rows keyed by id. */
	public array $sessions = [];
	public array $lookups = [];
	/** Fired after a visit row is inserted — the claim/commit window. */
	public ?\Closure $after_insert = null;
	private int $next_id = 0;

	/** Pre-load a connection with open visits, to reach its cap. */
	public function fill_open_visits( int $partner_id, int $count, string $expires = '' ): void {
		$expires = '' !== $expires ? $expires : gmdate( 'Y-m-d H:i:s', time() + 3600 );
		for ( $i = 0; $i < $count; $i++ ) {
			$id = ++$this->next_id;
			$this->sessions[ $id ] = [
				'id' => $id, 'partner_id' => $partner_id, 'user_id' => 501, 'wp_session_token' => '',
				'one_time_token_hash' => 'seed-' . $id, 'status' => Session::ACTIVE, 'order_id' => 0,
				'payload_id' => 'seed-' . $partner_id . '-' . $i, 'body_hash' => str_repeat( 'a', 64 ),
				'response_xml' => '<seed/>', 'expires' => $expires, 'created' => gmdate( 'Y-m-d H:i:s' ),
				'buyer_identity' => 'seed' . $i . '@example.test', 'buyer_name' => '',
				'buyer_identity_hash' => hash( 'sha256', $partner_id . '|seed' . $i . '@example.test' ),
			];
		}
	}

	public function suppress_errors( bool $suppress ): bool { return false; }

	public function prepare( string $sql, mixed ...$args ): array {
		return [ $sql, $args ];
	}

	public function get_row( array $query, string $format ): ?array {
		[ $sql, $args ] = $query;
		$this->lookups[] = $query;
		if ( str_contains( $sql, 'wp_pow_partners' ) ) { return $this->partner; }
		if ( ! str_contains( $sql, 'wp_pow_sessions' ) ) { return null; }
		if ( str_contains( $sql, 'WHERE id = %d' ) ) { return $this->sessions[ (int) $args[0] ] ?? null; }
		foreach ( $this->sessions as $row ) {
			if ( (int) $args[0] === (int) $row['partner_id'] && (string) $args[1] === (string) $row['payload_id'] ) { return $row; }
		}
		return null;
	}

	/** @return list<array<string, mixed>> */
	public function get_results( array $query, string $format ): array {
		[ $sql, $args ] = $query;
		$this->lookups[] = $query;
		if ( ! str_contains( $sql, 'wp_pow_sessions' ) ) { return []; }

		if ( str_contains( $sql, 'buyer_identity_hash = %s' ) ) {
			return array_values(
				array_filter(
					$this->sessions,
					static fn( array $row ): bool => (int) $args[0] === (int) $row['partner_id'] && '' !== (string) $args[1] && (string) $args[1] === (string) ( $row['buyer_identity_hash'] ?? '' ) && in_array( $row['status'], self::OPEN, true )
				)
			);
		}

		if ( str_contains( $sql, 'expires < %s' ) ) {
			return array_values(
				array_filter(
					$this->sessions,
					static fn( array $row ): bool => in_array( $row['status'], self::OPEN, true ) && (string) $row['expires'] < (string) $args[3]
				)
			);
		}

		return [];
	}

	public function get_var( array $query ): string {
		[ $sql, $args ] = $query;
		if ( str_contains( $sql, 'GET_LOCK' ) || str_contains( $sql, 'RELEASE_LOCK' ) ) { return '1'; }
		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'wp_pow_sessions' ) ) {
			$this->lookups[] = $query;
			$open = array_filter( $this->sessions, static fn( array $row ): bool => (int) $args[0] === (int) $row['partner_id'] && in_array( $row['status'], self::OPEN, true ) );
			return (string) count( $open );
		}
		return '';
	}

	public function insert( string $table, array $data ): int {
		if ( ! str_contains( $table, 'wp_pow_sessions' ) ) { return 0; }
		foreach ( $this->sessions as $row ) {
			// UNIQUE KEY (partner_id, payload_id).
			if ( (int) $row['partner_id'] === (int) ( $data['partner_id'] ?? 0 ) && (string) $row['payload_id'] === (string) ( $data['payload_id'] ?? '' ) ) { return 0; }
		}
		$id = ++$this->next_id;
		$this->insert_id = $id;
		$this->sessions[ $id ] = array_replace( [ 'wp_session_token' => '', 'order_id' => 0, 'created' => gmdate( 'Y-m-d H:i:s' ) ], $data, [ 'id' => $id ] );
		if ( $this->after_insert instanceof \Closure ) { ( $this->after_insert )( $this ); }
		return 1;
	}

	public function query( array $query ): int|false {
		[ $sql, $args ] = $query;

		if ( str_starts_with( $sql, 'DELETE FROM wp_pow_sessions' ) ) {
			$row = $this->sessions[ (int) $args[0] ] ?? null;
			if ( ! $row || Session::PENDING !== $row['status'] || 0 !== (int) $row['user_id'] || null !== ( $row['response_xml'] ?? null ) ) { return false; }
			unset( $this->sessions[ (int) $args[0] ] );
			return 1;
		}

		if ( str_contains( $sql, 'SET user_id = %d, response_xml = %s' ) ) {
			$id  = (int) $args[2];
			$row = $this->sessions[ $id ] ?? null;
			if ( ! $row || (int) $args[3] !== (int) $row['partner_id'] || (string) $args[4] !== (string) $row['payload_id'] || (string) $args[5] !== (string) $row['body_hash'] || (string) $args[6] !== (string) $row['one_time_token_hash'] || (string) $args[7] !== (string) $row['status'] || 0 !== (int) $row['user_id'] || null !== ( $row['response_xml'] ?? null ) || (string) $row['expires'] <= (string) $args[8] ) { return false; }
			$this->sessions[ $id ]['user_id']      = (int) $args[0];
			$this->sessions[ $id ]['response_xml'] = (string) $args[1];
			return 1;
		}

		if ( str_starts_with( $sql, 'UPDATE wp_pow_sessions SET status = %s' ) ) {
			$id  = (int) $args[1];
			$row = $this->sessions[ $id ] ?? null;
			if ( ! $row || (string) $row['status'] !== (string) $args[2] ) { return false; }
			$this->sessions[ $id ]['status'] = (string) $args[0];
			return 1;
		}

		return false;
	}
}
