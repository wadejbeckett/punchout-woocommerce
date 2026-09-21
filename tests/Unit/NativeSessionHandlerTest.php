<?php
/**
 * The two per-visit handler guarantees the guard suites cannot express: the visit's
 * lifetime reaching WooCommerce through the supported filter, and the handler-shape
 * check that fails a visit closed when WooCommerce moves the internals the bypass
 * stands on.
 *
 * Production NativeSessionGuard/NativeSessionHandler bodies run against a filter
 * seam of this file's own, so the value core would compute is observable. Every
 * double here is namespace- or file-local: no other suite may bind these names.
 */
declare(strict_types=1);
namespace POW\Tests\NativeHandler {
use POW\Sessions\Session;
use POW\Partners\Partner;
/** This visit's WooCommerce session key: 'pow_' + 28 hex = char(32). */
const VISIT_KEY='pow_1a2b3c4d5e6f708192a3b4c5d6e7';
final class Registry {public Partner $partner;public function find(int $id):?Partner{return $this->partner;}public function with_partner_lock(int $id,callable $fn):mixed{return $fn();}}
final class Store {public Session $session;public bool $valid=true;public function find_for_login(int $user,string $token,array $statuses):?Session{return $user===$this->session->user_id&&$token===$this->session->wp_session_token&&in_array($this->session->status,$statuses,true)?$this->session:null;}public function find_by_wc_session_key(string $key,array $statuses=[Session::ACTIVE,Session::ORDERED]):?Session{return $key===(string)$this->session->wc_session_key&&in_array($this->session->status,$statuses,true)?$this->session:null;}public function find(int $id):?Session{return $this->session;}public function login_valid_checked(Session $s):bool{return $this->valid;}public function invalidate_delivery(int $id,int $user,string $token):bool{return true;}}
/** Core's handler reduced to the surface the subclass overrides or calls; set_session_expiration() reproduces core's own filter read so the plugin's filter is observable. */
class NativeBase {
	protected $_customer_id='99';protected $_data=[];protected $_dirty=false;protected $_session_expiration=0;protected $_session_expiring=0;protected $_has_cookie=false;
	public int $parent_saves=0;public bool $hooked=false;public array $cookies=[];
	public function __construct(){$this->set_session_expiration();}
	public function init(){$this->init_hooks();$this->_data=$this->get_session($this->_customer_id,[]);}
	protected function init_hooks():void{$this->hooked=true;}
	/** Core reads wc_session_expiration here, with WEEK_IN_SECONDS for a signed-in shopper. */
	public function set_session_expiration(){$seconds=(int)apply_filters('wc_session_expiration',604800);$this->_session_expiration=time()+$seconds;$this->_session_expiring=time()+(int)($seconds*0.9);}
	public function expiration():int{return (int)$this->_session_expiration;}
	public function get_customer_id(){return $this->_customer_id;}public function get_session($key,$default=false){return $default;}public function get_session_data(){return $this->_data;}
	public function get($key,$default=null){return $this->_data[$key]??$default;}public function set($key,$value){$this->_data[$key]=$value;$this->_dirty=true;}
	public function has_session(){return true;}public function generate_customer_id(){return (string)state()->actor;}
	public function get_session_cookie(){return false;}public function init_session_cookie(){}
	public function set_customer_session_cookie($set){if($set){$this->cookies[]=$this->_customer_id;}}
	public function maybe_set_customer_session_cookie(){$this->set_customer_session_cookie(true);}
	public function get_customer_unique_id(){return (string)$this->_customer_id;}
	public function forget_session(){$this->_data=[];$this->_dirty=false;$this->_customer_id=$this->generate_customer_id();}
	public function destroy_session(){$this->delete_session($this->_customer_id);$this->forget_session();}
	public function save_data($old=''){++$this->parent_saves;}public function delete_session($id){}public function update_session_timestamp($id,$time){}
}
final class State {public Registry $registry;public Store $store;public mixed $session=null;public int $actor=99;public string $token='exact';public array $filters=[];public function __construct(){$this->registry=new Registry();$this->store=new Store();}}
function state():State{return $GLOBALS['native_handler_test'];}
function WC():State{return state();}
function add_filter(string $hook,mixed $callback,int $priority=10,int $args=1):void{state()->filters[$hook][]=$callback;}
function apply_filters(string $hook,mixed $value,mixed ...$rest):mixed{foreach(state()->filters[$hook]??[] as $callback){$value=$callback($value,...$rest);}return $value;}
function add_action(string $hook,mixed $callback,int $priority=10,int $args=1):void{}
function wp_die(mixed ...$args):never{throw new \RuntimeException('Bounded refusal');}
function get_current_user_id():int{return state()->actor;}
function wp_get_session_token():string{return state()->token;}
function wp_cache_delete(mixed $key,string $group=''):bool{return true;}
/** Test-only: re-namespaces this plugin's OWN source file from disk so the fakes above stand in for WordPress/WooCommerce — the same technique NativeSessionGuardTest and QuantityRollbackTest use. Nothing outside includes/Cart/ is read and no test input reaches eval(). */
function load_source():void{if(class_exists(__NAMESPACE__.'\\NativeSessionGuard',false)){return;}foreach(['NativeSessionGuard','NativeSessionHandler'] as $name){$path=dirname(__DIR__,2).'/includes/Cart/'.$name.'.php';\PHPUnit\Framework\TestCase::assertTrue(is_file($path));$src=file_get_contents($path);$src=str_replace('namespace POW\\Cart;','namespace POW\\Tests\\NativeHandler; use POW\\Cart\\NativeSessionRow;',$src);$src=str_replace(['use POW\\Partners\\Registry;','use POW\\Sessions\\Store;','\\WC_Session_Handler','use Automattic\\WooCommerce\\StoreApi\\Utilities\\CartTokenUtils;'],['','','NativeBase',''],$src);eval(substr($src,5));}}
}
namespace {
/** Interprets the visit-basket statements only; anything else is a test fault, not a fixture gap. */
final class VisitExpiryDatabase {
	public string $prefix='wp_';public string $usermeta='wp_usermeta';public string $last_error='';public array $rows=[];public array $queries=[];
	public array $metadata=[['meta_key'=>'wp_capabilities','meta_value'=>'customer'],['meta_key'=>'wp_user_level','meta_value'=>'0']];
	public array $locks=[];public bool $suppressed=false;
	public function prepare(string $sql,mixed ...$args):string{$key='q'.count($this->queries);$this->queries[$key]=[$sql,$args];return $key;}
	public function get_var(string $key):?string{[$sql,$a]=$this->queries[$key];
		if(str_contains($sql,'GET_LOCK')){$name=(string)$a[0];if(isset($this->locks[$name])){return '0';}$this->locks[$name]=1;return '1';}
		if(str_contains($sql,'RELEASE_LOCK')){unset($this->locks[(string)$a[0]]);return '1';}
		throw new LogicException('Unexpected scalar query: '.$sql);}
	public function suppress_errors(bool $suppress=true):bool{$previous=$this->suppressed;$this->suppressed=$suppress;return $previous;}
	/** The indexed column is compared directly and the stored key comes back, so the byte-exact test happens in PHP. */
	private function indexed(string $sql):void{if(str_contains($sql,'BINARY session_key')){throw new LogicException('BINARY on the indexed column costs the UNIQUE index: '.$sql);}if(!str_contains($sql,'session_key = %s')){throw new LogicException('Missing the native key predicate: '.$sql);}}
	public function get_results(string $key,mixed $format):?array{[$sql,$a]=$this->queries[$key];if(str_contains($sql,'FROM wp_usermeta')){return $this->metadata;}
		$this->indexed($sql);if(!str_contains($sql,'SELECT session_key,')){throw new LogicException('The stored key must come back: '.$sql);}
		return isset($this->rows[$a[0]])?[['session_key'=>$a[0]]+$this->rows[$a[0]]]:[];}
	public function query(string $key):int|false{[$sql,$a]=$this->queries[$key];
		if(str_starts_with($sql,'INSERT')){if(str_contains($sql,'ON DUPLICATE')){throw new LogicException('No overwrite on insert');}[$k,$value,$expiry]=$a;if(isset($this->rows[$k])){return 0;}$this->rows[$k]=['session_value'=>$value,'session_expiry'=>(string)$expiry];return 1;}
		$this->indexed($sql);if(!str_contains($sql,'BINARY session_value = BINARY %s')){throw new LogicException('Missing exact CAS on the value: '.$sql);}
		if(str_starts_with($sql,'DELETE')){[$k,$expected]=$a;if(!isset($this->rows[$k])||$this->rows[$k]['session_value']!==$expected){return 0;}unset($this->rows[$k]);return 1;}
		[$value,$expiry,$k,$expected]=$a;if(!isset($this->rows[$k])||$this->rows[$k]['session_value']!==$expected){return 0;}$changed=$this->rows[$k]!==['session_value'=>$value,'session_expiry'=>(string)$expiry];$this->rows[$k]=['session_value'=>$value,'session_expiry'=>(string)$expiry];return (int)$changed;}
}
/** A correctly shaped stand-in for WooCommerce's session handler: every method the per-visit subclass depends on, with the visibility it depends on. */
class ShapedCoreSessionHandler {
	protected function init_hooks(){}
	private function init_session(){}
	private function init_session_from_request(){}
	private function is_session_cookie_valid(){}
	private function is_customer_guest(){}
	private function migrate_guest_session_to_user_session(){}
	public function generate_customer_id(){}
	public function init_session_cookie(){}
	public function get_session_cookie(){}
	public function set_customer_session_cookie(){}
	public function maybe_set_customer_session_cookie(){}
	public function has_session(){}
	public function get_session_data(){}
	public function get_customer_unique_id(){}
	public function get_session(){}
	public function delete_session(){}
	public function destroy_session(){}
	public function forget_session(){}
	public function save_data(){}
	public function update_session_timestamp(){}
	public function set_session_expiration(){}
}
/** WooCommerce opens init_hooks() up: the bypass would still work, but a shape it was not read against must be reported, not assumed. */
class WidenedCoreSessionHandler extends ShapedCoreSessionHandler {
	public function init_hooks(){}
}
/** WooCommerce renames the method that registers the shutdown save: without init_hooks() a bypassing subclass never persists the visit's basket at all. */
class HooklessCoreSessionHandler {
	private function init_session(){}
	private function init_session_from_request(){}
	private function is_session_cookie_valid(){}
	private function is_customer_guest(){}
	private function migrate_guest_session_to_user_session(){}
	public function generate_customer_id(){}
	public function init_session_cookie(){}
	public function get_session_cookie(){}
	public function set_customer_session_cookie(){}
	public function maybe_set_customer_session_cookie(){}
	public function has_session(){}
	public function get_session_data(){}
	public function get_customer_unique_id(){}
	public function get_session(){}
	public function delete_session(){}
	public function destroy_session(){}
	public function forget_session(){}
	public function save_data(){}
	public function update_session_timestamp(){}
	public function set_session_expiration(){}
}

/** Records the operational log the guard writes, which is the whole point of a refusal. */
final class VisitRefusalLogger extends POW\Logger {
	public array $warnings=[];
	public function __construct(){}
	public function warning(string $message,array $context=[]):void{$this->warnings[]=[$message,$context];}
}
final class NativeSessionHandlerTest extends PHPUnit\Framework\TestCase {
	private mixed $previous;private mixed $s;private VisitExpiryDatabase $db;private mixed $guard;private VisitRefusalLogger $logger;
	/** Seconds the fixture visit still has when the request starts. */
	private const VISIT_SECONDS=3600;
	protected function setUp():void{
		\POW\Tests\NativeHandler\load_source();
		$this->previous=$GLOBALS['wpdb']??null;
		$GLOBALS['wpdb']=$this->db=new VisitExpiryDatabase();
		$GLOBALS['native_handler_test']=$this->s=new \POW\Tests\NativeHandler\State();
		$this->s->registry->partner=POW\Partners\Partner::from_row(['id'=>7,'status'=>'active','owner_user_id'=>20]);
		$this->s->store->session=POW\Sessions\Session::from_row(['id'=>42,'partner_id'=>7,'user_id'=>99,'wp_session_token'=>'exact','status'=>'active','expires'=>gmdate('Y-m-d H:i:s',time()+self::VISIT_SECONDS),'wc_session_key'=>\POW\Tests\NativeHandler\VISIT_KEY]);
		$this->db->rows[\POW\Tests\NativeHandler\VISIT_KEY]=['session_value'=>serialize(['cart'=>'A']),'session_expiry'=>(string)(time()+self::VISIT_SECONDS)];
		$this->logger=new VisitRefusalLogger();
		$this->guard=new \POW\Tests\NativeHandler\NativeSessionGuard($this->s->registry,$this->s->store,$this->logger);
		$this->guard->register();
	}
	protected function tearDown():void{$GLOBALS['wpdb']=$this->previous;unset($GLOBALS['native_handler_test']);}
	private function request():object{$handler=new \POW\Tests\NativeHandler\NativeSessionHandler();$this->s->session=$handler;$handler->init();return $handler;}
	private function filtered(int $default):int{return (int)\POW\Tests\NativeHandler\apply_filters('wc_session_expiration',$default);}

