<?php
/** Production guard and handler bodies with isolated native/registry boundaries; no shared WC stubs. */
declare(strict_types=1);
namespace POW\Tests\NativeGuard {
use POW\Sessions\Session;
use POW\Partners\Partner;
use POW\Cart\NativeSessionRow;
/** This visit's WooCommerce session key: 'pow_' + 28 hex = char(32). A sibling visit of the same shared login carries SIBLING_KEY. */
const VISIT_KEY='pow_1a2b3c4d5e6f708192a3b4c5d6e7';
const SIBLING_KEY='pow_00112233445566778899aabbccdd';
final class Registry {public Partner $partner;public bool $locked=false;public int $lock_calls=0;public function find(int $id):?Partner{return $this->partner;}public function find_by_owner(int $user_id):?Partner{return $user_id===$this->partner->owner_user_id?$this->partner:null;}public function with_partner_lock(int $id,callable $fn):mixed{if($this->locked){throw new \LogicException('Nested lock');}++$this->lock_calls;$this->locked=true;try{return $fn();}finally{$this->locked=false;}}}
final class Store {public Session $session;public bool $valid=true;public int $invalidations=0;public mixed $on_verify=null;public function find_for_login(int $user,string $token,array $statuses):?Session{return $user===$this->session->user_id&&$token===$this->session->wp_session_token&&in_array($this->session->status,$statuses,true)?$this->session:null;}public function find_by_wc_session_key(string $key,array $statuses=[Session::ACTIVE,Session::ORDERED]):?Session{return $key===(string)$this->session->wc_session_key&&in_array($this->session->status,$statuses,true)?$this->session:null;}public function find(int $id):?Session{return $this->session;}public function login_valid_checked(Session $s):bool{if(state()->registry->locked||[]!==$GLOBALS['wpdb']->locks){throw new \LogicException('Native authentication under lock');}if($this->on_verify){$f=$this->on_verify;$this->on_verify=null;$f();}return $this->valid;}public function invalidate_delivery(int $id,int $user,string $token):bool{++$this->invalidations;$this->session=new Session(...array_replace(get_object_vars($this->session),['delivery_confirmation_json'=>null]));return true;}}
/** Core's own session handler, reduced to the surface the per-visit subclass overrides or calls. Every method the subclass touches must exist here or the eval'd source will not bind. */
class NativeBase {
	protected $_customer_id='99';protected $_data=[];protected $_dirty=false;protected $_session_expiration=9999999999;protected $_has_cookie=false;public int $parent_saves=0;public bool $invalid_cookie=false;public bool $migrate=false;
	public array $init_trace=[];public array $cookies=[];public bool $hooked=false;
	public function __construct(){}public function init(){$this->init_trace[]='parent_init';$this->init_hooks();if($this->migrate){$this->_customer_id='guest';$this->_data=$this->get_session('guest',[]);$this->_customer_id='99';$this->_dirty=true;$this->save_data('guest');return;} $this->init_session_cookie(); }
	protected function init_hooks():void{$this->hooked=true;}
	/** Native order: restore before validity/cleanup, all before WordPress parse_request. No native auth or HTTP claim. */
	public function init_session_cookie(){$this->restore_session_data();if(!$this->is_session_cookie_valid()){$this->destroy_session();}$this->init_trace[]='init_finished';}
	private function restore_session_data():void{$this->init_trace[]='restore_session_data';$this->_data=$this->get_session($this->_customer_id,[]);}
	private function is_session_cookie_valid():bool{$this->init_trace[]='is_session_cookie_valid';return !$this->invalid_cookie&&(string)state()->actor===$this->_customer_id;}
	public function get_customer_id(){return $this->_customer_id;} public function get_session($key,$default=false){return ['cart'=>'STALE CACHE'];}public function get_session_data(){return $this->_data;}
	public function get($key,$default=null){return $this->_data[$key]??$default;}public function set($key,$value){$this->_data[$key]=$value;$this->_dirty=true;}public function has_session(){return true;}
	public function generate_customer_id(){return state()->actor>0?(string)state()->actor:'t_guest';}
	public function get_session_cookie(){return ['99','9999999999','9999999998','hash'];}
	public function set_customer_session_cookie($set){if($set){$this->cookies[]=$this->_customer_id;$this->_has_cookie=true;}}
	public function maybe_set_customer_session_cookie(){$this->set_customer_session_cookie(true);}
	public function get_customer_unique_id(){return (string)$this->_customer_id;}
	public function set_session_expiration(){}
	public function forget_session(){$this->_data=[];$this->_dirty=false;$this->_customer_id=$this->generate_customer_id();$this->_has_cookie=false;}
	public function save_data($old=''){++$this->parent_saves;$GLOBALS['wpdb']->rows[$this->_customer_id]=['session_value'=>serialize($this->_data),'session_expiry'=>'9999999999'];$this->_dirty=false;}
	public function delete_session($id){unset($GLOBALS['wpdb']->rows[$id]);}public function update_session_timestamp($id,$time){}public function destroy_session(){$this->init_trace[]='destroy_session';$this->delete_session($this->_customer_id);$this->forget_session();}
}
final class State {public Registry $registry;public Store $store;public mixed $session;public int $actor=99;public string $token='exact';public array $cache=[];public function __construct(){$this->registry=new Registry();$this->store=new Store();}}
final class CartTokenUtils {public static array $authenticated=[];public static function validate_cart_token(string $token):bool{return isset(self::$authenticated[$token]);}public static function get_cart_token_payload(string $token):array{if(!self::validate_cart_token($token)){throw new \LogicException('Do not trust unauthenticated payload');}return ['user_id'=>self::$authenticated[$token]];}}
function add_filter(string $hook,mixed $callback,int $priority=10,int $args=1):void{}
function add_action(string $hook,mixed $callback,int $priority=10,int $args=1):void{}
function wp_die(mixed ...$args):never{throw new \RuntimeException('Bounded refusal');}
function state():State{return $GLOBALS['native_guard_test'];}function WC():State{return state();}function get_current_user_id():int{return state()->actor;}function wp_get_session_token():string{return state()->token;}function wp_cache_delete(mixed $key,string $group=''):bool{unset(state()->cache[$key]);return true;}
function load_source():void{if(class_exists(__NAMESPACE__.'\\NativeSessionGuard',false)){return;}foreach(['NativeSessionGuard','NativeSessionHandler'] as $name){$path=dirname(__DIR__,2).'/includes/Cart/'.$name.'.php';\PHPUnit\Framework\TestCase::assertTrue(is_file($path),'Protect native hydration and every save');$src=file_get_contents($path);$src=str_replace('namespace POW\\Cart;','namespace POW\\Tests\\NativeGuard; use POW\\Cart\\NativeSessionRow;', $src);$src=str_replace(['use POW\\Partners\\Registry;','use POW\\Sessions\\Store;','\\WC_Session_Handler','use Automattic\\WooCommerce\\StoreApi\\Utilities\\CartTokenUtils;'],['','','NativeBase',''],$src);eval(substr($src,5));}}
}
namespace {
require_once __DIR__.'/NativeSessionRowTest.php';
/**
 * The row fixture plus the two boundaries the guard owns: the login fingerprint
 * and the per-visit cart lock.
 *
 * The usermeta read is answered ONLY for the keys the statement names, so a
 * fingerprint that still snapshots every row of the shared account cannot pass:
 * `session_tokens` and `wc_last_active` are present and must not be read.
 */
final class NativeGuardDatabase extends NativeSessionRowDatabase {
	public string $usermeta='wp_usermeta';
	public array $metadata=[
		['meta_key'=>'wp_capabilities','meta_value'=>'a:1:{s:8:"customer";b:1;}'],
		['meta_key'=>'wp_user_level','meta_value'=>'0'],
		['meta_key'=>'session_tokens','meta_value'=>'native-login'],
		['meta_key'=>'wc_last_active','meta_value'=>'1758400000'],
	];
	/** Named locks currently held, and every acquisition in order. */
	public array $locks=[];public array $lock_log=[];public array $released=[];
	public bool $lock_fails=false;public bool $release_fails=false;public bool $suppressed=false;
	/** Fired once, inside the cart lock's acquisition — the "another writer moved while we waited" seam. */
	public mixed $before_lock=null;
	public function get_results(string $key,mixed $format):?array{[$sql,$a]=$this->queries[$key];
		if(str_contains($sql,'FROM wp_usermeta')){
			if(!str_contains($sql,'meta_key IN')){throw new LogicException('The login fingerprint must name the authorising keys');}
			$wanted=array_slice($a,1);
			return array_values(array_filter($this->metadata,static fn(array $row):bool=>in_array($row['meta_key'],$wanted,true)));
		}
		return parent::get_results($key,$format);}
	public function get_var(string $key):?string{[$sql,$a]=$this->queries[$key];
		if(str_contains($sql,'GET_LOCK')){$name=(string)$a[0];$this->lock_log[]=$name;if($this->lock_fails){return '0';}if(isset($this->locks[$name])){return '0';}$this->locks[$name]=1;if($this->before_lock){$f=$this->before_lock;$this->before_lock=null;$f();}return '1';}
		if(str_contains($sql,'RELEASE_LOCK')){$name=(string)$a[0];unset($this->locks[$name]);$this->released[]=$name;return $this->release_fails?'0':'1';}
		throw new LogicException('Unexpected scalar query: '.$sql);}
	public function suppress_errors(bool $suppress=true):bool{$previous=$this->suppressed;$this->suppressed=$suppress;return $previous;}
}
/** WP_REST_Response's header surface, which is all the outbound Cart-Token filter may use (core has no remove_header()). */
final class GuardRestResponse {
	public function __construct(private array $headers=[]){}
	public function get_headers():array{return $this->headers;}
	public function set_headers(array $headers):void{$this->headers=$headers;}
	public function header(string $key,mixed $value,bool $replace=true):void{$this->headers[$key]=$value;}
}
final class NativeSessionGuardTest extends PHPUnit\Framework\TestCase {
	private mixed $previous;private mixed $s;private NativeGuardDatabase $db;private mixed $guard;
	protected function setUp():void{\POW\Tests\NativeGuard\load_source();$this->previous=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=$this->db=new NativeGuardDatabase();$GLOBALS['native_guard_test']=$this->s=new \POW\Tests\NativeGuard\State();$this->s->registry->partner=POW\Partners\Partner::from_row(['id'=>7,'status'=>'active','owner_user_id'=>20]);$this->s->store->session=POW\Sessions\Session::from_row(['id'=>42,'partner_id'=>7,'user_id'=>99,'wp_session_token'=>'exact','status'=>'active','expires'=>gmdate('Y-m-d H:i:s',time()+3600),'wc_session_key'=>\POW\Tests\NativeGuard\VISIT_KEY]);$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]=['session_value'=>serialize(['cart'=>'A']),'session_expiry'=>'9999999999'];$this->guard=new \POW\Tests\NativeGuard\NativeSessionGuard($this->s->registry,$this->s->store);$this->guard->register();$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();}
	protected function tearDown():void{$GLOBALS['wpdb']=$this->previous;unset($GLOBALS['native_guard_test'],$_SERVER['HTTP_CART_TOKEN'],$_GET['session']);\POW\Tests\NativeGuard\CartTokenUtils::$authenticated=[];}
	public function test_hydration_uses_direct_row_instead_of_stale_native_cache():void{self::assertSame('A',$this->s->session->get('cart'));}
	public function test_dirty_get_shutdown_replays_only_its_own_change_over_concurrent_saved_cart():void{$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M','other'=>'X']);$this->s->session->set('cart','R');$this->s->session->save_data();self::assertSame(['cart'=>'R','other'=>'X'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']),'One rehydrate-and-retry: own changed key replayed, concurrent keys kept');self::assertSame(0,$this->s->session->parent_saves);self::assertNull($this->s->session->last_refusal());}
	public function test_second_conflict_during_retry_is_final_and_blocks_late_shutdown():void{$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);$this->db->before_write=function(){$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M2']);};$this->s->session->set('cart','R');$this->s->session->save_data();self::assertSame(['cart'=>'M2'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));self::assertSame('row_conflict',$this->s->session->last_refusal());self::assertSame(0,$this->s->session->parent_saves);$this->s->session->set('customer','late shutdown');$this->s->session->save_data();self::assertSame(['cart'=>'M2'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_two_own_saves_then_interleaved_mutation_replays_once():void{$this->s->session->set('cart','B');$this->s->session->save_data();$this->s->session->set('cart','C');$this->s->session->save_data();self::assertSame(['cart'=>'C'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);$this->s->session->set('cart','D');$this->s->session->save_data();self::assertSame(['cart'=>'D'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);$this->db->before_write=function(){$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M2']);};$this->s->session->set('cart','E');$this->s->session->save_data();self::assertSame(['cart'=>'M2'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']),'Double conflict is final');}
	public function test_mutation_commit_and_consent_invalidation_share_lock():void{$this->guard->mark_changed();$this->s->session->set('cart','M');$this->s->session->save_data();self::assertSame(['cart'=>'M'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));self::assertSame(1,$this->s->store->invalidations);}
	public function test_return_winner_prevents_older_active_mutation_save():void{$this->s->session->set('cart','M');$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'returned']));$this->s->session->save_data();self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_exact_ordered_login_preserves_paid_native_save():void{$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'ordered']));$this->s->session->set('order_awaiting_payment',123);$this->s->session->save_data();self::assertSame(123,unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value'])['order_awaiting_payment']);}
	/** An authority change while the cart lock is being acquired still refuses the staged commit. */
	public function test_capability_change_during_lock_wait_refuses():void{$this->s->session->set('cart','R');$this->db->before_lock=function(){$this->db->metadata[0]['meta_value']='a:1:{s:13:"administrator";b:1;}';};$this->s->session->save_data();self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));self::assertSame('identity',$this->s->session->last_refusal());}
	/**
	 * A colleague redeeming a Start token writes `session_tokens` on the SAME
	 * shared account, between this request's staging and its commit. That is
	 * another visit beginning, not this login losing its authority, and it must
	 * not cost this buyer their cart lines.
	 */
	public function test_a_colleagues_login_write_between_staging_and_commit_does_not_refuse():void{
		$this->s->session->set('cart','R');
		$this->db->before_lock=function(){$this->db->metadata[2]['meta_value']='native-login,colleague-login';};
		$this->s->session->save_data();
		self::assertSame(['cart'=>'R'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
		self::assertNull($this->s->session->last_refusal());
	}
	/** WooCommerce itself writes `wc_last_active` on the shared account every five minutes of ordinary browsing, between staging and commit. One buyer shopping alone must not lose a commit to it. */
	public function test_a_woocommerce_last_active_write_between_staging_and_commit_does_not_refuse():void{
		$this->s->session->set('cart','R');
		$this->db->before_lock=function(){$this->db->metadata[3]['meta_value']=(string)time();};
		$this->s->session->save_data();
		self::assertSame(['cart'=>'R'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
		self::assertNull($this->s->session->last_refusal());
	}
	/** The fingerprint names the authorising rows in SQL, so no unrelated meta row — present or future — can reach it. */
	public function test_the_login_fingerprint_reads_only_authorising_meta_keys():void{
		$this->s->session->set('cart','R');$this->s->session->save_data();
		$reads=array_values(array_filter($this->db->queries,static fn(array $q):bool=>str_contains($q[0],'FROM wp_usermeta')));
		self::assertNotSame([],$reads,'The commit must still re-read the fingerprint under the lock');
		foreach($reads as [$sql,$args]){
			self::assertStringContainsString('meta_key IN',$sql);
			self::assertStringNotContainsString('umeta_id',$sql,'Insert order is not authority');
			$wanted=array_slice($args,1);
			self::assertSame(['wp_capabilities','wp_user_level'],$wanted);
		}
	}
	public function test_superseded_handler_cannot_save_or_destroy_current_row():void{$old=$this->s->session;$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();$this->s->session->set('cart','M');$this->s->session->save_data();$old->set('cart','R');$old->save_data();$old->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);self::assertSame(['cart'=>'M'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_unprotected_custom_handler_selection_is_unchanged():void{$this->s->actor=123;self::assertSame('Other_Handler',$this->guard->select_handler('Other_Handler'));}
	public function test_terminal_native_cleanup_deletes_only_its_unchanged_row():void{$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'returned']));$this->s->session->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);self::assertFalse(isset($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]));$this->s->session->set('cart','late shutdown');$this->s->session->save_data();self::assertFalse(isset($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]));}
	public function test_invalid_cookie_cleanup_cannot_delete_replacement_login_row():void{$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['wp_session_token'=>'replacement']));$this->s->session->destroy_session();self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_consent_guard_checks_durable_native_row_under_callers_lock():void{$prepared=$this->guard->prepare($this->s->store->session);$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);self::assertFalse($this->s->registry->with_partner_lock(7,fn()=>$this->guard->commit_locked($this->s->store->session,$prepared)));}
	public function test_post_staging_native_mutation_cannot_be_committed_as_earlier_candidate():void{$prepared=$this->guard->prepare($this->s->store->session);$this->s->session->set('cart','late');self::assertFalse($this->s->registry->with_partner_lock(7,fn()=>$this->guard->commit_locked($this->s->store->session,$prepared)));self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_valid_outer_cart_token_for_buyer_refuses_even_when_wp_actor_is_zero():void{$this->s->actor=0;$_SERVER['HTTP_CART_TOKEN']='native-authenticated';\POW\Tests\NativeGuard\CartTokenUtils::$authenticated=['native-authenticated'=>\POW\Tests\NativeGuard\VISIT_KEY];self::assertInstanceOf(WP_Error::class,$this->guard->token_authentication(true));try{$this->guard->select_handler('Automattic\\WooCommerce\\StoreApi\\SessionHandler');self::fail('Must refuse before token handler construction');}catch(RuntimeException $e){self::assertSame('Bounded refusal',$e->getMessage());}}
	public function test_ordinary_valid_and_invalid_cart_tokens_keep_native_selection():void{$this->s->actor=0;$_SERVER['HTTP_CART_TOKEN']='ordinary';\POW\Tests\NativeGuard\CartTokenUtils::$authenticated=['ordinary'=>'123'];self::assertTrue($this->guard->token_authentication(true));self::assertSame('Automattic\\WooCommerce\\StoreApi\\SessionHandler',$this->guard->select_handler('Automattic\\WooCommerce\\StoreApi\\SessionHandler'));$_SERVER['HTTP_CART_TOKEN']='untrusted';self::assertTrue($this->guard->token_authentication(true));}
	public function test_cookie_nonce_selects_guard_and_protected_custom_handler_refuses():void{self::assertSame(\POW\Tests\NativeGuard\NativeSessionHandler::class,$this->guard->select_handler('WC_Session_Handler'));try{$this->guard->select_handler('Other_Handler');self::fail('Do not take over custom handler');}catch(RuntimeException $e){self::assertSame('Bounded refusal',$e->getMessage());}}
	public function test_authentication_callback_cannot_bind_a_new_authority_baseline():void{$this->s->store->on_verify=function(){$this->db->metadata[0]['meta_value']='a:1:{s:13:"administrator";b:1;}';};$this->s->session->set('cart','R');self::assertFalse($this->s->session->save_checked());self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_authentication_callback_switching_actor_cannot_save_original_buyer():void{$this->s->store->on_verify=function(){$this->s->actor=123;};$this->s->session->set('cart','R');self::assertFalse($this->s->session->save_checked());self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	/** A stale or invalid WooCommerce session cookie cannot reach the visit at all: init() never runs core's cookie path, so there is nothing to invalidate and nothing to destroy. */
	public function test_an_invalid_woocommerce_cookie_cannot_destroy_the_visit_row():void{$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->invalid_cookie=true;$this->s->session->init();
		self::assertSame([],$this->s->session->init_trace,'Core cookie validation and destroy_session must not be reached');
		self::assertSame('A',$this->s->session->get('cart'));self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
		self::assertSame(\POW\Tests\NativeGuard\VISIT_KEY,(string)$this->s->session->get_customer_id());self::assertSame([],$this->s->session->cookies,'No WooCommerce session cookie may carry a visit key');}
	/** Core's guest-to-user migration writes the guest basket onto session_key = '<user id>' and re-cookies it, which under one shared login is every visit's basket on one row. init() must not reach it. */
	public function test_core_guest_migration_and_session_clone_paths_are_not_reached():void{$this->db->rows['t_guest']=['session_value'=>serialize(['cart'=>'guest']),'session_expiry'=>'9999999999'];
		$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->migrate=true;$this->s->session->init();
		self::assertSame([],$this->s->session->init_trace,'parent::init() must not run inside a visit');self::assertTrue($this->s->session->hooked,'init_hooks() must still run, or nothing is ever persisted');
		self::assertSame('A',$this->s->session->get('cart'));self::assertSame(\POW\Tests\NativeGuard\VISIT_KEY,(string)$this->s->session->get_customer_id());
		self::assertTrue($this->s->session->save_checked());
		self::assertSame(['cart'=>'guest'],unserialize($this->db->rows['t_guest']['session_value']),'The guest row is neither imported nor deleted');
		self::assertFalse(isset($this->db->rows['99']),'No row may be keyed on the shared user id');self::assertSame(0,$this->s->session->parent_saves);}
	/** A visit's key is a bearer credential for its basket. None of the three core triggers may put it in a cookie, including the shutdown-0 registration that runs before the plugin's own commit. */
	public function test_no_cookie_trigger_writes_the_visit_key():void{
		$this->s->session->set_customer_session_cookie(true);$this->s->session->maybe_set_customer_session_cookie();$this->s->session->init_session_cookie();
		self::assertSame([],$this->s->session->cookies);self::assertFalse($this->s->session->get_session_cookie());
		self::assertSame(\POW\Tests\NativeGuard\VISIT_KEY,(string)$this->s->session->get_customer_id(),'init_session_cookie() must not re-key the handler');
		self::assertSame(\POW\Tests\NativeGuard\VISIT_KEY,$this->s->session->get_customer_unique_id());self::assertTrue($this->s->session->has_session());}
	/** A sibling visit of the same shared login is a different basket: naming its key reads nothing and deletes nothing. */
	public function test_a_sibling_visit_key_can_neither_be_read_nor_deleted():void{
		$this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]=['session_value'=>serialize(['cart'=>'theirs']),'session_expiry'=>'9999999999'];
		self::assertSame('fallback',$this->s->session->get_session(\POW\Tests\NativeGuard\SIBLING_KEY,'fallback'));
		$this->s->session->delete_session(\POW\Tests\NativeGuard\SIBLING_KEY);
		self::assertSame(['cart'=>'theirs'],unserialize($this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]['session_value']));
		self::assertTrue($this->s->session->save_checked(),'Refusing a foreign key does not refuse this handler');}
	/** An ordinary shopper is byte-for-byte core: core's init runs, core's save runs, and a numeric or t_ key never reaches the guarded storage boundary. */
	public function test_an_ordinary_shopper_reaches_core_unchanged():void{
		$this->s->actor=123;$this->s->session=$shopper=new \POW\Tests\NativeGuard\NativeSessionHandler();$shopper->init();
		self::assertSame(['parent_init','restore_session_data','is_session_cookie_valid','destroy_session','init_finished'],$shopper->init_trace);
		self::assertFalse($shopper->is_protected());
		$shopper->set('cart','ordinary');$shopper->save_data();
		self::assertSame(1,$shopper->parent_saves);self::assertSame(['cart'=>'ordinary'],unserialize($this->db->rows['123']['session_value']));
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']),'The visit basket is untouched');
		$shopper->update_session_timestamp('123',9999999999);$shopper->set_customer_session_cookie(true);self::assertSame(['123'],$shopper->cookies);}
	/**
	 * Terminal cleanup is fenced on this visit's own row, not on "the login has
	 * no other open visit": with one shared login a colleague's open visit is
	 * the normal state, and a fence on it would leave every basket row behind
	 * for WooCommerce's expiry sweep to find.
	 */
	public function test_terminal_cleanup_deletes_its_own_row_while_a_sibling_visit_is_open():void{
		$this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]=['session_value'=>serialize(['cart'=>'theirs']),'session_expiry'=>'9999999999'];
		$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'returned']));
		$this->s->session->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);
		self::assertFalse(isset($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]),'This visit deletes its own basket');
		self::assertSame(['cart'=>'theirs'],unserialize($this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]['session_value']),'A sibling visit keeps its basket');
	}
	/** The cleanup fence is the visit's own key: a row whose key has been re-minted is a different visit's basket. */
	public function test_cleanup_refuses_when_the_rows_visit_key_no_longer_matches():void{
		$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'returned','wc_session_key'=>\POW\Tests\NativeGuard\SIBLING_KEY]));
		$this->s->session->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
	}
	/**
	 * Shape says a key COULD be a visit's; ownership says it is this request's.
	 * Every concurrent buyer of one connection holds a correctly shaped key, so
	 * the two questions must stay separate.
	 */
	public function test_shape_and_ownership_are_different_questions():void{
		foreach([\POW\Tests\NativeGuard\VISIT_KEY,\POW\Tests\NativeGuard\SIBLING_KEY] as $shaped){self::assertTrue($this->guard->protected_key($shaped));}
		foreach(['99','t_'.str_repeat('a',30),'pow_1A2B3C4D5E6F708192A3B4C5D6E7','pow_1a2b3c4d5e6f708192a3b4c5d6e',''] as $ordinary){
			self::assertFalse($this->guard->protected_key($ordinary),$ordinary);
			self::assertFalse($this->guard->owned_key($ordinary),$ordinary);
		}
		self::assertTrue($this->guard->owned_key(\POW\Tests\NativeGuard\VISIT_KEY));
		self::assertFalse($this->guard->owned_key(\POW\Tests\NativeGuard\SIBLING_KEY),'A sibling visit of the same shared login owns nothing here');
		// The same key, but this request holds another visit's login token.
		$this->s->token='other-visit';
		self::assertFalse($this->guard->owned_key(\POW\Tests\NativeGuard\VISIT_KEY),'The visit key alone is not the request');
	}
	// ------------------------------------------------------- the per-visit cart lock
	/**
	 * The cart mutex is the visit's, not the connection's. Every employee of one
	 * customer shares the bound account, so a per-connection lock would serialise
	 * every add-to-cart of every buyer behind one five-second wait.
	 */
	public function test_the_cart_commit_lock_is_per_visit_and_not_the_partner_lock():void{
		$this->s->session->set('cart','B');self::assertTrue($this->s->session->save_checked());
		$mine=$this->db->lock_log;
		self::assertNotSame([],$mine,'A cart commit is still taken under a named lock');
		self::assertSame([$mine[0]],array_unique($mine),'One visit uses one lock name');
		self::assertSame($this->db->lock_log,$this->db->released,'Every acquisition is released');
		self::assertSame([],$this->db->locks,'No lock is left held');
		self::assertStringStartsWith('pow_cart_',$mine[0]);
		self::assertStringNotContainsString(\POW\Tests\NativeGuard\VISIT_KEY,$mine[0],'The visit key is a bearer credential, not a lock name');
		self::assertSame(0,$this->s->registry->lock_calls,'A cart write never takes the connection lock');
		// A second visit of the same connection and the same account, committing its own basket.
		$this->db->lock_log=[];$this->db->released=[];
		$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['id'=>43,'wp_session_token'=>'other-visit','wc_session_key'=>\POW\Tests\NativeGuard\SIBLING_KEY]));
		$this->s->token='other-visit';
		$this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]=['session_value'=>serialize(['cart'=>'B']),'session_expiry'=>'9999999999'];
		$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();
		$this->s->session->set('cart','C');self::assertTrue($this->s->session->save_checked());
		self::assertNotSame($mine[0],$this->db->lock_log[0],'Two visits of one connection do not share a lock');
		self::assertSame(0,$this->s->registry->lock_calls);
	}
	/** A lock that cannot be acquired refuses the commit and is logged; it never falls back to an unguarded write. */
	public function test_an_unavailable_cart_lock_refuses_the_commit():void{
		$this->db->lock_fails=true;$this->s->session->set('cart','R');
		self::assertFalse($this->s->session->save_checked());
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
		self::assertSame('exception',$this->s->session->last_refusal());self::assertSame(0,$this->s->session->parent_saves);
	}
	// ---------------------------------------------------------- Cart-Token containment
	/**
	 * WooCommerce answers every Store API cart response with a signed Cart-Token
	 * carrying the session's customer id — inside a visit, the visit key. That
	 * token authenticates with no cookie and no nonce, so it must not leave.
	 */
	public function test_no_cart_token_header_leaves_a_visit():void{
		$response=new GuardRestResponse(['Cart-Token'=>'jwt.carrying.the.visit.key','Nonce'=>'n','Cart-Hash'=>'h']);
		self::assertSame($response,$this->guard->strip_cart_token($response));
		self::assertSame(['Nonce'=>'n','Cart-Hash'=>'h'],$response->get_headers());
	}
	/** An ordinary shopper's Store API response is untouched: the plugin is not in the way of the store's own cart. */
	public function test_an_ordinary_shoppers_cart_token_header_is_left_alone():void{
		$this->s->actor=123;$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();
		$response=new GuardRestResponse(['Cart-Token'=>'jwt','Nonce'=>'n']);
		self::assertSame($response,$this->guard->strip_cart_token($response));
		self::assertSame(['Cart-Token'=>'jwt','Nonce'=>'n'],$response->get_headers());
	}
	/** An inbound Cart-Token naming the connection's bound account is a bearer credential for a shared basket: refuse it, even though its payload is not a visit key. */
	public function test_a_cart_token_naming_the_bound_account_is_refused():void{
		$this->s->actor=0;$_SERVER['HTTP_CART_TOKEN']='bound-account';
		\POW\Tests\NativeGuard\CartTokenUtils::$authenticated=['bound-account'=>'20'];
		self::assertInstanceOf(WP_Error::class,$this->guard->token_authentication(true));
		try{$this->guard->select_handler('Automattic\\WooCommerce\\StoreApi\\SessionHandler');self::fail('Must refuse before token handler construction');}catch(RuntimeException $e){self::assertSame('Bounded refusal',$e->getMessage());}
	}
	/** Core's `?session=` clone path validates the parameter and then copies that basket onto this handler's row. The bypass never reaches it; it is refused explicitly all the same. */
	public function test_a_session_clone_request_inside_a_visit_is_refused():void{
		$_GET['session']='t_someone_elses_basket';
		try{$this->guard->select_handler('WC_Session_Handler');self::fail('A clone request must not reach core');}catch(RuntimeException $e){self::assertSame('Bounded refusal',$e->getMessage());}
		$this->s->actor=123;
		self::assertSame(\POW\Tests\NativeGuard\NativeSessionHandler::class,$this->guard->select_handler('WC_Session_Handler'),'An ordinary shopper keeps core behaviour');
	}
	/**
	 * WC::initialize_session() re-evaluates this filter on every call and
	 * re-constructs only when the live object is not an instance of what the
	 * filter answered. Answering anything else would build a SECOND handler and
	 * init() it mid-request while the plugin holds a staged snapshot.
	 */
	public function test_the_filter_answers_the_live_handler_class_on_every_call():void{
		// The eval loader rewrites the leading-slash spelling of core's class to NativeBase, so the allowlist entry under test here is the bare one.
		foreach(['WC_Session_Handler',\POW\Tests\NativeGuard\NativeSessionHandler::class] as $handler){
			self::assertSame(\POW\Tests\NativeGuard\NativeSessionHandler::class,$this->guard->select_handler($handler));
		}
		self::assertInstanceOf($this->guard->select_handler(\POW\Tests\NativeGuard\NativeSessionHandler::class),$this->s->session);
	}
	/** The staged identity carries the visit key, so a sibling visit's snapshot can never commit this visit's basket. */
	public function test_a_sibling_visits_staged_identity_cannot_commit_this_visits_row():void{
		$prepared=$this->guard->prepare($this->s->store->session);
		self::assertSame(\POW\Tests\NativeGuard\VISIT_KEY,$prepared['identity']['key']);
		$stolen=$prepared;$stolen['identity']['key']=\POW\Tests\NativeGuard\SIBLING_KEY;
		self::assertFalse($this->s->registry->with_partner_lock(7,fn()=>$this->guard->commit_locked($this->s->store->session,$stolen)));
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
	}
	public function test_logged_out_request_runs_core_init_on_its_own_key_and_cannot_touch_the_visit_row():void{
		$this->s->actor=0;$this->s->token='';$this->s->store->valid=false;
		$this->s->session=$old=new \POW\Tests\NativeGuard\NativeSessionHandler();$old->invalid_cookie=true;
		$old->init();$old->init_trace[]='parse_request:start';
		self::assertSame(['parent_init','restore_session_data','is_session_cookie_valid','destroy_session','init_finished','parse_request:start'],$old->init_trace,'No visit: core initialization is unchanged');
		self::assertFalse($old->is_protected());self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
		// Model a later successful Start binding of a NEW visit; the pre-login handler still cannot reach that visit's basket.
		$this->s->actor=99;$this->s->token='new-login';$this->s->store->valid=true;$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['id'=>43,'wp_session_token'=>'new-login','wc_session_key'=>\POW\Tests\NativeGuard\SIBLING_KEY]));
		$this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]=['session_value'=>serialize(['cart'=>'B']),'session_expiry'=>'9999999999'];
		$old->set('cart','old request shutdown');$old->save_data();
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
		self::assertSame(['cart'=>'B'],unserialize($this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]['session_value']));
		// The redirected request acquires its own directly loaded baseline, on its own visit's key.
		$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();
		self::assertSame(\POW\Tests\NativeGuard\SIBLING_KEY,(string)$this->s->session->get_customer_id());self::assertSame('B',$this->s->session->get('cart'));
		$this->s->session->set('cart','fresh catalog');self::assertTrue($this->s->session->save_checked());
		self::assertSame(['cart'=>'fresh catalog'],unserialize($this->db->rows[\POW\Tests\NativeGuard\SIBLING_KEY]['session_value']));
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']),'A sibling visit basket is untouched');
	}
	/** A revoked or expired visit is not a visit. The request is an ordinary shopper of the bound account, and the basket the revoked visit owned is left exactly as it is for cron or a return to remove. */
	public function test_a_revoked_visit_falls_back_to_ordinary_shopping_and_keeps_its_basket_row():void{
		$this->s->store->valid=false;$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();
		self::assertSame(['parent_init','restore_session_data','is_session_cookie_valid','init_finished'],$this->s->session->init_trace);
		self::assertFalse($this->s->session->is_protected());self::assertSame('99',(string)$this->s->session->get_customer_id());
		$this->s->session->set('cart','revoked shutdown');$this->s->session->save_data();
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']),'The visit basket is neither written nor deleted');
		$this->s->session->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);
		self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));
	}
	public function test_authorized_malformed_native_row_still_fails_initialization_closed():void{
		$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']='broken';$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();
		set_error_handler(static fn()=>true);try{$this->s->session->init();self::fail('Corrupt authorized cart must not be silently replaced');}catch(RuntimeException $e){self::assertSame('Bounded refusal',$e->getMessage());}finally{restore_error_handler();}
		self::assertFalse($this->s->session->save_checked());self::assertSame('broken',$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']);
	}
}
}
