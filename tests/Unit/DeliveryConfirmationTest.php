<?php
/** Confirmation service rules with isolated collaborator doubles; no native persistence claim. */
declare( strict_types = 1 );

namespace POW\Tests\DeliveryConfirmation {
use POW\Addresses\DeliveryData;
use POW\Partners\Partner;
use POW\Sessions\Session;

/** This visit's WooCommerce session key and a sibling visit's, as Cart\SessionKey mints them: `pow_` + 28 hex, 32 characters. */
const VISIT_KEY = 'pow_1a2b3c4d5e6f708192a3b4c5d6e7';
const SIBLING_KEY = 'pow_00112233445566778899aabbccdd';

final class Registry {
	public Partner $partner;
	public bool $locked = false;
	public mixed $before_lock = null;
	public int $fences = 0;
	public function transition_status(int $id,string $expected,array $fields):bool{++$this->fences;$this->partner=Partner::from_row(array_replace(get_object_vars($this->partner),$fields));return true;}
	public function find(int $id): ?Partner { return $id === $this->partner->id ? $this->partner : null; }
	public function with_partner_lock(int $id,callable $fn):mixed { if($this->locked){throw new \LogicException('Nested mutex');} if($this->before_lock){$f=$this->before_lock;$this->before_lock=null;$f();} $this->locked=true;try{return $fn();}finally{$this->locked=false;} }
}
final class Store {
	public Session $session;
	public bool $valid = true;
	public bool $fail_save = false;
	public bool $fail_invalidate = false;
	public int $writes = 0;
	public int $invalidations = 0;
	public bool $fail_expire = false;
	public int $expirations = 0;
	public mixed $recovery = null;
	public function expire_active_login_locked(Session $s):bool{++$this->expirations;if($this->recovery){return $this->recovery->expire_active_login_locked($s);}if($this->fail_expire){return false;}$this->change(['status'=>Session::EXPIRED]);$this->valid=false;return true;}
	public function find(int $id):?Session{return $id===$this->session->id?$this->session:null;}
	public function login_valid_checked(Session $s):bool{return $this->valid;}
	public function change(array $values):void{$this->session=new Session(...array_replace(get_object_vars($this->session),$values));}
	public function save_delivery(int $id,int $buyer,string $token,?string $choice,?string $confirmation,array $new_choice,array $new_confirmation):bool{
		\PHPUnit\Framework\TestCase::assertTrue(state()->registry->locked);
		if($choice!==$this->session->delivery_choice_json||$confirmation!==$this->session->delivery_confirmation_json){return false;}
		++$this->writes;$this->change(['delivery_choice_json'=>[]===$new_choice?null:json_encode($new_choice),'delivery_confirmation_json'=>json_encode($new_confirmation)]);return !$this->fail_save;
	}
	public function invalidate_delivery(int $id,int $buyer,string $token):bool{\PHPUnit\Framework\TestCase::assertTrue(state()->registry->locked);++$this->invalidations;if($this->fail_invalidate){return false;}$this->change(['delivery_confirmation_json'=>null]);return true;}
}
final class Resolver {
	public array $choices = [];
	public bool $associated = true;
	public mixed $on_validate = null;
	public function partner_for_user(int $buyer):?Partner{return $this->associated?state()->registry->partner:null;}
	public function choices_for_session(Session $s,Partner $p):array|\WP_Error{return $this->choices;}
	public function validate_active_choice_locked(Session $s,Partner $p,array $choice):bool|\WP_Error{
		\PHPUnit\Framework\TestCase::assertTrue(state()->registry->locked);
		if($this->on_validate){($this->on_validate)();}
		if('native'!==$choice['provider']){return true;}
		foreach($this->choices as $entry){if($entry['key']===$choice['key']){return $entry['entry_fingerprint']===$choice['entry_fingerprint']?true:new \WP_Error('address_changed','Changed');}}
		return new \WP_Error('address_unavailable','Unavailable');
	}
}
final class QuoteAddress {
	public ?array $candidate = null;
	public static function payload(?array $choice):?array{return \POW\Addresses\QuoteAddress::payload($choice);}
	public function resolve_destination(Session $s,Partner $p):?array{\PHPUnit\Framework\TestCase::assertFalse(state()->registry->locked);return $this->candidate;}
}
final class PoomMapper {
	public mixed $callback = null;
	public int $price = 1000;
	public function from_cart(Partner $p):array{\PHPUnit\Framework\TestCase::assertFalse(state()->registry->locked);if($this->callback){($this->callback)();}return ['items'=>[['supplier_part_id'=>'SKU','quantity'=>2.0,'unit_price_cents'=>$this->price,'description'=>'Goods','uom'=>'EA']],'total_cents'=>$this->price*2,'currency'=>state()->currency,'skipped'=>[]];}
}
final class Product {
	public bool $physical = true;
	public bool $forbid_data = false;
	public function needs_shipping():bool{return $this->physical;}
	public function get_data():array{if($this->forbid_data){throw new \LogicException('Metadata-loading getter');}return ['id'=>1,'virtual'=>!$this->physical,'price'=>'10.00'];}
	public function get_id():int{return 1;}
	public function __call(string $method,array $args):mixed{if(($args[0]??'')!=='edit'){throw new \LogicException('Filtered getter');}return match($method){'get_virtual'=>!$this->physical,'get_price'=>'10.00',default=>''};}
	public function get_changes():array{return [];}
}
final class Cart {
	public array $cart_contents;
	public array $applied_coupons = [];
	public function __construct(){$this->cart_contents=['line'=>['data'=>new Product(),'product_id'=>1,'quantity'=>2,'line_total'=>20.0,'line_tax'=>3.0]];}
	public function get_cart():array{if(state()->registry->locked){throw new \LogicException('Filtered cart read under lock');}return $this->cart_contents;}
	public function get_totals():array{return ['total'=>20.0];}
}
final class Customer {
	public array $address=[];
	public function get_id():int{return 99;}
	public function get_data():array{return ['id'=>99,'shipping'=>$this->address];}
	public function get_changes():array{return [];}
	public function get_shipping(string $context='view'):array{return $this->address;}
}
final class NativeSession {
	public array $values=[];
	public function __construct(public string $key=VISIT_KEY){}
	public function get_customer_id():string{return $this->key;}
	public function get(string $key,mixed $default=null):mixed{return $this->values[$key]??$default;}
	public function set(string $key,mixed $value):void{$this->values[$key]=$value;}
}
final class NativeSessionGuard {
	public function __construct(mixed ...$args){}
	public function prepare(Session $session):array{return ['loaded'=>state()->loaded_native];}
	public function commit_locked(Session $session,array $prepared):bool{return ($prepared['loaded']??null)===state()->durable_native;}
	public function check_locked(Session $session,array $prepared):bool{return $this->commit_locked($session,$prepared);}
	public function flush_cache():void{}
}
final class NativeRate {public function __construct(public array $data){} public function jsonSerialize():array{return ['data'=>$this->data,'meta_data'=>[]];}}
final class Shipping {public array $packages=[];public function get_packages():array{return $this->packages;}}
final class DeliveryEstimate {
	public mixed $callback=null;
	public bool $unknown=false;
	public int $amount=500;
	public array $taxes=[1=>'0.75'];
	public bool $split_destination=false;
	public int $calls=0;
	public function quote(Session $s,Partner $p,?array $destination):array{
		\PHPUnit\Framework\TestCase::assertFalse(state()->registry->locked);++$this->calls;if($this->callback){($this->callback)();}
		$physical=state()->cart->cart_contents['line']['data']->physical;
		if($physical&&null===$destination){throw new \DomainException('Missing address');}
		if($destination){state()->customer->address=$destination['address'];}
		$chosen=state()->session->get('chosen_shipping_methods',[0=>'flat:1']);state()->session->set('chosen_shipping_methods',$physical?$chosen:[]);
		$rates=[];foreach(['flat:1'=>$this->amount,'flat:2'=>900] as $id=>$amount){$rates[]=['package_key'=>0,'rate_id'=>$id,'method_id'=>'flat','instance_id'=>1,'label'=>'Delivery','amount_cents'=>$amount,'taxes'=>$this->taxes];}
		$selected=array_values(array_filter($rates,fn($r)=>$r['rate_id']===($chosen[0]??'')));
		state()->shipping->packages=$physical?[0=>['destination'=>$destination['address'],'rates'=>$this->unknown?[]:array_column(array_map(fn($r)=>['key'=>$r['rate_id'],'rate'=>new NativeRate($r)],$rates),'rate','key')]]:[];
		if($this->split_destination){state()->shipping->packages[0]['destination']['city']='Other destination';}
		$d=['status'=>!$physical?'not_required':(!$p->emit_delivery_line?'disabled':($this->unknown?'unknown':'quoted')),'amount_cents'=>!$physical||$this->unknown?null:($selected[0]['amount_cents']??null),'currency'=>state()->currency,'code'=>$destination['code']??'','emit'=>$physical&&$p->emit_delivery_line&&!$this->unknown,'rates'=>!$physical||$this->unknown?[]:$selected,'freight'=>['supplier_part_id'=>$p->freight_supplier_part_id,'uom'=>$p->freight_uom,'classification_domain'=>$p->freight_classification_domain,'classification'=>$p->freight_classification]];
		$ack=$physical&&$p->emit_delivery_line&&$this->unknown&&'quote_separately'===$p->delivery_unknown_policy;
		return ['delivery'=>$d,'packages'=>!$physical?[]:[['package_key'=>0,'label'=>'Parcel','selected_rate_id'=>$this->unknown?null:($chosen[0]??null),'rates'=>$this->unknown?[]:array_map(fn($r)=>$r+['display_label'=>'<b>Native</b>'],$rates)]],'can_confirm'=>!$p->emit_delivery_line||!$this->unknown||$ack,'requires_unknown_acknowledgement'=>$ack];
	}
}
final class PolicyDatabase {
	public string $options='wp_options';public string $last_error='';public array $values=['pow_settings'=>'a:0:{}','woocommerce_currency'=>'ZAR'];public mixed $callback=null;
	public function prepare(string $sql,mixed ...$args):string{return $sql;}
	public function get_results(string $sql,string $format):array{if(!str_contains($sql,'SELECT option_name, option_value FROM wp_options')){throw new \LogicException('Unexpected option query');}if($this->callback){$f=$this->callback;$this->callback=null;$f();}$rows=[];foreach($this->values as $key=>$value){$rows[]=['option_name'=>$key,'option_value'=>$value];}return $rows;}
}
final class State {
	public string $loaded_native='A';public string $durable_native='A';
	public Registry $registry;public Store $store;public Resolver $resolver;public QuoteAddress $address;public DeliveryEstimate $estimate;public PoomMapper $mapper;public Cart $cart;public Customer $customer;public NativeSession $session;public NativeSession $sibling;public Shipping $shipping;public int $actor=99;public string $token='exact-token';public string $currency='ZAR';public mixed $currency_callback=null;
	public function __construct(){foreach(['registry'=>Registry::class,'store'=>Store::class,'resolver'=>Resolver::class,'address'=>QuoteAddress::class,'estimate'=>DeliveryEstimate::class,'mapper'=>PoomMapper::class,'cart'=>Cart::class,'customer'=>Customer::class,'session'=>NativeSession::class,'shipping'=>Shipping::class] as $key=>$class){$this->$key=new $class();}$this->sibling=new NativeSession(SIBLING_KEY);}
	public function shipping():Shipping{return $this->shipping;}
}
function state():State{return $GLOBALS['confirmation_test_state'];}
function WC():State{return state();}
function get_current_user_id():int{return state()->actor;}
function wp_get_session_token():string{return state()->token;}
function get_woocommerce_currency():string{return state()->currency;}
function get_option(string $key,mixed $default=null):mixed{if('woocommerce_currency'===$key&&state()->currency_callback){$f=state()->currency_callback;state()->currency_callback=null;$f();}return 'woocommerce_currency'===$key?state()->currency:$default;}
function sanitize_textarea_field(string $notes):string{return trim(strip_tags($notes));}
/** The real consent fence runs against this suite's Store/Registry doubles, so their invalidation/expiry/fence counters stay meaningful. */
function load_fence_source():void{
	if(class_exists(__NAMESPACE__.'\\ConsentFence',false)){return;}
	$source=file_get_contents(dirname(__DIR__,2).'/includes/Sessions/ConsentFence.php');
	$source=str_replace('namespace POW\\Sessions;','namespace '.__NAMESPACE__.'; use POW\\Sessions\\Session;',$source);
	$source=str_replace('use POW\\Partners\\{Partner, Registry};','use POW\\Partners\\Partner;',$source);
	eval(substr($source,5));
}
function load_source():void{
	if(class_exists(__NAMESPACE__.'\\Confirmation',false)){return;}
	$path=dirname(__DIR__,2).'/includes/Addresses/Confirmation.php';
	\PHPUnit\Framework\TestCase::assertTrue(is_file($path),'Confirmation model must exist');
	$source=file_get_contents($path);
	$source=str_replace('namespace POW\\Addresses;','namespace POW\\Tests\\DeliveryConfirmation; use POW\\Addresses\\DeliveryData; use POW\\Addresses\\ReturnConfirmation;', $source);
	foreach(['POW\\Partners\\Registry','POW\\Sessions\\Store','POW\\Cart\\PoomMapper','POW\\Cart\\NativeSessionGuard','POW\\Sessions\\ConsentFence'] as $import){$source=str_replace('use '.$import.';','',$source);}
	eval(substr($source,5));
	load_fence_source();
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Tests\DeliveryConfirmation\{Confirmation,State};
use POW\Addresses\DeliveryData;
use POW\Sessions\Session;
use POW\Partners\Partner;

final class DeliveryConfirmationTest extends TestCase {
	private const KEY = \POW\Tests\DeliveryConfirmation\VISIT_KEY;
	private const OTHER_KEY = \POW\Tests\DeliveryConfirmation\SIBLING_KEY;
	private State $s;
	private Confirmation $model;
	private mixed $previous_db;
	protected function setUp():void{
		$this->previous_db=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=new \POW\Tests\DeliveryConfirmation\PolicyDatabase();\POW\Tests\DeliveryConfirmation\load_source();$this->s=new State();$GLOBALS['confirmation_test_state']=$this->s;
		$this->s->registry->partner=Partner::from_row(['id'=>7,'owner_user_id'=>99,'status'=>'active','emit_delivery_line'=>true]);
		$this->s->store->session=Session::from_row(['id'=>42,'partner_id'=>7,'user_id'=>99,'wp_session_token'=>'exact-token','status'=>'active','expires'=>gmdate('Y-m-d H:i:s',time()+3600),'wc_session_key'=>self::KEY]);
		$address=['first_name'=>'Ada','last_name'=>'Buyer','company'=>'Company','address_1'=>'1 Main St','address_2'=>'','city'=>'Pretoria','state'=>'GP','postcode'=>'0001','country'=>'ZA','phone'=>''];
		$this->s->resolver->choices=[['schema'=>1,'partner_id'=>7,'storage_user_id'=>99,'provider'=>'native','key'=>'depot','code'=>'DEPOT','address'=>$address,'label'=>'Depot','source'=>'company_book','book_revision'=>1,'entry_fingerprint'=>str_repeat('a',64)]];
		$this->model=new Confirmation($this->s->registry,$this->s->store,$this->s->address,$this->s->estimate,$this->s->resolver,$this->s->mapper);
	}
	protected function tearDown():void{unset($GLOBALS['confirmation_test_state']);if(null===$this->previous_db){unset($GLOBALS['wpdb']);}else{$GLOBALS['wpdb']=$this->previous_db;}}
	private function preview(array $input=[]):array|WP_Error{return $this->model->preview($this->s->store->session,$this->s->registry->partner,$input);}
	private function input(array $changes=[]):array{$v=$this->preview($changes);self::assertTrue(is_array($v));self::assertNull($v['error']);return $changes+['provider'=>'native','key'=>'depot','notes'=>$v['notes'],'review_digest'=>$v['review_digest']];}
	private function confirm(array $input):array|WP_Error{return $this->model->confirm($this->s->store->session,$this->s->registry->partner,$input);}
	private function returned():array|WP_Error{return $this->model->for_return($this->s->store->session,$this->s->registry->partner);}
	public function test_default_review_does_not_persist_consent():void{$v=$this->preview();self::assertTrue($v['can_confirm']);self::assertSame(0,$this->s->store->writes);self::assertNull($this->s->store->session->delivery_confirmation_json);self::assertSame(2500,$v['total_cents']);}
	public function test_pending_null_consent_cannot_accept_another_requests_durable_cart():void{$input=$this->input();$this->s->estimate->callback=function(){$this->s->durable_native='M quantity 4';};self::assertInstanceOf(WP_Error::class,$this->confirm($input));self::assertSame(0,$this->s->store->writes);self::assertNull($this->s->store->session->delivery_confirmation_json);}
	public function test_stale_callback_failure_does_not_clear_another_requests_consent():void{$accepted=$this->confirm($this->input());$raw=json_encode($accepted['delivery_confirmation']);$input=$this->input();$this->s->estimate->callback=function()use($raw){$this->s->durable_native='M';$this->s->store->change(['delivery_confirmation_json'=>$raw]);throw new RuntimeException('R callback failed after M');};self::assertInstanceOf(WP_Error::class,$this->confirm($input));self::assertSame($raw,$this->s->store->session->delivery_confirmation_json);}
	public function test_final_return_guard_rejects_distinct_durable_rate_with_same_local_cart():void{$this->confirm($this->input());$prepared=$this->returned();self::assertTrue(is_array($prepared));$this->s->durable_native='M rate 6';self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$prepared)));}
	public function test_explicit_confirmation_and_immutable_merchandise_return():void{$v=$this->confirm($this->input());self::assertTrue(is_array($v));$r=$this->returned();self::assertTrue(is_array($r));self::assertCount(1,$r['items']);self::assertSame(2000,$r['merchandise_total_cents']);self::assertSame(2500,$r['total_cents']);self::assertSame('DEPOT',$r['delivery_destination']['code']);self::assertSame(500,$r['delivery_confirmation']['delivery']['amount_cents']);}
	public function test_changed_charge_requires_new_review_digest():void{$i=$this->input();$this->s->estimate->amount=800;self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(0,$this->s->store->writes);}
	public function test_mapper_unit_price_change_invalidates_review_and_return():void{$i=$this->input();$this->s->mapper->price=1200;self::assertInstanceOf(WP_Error::class,$this->confirm($i));$this->confirm($this->input());$this->s->mapper->price=1400;self::assertInstanceOf(WP_Error::class,$this->returned());}
	public function test_only_offered_rates_are_accepted_and_selection_requires_preview():void{$i=$this->input();$i['rates']=[0=>'injected'];self::assertInstanceOf(WP_Error::class,$this->confirm($i));$i=$this->input(['rates'=>[0=>'flat:2']]);self::assertTrue(is_array($this->confirm($i)));self::assertSame(900,$this->returned()['delivery']['amount_cents']);}
	public function test_notes_reject_arrays_invalid_utf8_and_limits_preserve_multiline():void{foreach([[],"\xff",str_repeat('x',2001),str_repeat('😀',2001)] as $notes){$result=$this->confirm(array_replace($this->input(),['notes'=>$notes]));self::assertInstanceOf(WP_Error::class,$result);self::assertSame('delivery_notes_invalid',$result->get_error_code());} $i=$this->input(['notes'=>"<b>Gate</b>\nRing bell"]);self::assertTrue(is_array($this->confirm($i)));self::assertSame("Gate\nRing bell",$this->returned()['delivery_notes']);}
	public function test_unknown_requires_explicit_policy_and_acknowledgement():void{$this->s->estimate->unknown=true;$v=$this->preview();self::assertFalse($v['can_confirm']);$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['delivery_unknown_policy'=>'quote_separately']));$i=$this->input();self::assertInstanceOf(WP_Error::class,$this->confirm($i));$i['acknowledge_unknown']='1';self::assertTrue(is_array($this->confirm($i)));self::assertNull($this->returned()['delivery']['amount_cents']);self::assertSame(2000,$this->returned()['total_cents']);}
	public function test_emission_off_still_requires_physical_destination():void{$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['emit_delivery_line'=>false]));$this->s->resolver->choices=[];$v=$this->preview();self::assertFalse($v['can_confirm']);self::assertInstanceOf(WP_Error::class,$v['error']);}
	public function test_virtual_confirmation_stores_sql_null_choice():void{$this->s->cart->cart_contents['line']['data']->physical=false;$i=$this->input();unset($i['provider'],$i['key']);self::assertTrue(is_array($this->confirm($i)));self::assertNull($this->s->store->session->delivery_choice_json);self::assertNull($this->returned()['delivery_destination']);self::assertSame('not_required',$this->returned()['delivery']['status']);}
	public function test_malformed_recorded_choice_allows_reselection_not_fallback():void{$this->s->store->change(['delivery_choice_json'=>'garbage']);$v=$this->preview();self::assertCount(1,$v['choices']);self::assertFalse($v['can_confirm']);self::assertNull($v['selected_choice']);$v=$this->preview(['provider'=>'native','key'=>'depot']);self::assertNull($v['error']);}
	public function test_selected_entry_edit_requires_explicit_reselection():void{$this->confirm($this->input());$this->s->resolver->choices[0]['entry_fingerprint']=str_repeat('b',64);$this->s->resolver->choices[0]['address']['city']='Johannesburg';self::assertInstanceOf(WP_Error::class,$this->returned());self::assertFalse($this->preview()['can_confirm']);self::assertNull($this->preview(['provider'=>'native','key'=>'depot'])['error']);}
	public function test_unrelated_book_revision_preserves_confirmation():void{$this->confirm($this->input());$this->s->resolver->choices[0]['book_revision']=2;self::assertTrue(is_array($this->returned()));}
	/** Every visit of a connection shares one login, so the proof is the visit's own WP session token and its own cart key. An account id proves nothing. */
	public function test_unbound_login_foreign_token_and_foreign_visit_key_rejected():void{
		foreach(['owner','token','valid','associated','visit_key','unkeyed'] as $case){
			$this->s->token='exact-token';$this->s->store->valid=true;$this->s->resolver->associated=true;$this->s->session->key=self::KEY;$this->s->store->change(['wc_session_key'=>self::KEY]);$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['owner_user_id'=>99]));
			switch($case){
				case 'owner':$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['owner_user_id'=>21]));break;
				case 'token':$this->s->token='other';break;
				case 'valid':$this->s->store->valid=false;break;
				case 'associated':$this->s->resolver->associated=false;break;
				case 'visit_key':$this->s->session->key=self::OTHER_KEY;break;
				case 'unkeyed':$this->s->store->change(['wc_session_key'=>null]);
			}
			$result=$this->preview();
			self::assertInstanceOf(WP_Error::class,$result,$case);
			self::assertSame('delivery_forbidden',$result->get_error_code(),$case);
		}
	}
	public function test_callback_revocation_prevents_save():void{$i=$this->input();$this->s->estimate->callback=function(){$this->s->store->valid=false;};self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(0,$this->s->store->writes);}
	public function test_entry_mutation_during_rates_prevents_save():void{$i=$this->input();$this->s->estimate->callback=function(){$this->s->resolver->choices=[];};self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(0,$this->s->store->writes);}
	public function test_false_after_committed_save_invalidates_candidate():void{$i=$this->input();$this->s->store->fail_save=true;self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(1,$this->s->store->writes);self::assertNull($this->s->store->session->delivery_confirmation_json);self::assertGreaterThan(0,$this->s->store->invalidations);}
	public function test_final_guard_reads_without_map_rate_or_nested_mutex():void{$this->confirm($this->input());$r=$this->returned();self::assertTrue(is_array($r));$this->s->estimate->callback=fn()=>throw new \LogicException('Must not quote');$this->s->mapper->callback=fn()=>throw new \LogicException('Must not map');self::assertTrue($this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));$this->s->cart->cart_contents['line']['quantity']=3;self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));}
	/** There is one exit, so a changed exit_policy must not force a new review; any other policy input still must. */
	public function test_exit_policy_change_no_longer_invalidates_consent_while_raw_policy_still_does():void{
		$this->confirm($this->input());$r=$this->returned();self::assertTrue(is_array($r));
		$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['exit_policy'=>'punchout_and_checkout']));
		self::assertTrue($this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));
		$GLOBALS['wpdb']->values['pow_settings']='changed';
		self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));
	}
	public function test_tax_changes_and_independent_policy_flags_invalidate():void{$this->confirm($this->input());$this->s->estimate->taxes=[1=>'2.00'];self::assertInstanceOf(WP_Error::class,$this->returned());$this->confirm($this->input());$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['emit_ship_to'=>true]));self::assertInstanceOf(WP_Error::class,$this->returned());}
	public function test_preview_after_confirmation_clears_consent():void{$this->confirm($this->input());$this->preview(['notes'=>'Changed instructions']);self::assertNull($this->s->store->session->delivery_confirmation_json);self::assertInstanceOf(WP_Error::class,$this->returned());}
	public function test_posted_null_notes_are_not_an_absent_field():void{self::assertInstanceOf(WP_Error::class,$this->preview(['notes'=>null]));}
	/** Decision 10: the fallback candidate is the bound account's own profile address, shared by every employee of the connection, so it is labelled as the company's and never as this buyer's own. */
	public function test_bound_account_profile_candidate_is_labelled_as_the_company_address():void{
		$this->s->resolver->choices=[];
		$address=['first_name'=>'Ada','last_name'=>'Buyer','company'=>'Company','address_1'=>'1 Main St','address_2'=>'','city'=>'Pretoria','state'=>'GP','postcode'=>'0001','country'=>'ZA','phone'=>''];
		foreach(['customer'=>'Company address','ship_to'=>'Purchasing system destination','filter'=>'Suggested delivery address'] as $source=>$label){
			$this->s->address->candidate=['address'=>$address,'code'=>'','source'=>$source];
			$v=$this->preview();
			self::assertTrue(is_array($v),$source);
			self::assertCount(1,$v['choices'],$source);
			self::assertSame('candidate',$v['choices'][0]['key'],$source);
			self::assertSame($source,$v['choices'][0]['source'],$source);
			self::assertSame($label,$v['choices'][0]['label'],$source);
		}
	}
	public function test_readonly_guard_avoids_metadata_loading_product_get_data():void{$this->confirm($this->input());$r=$this->returned();$this->s->cart->cart_contents['line']['data']->forbid_data=true;self::assertTrue($this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));}
	public function test_final_guard_rejects_in_memory_native_rate_mutation():void{$this->confirm($this->input());$r=$this->returned();$this->s->shipping->packages[0]['rates']['flat:1']->data['amount_cents']=999;self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));}
	public function test_native_packages_cannot_quote_a_different_physical_destination():void{$this->s->estimate->split_destination=true;self::assertInstanceOf(WP_Error::class,$this->preview());}
	public function test_final_guard_binds_extension_cart_data_without_running_object_callbacks():void{$this->s->cart->cart_contents['line']['negotiated_tier']='A';$this->confirm($this->input());$r=$this->returned();$this->s->cart->cart_contents['line']['negotiated_tier']='B';self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));}
	public function test_default_review_preserves_existing_notes_without_preserving_consent():void{$this->confirm($this->input(['notes'=>"Use side gate\nCall on arrival"]));$v=$this->model->prepare($this->s->store->session,$this->s->registry->partner);self::assertSame("Use side gate\nCall on arrival",$v['notes']);self::assertNull($this->s->store->session->delivery_confirmation_json);}
	public function test_buyer_can_type_notes_on_the_shown_review_and_submit_directly():void{$i=$this->input();$i['notes']="<b>Use gate</b>\nRing bell";$result=$this->confirm($i);self::assertTrue(is_array($result));self::assertSame("Use gate\nRing bell",$result['delivery_confirmation']['notes']);self::assertNotSame($i['review_digest'],$result['delivery_confirmation']['cart_fingerprint']);$returned=$this->returned();self::assertTrue(is_array($returned));self::assertSame("Use gate\nRing bell",$returned['delivery_notes']);}
	public function test_typing_notes_does_not_authorize_an_unseen_charge_change():void{$i=$this->input();$i['notes']='Use gate';$this->s->estimate->amount=900;self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(0,$this->s->store->writes);}
	public function test_final_guard_rechecks_cart_and_consent_after_address_validation():void{$this->confirm($this->input());$r=$this->returned();$this->s->resolver->on_validate=function(){$this->s->cart->cart_contents['line']['quantity']=9;};self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));}
	public function test_internal_refresh_signal_covers_callbacks_and_resets_after_failure():void{self::assertTrue(method_exists($this->model,'is_refreshing'),'Expose internal refresh signal');self::assertFalse($this->model->is_refreshing());$seen=false;$this->s->estimate->callback=function()use(&$seen){$seen=$this->model->is_refreshing();throw new \RuntimeException('Carrier failure');};self::assertInstanceOf(WP_Error::class,$this->preview());self::assertTrue($seen);self::assertFalse($this->model->is_refreshing());}
	public function test_unverified_consent_clear_expires_exact_session():void{$i=$this->input();$this->s->store->fail_save=true;$this->s->store->fail_invalidate=true;self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(1,$this->s->store->expirations);self::assertSame(Session::EXPIRED,$this->s->store->session->status);}
	public function test_failed_expiry_fences_partner_when_consent_clear_cannot_be_verified():void{$i=$this->input();$this->s->store->fail_save=true;$this->s->store->fail_invalidate=true;$this->s->store->fail_expire=true;self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(1,$this->s->registry->fences);self::assertFalse($this->s->registry->partner->is_active());}
	public function test_new_offered_rate_still_requires_review_with_typed_notes():void{$i=$this->input();$i['rates']=[0=>'flat:2'];$i['notes']='Use gate';self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(0,$this->s->store->writes);}
	public function test_explicit_updated_destination_still_requires_review_with_typed_notes():void{$i=$this->input();$this->s->resolver->choices[0]['address']['city']='Johannesburg';$this->s->resolver->choices[0]['entry_fingerprint']=str_repeat('b',64);$i['notes']='Use gate';self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame(0,$this->s->store->writes);}
	public function test_notes_accept_exact_unicode_character_and_byte_limits():void{$i=$this->input();$i['notes']=str_repeat('😀',2000);$result=$this->confirm($i);self::assertTrue(is_array($result));self::assertSame($i['notes'],$result['delivery_confirmation']['notes']);}
	/** The currency option read now holds the position the exit-policy read had: before authorization is proved again. */
	public function test_currency_callback_revoking_exact_native_login_refuses_final_guard():void{$this->confirm($this->input());$r=$this->returned();$this->s->currency_callback=function(){$this->s->store->valid=false;};self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));}

	public function test_final_currency_callback_cannot_change_company_after_policy_check():void{
		$this->confirm($this->input());$r=$this->returned();
		$this->s->currency_callback=function(){$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['emit_ship_to'=>true]));};
		self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));
		self::assertTrue($this->s->registry->partner->emit_ship_to);
	}
	public function test_final_policy_callback_cannot_change_native_cart_after_snapshot():void{
		$this->confirm($this->input());$r=$this->returned();
		$GLOBALS['wpdb']->callback=function(){$this->s->cart->cart_contents['line']['quantity']=8;};
		self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));
	}
	public function test_final_policy_callback_cannot_change_company_or_raw_policy_after_comparison():void{
		$this->confirm($this->input());$r=$this->returned();
		$GLOBALS['wpdb']->callback=function(){$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['emit_delivery_code'=>true]));$GLOBALS['wpdb']->values['pow_settings']='changed';};
		self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));
	}
	public function test_final_choice_callback_cannot_change_raw_policy_before_its_comparison():void{
		$this->confirm($this->input());$r=$this->returned();
		$this->s->resolver->on_validate=function(){$GLOBALS['wpdb']->values['pow_settings']='changed';};
		self::assertInstanceOf(WP_Error::class,$this->s->registry->with_partner_lock(7,fn()=>$this->model->validate_prepared_locked($this->s->store->session,$this->s->registry->partner,$r)));
	}

	public function test_failed_clear_preserves_interleaved_paid_winner_using_real_store_recovery():void{
		require_once dirname(__DIR__).'/Support/return-database.php';
		$i=$this->input();$this->s->store->fail_save=true;$this->s->store->fail_invalidate=true;
		$old=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=$db=new ReturnDatabase();$db->session=array_replace($db->session,['wp_session_token'=>'exact-token']);
		$state=$this->s;
		$recovery=new class($state,$db) extends \POW\Sessions\Store {
			public int $destroyed=0;public bool $first=true;
			public function __construct(private State $state,private ReturnDatabase $db){}
			public function find(int $id):?Session{if($this->first){$this->first=false;$this->db->session['status']='ordered';$this->db->session['order_id']=314;$this->state->store->change(['status'=>'ordered','order_id'=>314]);}return parent::find($id);}
			public function destroy_login_checked(Session $s):bool{++$this->destroyed;return true;}
		};
		$db->before_transition=function()use($db,$state){$db->session['status']='ordered';$db->session['order_id']=314;$state->store->change(['status'=>'ordered','order_id'=>314]);};
		$this->s->store->recovery=$recovery;
		try{self::assertInstanceOf(WP_Error::class,$this->confirm($i));self::assertSame('ordered',$db->session['status']);self::assertSame(314,$db->session['order_id']);self::assertSame(0,$recovery->destroyed);self::assertSame(0,$this->s->registry->fences);}finally{if(null===$old){unset($GLOBALS['wpdb']);}else{$GLOBALS['wpdb']=$old;}}
	}

	/** Two employees punch in on one login, so the live cart must be THIS visit's before its consent can be confirmed. */
	public function test_a_sibling_visits_live_cart_cannot_confirm_this_visits_consent():void{
		$input=$this->input();
		$this->s->session->key=self::OTHER_KEY;
		$result=$this->confirm($input);
		self::assertInstanceOf(WP_Error::class,$result);
		self::assertSame('delivery_forbidden',$result->get_error_code());
		self::assertSame(0,$this->s->store->writes);
		self::assertNull($this->s->store->session->delivery_confirmation_json);
	}

	/** The chosen rates are written to the live handler only; the sibling visit keeps its own selection. */
	public function test_a_visits_rate_choice_does_not_reach_a_sibling_visits_cart():void{
		$this->s->sibling->set('chosen_shipping_methods',[0=>'flat:1']);
		$result=$this->confirm($this->input(['rates'=>[0=>'flat:2']]));
		self::assertTrue(is_array($result));
		self::assertSame([0=>'flat:2'],$this->s->session->get('chosen_shipping_methods'));
		self::assertSame([0=>'flat:1'],$this->s->sibling->get('chosen_shipping_methods'));
		self::assertSame(900,$result['delivery_confirmation']['delivery']['amount_cents']);
	}

	/** Pin the stored policy fingerprint's inputs: company fields and raw option state, no exit policy and no per-buyer value. */
	public function test_policy_fingerprint_inputs_exclude_exit_policy_and_still_move():void{
		$policy=$this->preview()['_guard']['policy'];
		$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['exit_policy'=>'punchout_and_checkout']));
		self::assertSame($policy,$this->preview()['_guard']['policy'],'A changed exit policy cannot move a fingerprint it is not part of.');
		$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['emit_ship_to'=>true]));
		self::assertNotSame($policy,$this->preview()['_guard']['policy']);
		$this->s->registry->partner=Partner::from_row(array_replace(get_object_vars($this->s->registry->partner),['emit_ship_to'=>false]));
		self::assertSame($policy,$this->preview()['_guard']['policy']);
		$GLOBALS['wpdb']->values['pow_settings']='changed';
		self::assertNotSame($policy,$this->preview()['_guard']['policy']);
	}

	public function test_resolver_produces_full_fresh_choices_from_its_locked_book():void{
		require_once __DIR__.'/AddressProviderTest.php';\POW\Tests\AddressProvider\load_source();
		$keys=['wpdb','pow_test_users','pow_test_user_meta','pow_test_current_user_id'];$before=[];foreach($keys as $key){$before[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];}
		try{
			// The visit signs in as the connection's own bound account, so the row must name it and be this request's.
			$visit=new Session(...array_replace(get_object_vars($this->s->store->session),['user_id'=>20]));
			$GLOBALS['wpdb']=(object)['last_error'=>''];$GLOBALS['pow_test_current_user_id']=20;$GLOBALS['pow_test_users']=[20=>(object)['ID'=>20,'roles'=>['customer'],'allcaps'=>['read'=>true]],30=>(object)['ID'=>30,'roles'=>['customer'],'allcaps'=>['read'=>true]]];$GLOBALS['pow_test_user_meta']=[];
			$r=new \POW\Tests\AddressProvider\Registry();$r->rows=[7=>['id'=>7,'owner_user_id'=>20,'status'=>'active']];$b=new \POW\Tests\AddressProvider\CompanyBook($r);$b->state=['revision'=>9,'addresses'=>['depot'=>['label'=>'Depot','address'=>$this->s->resolver->choices[0]['address'],'code'=>'DEPOT','use_for_punchout'=>true],'disabled'=>['label'=>'Disabled','address'=>$this->s->resolver->choices[0]['address'],'code'=>'OFF','use_for_punchout'=>false]]];
			$visits=new \POW\Tests\AddressProvider\Current();$visits->live=$visit;
			$resolver=new \POW\Tests\AddressProvider\Resolver($r,$b,$visits);self::assertTrue(method_exists($resolver,'choices_for_session'),'Full choice producer must exist');$choices=$resolver->choices_for_session($visit,$r->find(7));self::assertCount(1,$choices);self::assertSame(9,$choices[0]['book_revision']);self::assertSame(20,$choices[0]['storage_user_id']);self::assertSame(\POW\Tests\AddressProvider\CompanyBook::entry_fingerprint('depot',$b->state['addresses']['depot']),$choices[0]['entry_fingerprint']);self::assertSame(1,$b->reads);
			$r->on_lock=function()use($r){$r->rows[7]['owner_user_id']=30;};self::assertInstanceOf(WP_Error::class,$resolver->choices_for_session($visit,$r->find(7)));
		}finally{foreach($before as $key=>[$exists,$value]){if($exists){$GLOBALS[$key]=$value;}else{unset($GLOBALS[$key]);}}}
	}
}
}
