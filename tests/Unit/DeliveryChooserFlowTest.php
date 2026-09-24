<?php
/**
 * Executes the actual Chooser and Confirmation with isolated service collaborators. No WP/Woo runtime, SQL connection, HTTP, rendering or concurrent-process proof.
 * The two-zone quote double supplies offers/defaults; the production model decides whether posted rates, destination, notes and consent may proceed.
 */
declare( strict_types = 1 );

namespace POW\Tests\DeliveryChooserFlow {

use PHPUnit\Framework\TestCase;
use POW\Partners\Partner;
use POW\Sessions\Session;

const POW_PLUGIN_FILE = __FILE__;

final class Plugin {
	public function enabled(): bool { return true; }
}

/** Stands in for POW\Sessions\Current so the Chooser resolves its visit through this namespace's actor, token and store doubles. */
final class Current {
	public function __construct( private mixed $sessions ) {}
	public function visit( array $statuses = [ Session::ACTIVE, Session::ORDERED ] ): ?Session {
		return is_user_logged_in() ? $this->sessions->find_for_login( get_current_user_id(), wp_get_session_token(), $statuses ) : null;
	}
}

final class ReturnEndpoint {
	public int $handoffs = 0;
	public function handle(): void { ++$this->handoffs; }
}

final class Templates {
	public static ?array $view = null;
	public static ?array $vars = null;
	public static function render( string $name, array $vars ): string {
		self::$view = $vars['view'];
		self::$vars = $vars;
		return '';
	}
}

/** Stands in for Addresses\CompanyBook's one visit write; the lock, flag and ownership checks it makes are CompanyBookTest's. */
final class CompanyBook {
	/** @var list<array{int, string, array}> */
	public array $calls = [];
	public mixed $result = null;
	public function add_for_visit( Session $visit, string $label, array $address ): array|\WP_Error {
		$this->calls[] = [ $visit->id, $label, $address ];
		return is_callable( $this->result ) ? ( $this->result )() : $this->result;
	}
}

/** The buyer form's schema and draft come from the real Addresses\Fields; only the native field markup is replaced. */
final class Fields {
	public static function buyer_post_allowed( array $post ): bool { return \POW\Addresses\Fields::buyer_post_allowed( $post ); }
	public static function buyer_draft( array $post ): array { return \POW\Addresses\Fields::buyer_draft( $post ); }
	public static function address_inputs( string $id_prefix, string $country, array $address ): string { return '<input name="shipping_city">'; }
}

function is_user_logged_in(): bool { return state()->actor > 0; }
function wc_get_cart_url(): string { return '/cart/'; }
function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }
function wp_verify_nonce( string $nonce, string $action ): int|false { return $nonce === wp_create_nonce( $action ) ? 1 : false; }
function plugins_url( string $path, string $file ): string { return $path; }
function get_bloginfo( string $field ): string { return 'Example shop'; }
function wp_safe_redirect( string $url, int $status = 302 ): bool { $GLOBALS['pow_test_redirects'][] = [ $url, $status ]; return true; }

/** Checked replacements bind collaborators only; production method bodies stay intact. */
function replace_once( string $source, string $from, string $to ): string {
	$result = str_replace( $from, $to, $source, $count );
	if ( 1 !== $count ) { throw new \LogicException( 'Source fixture binding changed: ' . $from ); }
	return $result;
}

