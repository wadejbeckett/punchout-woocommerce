<?php
/** Provider association/eligibility with isolated service boundaries, not native SQL proof. */
declare( strict_types = 1 );

namespace POW\Tests\AddressProvider {

use POW\Addresses\DeliveryData;
use POW\Partners\Partner;
use POW\Sessions\Session;

/** The visit this request is inside, and a sibling visit of the very same account. */
const VISIT_KEY = 'pow_1a2b3c4d5e6f708192a3b4c5d6e7';
const SIBLING_KEY = 'pow_00112233445566778899aabbccdd';

final class Registry {
	public array $rows = [];
	public bool $fail = false;
	public int $depth = 0;
	public mixed $on_lock = null;
	public function find( int $id ): ?Partner { if ( $this->fail ) { throw new \RuntimeException( 'PRIVATE lookup error' ); } return isset( $this->rows[$id] ) ? Partner::from_row( $this->rows[$id] ) : null; }
	public function find_by_owner( int $owner ): ?Partner { if ( $this->fail ) { throw new \RuntimeException( 'PRIVATE lookup error' ); } foreach ( $this->rows as $row ) { if ( $row['owner_user_id'] === $owner ) { return Partner::from_row( $row ); } } return null; }
	public function with_partner_lock( int $id, callable $callback ): mixed { ++$this->depth; try { if ( $this->on_lock ) { $call = $this->on_lock; $this->on_lock = null; $call(); } return $callback(); } finally { --$this->depth; } }
}
/** Sessions\Current's whole surface: which visit, if any, this request is inside. */
final class Current {
	public ?Session $live = null;
	public bool $unreachable = false;
	public int $lookups = 0;
	public function visit( array $statuses = [ Session::ACTIVE, Session::ORDERED ] ): ?Session { ++$this->lookups; if ( $this->unreachable ) { throw new \RuntimeException( 'PRIVATE session lookup error' ); } return $this->live; }
}
final class CompanyBook {
	public array $state = [ 'revision' => 3, 'addresses' => [] ];
	public bool $fail = false;
	public array $saves = [];
	public int $reads = 0;
	public function __construct( public Registry $registry ) {}
	public function read_for_partner_locked( Partner $partner ): array|\WP_Error { if ( $this->registry->depth !== 1 ) { throw new \LogicException( 'Missing or reacquired partner mutex.' ); } ++$this->reads; return $this->fail ? new \WP_Error( 'address_state_unavailable', 'Unavailable' ) : $this->state; }
	public function read( int $partner, int $actor ): array|\WP_Error { $user = \get_userdata( $actor ); return $actor === 20 || \user_can( $user, 'manage_woocommerce' ) ? $this->state : new \WP_Error( 'denied', 'Denied' ); }
	public function save( int $partner, int $actor, int $revision, ?string $key, array $fields ): array|\WP_Error { $this->saves[] = [$partner,$actor,$revision,$key,$fields]; return is_array( $this->read( $partner, $actor ) ) ? [ 'revision' => $revision+1, 'entry' => $fields ] : new \WP_Error( 'denied', 'Denied' ); }
	public static function entry_fingerprint( string $key, array $entry ): string { return DeliveryData::fingerprint( ['key'=>$key] + $entry ); }
}
final class Shape {
	public static string $error = '';
	public static function normalise( array $address ): array|\WP_Error { return '' === self::$error ? $address : new \WP_Error( self::$error, 'Current destination unavailable' ); }
}
function wp_cache_delete( mixed $key, string $group = '' ): bool { $GLOBALS['provider_cache_reads'][] = [$key,$group]; return true; }
/** The one remaining native account read: a failed one must never read as "no such account". */
function get_userdata( int $id ): object|false {
	if ( $GLOBALS['provider_user_read_fail'] ?? false ) { $GLOBALS['wpdb']->last_error = 'PRIVATE user lookup error'; return false; }
	return \get_userdata( $id );
}
function load_source(): void {
	if ( class_exists( __NAMESPACE__ . '\\Resolver', false ) ) { return; }
	foreach ( ['Provider','Resolver','NativeProvider'] as $name ) {
		$file = dirname( __DIR__, 2 ) . '/includes/Addresses/' . $name . '.php';
		\PHPUnit\Framework\TestCase::assertTrue( is_file( $file ), 'Address provider source is missing.' );
		$source = str_replace( 'namespace POW\\Addresses;', 'namespace POW\\Tests\\AddressProvider; use POW\\Addresses\\DeliveryData;', file_get_contents( $file ) );
		$source = str_replace( [ 'use POW\\Partners\\Registry;', 'use POW\\Sessions\\Current;' ], '', $source );
		eval( substr( $source, 5 ) ); // Dependency bindings only; production method bodies are unchanged.
	}
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Tests\AddressProvider\{Registry,CompanyBook,Current,Resolver,NativeProvider};
use POW\Sessions\Session;
use const POW\Tests\AddressProvider\{VISIT_KEY,SIBLING_KEY};

final class AddressProviderTest extends TestCase {
	private array $saved = [];
	private Registry $registry;
	private CompanyBook $book;
	private Current $visits;
	private Resolver $resolver;
	private NativeProvider $provider;
	protected function setUp(): void {
		\POW\Tests\AddressProvider\load_source();
		\POW\Tests\AddressProvider\Shape::$error = '';
		foreach ( ['wpdb','pow_test_users','pow_test_user_meta','pow_test_current_user_id','provider_cache_reads','provider_user_read_fail'] as $key ) { $this->saved[$key] = [array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null]; unset($GLOBALS[$key]); }
		$GLOBALS['wpdb'] = (object)['last_error'=>''];
		// One connection, one login: 20 is partner 12's bound account and 21 is partner 13's. 22 is an ordinary
		// shopper with no connection, 23 an account that can no longer read the shop, 30 a shop manager.
		$GLOBALS['pow_test_users'] = [];
		foreach ( [20,21,22,23,30] as $id ) { $GLOBALS['pow_test_users'][$id] = (object)['ID'=>$id,'roles'=>['customer'],'allcaps'=>['read'=>23!==$id,'manage_woocommerce'=>30===$id]]; }
		$GLOBALS['pow_test_user_meta'] = [];
		$GLOBALS['pow_test_current_user_id'] = 20;
		$this->registry = new Registry(); $this->registry->rows = [12=>['id'=>12,'owner_user_id'=>20,'status'=>'active'],13=>['id'=>13,'owner_user_id'=>21,'status'=>'active']];
		$this->book = new CompanyBook($this->registry);
		$this->visits = new Current();
		$address = array_fill_keys(['first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone'],'');
		$address = array_replace($address,['first_name'=>'Zoë','last_name'=>"O'Neil",'address_1'=>'1 Main Street','city'=>'Oakland','state'=>'CA','postcode'=>'94612','country'=>'US']);
		$this->book->state['addresses'] = ['enabled'=>['label'=>'Depot','address'=>$address,'code'=>'BUYER-001','use_for_punchout'=>true],'disabled'=>['label'=>'Other','address'=>$address,'code'=>'BUYER-002','use_for_punchout'=>false]];
		$this->resolver = new Resolver($this->registry,$this->book,$this->visits); $this->provider = new NativeProvider($this->registry,$this->book,$this->visits);
	}
	protected function tearDown(): void { foreach($this->saved as $key=>[$exists,$value]) { if($exists){$GLOBALS[$key]=$value;}else{unset($GLOBALS[$key]);} } }
	private function choice(): array { $entry=$this->book->state['addresses']['enabled']; return ['schema'=>1,'partner_id'=>12,'storage_user_id'=>20,'provider'=>'native','key'=>'enabled','code'=>$entry['code'],'address'=>$entry['address'],'label'=>$entry['label'],'source'=>'company_book','book_revision'=>3,'entry_fingerprint'=>CompanyBook::entry_fingerprint('enabled',$entry)]; }
	private function session( ?array $choice = null, array $row = [] ): Session { return Session::from_row(array_replace(['id'=>81,'partner_id'=>12,'user_id'=>20,'status'=>Session::ACTIVE,'wc_session_key'=>VISIT_KEY,'delivery_choice'=>null===$choice ? null : json_encode($choice,JSON_THROW_ON_ERROR)],$row)); }
	/** Put the request inside a visit and hand back the row a caller would be holding. */
	private function enter( ?array $choice = null, array $row = [] ): Session { $session = $this->session($choice,$row); $this->visits->live = $this->session($choice,$row); return $session; }
	/** With no session given this enters the visit; with one, the caller owns what Current answers. */
	private function active( array $choice, ?Session $session = null ): bool|WP_Error { $session ??= $this->enter($choice); return $this->registry->with_partner_lock(12,fn()=>$this->resolver->validate_active_choice_locked($session,$this->registry->find(12),$choice)); }
	private function error( mixed $result, string $code ): void { self::assertInstanceOf(WP_Error::class,$result); self::assertSame($code,$result->get_error_code()); self::assertStringNotContainsString('PRIVATE',$result->get_error_message()); }
	public function test_the_bound_account_and_a_shop_manager_share_only_enabled_options_without_mutation(): void {
		self::assertSame(20,$this->resolver->storage_user_id(20)); self::assertSame(21,$this->resolver->storage_user_id(21)); self::assertSame(0,$this->resolver->storage_user_id(22));
		$before=$GLOBALS['pow_test_users']; $own=$this->provider->list_for_user(20);
		$GLOBALS['pow_test_current_user_id']=30; $managed=$this->provider->list_for_user(20);
		self::assertSame($own,$managed); self::assertCount(1,$own); self::assertSame('enabled',$own[0]['key']); self::assertSame($before,$GLOBALS['pow_test_users']); self::assertSame([],$this->book->saves); self::assertInstanceOf(NativeProvider::class,$this->resolver->current());
	}
	public function test_only_the_owner_of_a_connection_resolves_and_a_lost_account_never_does(): void {
		foreach ( [0,-1,22,23,999] as $id ) { self::assertSame(0,$this->resolver->storage_user_id($id)); }
		$GLOBALS['pow_test_current_user_id']=22; self::assertSame([],$this->provider->list_for_user(22)); $GLOBALS['pow_test_current_user_id']=20;
		$this->registry->rows[12]['owner_user_id']=23; self::assertSame(0,$this->resolver->storage_user_id(23));
		$this->registry->rows[12]['owner_user_id']=20; unset($GLOBALS['pow_test_users'][20]); self::assertSame(0,$this->resolver->storage_user_id(20));
	}
	public function test_native_user_and_registry_read_errors_never_become_owner_access(): void {
		$GLOBALS['provider_user_read_fail']=true; self::assertSame(0,$this->resolver->storage_user_id(20)); unset($GLOBALS['provider_user_read_fail']);
		$this->registry->fail=true; self::assertSame(0,$this->resolver->storage_user_id(20));
	}
	public function test_book_read_error_is_not_reported_as_an_empty_option_list(): void {
		$this->book->fail=true; self::expectException(DomainException::class); $this->provider->list_for_user(20);
	}
	public function test_association_is_rechecked_after_mutex_acquisition(): void {
		$this->registry->on_lock=function(){ $this->registry->rows[12]['owner_user_id']=21; };
		self::assertSame([],$this->provider->list_for_user(20));
	}
	public function test_cross_user_viewer_losing_permission_while_waiting_cannot_read_the_book(): void {
		$GLOBALS['pow_test_current_user_id']=30;
		$this->registry->on_lock=function(){ $GLOBALS['pow_test_users'][30]->allcaps['manage_woocommerce']=false; };
		self::assertSame([],$this->provider->list_for_user(20)); self::assertSame(0,$this->book->reads);
	}
	public function test_a_visit_reads_its_own_options_but_never_another_accounts_book(): void {
		$this->enter();
		self::assertCount(1,$this->provider->list_for_user(20));
		// Inside a visit the request holds whatever the shared account holds, so a shop-wide grant stops being one.
		$GLOBALS['pow_test_current_user_id']=30; self::assertSame([],$this->provider->list_for_user(20));
	}
	public function test_snapshot_is_preserved_and_missing_is_not_confirmed(): void {
		$choice=$this->choice(); $session=$this->enter($choice);
		self::assertSame($choice,$this->resolver->selected_snapshot($session)); self::assertNull($this->resolver->selected_snapshot($this->session()));
		$this->book->state['addresses']['enabled']['label']='Changed later'; self::assertSame($choice,$this->provider->selected_for_session($session)); self::assertSame(0,$this->book->reads);
	}
	public function test_foreign_owner_snapshot_is_a_bounded_error(): void {
		$choice=$this->choice(); $choice['storage_user_id']=21;
		self::expectException(DomainException::class); $this->resolver->selected_snapshot($this->enter($choice));
	}
	public function test_another_visits_snapshot_cannot_be_replayed_in_this_request(): void {
		$choice=$this->choice(); $sibling=$this->session($choice,['id'=>82,'wc_session_key'=>SIBLING_KEY]);
		// This request is visit 81; 82 is a second employee of the same account, signed in as the same user.
		$this->visits->live=$this->session($choice);
		foreach ( [ $sibling, $this->session($choice,['wc_session_key'=>SIBLING_KEY]), $this->session($choice,['wc_session_key'=>null]) ] as $other ) {
			try { $this->resolver->selected_snapshot($other); self::fail('Another visit\'s snapshot was accepted.'); }
			catch ( DomainException $error ) { self::assertStringNotContainsString('PRIVATE',$error->getMessage()); }
		}
		$this->error($this->active($choice,$sibling),'address_unavailable');
		$this->visits->live=null; $this->error($this->active($choice,$this->session($choice)),'address_unavailable');
	}
	public function test_active_choice_survives_unrelated_revision_but_not_selected_edits(): void {
		$choice=$this->choice(); self::assertTrue($this->active($choice)); $this->book->state['revision']=4; $this->book->state['addresses']['disabled']['label']='Unrelated'; self::assertTrue($this->active($choice));
		foreach ( ['label'=>'Changed','code'=>'BUYER-003','address'=>array_replace($choice['address'],['city'=>'Elsewhere'])] as $field=>$value ) { $old=$this->book->state['addresses']['enabled'][$field]; $this->book->state['addresses']['enabled'][$field]=$value; $this->error($this->active($choice),'address_changed'); $this->book->state['addresses']['enabled'][$field]=$old; }
	}
	public function test_disabled_or_removed_entry_requires_reselection(): void {
		$choice=$this->choice(); $this->book->state['addresses']['enabled']['use_for_punchout']=false; $this->error($this->active($choice),'address_unavailable'); unset($this->book->state['addresses']['enabled']); $this->error($this->active($choice),'address_unavailable');
	}
	public function test_current_shipping_policy_can_refuse_selection_without_changing_its_snapshot(): void {
		$choice=$this->choice(); $session=$this->enter($choice);
		\POW\Tests\AddressProvider\Shape::$error='address_invalid';
		$this->error($this->active($choice,$session),'address_unavailable'); self::assertSame($choice,$this->resolver->selected_snapshot($session));
		\POW\Tests\AddressProvider\Shape::$error='address_validation_unavailable';
		$this->error($this->active($choice,$session),'address_state_unavailable');
	}
	public function test_copying_current_fingerprint_cannot_authorize_different_snapshot_content(): void {
		$choice=$this->choice(); $choice['address']['city']='Injected city'; $this->error($this->active($choice),'address_changed');
	}
	public function test_legacy_choice_needs_reconfirmation_and_read_failure_is_distinct(): void {
		$choice=$this->choice(); unset($choice['book_revision'],$choice['entry_fingerprint']); $this->error($this->active($choice),'address_changed');
		$this->book->fail=true; $this->error($this->active($this->choice()),'address_state_unavailable');
	}
	public function test_nonbook_snapshot_never_reads_or_edits_the_company_master(): void {
		$choice=$this->choice(); $choice['provider']='customer'; $choice['source']='customer'; $choice['book_revision']=null; $choice['entry_fingerprint']=null;
		self::assertTrue($this->active($choice)); self::assertSame(0,$this->book->reads); self::assertSame([],$this->book->saves);
		$this->registry->rows[12]['status']='disabled'; $this->error($this->active($choice),'address_unavailable');
	}
	public function test_choices_are_produced_for_this_requests_visit_and_no_other(): void {
		$session=$this->enter(); $partner=$this->registry->find(12);
		$choices=$this->resolver->choices_for_session($session,$partner);
		self::assertCount(1,$choices); self::assertSame('enabled',$choices[0]['key']); self::assertSame(20,$choices[0]['storage_user_id']); self::assertSame(1,$this->book->reads);
		$this->error($this->resolver->choices_for_session($this->session(null,['id'=>82,'wc_session_key'=>SIBLING_KEY]),$partner),'address_unavailable');
		$this->error($this->resolver->choices_for_session($this->session(null,['status'=>Session::EXPIRED]),$partner),'address_unavailable');
		$this->visits->live=null; $this->error($this->resolver->choices_for_session($session,$partner),'address_unavailable');
		self::assertSame(1,$this->book->reads);
	}
	public function test_an_unreachable_visit_lookup_is_a_bounded_refusal_not_an_answer(): void {
		$session=$this->enter(); $this->visits->unreachable=true;
		$this->error($this->resolver->choices_for_session($session,$this->registry->find(12)),'address_state_unavailable');
		$this->error($this->active($this->choice(),$session),'address_state_unavailable');
		self::assertFalse($this->provider->set_code(20,'enabled','NEW')); self::assertSame([],$this->book->saves);
	}
	public function test_code_changes_delegate_revision_and_actual_editor_outside_a_visit_only(): void {
		self::assertTrue($this->provider->set_code(20,'enabled','NEW')); self::assertSame([12,20,3,'enabled'],array_slice($this->book->saves[0],0,4));
		$GLOBALS['pow_test_current_user_id']=30; self::assertTrue($this->provider->set_code(20,'enabled','MANAGED')); self::assertCount(2,$this->book->saves);
		$GLOBALS['pow_test_current_user_id']=22; self::assertFalse($this->provider->set_code(20,'enabled','NEW'));
		$GLOBALS['pow_test_current_user_id']=0; self::assertFalse($this->provider->set_code(20,'enabled','NEW')); self::assertCount(2,$this->book->saves);
	}
	public function test_the_bound_account_cannot_edit_a_delivery_code_from_inside_its_own_visit(): void {
		$this->enter();
		self::assertFalse($this->provider->set_code(20,'enabled','NEW'));
		$GLOBALS['pow_test_current_user_id']=30; self::assertFalse($this->provider->set_code(20,'enabled','NEW'));
		self::assertSame([],$this->book->saves);
	}
}
}
