<?php
/**
 * Regression tests for hypotheses H1 and H3 of the 2026-09-15 quantity rollback review: a compare-and-set loss is retried once on the fresh row, and every final refusal is logged (hashed key + reason).
 *
 * Production NativeSessionGuard/NativeSessionHandler bodies run against the REAL POW\Sessions\Store
 * (so Store::invalidate_delivery's own predicates decide), a fake partner registry and an
 * interpreted wpdb fixture. Two handler instances stand in for two overlapping requests; the
 * WC()->session switch models which request is currently executing. No database, no timing.
 */
declare(strict_types=1);
namespace POW\Tests\Rollback {
use POW\Sessions\Session;
use POW\Partners\Partner;
/** This visit's WooCommerce session key: 'pow_' + 28 hex = char(32). */
const VISIT_KEY='pow_1a2b3c4d5e6f708192a3b4c5d6e7';
const SIBLING_KEY='pow_00112233445566778899aabbccdd';
final class Registry {public Partner $partner;public bool $locked=false;public function find(int $id):?Partner{return $this->partner;}public function with_partner_lock(int $id,callable $fn):mixed{if($this->locked){throw new \LogicException('Nested lock');}$this->locked=true;try{return $fn();}finally{$this->locked=false;}}}
class NativeBase {
	protected $_customer_id='99';protected $_data=[];protected $_dirty=false;protected $_session_expiration=9999999999;protected $_has_cookie=false;public int $parent_saves=0;public bool $hooked=false;public array $cookies=[];
	public function __construct(){}public function init(){$this->init_hooks();$this->_data=$this->get_session($this->_customer_id,[]);}
	protected function init_hooks():void{$this->hooked=true;}
	public function get_customer_id(){return $this->_customer_id;}public function get_session($key,$default=false){return ['cart'=>'STALE CACHE'];}
	public function get($key,$default=null){return $this->_data[$key]??$default;}public function set($key,$value){$this->_data[$key]=$value;$this->_dirty=true;}
	public function set_expiration(int $expiry):void{$this->_session_expiration=$expiry;}
	public function set_session_expiration(){}public function has_session(){return true;}public function get_session_data(){return $this->_data;}
	public function generate_customer_id(){return (string)state()->actor;}public function get_session_cookie(){return false;}
	public function set_customer_session_cookie($set){if($set){$this->cookies[]=$this->_customer_id;}}public function maybe_set_customer_session_cookie(){$this->set_customer_session_cookie(true);}
	public function init_session_cookie(){}public function get_customer_unique_id(){return (string)$this->_customer_id;}
	public function forget_session(){$this->_data=[];$this->_dirty=false;$this->_customer_id=$this->generate_customer_id();}
	public function destroy_session(){$this->delete_session($this->_customer_id);$this->forget_session();}
	public function save_data($old=''){++$this->parent_saves;}public function delete_session($id){}public function update_session_timestamp($id,$time){}
}
final class State {public Registry $registry;public mixed $session=null;public int $actor=99;public string $token='exact';public function __construct(){$this->registry=new Registry();}}
function add_filter(string $hook,mixed $callback,int $priority=10,int $args=1):void{}
function add_action(string $hook,mixed $callback,int $priority=10,int $args=1):void{}
function wp_die(mixed ...$args):never{throw new \RuntimeException('Bounded refusal');}
function state():State{return $GLOBALS['rollback_test'];}function WC():State{return state();}function get_current_user_id():int{return state()->actor;}function wp_get_session_token():string{return state()->token;}function wp_cache_delete(mixed $key,string $group=''):bool{return true;}
/** Test-only: re-namespaces the production source so the fakes above stand in for WordPress/WooCommerce, the same technique NativeSessionGuardTest uses. */
function load_source():void{if(class_exists(__NAMESPACE__.'\\NativeSessionGuard',false)){return;}foreach(['NativeSessionGuard','NativeSessionHandler'] as $name){$path=dirname(__DIR__,2).'/includes/Cart/'.$name.'.php';\PHPUnit\Framework\TestCase::assertTrue(is_file($path));$src=file_get_contents($path);$src=str_replace('namespace POW\\Cart;','namespace POW\\Tests\\Rollback; use POW\\Cart\\NativeSessionRow;',$src);$src=str_replace(['use POW\\Partners\\Registry;','\\WC_Session_Handler','use Automattic\\WooCommerce\\StoreApi\\Utilities\\CartTokenUtils;'],['','NativeBase',''],$src);eval(substr($src,5));}}
}
namespace {
/** Interprets the exact SQL the code emits: pow_sessions lookups/consent clear, usermeta snapshot, woocommerce_sessions compare-and-set. */
final class RollbackDatabase {
	public string $prefix='wp_';public string $usermeta='wp_usermeta';public string $last_error='';public array $queries=[];
	public array $rows=[];public array $session=[];public array $metadata=[['umeta_id'=>'1','meta_key'=>'session_tokens','meta_value'=>'native-login']];
	public ?int $invalidate_affected=null;public mixed $after_invalidate=null;public mixed $before_cas=null;public int $consent_clears=0;public array $log=[];
	public function prepare(string $sql,mixed ...$args):string{$key='q'.count($this->queries);$this->queries[$key]=[$sql,$args];return $key;}
	public function get_row(string $key,mixed $format):?array{[$sql,$a]=$this->queries[$key];if(!str_contains($sql,'wp_pow_sessions')){throw new LogicException('Unexpected row query: '.$sql);}
		if(str_contains($sql,'WHERE id = %d')){return (int)$a[0]===(int)$this->session['id']?$this->session:null;}
		if(str_contains($sql,'wp_session_token = %s AND status IN')){[$user,$token]=$a;$statuses=array_slice($a,2);return (int)$user===(int)$this->session['user_id']&&$token===$this->session['wp_session_token']&&in_array($this->session['status'],$statuses,true)?$this->session:null;}
		throw new LogicException('Unexpected session query: '.$sql);}
	public function get_results(string $key,mixed $format):?array{[$sql,$a]=$this->queries[$key];
		if(str_contains($sql,'FROM wp_usermeta')){return $this->metadata;}
		if(str_contains($sql,'wp_pow_sessions')){return (int)$a[0]===(int)$this->session['user_id']&&in_array($this->session['status'],array_slice($a,1),true)?[$this->session]:[];}
		if(!str_contains($sql,'BINARY session_key = BINARY %s')){throw new LogicException('Missing exact native key');}return isset($this->rows[$a[0]])?[$this->rows[$a[0]]]:[];}
	public function query(string $key):int|false{[$sql,$a]=$this->queries[$key];$this->log[]=$sql;
		if(str_contains($sql,'wp_pow_sessions')){
			if(!str_starts_with($sql,'UPDATE')||!str_contains($sql,'SET delivery_confirmation = NULL')){throw new LogicException('Unexpected session write: '.$sql);}
			++$this->consent_clears;[$id,$partner,$user,$token,$status,$confirmation]=$a;$s=$this->session;
			$matches=(int)$s['id']===(int)$id&&(int)$s['partner_id']===(int)$partner&&(int)$s['user_id']===(int)$user&&$s['wp_session_token']===$token&&$s['status']===$status&&($s['delivery_confirmation']??null)===$confirmation;
			if(null!==$this->invalidate_affected){return $this->invalidate_affected;}
			if(!$matches){return 0;}$this->session['delivery_confirmation']=null;if($this->after_invalidate){$f=$this->after_invalidate;$this->after_invalidate=null;$f();}return 1;}
		if(!str_contains($sql,'BINARY session_value = BINARY %s')||!str_contains($sql,'BINARY session_key = BINARY %s')){throw new LogicException('Missing exact CAS');}
		if($this->before_cas){$f=$this->before_cas;$this->before_cas=null;$f();}[$value,$expiry,$key,$expected]=$a;if(!isset($this->rows[$key])||$this->rows[$key]['session_value']!==$expected){return 0;}$changed=$this->rows[$key]!==['session_value'=>$value,'session_expiry'=>(string)$expiry];$this->rows[$key]=['session_value'=>$value,'session_expiry'=>(string)$expiry];return (int)$changed;}
}
final class RollbackLogger extends \POW\Logger {public array $warnings=[];public function __construct(){}public function warning(string $message,array $context=[]):void{$this->warnings[]=[$message,$context];}}
final class QuantityRollbackTest extends PHPUnit\Framework\TestCase {
	private mixed $previous;private mixed $previous_tokens;private mixed $s;private RollbackDatabase $db;private mixed $guard;private array $logged=[];private RollbackLogger $logger;
	protected function setUp():void{
		\POW\Tests\Rollback\load_source();
		$this->previous=$GLOBALS['wpdb']??null;$this->previous_tokens=$GLOBALS['pow_test_session_tokens']??null;
		$GLOBALS['wpdb']=$this->db=new RollbackDatabase();$GLOBALS['rollback_test']=$this->s=new \POW\Tests\Rollback\State();
		$GLOBALS['pow_test_session_tokens']=[99=>['exact'=>['login'=>1]]];
		$this->s->registry->partner=POW\Partners\Partner::from_row(['id'=>7,'status'=>'active','owner_user_id'=>20]);
		$this->db->session=['id'=>42,'partner_id'=>7,'user_id'=>99,'wp_session_token'=>'exact','status'=>'active','expires'=>gmdate('Y-m-d H:i:s',time()+3600),'wc_session_key'=>\POW\Tests\Rollback\VISIT_KEY,'delivery_choice'=>'{"provider":"book","key":"hq"}','delivery_confirmation'=>'{"confirmed":true}'];
		$this->db->rows[\POW\Tests\Rollback\VISIT_KEY]=['session_value'=>serialize(['cart'=>['item'=>['quantity'=>1]]]),'session_expiry'=>'9999999999'];
		$this->logger=new RollbackLogger();$this->guard=new \POW\Tests\Rollback\NativeSessionGuard($this->s->registry,new POW\Sessions\Store(),$this->logger);$this->guard->register();
		$this->logged=[];set_error_handler(function(int $no,string $msg):bool{$this->logged[]=$msg;return true;});
	}
	protected function tearDown():void{restore_error_handler();$GLOBALS['wpdb']=$this->previous;$GLOBALS['pow_test_session_tokens']=$this->previous_tokens;unset($GLOBALS['rollback_test']);}
	/** A request hydrates the buyer row exactly as WooCommerce would at init. */
	private function request():object{$handler=new \POW\Tests\Rollback\NativeSessionHandler();$this->s->session=$handler;$handler->init();return $handler;}
	private function stored():array{return unserialize($this->db->rows[\POW\Tests\Rollback\VISIT_KEY]['session_value']);}
	private function quantity(object $handler,int $qty):void{$this->s->session=$handler;$handler->set('cart',['item'=>['quantity'=>$qty]]);}
	/** One warning with the hashed key and reason; never the raw key, session data or secrets. */
	private function assertRefusalLogged(string $reason):void{self::assertCount(1,$this->logger->warnings);[$message,$context]=$this->logger->warnings[0];self::assertSame('Native cart commit refused',$message);self::assertSame(['session_key_hash'=>substr(hash('sha256',\POW\Tests\Rollback\VISIT_KEY),0,16),'reason'=>$reason],$context);self::assertStringNotContainsString(\POW\Tests\Rollback\VISIT_KEY,json_encode($context),'The key is a bearer credential: only its hash may be logged');}