	// ------------------------------------------------ the visit's own lifetime

	/**
	 * A visit's basket must not outlive the visit. The lever is the supported
	 * wc_session_expiration filter rather than an override of
	 * set_session_expiration(), which core calls from __construct() before any
	 * visit can be resolved.
	 */
	public function test_the_visit_lifetime_reaches_core_through_the_supported_filter():void{
		$handler=$this->request();
		self::assertSame(self::VISIT_SECONDS,$this->filtered(604800),'The filter answers with what the visit has left, not a week');
		self::assertSame(time()+self::VISIT_SECONDS,$handler->expiration());
		self::assertTrue($handler->in_visit());
	}
	/**
	 * The same filter is what CartTokenUtils::get_cart_token_expiration() reads,
	 * so a Store API cart token cannot outlive the visit it names either.
	 */
	public function test_the_same_filter_caps_the_store_api_cart_token_default():void{
		$this->request();
		self::assertSame(self::VISIT_SECONDS,$this->filtered(2*86400));
	}
	/** Nothing is filtered for a request that is not inside a visit. */
	public function test_a_request_outside_a_visit_leaves_the_expiration_filter_alone():void{
		$this->s->actor=123;
		$handler=$this->request();
		self::assertFalse(isset($this->s->filters['wc_session_expiration']),'An ordinary shopper installs no expiry filter');
		self::assertSame(604800,$this->filtered(604800));
		self::assertFalse($handler->is_protected());self::assertFalse($handler->in_visit());
	}
	/** A visit whose row has already run out still gets a floor, so core's own `?: default` fallback can never swap a week back in. */
	public function test_an_exhausted_visit_still_yields_a_positive_lifetime():void{
		$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['expires'=>gmdate('Y-m-d H:i:s',time()-10)]));
		$this->s->store->valid=true;
		// An expired row is not a live visit, so resolve one that is live but nearly over.
		$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['expires'=>gmdate('Y-m-d H:i:s',time()+1)]));
		$this->request();
		self::assertSame(60,$this->filtered(604800));
	}
	/** The staged expiry is the filtered one: the snapshot the compare-and-set verifies must be the value the row will carry. */
	public function test_the_staged_expiry_is_the_visit_lifetime():void{
		$handler=$this->request();
		$handler->set('cart','B');
		self::assertTrue($handler->save_checked());
		self::assertSame((string)(time()+self::VISIT_SECONDS),$this->db->rows[\POW\Tests\NativeHandler\VISIT_KEY]['session_expiry']);
	}

	// -------------------------------------------- the deliberate terminal removal

	/**
	 * A completed return is not a refused basket commit.
	 *
	 * ReturnEndpoint wins the transition and then empties the cart, which fires
	 * woocommerce_cart_emptied -> cleanup_terminal_cart() -> cleanup(), and that
	 * removes this visit's own wp_woocommerce_sessions row and refuses the
	 * handler on purpose. Core's init_hooks() registered its own save_data() on
	 * shutdown at priority 20 and it still runs, so the handler must recognise
	 * its own teardown: staging a row that was just deleted throws, and the
	 * throw used to be recorded as reason 'exception' and logged at warning
	 * level on every successful return, abandon and in-visit logout — making the
	 * only operational signal for a real basket-protection failure
	 * indistinguishable from success.
	 */
	public function test_a_completed_return_stages_nothing_and_logs_no_refusal():void{
		$handler=$this->request();
		self::assertSame('A',$handler->get('cart'));
		$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>POW\Sessions\Session::RETURNED]));
		$this->guard->cleanup_terminal_cart();
		self::assertSame([],$this->db->rows,'The terminal cleanup removes this visit\'s basket row');
		// Core's shutdown save, which runs whatever the return endpoint did.
		$handler->save_data('');
		self::assertSame([],$this->db->rows,'A deliberately removed row is never re-staged');
		self::assertNull($handler->last_refusal(),'A completed return is not a refusal');
		self::assertSame([],$this->logger->warnings,'No refusal warning may be logged for a successful return');
		self::assertTrue($handler->is_protected(),'The handler stays protected: no unguarded parent save may run');
		self::assertSame(0,$handler->parent_saves);
	}
	/** A Store API response arriving after the same teardown answers success rather than a 500 from a commit that had nothing to commit. */
	public function test_a_rest_save_after_the_terminal_removal_is_not_a_failure():void{
		$handler=$this->request();
		$this->s->store->session=new POW\Sessions\Session(...array_replace(get_object_vars($this->s->store->session),['status'=>POW\Sessions\Session::CLOSED]));
		$this->guard->cleanup_terminal_cart();
		self::assertTrue($handler->save_checked());
		self::assertNull($handler->last_refusal());
		self::assertSame([],$this->logger->warnings);
		self::assertSame([],$this->db->rows);
	}
	/** The silence is specific to that teardown: a commit refused for any other reason still warns. */
	public function test_a_refusal_that_is_not_a_deliberate_removal_still_warns():void{
		$handler=$this->request();
		// A later handler took over WC()->session mid-request.
		$this->s->session=null;
		$handler->save_data('');
		self::assertSame('exception',$handler->last_refusal());
		self::assertSame(['Native cart commit refused'],array_column($this->logger->warnings,0));
		self::assertSame(serialize(['cart'=>'A']),$this->db->rows[\POW\Tests\NativeHandler\VISIT_KEY]['session_value'],'A refused commit changes nothing');
	}

	// ------------------------------------------------------ handler-shape check

	/** A shape the bypass was read against reports nothing. */
	public function test_a_correctly_shaped_core_handler_reports_no_faults():void{
		self::assertSame([],POW\Docs\SelfTest::cart_handler_faults(ShapedCoreSessionHandler::class));
	}
	/** An absent class is not a fault: WooCommerce may simply not be loaded. */
	public function test_an_absent_core_handler_is_not_a_fault():void{
		self::assertSame([],POW\Docs\SelfTest::cart_handler_faults('\\POW_No_Such_Session_Handler'));
	}
	/** A re-scoped method is reported with what was expected and what was found. */
	public function test_a_rescoped_core_method_is_reported():void{
		$faults=POW\Docs\SelfTest::cart_handler_faults(WidenedCoreSessionHandler::class);
		self::assertSame(['init_hooks is public, expected protected'],$faults);
	}
	/** Every method the subclass depends on is checked, not a sample of them. */
	public function test_a_handler_missing_everything_reports_every_method():void{
		$faults=POW\Docs\SelfTest::cart_handler_faults(VisitExpiryDatabase::class);
		self::assertContains('init_hooks is gone',$faults);
		self::assertContains('migrate_guest_session_to_user_session is gone',$faults);
		self::assertContains('set_session_expiration is gone',$faults);
		self::assertSame(21,count($faults));
	}
	/**
	 * init_hooks() is the one the bypass cannot do without: core registers the
	 * shutdown save, the `wp` cookie hook and template_redirect there, so a
	 * subclass that skips init_session() and cannot call it would hydrate a
	 * visit's basket and never persist it.
	 */
	public function test_a_missing_hook_registrar_is_reported():void{
		self::assertSame(['init_hooks is gone'],POW\Docs\SelfTest::cart_handler_faults(HooklessCoreSessionHandler::class));
	}
}
}