function load_flow_source(): void {
	if ( class_exists( __NAMESPACE__ . '\\Chooser', false ) ) { return; }
	// Reuse service-fixture definitions in a private namespace without loading its test class or changing the other suite's state or owner-controlled file.
	$fixture = file_get_contents( __DIR__ . '/DeliveryConfirmationTest.php' );
	$parts = explode( "\nnamespace {", $fixture, 2 );
	if ( 2 !== count( $parts ) ) { throw new \LogicException( 'Confirmation fixture boundary changed.' ); }
	$fixture = str_replace(
		[ 'POW\\Tests\\DeliveryConfirmation', 'POW\\\\Tests\\\\DeliveryConfirmation', "'confirmation_test_state'", '__DIR__' ],
		[ __NAMESPACE__, str_replace( '\\', '\\\\', __NAMESPACE__ ), "'delivery_chooser_flow_state'", var_export( __DIR__, true ) ],
		$parts[0]
	);
	$fixture = replace_once( $fixture, 'final class Store {', <<<'STORE'
final class Store {
	public function find_for_login(int $buyer, string $token, array $statuses): ?Session {
		return $buyer === $this->session->user_id && $token === $this->session->wp_session_token && in_array($this->session->status, $statuses, true) ? $this->session : null;
	}
STORE
	);
	$fixture = replace_once( $fixture, 'final class DeliveryEstimate {', <<<'OFFERS'
final class DeliveryEstimate {
	public array $offers = ['Pretoria' => ['flat:1' => 500, 'shared' => 800], 'Durban' => ['flat:2' => 900, 'shared' => 800]];
OFFERS
	);
	$fixture = replace_once( $fixture, "$" . "chosen=state()->session->get('chosen_shipping_methods',[0=>'flat:1']);", <<<'DEFAULTS'
$available=$this->offers[$destination['address']['city']??'']??[];
$chosen=state()->session->get('chosen_shipping_methods',[0=>array_key_first($available)]);
if (!isset($available[$chosen[0]??''])) { $chosen=[0=>array_key_first($available)]; }
DEFAULTS
	);
	$fixture = replace_once( $fixture, "foreach(['flat:1'=>\$this->amount,'flat:2'=>900] as \$id=>\$amount)", 'foreach($available as $id=>$amount)' );
	// WC()->countries, for the base country an empty add-address form starts from.
	$fixture = replace_once( $fixture, 'public function shipping():Shipping{return $this->shipping;}', 'public function shipping():Shipping{return $this->shipping;} public ?object $countries = null;' );
	eval( substr( $fixture, 5 ) );
	load_source(); // Existing loader now binds the actual Confirmation to this namespace.

	$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Addresses/Chooser.php' );
	$source = replace_once( $source, 'namespace POW\\Addresses;', 'namespace ' . __NAMESPACE__ . '; use POW\\Addresses\\DeliveryData;' );
	foreach ( [ 'use POW\\Http\\ReturnEndpoint;', 'use POW\\Plugin;', 'use POW\\Support\\Templates;', 'use POW\\Sessions\\ConsentFence;', 'use POW\\Sessions\\Current;' ] as $import ) { $source = replace_once( $source, $import, '' ); }
	$source = replace_once( $source, 'use POW\\Partners\\Registry;', '' );
	$source = replace_once( $source, 'use POW\\Sessions\\{Session, Store};', 'use POW\\Sessions\\Session;' );
	eval( substr( $source, 5 ) );
}

}

namespace {

use PHPUnit\Framework\TestCase;
use POW\Partners\Partner;
use POW\Sessions\Session;
use POW\Tests\DeliveryChooserFlow\{Chooser, CompanyBook, Confirmation, Plugin, PolicyDatabase, ReturnEndpoint, State, Templates};

final class DeliveryChooserFlowTest extends TestCase {
	/** The same two keys the reused fixture declares as VISIT_KEY and SIBLING_KEY; they are literals here because that namespace's constants only exist once load_flow_source() has run. */
	private const KEY = 'pow_1a2b3c4d5e6f708192a3b4c5d6e7';
	private const OTHER_KEY = 'pow_00112233445566778899aabbccdd';
	private State $state;
	private Confirmation $model;
	private Chooser $chooser;
	private ReturnEndpoint $return_endpoint;
	private CompanyBook $book;
	private array $globals_before = [];

	protected function setUp(): void {
		foreach ( [ 'wpdb', 'delivery_chooser_flow_state', '_POST', '_SERVER', 'pow_test_status_headers', 'pow_test_redirects' ] as $key ) {
			$this->globals_before[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ];
		}
		\POW\Tests\DeliveryChooserFlow\load_flow_source();
		$GLOBALS['wpdb'] = new PolicyDatabase();
		$this->state = new State();
		$GLOBALS['delivery_chooser_flow_state'] = $this->state;
		$this->state->countries = new class() { public function get_base_country(): string { return 'ZA'; } };
		$this->state->registry->partner = Partner::from_row( [ 'id' => 7, 'owner_user_id' => 99, 'status' => 'active', 'emit_delivery_line' => true ] );
		$this->state->store->session = Session::from_row( [ 'id' => 42, 'partner_id' => 7, 'user_id' => 99, 'wp_session_token' => 'exact-token', 'status' => 'active', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'wc_session_key' => self::KEY ] );
		$address = [ 'first_name' => 'Ada', 'last_name' => 'Buyer', 'company' => 'Company', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'Pretoria', 'state' => 'GP', 'postcode' => '0001', 'country' => 'ZA', 'phone' => '' ];
		$depot = [ 'schema' => 1, 'partner_id' => 7, 'storage_user_id' => 99, 'provider' => 'native', 'key' => 'depot', 'code' => 'DEPOT', 'address' => $address, 'label' => 'Depot', 'source' => 'company_book', 'book_revision' => 1, 'entry_fingerprint' => str_repeat( 'a', 64 ) ];
		$coast = array_replace( $depot, [ 'key' => 'coast', 'code' => 'COAST', 'label' => 'Coast', 'address' => array_replace( $address, [ 'city' => 'Durban' ] ), 'entry_fingerprint' => str_repeat( 'b', 64 ) ] );
		$this->state->resolver->choices = [ $depot, $coast ];
		$s = $this->state;
		$this->model = new Confirmation( $s->registry, $s->store, $s->address, $s->estimate, $s->resolver, $s->mapper );
		$this->return_endpoint = new ReturnEndpoint();
		$this->book = new CompanyBook();
		$this->chooser = new Chooser( new Plugin(), $s->registry, $s->store, $this->model, $this->return_endpoint, null, $this->book );
		Templates::$view = null;
	}

	protected function tearDown(): void {
		Templates::$view = null;
		Templates::$vars = null;
		foreach ( $this->globals_before as $key => [ $existed, $value ] ) {
			if ( $existed ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); }
		}
	}

	private function request( array $fields = [] ): array {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array_replace( [ 'pow_nonce' => 'nonce-pow_confirm_delivery', 'pow_return_nonce' => 'nonce-pow_return', 'pow_delivery_action' => 'review' ], $fields );
		Templates::$view = null;
		ob_start();
		try { $this->chooser->handle(); } finally { ob_end_clean(); }
		return Templates::$view ?? [];
	}

	private function initial_review(): array {
		$view = $this->request();
		self::assertNull( $view['error'] );
		self::assertSame( 'depot', $view['selected_choice']['key'] );
		self::assertSame( 'flat:1', $view['packages'][0]['selected_rate_id'] );
		return $view;
	}

	private function assert_no_consent_or_handoff(): void {
		self::assertSame( 0, $this->state->store->writes );
		self::assertNull( $this->state->store->session->delivery_confirmation_json );
		self::assertSame( 0, $this->return_endpoint->handoffs );
	}

	public function test_address_change_reviews_new_native_default_without_storing_consent(): void {
		$first = $this->initial_review();
		$view = $this->request( [ 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:1' ], 'notes' => "Use gate\nRing bell", 'review_digest' => $first['review_digest'] ] );
		self::assertNull( $view['error'], 'The old rate must not trap a valid address change on the previous destination.' );
		self::assertSame( 'coast', $view['selected_choice']['key'] );
		self::assertSame( 'Durban', $view['delivery_destination']['address']['city'] );
		self::assertSame( 'flat:2', $view['packages'][0]['selected_rate_id'] );
		self::assertSame( 900, $view['delivery']['amount_cents'] );
		self::assertSame( "Use gate\nRing bell", $view['notes'] );
		self::assertNotSame( $first['review_digest'], $view['review_digest'] );
		self::assertTrue( $view['can_confirm'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_valid_nondefault_rate_is_preserved_on_same_address_review(): void {
		$this->initial_review();
		$view = $this->request( [ 'choice' => 'native:depot', 'rates' => [ 0 => 'shared' ] ] );
		self::assertNull( $view['error'] );
		self::assertSame( 'shared', $view['packages'][0]['selected_rate_id'] );
		self::assertSame( 800, $view['delivery']['amount_cents'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_rate_still_offered_at_new_address_is_not_replaced_by_its_default(): void {
		$this->initial_review();
		$view = $this->request( [ 'choice' => 'native:coast', 'rates' => [ 0 => 'shared' ] ] );
		self::assertNull( $view['error'] );
		self::assertSame( 'coast', $view['selected_choice']['key'] );
		self::assertSame( 'shared', $view['packages'][0]['selected_rate_id'] );
		self::assertSame( 800, $view['delivery']['amount_cents'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_retry_without_available_rates_remains_unknown_and_unconfirmable(): void {
		$this->initial_review();
		$this->state->estimate->unknown = true;
		$view = $this->request( [ 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:1' ] ] );
		self::assertSame( 'coast', $view['selected_choice']['key'], 'Even an unavailable quote must describe the newly selected destination.' );
		self::assertSame( 'unknown', $view['delivery']['status'] );
		self::assertNull( $view['delivery']['amount_cents'] );
		self::assertFalse( $view['can_confirm'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_address_removed_during_first_quote_cannot_be_authorized_by_retry(): void {
		$this->initial_review();
		$this->state->estimate->callback = function (): void {
			$this->state->resolver->choices = [ $this->state->resolver->choices[0] ];
			$this->state->estimate->callback = null;
		};
		$view = $this->request( [ 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:1' ] ] );
		self::assertInstanceOf( WP_Error::class, $view['error'] );
		self::assertSame( 'address_unavailable', $view['error']->get_error_code() );
		self::assertFalse( $view['can_confirm'] );
		self::assertNotSame( 'coast', $view['selected_choice']['key'] ?? null );
		$this->assert_no_consent_or_handoff();
	}

	public function test_native_quote_failure_during_retry_does_not_restore_usable_consent(): void {
		$this->initial_review();
		$quotes = 0;
		$this->state->estimate->callback = static function () use ( &$quotes ): void {
			if ( 2 === ++$quotes ) { throw new RuntimeException( 'Carrier unavailable during the renewed review.' ); }
		};
		$view = $this->request( [ 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:1' ] ] );
		self::assertInstanceOf( WP_Error::class, $view['error'] );
		self::assertSame( 'delivery_review_required', $view['error']->get_error_code(), 'The retry must report the quote failure, not replay the obsolete rate error.' );
		self::assertFalse( $view['can_confirm'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_submit_never_drops_invalid_rate_even_when_digest_would_otherwise_match(): void {
		$first = $this->initial_review();
		$view = $this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:depot', 'rates' => [ 0 => 'forged' ], 'review_digest' => $first['review_digest'] ] );
		self::assertInstanceOf( WP_Error::class, $view['error'] );
		self::assertSame( 'delivery_rate_invalid', $view['error']->get_error_code() );
		self::assertFalse( $view['can_confirm'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_submit_with_changed_destination_and_old_digest_is_refused(): void {
		$first = $this->initial_review();
		$view = $this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:2' ], 'review_digest' => $first['review_digest'] ] );
		self::assertInstanceOf( WP_Error::class, $view['error'] );
		self::assertSame( 'delivery_review_required', $view['error']->get_error_code() );
		$this->assert_no_consent_or_handoff();
	}

	public function test_only_explicit_submit_of_new_review_persists_destination_rate_and_notes(): void {
		$this->initial_review();
		$view = $this->request( [ 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:1' ], 'notes' => 'Use gate' ] );
		self::assertNull( $view['error'] );
		$this->assert_no_consent_or_handoff();
		$this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:2' ], 'notes' => 'Use gate', 'review_digest' => $view['review_digest'] ] );
		self::assertSame( 1, $this->return_endpoint->handoffs );
		self::assertSame( 1, $this->state->store->writes );
		self::assertSame( 'coast', $this->state->store->session->delivery_choice()['key'] );
		$accepted = $this->state->store->session->delivery_confirmation();
		self::assertSame( 'Use gate', $accepted['notes'] );
		self::assertSame( 900, $accepted['delivery']['amount_cents'] );
		self::assertSame( 'flat:2', $accepted['delivery']['rates'][0]['rate_id'] );
	}

	public function test_unknown_retry_still_requires_explicit_quote_separately_acknowledgement(): void {
		$this->state->registry->partner = Partner::from_row( array_replace( get_object_vars( $this->state->registry->partner ), [ 'delivery_unknown_policy' => 'quote_separately' ] ) );
		$this->initial_review();
		$this->state->estimate->unknown = true;
		$view = $this->request( [ 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:1' ] ] );
		self::assertNull( $view['error'] );
		self::assertTrue( $view['requires_unknown_acknowledgement'] );
		self::assertNull( $view['delivery']['amount_cents'] );
		$this->assert_no_consent_or_handoff();
		$refused = $this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:coast', 'review_digest' => $view['review_digest'] ] );
		self::assertSame( 'delivery_acknowledgement_required', $refused['error']->get_error_code() );
		$this->assert_no_consent_or_handoff();
	}

	/** Two employees punch in on one login, so a cart mutation carrying another visit's key must leave this visit's consent alone. */
	public function test_a_cart_mutation_in_another_visit_does_not_clear_this_visits_consent(): void {
		$view = $this->initial_review();
		$this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:depot', 'rates' => [ 0 => 'flat:1' ], 'review_digest' => $view['review_digest'] ] );
		$accepted = $this->state->store->session->delivery_confirmation_json;
		self::assertNotNull( $accepted, 'The submitted review must have stored consent.' );

		$this->state->session->key = self::OTHER_KEY;
		$this->chooser->invalidate_changed_cart();
		self::assertSame( $accepted, $this->state->store->session->delivery_confirmation_json );
		self::assertSame( 0, $this->state->store->invalidations );

		$this->state->session->key = self::KEY;
		$this->chooser->invalidate_changed_cart();
		self::assertNull( $this->state->store->session->delivery_confirmation_json );
		self::assertSame( 1, $this->state->store->invalidations );
	}

	public function test_preferred_date_is_posted_through_and_kept_on_a_refused_form(): void {
		$first = $this->initial_review();
		$date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+5 days' )->format( 'Y-m-d' );
		$view = $this->request( [ 'choice' => 'native:depot', 'preferred_delivery_date' => $date ] );
		self::assertNull( $view['error'] );
		self::assertSame( $date, $view['preferred_delivery_date'] );
		self::assertSame( $first['review_digest'], $view['review_digest'], 'The date is not part of the review digest.' );
		$refused = $this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:depot', 'preferred_delivery_date' => $date, 'review_digest' => str_repeat( '0', 64 ) ] );
		self::assertInstanceOf( WP_Error::class, $refused['error'] );
		self::assertSame( $date, $refused['preferred_delivery_date'], 'A well-formed date survives a refused form.' );
		$this->assert_no_consent_or_handoff();
		$cleared = $this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:depot', 'preferred_delivery_date' => '', 'review_digest' => str_repeat( '0', 64 ) ] );
		self::assertNull( $cleared['preferred_delivery_date'] );
		foreach ( [ '2026-13-40', '<b>2026-10-07</b>', [ $date ] ] as $bad ) {
			$malformed = $this->request( [ 'choice' => 'native:depot', 'preferred_delivery_date' => $bad ] );
			self::assertSame( 'delivery_date_invalid', $malformed['error']->get_error_code() );
			self::assertNotSame( $bad, $malformed['preferred_delivery_date'] );
			self::assertFalse( $malformed['can_confirm'] );
		}
		$this->request( [ 'pow_delivery_action' => 'submit', 'choice' => 'native:depot', 'preferred_delivery_date' => $date, 'review_digest' => $first['review_digest'] ] );
		self::assertSame( 1, $this->return_endpoint->handoffs );
		self::assertSame( $date, $this->state->store->session->delivery_confirmation()['preferred_delivery_date'] );
	}

	/** A well-formed date before tomorrow on Update: the address and method still apply, the date stays in the field beside its error, and it binds nothing. */
	public function test_a_refused_past_date_on_update_stays_in_the_field_for_display_only(): void {
		$this->initial_review();
		$yesterday = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$view = $this->request( [ 'choice' => 'native:coast', 'preferred_delivery_date' => $yesterday ] );
		self::assertSame( 'delivery_date_invalid', $view['error']->get_error_code() );
		self::assertSame( 'coast', $view['selected_choice']['key'], 'The address change still applies.' );
		self::assertSame( $yesterday, $view['preferred_delivery_date'], 'What the buyer typed stays in the field.' );
		self::assertFalse( $view['can_confirm'] );
		$emptied = $this->request( [ 'choice' => 'native:coast', 'preferred_delivery_date' => '' ] );
		self::assertNull( $emptied['error'] );
		self::assertNull( $emptied['preferred_delivery_date'] );
		self::assertSame( $emptied['_confirmation_fingerprint'], $view['_confirmation_fingerprint'], 'The refused date is display only: the fingerprint binds no date.' );
		$this->assert_no_consent_or_handoff();
	}

	/** Runs the real Store expiry SQL; only database I/O and native token cleanup are replaced. */
	private function recovery_fixture(): array {
		require_once dirname( __DIR__ ) . '/Support/return-database.php';
		$db = new ReturnDatabase();
		$db->session = array_replace( $db->session, [ 'wp_session_token' => 'exact-token', 'delivery_choice' => 'accepted-choice', 'delivery_confirmation' => 'accepted-consent' ] );
		$GLOBALS['wpdb'] = $db;
		$this->state->store->change( [ 'delivery_choice_json' => 'accepted-choice', 'delivery_confirmation_json' => 'accepted-consent' ] );
		$this->state->store->fail_invalidate = true;
		$recovery = new class() extends \POW\Sessions\Store {
			public int $destroyed = 0;
			public function destroy_login_checked( Session $session ): bool { ++$this->destroyed; return true; }
		};
		$this->state->store->recovery = $recovery;
		return [ $db, $recovery ];
	}

	public function test_failed_clear_preserves_ordered_winner_at_real_store_cas_boundary(): void {
		[ $db, $recovery ] = $this->recovery_fixture();
		$interleaved = false;
		$db->before_transition = function () use ( $db, &$interleaved ): void {
			$interleaved = true;
			$db->session['status'] = Session::ORDERED;
			$db->session['order_id'] = 314;
			$this->state->store->change( [ 'status' => Session::ORDERED, 'order_id' => 314 ] );
		};
		$this->chooser->invalidate_changed_cart();
		self::assertTrue( $interleaved, 'The payment must interleave at an actually attempted recovery UPDATE.' );
		self::assertSame( 1, $db->guarded_updates );
		self::assertSame( Session::ORDERED, $db->session['status'] );
		self::assertSame( 314, $db->session['order_id'] );
		self::assertSame( 'exact-token', $db->session['wp_session_token'] );
		self::assertSame( 'accepted-consent', $db->session['delivery_confirmation'] );
		self::assertSame( 0, $recovery->destroyed );
		self::assertSame( 0, $this->state->registry->fences );
		self::assertTrue( $this->state->registry->partner->is_active() );
	}

	public function test_failed_clear_preserves_replacement_login_at_real_store_cas_boundary(): void {
		[ $db, $recovery ] = $this->recovery_fixture();
		$db->before_transition = function () use ( $db ): void {
			$db->session['wp_session_token'] = 'replacement-token';
			$this->state->store->change( [ 'wp_session_token' => 'replacement-token' ] );
		};
		$this->chooser->invalidate_changed_cart();
		self::assertSame( 1, $db->guarded_updates );
		self::assertSame( Session::ACTIVE, $db->session['status'] );
		self::assertSame( 'replacement-token', $db->session['wp_session_token'] );
		self::assertSame( 0, $recovery->destroyed );
		self::assertSame( 0, $this->state->registry->fences );
	}

	public function test_failed_recovery_still_fences_the_same_active_login(): void {
		[ $db, $recovery ] = $this->recovery_fixture();
		$db->transition_fails = true;
		$this->chooser->invalidate_changed_cart();
		self::assertSame( 1, $db->guarded_updates );
		self::assertSame( Session::ACTIVE, $db->session['status'] );
		self::assertSame( 0, $recovery->destroyed );
		self::assertSame( 1, $this->state->registry->fences );
		self::assertFalse( $this->state->registry->partner->is_active() );
	}

	// ------------------------------------------------ buyer-added addresses

	private function offer_buyer_addresses(): void {
		$this->state->registry->partner = Partner::from_row( array_replace( get_object_vars( $this->state->registry->partner ), [ 'buyer_addresses' => true ] ) );
	}

	/** What the one review form posts when its add button is pressed: the review's own fields ride along, and the add reads only its fieldset and the notes and date. */
	private function add_request( array $fields = [], array $without = [] ): array {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$post = array_replace( [ 'pow_nonce' => 'nonce-pow_confirm_delivery', 'pow_return_nonce' => 'nonce-pow_return', 'review_digest' => str_repeat( 'a', 64 ), 'choice' => 'native:coast', 'rates' => [ 0 => 'flat:1' ], 'acknowledge_unknown' => '1', 'notes' => '', 'preferred_delivery_date' => self::in_days( 14 ), 'pow_address_nonce' => 'nonce-pow_add_delivery_address', 'pow_delivery_action' => 'add_address', 'pow_address_label' => 'Site B', 'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Buyer', 'shipping_company' => '', 'shipping_address_1' => '9 Dock Road', 'shipping_address_2' => '', 'shipping_city' => 'Durban', 'shipping_state' => 'KZN', 'shipping_postcode' => '4001', 'shipping_country' => 'ZA', 'shipping_phone' => '' ], $fields );
		$_POST = array_diff_key( $post, array_flip( $without ) );
		$GLOBALS['pow_test_status_headers'] = []; $GLOBALS['pow_test_redirects'] = [];
		Templates::$view = null; Templates::$vars = null;
		ob_start();
		try { $this->chooser->handle(); } finally { ob_end_clean(); }
		return Templates::$view ?? [];
	}

	/** Opening the review page, as the redirect after an add does. */
	private function review_get(): array {
		$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
		$GLOBALS['pow_test_status_headers'] = []; $GLOBALS['pow_test_redirects'] = [];
		Templates::$view = null; Templates::$vars = null;
		ob_start();
		try { $this->chooser->handle(); } finally { ob_end_clean(); }
		return Templates::$view ?? [];
	}

	private static function in_days( int $days ): string { return ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( ( $days < 0 ? '' : '+' ) . $days . ' days' )->format( 'Y-m-d' ); }

	private function assert_expired( array $view, string $case ): void {
		self::assertSame( [ 403 ], $GLOBALS['pow_test_status_headers'], $case );
		self::assertSame( 'delivery_unavailable', $view['error']->get_error_code(), $case );
		self::assertSame( [], $this->book->calls, $case );
		self::assertNull( Templates::$vars['add_address'] ?? null, $case );
	}

	public function test_review_offers_the_add_form_only_when_the_connection_allows_it(): void {
		$this->request();
		self::assertNull( Templates::$vars['add_address'] );
		$this->offer_buyer_addresses();
		$this->request();
		$add = Templates::$vars['add_address'];
		self::assertSame( '<input name="shipping_city">', $add['fields'] );
		self::assertSame( 'nonce-pow_add_delivery_address', $add['nonce'] );
		self::assertSame( '', $add['label'] );
		self::assertFalse( $add['open'] );
		self::assertNull( $add['notice'] );
		$chooser = new Chooser( new Plugin(), $this->state->registry, $this->state->store, $this->model, $this->return_endpoint );
		$this->chooser = $chooser; $this->request();
		self::assertNull( Templates::$vars['add_address'], 'No book wired, no form.' );
	}

	public function test_add_address_is_refused_when_the_connection_does_not_offer_it(): void {
		$this->assert_expired( $this->add_request(), 'flag off' );
		$this->assert_expired( $this->add_request( [ 'pow_address_refresh' => '1' ], [ 'pow_delivery_action' ] ), 'refresh, flag off' );
		$this->offer_buyer_addresses();
		$this->chooser = new Chooser( new Plugin(), $this->state->registry, $this->state->store, $this->model, $this->return_endpoint );
		$this->assert_expired( $this->add_request(), 'no book' );
		$this->assert_no_consent_or_handoff();
	}

	public function test_add_address_needs_its_own_nonce(): void {
		$this->offer_buyer_addresses();
		$this->assert_expired( $this->add_request( [], [ 'pow_address_nonce' ] ), 'missing' );
		$this->assert_expired( $this->add_request( [ 'pow_address_nonce' => 'nonce-pow_confirm_delivery' ] ), 'the review nonce' );
		$this->assert_expired( $this->add_request( [ 'pow_address_nonce' => [ 'nonce-pow_add_delivery_address' ] ] ), 'an array' );
		$this->add_request( [], [ 'pow_nonce' ] );
		self::assertSame( [ 403 ], $GLOBALS['pow_test_status_headers'] );
		self::assertSame( [], $this->book->calls );
		$this->assert_no_consent_or_handoff();
	}

	public function test_add_address_refuses_a_forged_code_or_any_other_field(): void {
		$this->offer_buyer_addresses();
		foreach ( [ [ 'pow_address_code' => 'MINE' ], [ 'pow_address_code' => '' ], [ 'use_for_punchout' => '1' ], [ 'pow_address_key' => 'depot' ], [ 'pow_address_revision' => '1' ], [ 'pow_mode' => 'cart' ], [ 'shipping_city' => [ 'Durban' ] ], [ 'pow_address_refresh' => '2' ], [ 'notes' => [ 'Gate 3' ] ], [ 'preferred_delivery_date' => [ '2026-10-07' ] ] ] as $forged ) {
			$this->assert_expired( $this->add_request( $forged ), (string) json_encode( $forged ) );
		}
		$this->assert_no_consent_or_handoff();
	}

	private function fresh_entry_added(): void {
		$fresh = array_replace( $this->state->resolver->choices[1], [ 'key' => 'fresh', 'code' => 'BUYER-003', 'label' => 'Site B', 'entry_fingerprint' => str_repeat( 'c', 64 ) ] );
		$this->book->result = function () use ( $fresh ): array {
			if ( ! in_array( $fresh, $this->state->resolver->choices, true ) ) { $this->state->resolver->choices[] = $fresh; }
			return [ 'revision' => 2, 'key' => 'fresh', 'entry' => [], 'changed' => true ];
		};
	}

	/** Post, redirect, get: the add answers with a 303 to the review, so a reload repeats the GET and never the add. The notes and date typed into the review (not yet sent by Update) post with the add, and the next review selects the new entry and keeps them; it stores no consent. The review's other fields ride along and reach nothing. */
	public function test_a_successful_add_redirects_and_the_next_review_selects_it_with_the_notes_and_date(): void {
		$this->offer_buyer_addresses();
		$this->fresh_entry_added();
		$date = self::in_days( 5 );
		$view = $this->add_request( [ 'notes' => "Gate 3\nAsk for the site manager", 'preferred_delivery_date' => $date ] );
		self::assertSame( [], $view, 'No page is drawn in answer to the add itself.' );
		self::assertNull( Templates::$vars );
		self::assertSame( [ [ \POW\Support\Transport::supplier_url( home_url( '/punchout/confirm' ) ), 303 ] ], $GLOBALS['pow_test_redirects'] );
		self::assertSame( [], $GLOBALS['pow_test_status_headers'] );
		self::assertSame( [ [ 42, 'Site B', [ 'first_name' => 'Ada', 'last_name' => 'Buyer', 'company' => '', 'address_1' => '9 Dock Road', 'address_2' => '', 'city' => 'Durban', 'state' => 'KZN', 'postcode' => '4001', 'country' => 'ZA', 'phone' => '' ] ] ], $this->book->calls );
		$this->assert_no_consent_or_handoff();

		$view = $this->review_get();
		self::assertSame( [], $GLOBALS['pow_test_status_headers'] );
		self::assertNull( $view['error'] );
		self::assertSame( 'fresh', $view['selected_choice']['key'] );
		self::assertSame( 'Durban', $view['delivery_destination']['address']['city'] );
		self::assertTrue( $view['can_confirm'] );
		self::assertSame( "Gate 3\nAsk for the site manager", $view['notes'] );
		self::assertSame( $date, $view['preferred_delivery_date'] );
		$add = Templates::$vars['add_address'];
		self::assertStringContainsString( 'selected for this cart', (string) $add['notice'] );
		self::assertFalse( $add['open'] );
		self::assertSame( '', $add['label'], 'A saved address leaves an empty form behind.' );
		$this->assert_no_consent_or_handoff();

		// Taken once: reloading the review is the ordinary review again.
		$again = $this->review_get();
		self::assertSame( 'depot', $again['selected_choice']['key'] );
		self::assertSame( '', $again['notes'] );
		self::assertNull( Templates::$vars['add_address']['notice'] );
	}

	/** A double-click posts the add twice; the book answers the second with the same entry, and both answers are the same redirect. */
	public function test_a_repeated_add_redirects_to_the_same_selection(): void {
		$this->offer_buyer_addresses();
		$this->fresh_entry_added();
		$this->add_request();
		$this->book->result = [ 'revision' => 2, 'key' => 'fresh', 'entry' => [], 'changed' => false ];
		$this->add_request();
		self::assertCount( 2, $this->book->calls );
		self::assertSame( 303, $GLOBALS['pow_test_redirects'][0][1] );
		self::assertSame( 'fresh', $this->review_get()['selected_choice']['key'] );
		$this->assert_no_consent_or_handoff();
	}

	/** An emptied date travels as no preference; malformed notes or dates are dropped, and the review's own values stand. */
	public function test_an_add_carries_only_well_formed_notes_and_dates(): void {
		$this->offer_buyer_addresses();
		$this->fresh_entry_added();
		$this->add_request( [ 'notes' => 'Use gate 3', 'preferred_delivery_date' => '' ] );
		$view = $this->review_get();
		self::assertSame( 'Use gate 3', $view['notes'] );
		self::assertNull( $view['preferred_delivery_date'] );
		$this->add_request( [ 'notes' => "\xff", 'preferred_delivery_date' => '<b>2026-10-07</b>' ] );
		$view = $this->review_get();
		self::assertSame( 'fresh', $view['selected_choice']['key'] );
		self::assertSame( '', $view['notes'] );
		self::assertSame( self::in_days( 14 ), $view['preferred_delivery_date'] );
		$this->assert_no_consent_or_handoff();
	}

	/** The carry lives in the visit's own WooCommerce session; the review takes it once and only when it names this visit and a well-formed key. */
	public function test_the_review_takes_only_this_visits_well_formed_carry(): void {
		$this->offer_buyer_addresses();
		foreach ( [ 'another visit' => [ 'visit' => 43, 'key' => 'coast' ], 'no visit' => [ 'key' => 'coast' ], 'a malformed key' => [ 'visit' => 42, 'key' => 'coast"' ], 'not an array' => 'native:coast' ] as $case => $carry ) {
			$this->state->session->values['pow_delivery_added'] = $carry;
			$view = $this->review_get();
			self::assertSame( 'depot', $view['selected_choice']['key'], $case );
			self::assertNull( Templates::$vars['add_address']['notice'], $case );
			self::assertNull( $this->state->session->values['pow_delivery_added'] ?? null, $case . ': taken even when refused' );
		}
		$this->state->session->values['pow_delivery_added'] = [ 'visit' => 42, 'key' => 'coast' ];
		self::assertSame( 'coast', $this->review_get()['selected_choice']['key'] );
		// An entry that is gone by the time the review opens is reported, not silently replaced.
		$this->state->session->values['pow_delivery_added'] = [ 'visit' => 42, 'key' => 'gone', 'notes' => 'Keep me' ];
		$view = $this->review_get();
		self::assertSame( 'address_unavailable', $view['error']->get_error_code() );
		self::assertSame( 'Keep me', $view['notes'] );
		self::assertFalse( $view['can_confirm'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_a_book_error_keeps_the_draft_and_cannot_confirm(): void {
		$this->offer_buyer_addresses();
		$this->book->result = new WP_Error( 'address_book_invalid', 'Supply a valid delivery address, label and unused delivery code within the allowed lengths.' );
		$view = $this->add_request( [ 'pow_address_label' => 'Site "B"' ] );
		self::assertSame( [], $GLOBALS['pow_test_status_headers'] );
		self::assertSame( 'address_book_invalid', $view['error']->get_error_code() );
		self::assertSame( 'Enter an address name of at most 190 characters and a complete delivery address.', $view['error']->get_error_message() );
		self::assertFalse( $view['can_confirm'] );
		$add = Templates::$vars['add_address'];
		self::assertSame( 'Site "B"', $add['label'] );
		self::assertTrue( $add['open'] );
		self::assertNull( $add['notice'] );
		foreach ( [ 'address_add_forbidden', 'address_book_full' ] as $code ) {
			$this->book->result = new WP_Error( $code, 'Message for ' . $code );
			$view = $this->add_request();
			self::assertSame( 'Message for ' . $code, $view['error']->get_error_message() );
			self::assertFalse( $view['can_confirm'] );
		}
		self::assertSame( [], $GLOBALS['pow_test_redirects'], 'A refusal is drawn in place, never redirected.' );
		$this->assert_no_consent_or_handoff();
	}

	public function test_notes_and_date_survive_a_refused_add_and_a_country_refresh(): void {
		$this->offer_buyer_addresses();
		$date = self::in_days( 5 );
		$this->book->result = new WP_Error( 'address_book_full', 'Full' );
		$view = $this->add_request( [ 'notes' => 'Use gate 3', 'preferred_delivery_date' => $date ] );
		self::assertSame( 'address_book_full', $view['error']->get_error_code() );
		self::assertSame( 'Use gate 3', $view['notes'] );
		self::assertSame( $date, $view['preferred_delivery_date'] );
		self::assertFalse( $view['can_confirm'] );
		$view = $this->add_request( [ 'pow_address_refresh' => '1', 'notes' => 'Use gate 3', 'preferred_delivery_date' => '' ], [ 'pow_delivery_action' ] );
		self::assertCount( 1, $this->book->calls, 'The refresh asks the book nothing.' );
		self::assertNull( $view['error'] );
		self::assertSame( 'Use gate 3', $view['notes'] );
		self::assertNull( $view['preferred_delivery_date'], 'An emptied date stays empty.' );
		$view = $this->add_request( [ 'pow_address_refresh' => '1', 'notes' => "\xff", 'preferred_delivery_date' => '<b>2026-10-07</b>' ], [ 'pow_delivery_action' ] );
		self::assertSame( '', $view['notes'] );
		self::assertSame( self::in_days( 14 ), $view['preferred_delivery_date'] );
		$this->assert_no_consent_or_handoff();
	}

	public function test_a_country_refresh_redraws_the_form_without_saving(): void {
		$this->offer_buyer_addresses();
		// The refresh is its own submit button in the review form: it posts pow_address_refresh and no action.
		$view = $this->add_request( [ 'pow_address_refresh' => '1', 'pow_address_label' => 'Site C' ], [ 'pow_delivery_action' ] );
		self::assertSame( [], $GLOBALS['pow_test_status_headers'] );
		self::assertSame( [], $this->book->calls );
		self::assertNull( $view['error'] );
		$add = Templates::$vars['add_address'];
		self::assertSame( 'Site C', $add['label'] );
		self::assertTrue( $add['open'] );
		$this->assert_no_consent_or_handoff();
	}
}
}