	// ---- H1: optimistic compare-and-set loses to an overlapping request ----

	public function test_h1_overlapping_request_that_rewrites_the_row_no_longer_drops_the_quantity_change():void{
		$quantity=$this->request();$fragments=$this->request(); // both hydrated from quantity=1
		self::assertSame(1,$quantity->get('cart')['item']['quantity']);
		// The overlapping request persists first, with a different value (e.g. recalculated shipping/cart_totals keys).
		$this->s->session=$fragments;$fragments->set('cart_totals',['total'=>'10.00']);self::assertTrue($fragments->save_checked());
		// The quantity request's first compare-and-set loses; it rehydrates once and replays only its own changed key.
		$this->quantity($quantity,2);
		self::assertTrue($quantity->save_checked(),'Retry on the fresh row succeeds');
		self::assertSame(2,$this->stored()['cart']['item']['quantity'],'Quantity 2 persisted via the retry');
		self::assertSame(['total'=>'10.00'],$this->stored()['cart_totals'],'The overlapping request\'s value is preserved, not overwritten by the stale snapshot');
		self::assertSame('',$this->db->last_error,'No SQL error');self::assertSame([],$this->logged);self::assertSame([],$this->logger->warnings,'Success is not logged');self::assertSame(0,$quantity->parent_saves,'No native fallback save');self::assertNull($quantity->last_refusal());
		self::assertSame(2,count(array_filter($this->db->log,fn($q)=>str_contains($q,'wp_woocommerce_sessions'))),'Exactly one retry write after the fragments write');
	}
	public function test_h1_second_conflict_during_the_retry_is_refused_and_logged():void{
		$quantity=$this->request();$fragments=$this->request();
		$this->s->session=$fragments;$fragments->set('cart_totals',['total'=>'10.00']);self::assertTrue($fragments->save_checked());
		// Another writer lands between the retry's rehydration and its compare-and-set.
		$this->db->before_cas=function(){$this->db->rows[\POW\Tests\Rollback\VISIT_KEY]['session_value']=serialize(['cart'=>['item'=>['quantity'=>1]],'cart_totals'=>['total'=>'12.00']]);};
		$this->quantity($quantity,2);
		self::assertFalse($quantity->save_checked(),'Only one retry');
		self::assertSame(1,$this->stored()['cart']['item']['quantity']);self::assertSame(['total'=>'12.00'],$this->stored()['cart_totals'],'Latest concurrent value untouched');
		self::assertSame('row_conflict',$quantity->last_refusal());$this->assertRefusalLogged('row_conflict');self::assertSame(0,$quantity->parent_saves,'No native fallback save');
		// The refused request stays blocked through shutdown.
		$this->quantity($quantity,2);self::assertFalse($quantity->save_checked());self::assertSame(1,$this->stored()['cart']['item']['quantity']);
	}
	public function test_h1_overlapping_request_that_only_refreshes_expiry_does_not_lose_the_quantity_change():void{
		$quantity=$this->request();$fragments=$this->request();
		$this->s->session=$fragments;$fragments->set_expiration(9999999998);self::assertTrue($fragments->save_checked());
		self::assertSame('9999999998',$this->db->rows[\POW\Tests\Rollback\VISIT_KEY]['session_expiry']);
		$this->quantity($quantity,2);self::assertTrue($quantity->save_checked(),'Same session_value baseline still matches after an expiry-only rewrite');
		self::assertSame(2,$this->stored()['cart']['item']['quantity']);
	}
	public function test_h1_first_writer_wins_regardless_of_which_request_carries_the_quantity():void{
		$quantity=$this->request();$fragments=$this->request();
		$this->quantity($quantity,2);self::assertTrue($quantity->save_checked());
		$this->s->session=$fragments;$fragments->set('cart_totals',['total'=>'10.00']);self::assertTrue($fragments->save_checked(),'Late overlapping request replays its own key over the quantity row');
		self::assertSame(2,$this->stored()['cart']['item']['quantity'],'No rollback of quantity');self::assertSame(['total'=>'10.00'],$this->stored()['cart_totals']);self::assertSame([],$this->logged);self::assertSame([],$this->logger->warnings);
	}

