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
	public static function render( string $name, array $vars ): string {
		self::$view = $vars['view'];
		return '';
	}
}

function is_user_logged_in(): bool { return state()->actor > 0; }
function wc_get_cart_url(): string { return '/cart/'; }
function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }
function wp_verify_nonce( string $nonce, string $action ): int|false { return $nonce === wp_create_nonce( $action ) ? 1 : false; }
function plugins_url( string $path, string $file ): string { return $path; }
function get_bloginfo( string $field ): string { return 'Example shop'; }

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
use POW\Tests\DeliveryChooserFlow\{Chooser, Confirmation, Plugin, PolicyDatabase, ReturnEndpoint, State, Templates};

final class DeliveryChooserFlowTest extends TestCase {
	/** The same two keys the reused fixture declares as VISIT_KEY and SIBLING_KEY; they are literals here because that namespace's constants only exist once load_flow_source() has run. */
	private const KEY = 'pow_1a2b3c4d5e6f708192a3b4c5d6e7';
	private const OTHER_KEY = 'pow_00112233445566778899aabbccdd';
	private State $state;
	private Confirmation $model;
	private Chooser $chooser;
	private ReturnEndpoint $return_endpoint;
	private array $globals_before = [];

	protected function setUp(): void {
		foreach ( [ 'wpdb', 'delivery_chooser_flow_state', '_POST', '_SERVER', 'pow_test_status_headers' ] as $key ) {
			$this->globals_before[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ];
		}
		\POW\Tests\DeliveryChooserFlow\load_flow_source();
		$GLOBALS['wpdb'] = new PolicyDatabase();
		$this->state = new State();
		$GLOBALS['delivery_chooser_flow_state'] = $this->state;
		$this->state->registry->partner = Partner::from_row( [ 'id' => 7, 'owner_user_id' => 99, 'status' => 'active', 'emit_delivery_line' => true ] );
		$this->state->store->session = Session::from_row( [ 'id' => 42, 'partner_id' => 7, 'user_id' => 99, 'wp_session_token' => 'exact-token', 'status' => 'active', 'expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'wc_session_key' => self::KEY ] );
		$address = [ 'first_name' => 'Ada', 'last_name' => 'Buyer', 'company' => 'Company', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'Pretoria', 'state' => 'GP', 'postcode' => '0001', 'country' => 'ZA', 'phone' => '' ];
		$depot = [ 'schema' => 1, 'partner_id' => 7, 'storage_user_id' => 99, 'provider' => 'native', 'key' => 'depot', 'code' => 'DEPOT', 'address' => $address, 'label' => 'Depot', 'source' => 'company_book', 'book_revision' => 1, 'entry_fingerprint' => str_repeat( 'a', 64 ) ];
		$coast = array_replace( $depot, [ 'key' => 'coast', 'code' => 'COAST', 'label' => 'Coast', 'address' => array_replace( $address, [ 'city' => 'Durban' ] ), 'entry_fingerprint' => str_repeat( 'b', 64 ) ] );
		$this->state->resolver->choices = [ $depot, $coast ];
		$s = $this->state;
		$this->model = new Confirmation( $s->registry, $s->store, $s->address, $s->estimate, $s->resolver, $s->mapper );
		$this->return_endpoint = new ReturnEndpoint();
		$this->chooser = new Chooser( new Plugin(), $s->registry, $s->store, $this->model, $this->return_endpoint );
		Templates::$view = null;
	}

	protected function tearDown(): void {
		Templates::$view = null;
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
}
}
