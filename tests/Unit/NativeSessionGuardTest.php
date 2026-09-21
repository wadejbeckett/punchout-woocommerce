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
final class Registry {public Partner $partner;public bool $locked=false;public mixed $before_lock=null;public function find(int $id):?Partner{return $this->partner;}public function with_partner_lock(int $id,callable $fn):mixed{if($this->locked){throw new \LogicException('Nested lock');}if($this->before_lock){$f=$this->before_lock;$this->before_lock=null;$f();}$this->locked=true;try{return $fn();}finally{$this->locked=false;}}}
final class Store {public Session $session;public bool $valid=true;public int $invalidations=0;public mixed $on_verify=null;public function find_for_login(int $user,string $token,array $statuses):?Session{return $user===$this->session->user_id&&$token===$this->session->wp_session_token&&in_array($this->session->status,$statuses,true)?$this->session:null;}public function find(int $id):?Session{return $this->session;}public function login_valid_checked(Session $s):bool{if(state()->registry->locked){throw new \LogicException('Native authentication under lock');}if($this->on_verify){$f=$this->on_verify;$this->on_verify=null;$f();}return $this->valid;}public function invalidate_delivery(int $id,int $user,string $token):bool{++$this->invalidations;$this->session=new Session(...array_replace(get_object_vars($this->session),['delivery_confirmation_json'=>null]));return true;}public function open_for_user(int $id):array{return in_array($this->session->status,[Session::ACTIVE,Session::ORDERED],true)?[$this->session]:[];}}
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
final class NativeGuardDatabase extends NativeSessionRowDatabase {public string $usermeta='wp_usermeta';public array $metadata=[['umeta_id'=>'1','meta_key'=>'session_tokens','meta_value'=>'native-login']];public function get_results(string $key,mixed $format):?array{[$sql,$a]=$this->queries[$key];if(str_contains($sql,'FROM wp_usermeta')){return $this->metadata;}return parent::get_results($key,$format);}}
final class NativeSessionGuardTest extends PHPUnit\Framework\TestCase {
	private mixed $previous;private mixed $s;private NativeGuardDatabase $db;private mixed $guard;
	protected function setUp():void{\POW\Tests\NativeGuard\load_source();$this->previous=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=$this->db=new NativeGuardDatabase();$GLOBALS['native_guard_test']=$this->s=new \POW\Tests\NativeGuard\State();$this->s->registry->partner=POW\Partners\Partner::from_row(['id'=>7,'status'=>'active','owner_user_id'=>20]);$this->s->store->session=POW\Sessions\Session::from_row(['id'=>42,'partner_id'=>7,'user_id'=>99,'wp_session_token'=>'exact','status'=>'active','expires'=>gmdate('Y-m-d H:i:s',time()+3600),'wc_session_key'=>\POW\Tests\NativeGuard\VISIT_KEY]);$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]=['session_value'=>serialize(['cart'=>'A']),'session_expiry'=>'9999999999'];$this->guard=new \POW\Tests\NativeGuard\NativeSessionGuard($this->s->registry,$this->s->store);$this->guard->register();$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();}
	protected function tearDown():void{$GLOBALS['wpdb']=$this->previous;unset($GLOBALS['native_guard_test'],$_SERVER['HTTP_CART_TOKEN']);\POW\Tests\NativeGuard\CartTokenUtils::$authenticated=[];}
	public function test_hydration_uses_direct_row_instead_of_stale_native_cache():void{self::assertSame('A',$this->s->session->get('cart'));}
	public function test_dirty_get_shutdown_replays_only_its_own_change_over_concurrent_saved_cart():void{$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M','other'=>'X']);$this->s->session->set('cart','R');$this->s->session->save_data();self::assertSame(['cart'=>'R','other'=>'X'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']),'One rehydrate-and-retry: own changed key replayed, concurrent keys kept');self::assertSame(0,$this->s->session->parent_saves);self::assertNull($this->s->session->last_refusal());}
	public function test_second_conflict_during_retry_is_final_and_blocks_late_shutdown():void{$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);$this->db->before_write=function(){$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M2']);};$this->s->session->set('cart','R');$this->s->session->save_data();self::assertSame(['cart'=>'M2'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));self::assertSame('row_conflict',$this->s->session->last_refusal());self::assertSame(0,$this->s->session->parent_saves);$this->s->session->set('customer','late shutdown');$this->s->session->save_data();self::assertSame(['cart'=>'M2'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_two_own_saves_then_interleaved_mutation_replays_once():void{$this->s->session->set('cart','B');$this->s->session->save_data();$this->s->session->set('cart','C');$this->s->session->save_data();self::assertSame(['cart'=>'C'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);$this->s->session->set('cart','D');$this->s->session->save_data();self::assertSame(['cart'=>'D'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);$this->db->before_write=function(){$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M2']);};$this->s->session->set('cart','E');$this->s->session->save_data();self::assertSame(['cart'=>'M2'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']),'Double conflict is final');}
	public function test_mutation_commit_and_consent_invalidation_share_lock():void{$this->guard->mark_changed();$this->s->session->set('cart','M');$this->s->session->save_data();self::assertSame(['cart'=>'M'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));self::assertSame(1,$this->s->store->invalidations);}
	public function test_return_winner_prevents_older_active_mutation_save():void{$this->s->session->set('cart','M');$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'returned']));$this->s->session->save_data();self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_exact_ordered_login_preserves_paid_native_save():void{$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'ordered']));$this->s->session->set('order_awaiting_payment',123);$this->s->session->save_data();self::assertSame(123,unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value'])['order_awaiting_payment']);}
	public function test_replacement_login_and_native_revocation_during_lock_wait_refuse():void{$this->s->session->set('cart','R');$this->s->registry->before_lock=function(){$this->db->metadata[0]['meta_value']='revoked';};$this->s->session->save_data();self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_superseded_handler_cannot_save_or_destroy_current_row():void{$old=$this->s->session;$this->s->session=new \POW\Tests\NativeGuard\NativeSessionHandler();$this->s->session->init();$this->s->session->set('cart','M');$this->s->session->save_data();$old->set('cart','R');$old->save_data();$old->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);self::assertSame(['cart'=>'M'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_unprotected_custom_handler_selection_is_unchanged():void{$this->s->actor=123;self::assertSame('Other_Handler',$this->guard->select_handler('Other_Handler'));}
	public function test_terminal_native_cleanup_deletes_only_its_unchanged_row():void{$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'returned']));$this->s->session->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);self::assertFalse(isset($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]));$this->s->session->set('cart','late shutdown');$this->s->session->save_data();self::assertFalse(isset($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]));}
	public function test_invalid_cookie_cleanup_cannot_delete_replacement_login_row():void{$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['wp_session_token'=>'replacement']));$this->s->session->destroy_session();self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_consent_guard_checks_durable_native_row_under_callers_lock():void{$prepared=$this->guard->prepare($this->s->store->session);$this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']=serialize(['cart'=>'M']);self::assertFalse($this->s->registry->with_partner_lock(7,fn()=>$this->guard->commit_locked($this->s->store->session,$prepared)));}
	public function test_post_staging_native_mutation_cannot_be_committed_as_earlier_candidate():void{$prepared=$this->guard->prepare($this->s->store->session);$this->s->session->set('cart','late');self::assertFalse($this->s->registry->with_partner_lock(7,fn()=>$this->guard->commit_locked($this->s->store->session,$prepared)));self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
	public function test_valid_outer_cart_token_for_buyer_refuses_even_when_wp_actor_is_zero():void{$this->s->actor=0;$_SERVER['HTTP_CART_TOKEN']='native-authenticated';\POW\Tests\NativeGuard\CartTokenUtils::$authenticated=['native-authenticated'=>\POW\Tests\NativeGuard\VISIT_KEY];self::assertInstanceOf(WP_Error::class,$this->guard->token_authentication(true));try{$this->guard->select_handler('Automattic\\WooCommerce\\StoreApi\\SessionHandler');self::fail('Must refuse before token handler construction');}catch(RuntimeException $e){self::assertSame('Bounded refusal',$e->getMessage());}}
	public function test_ordinary_valid_and_invalid_cart_tokens_keep_native_selection():void{$this->s->actor=0;$_SERVER['HTTP_CART_TOKEN']='ordinary';\POW\Tests\NativeGuard\CartTokenUtils::$authenticated=['ordinary'=>'123'];self::assertTrue($this->guard->token_authentication(true));self::assertSame('Automattic\\WooCommerce\\StoreApi\\SessionHandler',$this->guard->select_handler('Automattic\\WooCommerce\\StoreApi\\SessionHandler'));$_SERVER['HTTP_CART_TOKEN']='untrusted';self::assertTrue($this->guard->token_authentication(true));}
	public function test_cookie_nonce_selects_guard_and_protected_custom_handler_refuses():void{self::assertSame(\POW\Tests\NativeGuard\NativeSessionHandler::class,$this->guard->select_handler('WC_Session_Handler'));try{$this->guard->select_handler('Other_Handler');self::fail('Do not take over custom handler');}catch(RuntimeException $e){self::assertSame('Bounded refusal',$e->getMessage());}}
	public function test_authentication_callback_cannot_bind_a_new_revoked_baseline():void{$this->s->store->on_verify=function(){$this->db->metadata[0]['meta_value']='revoked after native read';};$this->s->session->set('cart','R');self::assertFalse($this->s->session->save_checked());self::assertSame(['cart'=>'A'],unserialize($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]['session_value']));}
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
	public function test_old_active_request_cannot_delete_a_newly_ordered_native_session():void{$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>'ordered']));$this->s->session->delete_session(\POW\Tests\NativeGuard\VISIT_KEY);self::assertTrue(isset($this->db->rows[\POW\Tests\NativeGuard\VISIT_KEY]),'Old ACTIVE cleanup must preserve paid winner');}
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