	// ---- H3: consent invalidation gating the cart write ----

	public function test_h3_active_session_with_confirmation_clears_consent_and_commits_quantity():void{
		$quantity=$this->request();$this->guard->mark_changed();$this->quantity($quantity,2);
		self::assertTrue($quantity->save_checked());self::assertSame(2,$this->stored()['cart']['item']['quantity']);
		self::assertNull($this->db->session['delivery_confirmation']);self::assertSame(1,$this->db->consent_clears);
	}
	public function test_h3_ordered_session_skips_invalidation_and_commits():void{
		$this->db->session['status']='ordered';$quantity=$this->request();$this->guard->mark_changed();$this->quantity($quantity,2);
		self::assertTrue($quantity->save_checked());self::assertSame(0,$this->db->consent_clears);self::assertSame(2,$this->stored()['cart']['item']['quantity']);
	}
	public function test_h3_session_leaving_active_after_hydration_drops_commit_before_invalidation_runs():void{
		$quantity=$this->request();$this->db->session['status']='returned';
		$this->quantity($quantity,2);self::assertFalse($quantity->save_checked());
		self::assertSame(0,$this->db->consent_clears,'identity_locked refuses first; invalidate_delivery never reached');
		self::assertSame(1,$this->stored()['cart']['item']['quantity']);
		// With mark_changed the outcome is identical: the drop is the identity check, not the consent gate.
		$this->db->session['status']='active';$again=$this->request();$this->db->session['status']='returned';$this->guard->mark_changed();$this->quantity($again,2);
		self::assertFalse($again->save_checked());self::assertSame(0,$this->db->consent_clears);self::assertSame(1,$this->stored()['cart']['item']['quantity']);self::assertSame([],$this->logged);
		// The pre-lock login re-check in stage_identity() throws, so both attempts surface as 'exception' (identity_locked is never reached).
		self::assertSame('exception',$again->last_refusal());self::assertCount(2,$this->logger->warnings);self::assertSame(['exception','exception'],array_column(array_column($this->logger->warnings,1),'reason'));
		// An 'exception' refusal names the throwable so a benign login-window refusal can be told from a storage fault. Still no raw key or session data.
		foreach($this->logger->warnings as [$message,$context]){self::assertSame('RuntimeException: Native cart login unavailable.',$context['detail']);self::assertStringNotContainsString('"99"',json_encode($context));self::assertStringNotContainsString('quantity',json_encode($context));}
	}
	/** An expired visit is not a visit: the request is an ordinary shopper of the bound account, core owns its numeric row and the visit's basket is left for cron or a return to remove. Nothing is refused, so nothing is logged. */
	public function test_an_expired_visit_becomes_an_ordinary_shopper_and_keeps_the_visit_basket():void{
		$this->db->session['status']='expired';$handler=$this->request();
		self::assertFalse($handler->is_protected());self::assertSame('99',(string)$handler->get_customer_id());
		$handler->save_data();
		self::assertSame([],$this->logger->warnings,'Nothing refused, nothing to log');self::assertNull($handler->last_refusal());
		self::assertSame(1,$this->stored()['cart']['item']['quantity'],'Visit basket untouched');
		self::assertSame([],$this->db->log,'No statement may touch wp_woocommerce_sessions for a key that is not a visit key');
	}
	/** Two visits of one shared login are two baskets: each compare-and-set sees only its own row. */
	public function test_two_visits_of_one_login_commit_independently():void{
		$mine=$this->request();
		$this->db->rows[\POW\Tests\Rollback\SIBLING_KEY]=['session_value'=>serialize(['cart'=>['item'=>['quantity'=>5]]]),'session_expiry'=>'9999999999'];
		$this->quantity($mine,2);self::assertTrue($mine->save_checked());
		self::assertSame(2,$this->stored()['cart']['item']['quantity']);
		self::assertSame(5,unserialize($this->db->rows[\POW\Tests\Rollback\SIBLING_KEY]['session_value'])['cart']['item']['quantity'],'A sibling visit basket is never a candidate');
		self::assertSame([],$this->logger->warnings);
	}
	public function test_h3_consent_clear_affecting_zero_rows_drops_the_cart_commit_and_logs_it():void{
		$quantity=$this->request();$this->guard->mark_changed();$this->quantity($quantity,2);
		$this->db->invalidate_affected=0; // DB matched nothing (or reported 0) although the snapshot said a confirmation exists
		$result=$quantity->save_checked();
		self::assertFalse($result);self::assertSame(1,$this->db->consent_clears);self::assertSame(1,$this->stored()['cart']['item']['quantity'],'Cart commit dropped');
		self::assertSame('',$this->db->last_error);self::assertSame([],$this->logged);
		self::assertSame(0,count(array_filter($this->db->log,fn($q)=>str_contains($q,'wp_woocommerce_sessions'))),'No cart write attempted');
		self::assertSame('consent_invalidation',$quantity->last_refusal());$this->assertRefusalLogged('consent_invalidation');self::assertSame(0,$quantity->parent_saves,'No native fallback save');
	}
	public function test_h3_same_failure_without_mark_changed_commits_normally():void{
		$quantity=$this->request();$this->quantity($quantity,2);$this->db->invalidate_affected=0;
		self::assertTrue($quantity->save_checked());self::assertSame(0,$this->db->consent_clears);self::assertSame(2,$this->stored()['cart']['item']['quantity']);
	}
	public function test_h3_delivery_choice_drift_during_clear_removes_consent_drops_cart_commit_and_logs_it():void{
		$quantity=$this->request();$this->guard->mark_changed();$this->quantity($quantity,2);
		$this->db->after_invalidate=function(){$this->db->session['delivery_choice']='{"provider":"book","key":"branch"}';};
		self::assertFalse($quantity->save_checked());
		self::assertNull($this->db->session['delivery_confirmation'],'Consent already removed');self::assertSame(1,$this->stored()['cart']['item']['quantity'],'Cart change lost');self::assertSame([],$this->logged);
		self::assertSame('consent_invalidation',$quantity->last_refusal());$this->assertRefusalLogged('consent_invalidation');
	}
	public function test_h3_already_cleared_confirmation_is_not_a_gate():void{
		$this->db->session['delivery_confirmation']=null;$quantity=$this->request();$this->guard->mark_changed();$this->quantity($quantity,2);
		self::assertTrue($quantity->save_checked());self::assertSame(0,$this->db->consent_clears);self::assertSame(2,$this->stored()['cart']['item']['quantity']);
	}
}
}
