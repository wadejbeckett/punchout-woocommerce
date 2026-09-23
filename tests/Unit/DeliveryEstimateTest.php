<?php
/** Native shipping boundaries are isolated doubles; stored schema, producer and money logic are real. */
declare( strict_types = 1 );

namespace POW\Tests\DeliveryEstimate {

/** This visit's WooCommerce session key and a sibling visit's: `pow_` + 28 hex, 32 characters. */
const VISIT_KEY = 'pow_1a2b3c4d5e6f708192a3b4c5d6e7';
const SIBLING_KEY = 'pow_00112233445566778899aabbccdd';

final class SessionStore {}
final class Customer {
	public array $address = [];
	/** The bound account's stored profile address, shared by every visit: the session data store never writes here. */
	public array $profile = [ 'shipping_city' => 'Head office' ];
	public bool $calculated = false;
	public bool $fail = false;
	public bool $wrong_store = false;
	public bool $native_order = false;
	public int $id = 99;
	public function get_id(): int { return $this->id; }
	public function get_data_store(): object { return new class($this->wrong_store) { public function __construct(private bool $wrong) {} public function get_current_class_name(): string { return $this->wrong ? 'OtherStore' : SessionStore::class; } }; }
	public function get_shipping( string $context = 'view' ): array { return $this->native_order ? array_reverse($this->address,true) : $this->address; }
	public function __call( string $name, array $args ): void { if (!str_starts_with($name,'set_shipping_')) { throw new \LogicException('Unexpected customer method'); } $this->address[substr($name,13)]=$args[0]; }
	public function set_calculated_shipping( bool $value ): void { $this->calculated=$value; }
	public function has_calculated_shipping(): bool { return $this->calculated; }
	public function save(): int {
		if ($this->fail) { WC()->session->set('customer',['partial'=>'PRIVATE']); throw new \RuntimeException('PRIVATE save failed'); }
		$data=['id'=>(string)$this->id]; foreach($this->address as $key=>$value){$data['shipping_'.$key]=$value;} WC()->session->set('customer',$data); return $this->id;
	}
}
final class NativeSession {
	public array $data = [];
	public array $persisted = [];
	public string $key = VISIT_KEY;
	public function get_customer_id(): string { return $this->key; }
	public function get_session_data(): array { return $this->persisted; }
	public function get( string $key, mixed $default = null ): mixed { return $this->data[$key]??$default; }
	public function set( string $key, mixed $value ): void { $this->data[$key]=$value; }
}
final class Product { public function __construct(private bool $physical=true){} public function needs_shipping(): bool { if(WC()->on_needs_shipping){(WC()->on_needs_shipping)();}return $this->physical; } }
final class Rate {
	public mixed $filtered_cost = null;
	public function __construct(public string $id='flat_rate:1',public mixed $cost='12.50',public array $taxes=[1=>'1.875'],public string $label='Standard',public string $method='flat_rate',public int $instance=1){}
	public function get_id(): string { return $this->id; }
	public function get_cost(): mixed { return $this->filtered_cost ?? $this->cost; }
	public function jsonSerialize(): array { return ['data'=>['id'=>$this->id,'cost'=>$this->cost,'taxes'=>$this->taxes,'label'=>$this->label,'method_id'=>$this->method,'instance_id'=>$this->instance]]; }
	public function get_taxes(): array { return $this->taxes; }
	public function get_label(): string { return $this->label; }
	public function get_method_id(): string { return $this->method; }
	public function get_instance_id(): int { return $this->instance; }
}
final class Shipping {
	public array $packages = [];
	public function get_packages(): array { return $this->packages; }
	public function reset_shipping(): void { $this->packages=[]; WC()->session->set('chosen_shipping_methods',[]); }
}
final class Cart {
	public bool $physical = true;
	public bool $empty = false;
	public bool $enabled = true;
	public bool $fail = false;
	public int $calls = 0;
	public string $cart_context = 'shortcode';
	public array $totals = ['total'=>100];
	public array $offers = [];
	public array $defaults = [];
	public mixed $on_calculate = null;
	public function get_cart(): array { return $this->empty?[]:[['data'=>new Product($this->physical),'quantity'=>1]]; }
	public function get_shipping_packages(): array { return $this->offers; }
	public function needs_shipping(): bool { return $this->physical&&!$this->empty&&$this->enabled; }
	public function get_totals(): array { return $this->totals; }
	public function set_totals( array $totals ): void { $this->totals=$totals; }
	public function calculate_totals(): void {
		++$this->calls;
		if($this->fail){throw new \RuntimeException('PRIVATE rate callback failure');}
		if($this->on_calculate){($this->on_calculate)($this);}
		WC()->shipping()->packages=$this->enabled?$this->offers:[];
		$chosen=WC()->session->get('chosen_shipping_methods',[]);
		foreach(WC()->shipping()->packages as $key=>$package){
			// Model native first recalculation resetting stale method counts, even if an expensive choice remains offered.
			if($this->calls===1||!isset($package['rates'][$chosen[$key]??''])){$chosen[$key]=wc_get_default_shipping_method_for_package($key,$package,(string)($chosen[$key]??''));}
		}
		WC()->session->set('chosen_shipping_methods',$chosen);
		$this->totals=['total'=>123];
	}
}
final class Countries {
	public function country_exists(string $country):bool{return 'ZA'===$country;}
	public function get_shipping_countries():array{return ['ZA'=>'South Africa'];}
	public function get_states(string $country):array{return ['GP'=>'Gauteng'];}
	public function get_address_fields(string $country,string $prefix):array{return ['shipping_address_1'=>['required'=>true],'shipping_city'=>['required'=>true],'shipping_country'=>['required'=>true]];}
}
final class Validation {
	public static function is_postcode(string $value,string $country):bool{return true;}
	public static function is_phone(string $value,string $country):bool{return true;}
}
final class Runtime {
	public Customer $customer;
	public NativeSession $session;
	public Cart $cart;
	public Countries $countries;
	public Shipping $shipping;
	public string $currency='ZAR';
	public int $actor=99;
	public array $default_calls=[];
	public mixed $on_label=null;
	public mixed $on_currency=null;
	public mixed $on_needs_shipping=null;
	public mixed $on_default=null;
	public function __construct(){ $this->customer=new Customer();$this->session=new NativeSession();$this->cart=new Cart();$this->countries=new Countries();$this->shipping=new Shipping(); }
	public function shipping():Shipping{return $this->shipping;}
}
function WC(): Runtime { return $GLOBALS['delivery_estimate_runtime']; }
function get_current_user_id():int{return WC()->actor;}
function get_woocommerce_currency():string{if(WC()->on_currency){(WC()->on_currency)();}return WC()->currency;}
function wc_strtoupper(string $value):string{return strtoupper($value);}
function wc_format_postcode(string $value,string $country):string{return strtoupper($value);}
function wc_get_default_shipping_method_for_package(int|string $key,array $package,string $chosen):string { WC()->default_calls[]=[$key,WC()->cart->cart_context];if(WC()->on_default){(WC()->on_default)();} $default=WC()->cart->defaults[$key]??''; return isset($package['rates'][$default])?$default:''; }
function wc_cart_totals_shipping_method_label(Rate $rate):string { if(WC()->on_label){(WC()->on_label)($rate);}return 'Native display '.$rate->get_label(); }
function load_source(): void {
	foreach(['Shape','DeliveryEstimate'] as $name){
		if(class_exists(__NAMESPACE__.'\\'.$name,false)){continue;}
		$path=dirname(__DIR__,2).'/includes/Addresses/'.$name.'.php';
		\PHPUnit\Framework\TestCase::assertTrue(is_file($path),'DeliveryEstimate producer must exist.');
		$source=str_replace(['namespace POW\\Addresses;','use WC_Validation;','use WC_Shipping_Rate;','use WC_Customer_Data_Store_Session;'],['namespace POW\\Tests\\DeliveryEstimate; use POW\\Addresses\\QuoteAddress; use POW\\Addresses\\DeliveryData;','use POW\\Tests\\DeliveryEstimate\\Validation as WC_Validation;','use POW\\Tests\\DeliveryEstimate\\Rate as WC_Shipping_Rate;','use POW\\Tests\\DeliveryEstimate\\SessionStore as WC_Customer_Data_Store_Session;'],file_get_contents($path));
		eval(substr($source,5)); // Bind native dependencies only; production method bodies are unchanged.
	}
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Tests\DeliveryEstimate\{DeliveryEstimate,Runtime,Rate};
use POW\Addresses\DeliveryData;
use POW\Sessions\Session;
use POW\Partners\Partner;

final class DeliveryEstimateTest extends TestCase {
	private const KEY = \POW\Tests\DeliveryEstimate\VISIT_KEY;
	private const OTHER_KEY = \POW\Tests\DeliveryEstimate\SIBLING_KEY;
	private Runtime $wc;
	private DeliveryEstimate $producer;
	private mixed $saved;
	protected function setUp():void{
		\POW\Tests\DeliveryEstimate\load_source();
		$this->saved=$GLOBALS['delivery_estimate_runtime']??null;
		$this->wc=new Runtime();$GLOBALS['delivery_estimate_runtime']=$this->wc;
		$this->wc->customer->address=$this->address(['city'=>'Old city']);
		$this->wc->cart->offers=[0=>['package_name'=>'Parcel 1','rates'=>['flat_rate:1'=>new Rate(),'flat_rate:2'=>new Rate('flat_rate:2','22.50',[],'Express','flat_rate',2),'local_pickup:3'=>new Rate('local_pickup:3','0',[],'Collect','local_pickup',3)]]];
		$this->wc->cart->defaults=[0=>'flat_rate:1'];
		$this->producer=new DeliveryEstimate(new QuoteOrderTestSettings());
	}
	protected function tearDown():void{if(null===$this->saved){unset($GLOBALS['delivery_estimate_runtime']);}else{$GLOBALS['delivery_estimate_runtime']=$this->saved;}}
	private function address(array $changes=[]):array{return array_replace(['first_name'=>'Ada','last_name'=>'Buyer','company'=>'Example Co','address_1'=>'1 Main Street','address_2'=>'','city'=>'Pretoria','state'=>'GP','postcode'=>'0001','country'=>'ZA','phone'=>''],$changes);}
	private function destination(array $changes=[]):array{return ['address'=>$this->address($changes),'code'=>'DEPOT','source'=>'customer'];}
	private function partner(array $changes=[]):Partner{return Partner::from_row(array_replace(['id'=>7,'owner_user_id'=>20,'status'=>'active','emit_delivery_line'=>true],$changes));}
	private function session(array $changes=[]):Session{return Session::from_row(array_replace(['id'=>42,'partner_id'=>7,'user_id'=>99,'status'=>Session::ACTIVE,'wc_session_key'=>self::KEY],$changes));}
	private function quote(?Partner $partner=null,?array $destination=null):array{return $this->producer->quote($this->session(),$partner??$this->partner(),$destination??$this->destination());}
	private function confirmed(array $delivery,?array $destination=null):Session {
		$choice=null;
		if('not_required'!==$delivery['status']){$choice=['schema'=>1,'partner_id'=>7,'storage_user_id'=>20,'provider'=>'customer','key'=>'candidate','label'=>'Buyer destination','book_revision'=>null,'entry_fingerprint'=>null]+($destination??$this->destination());}
		$confirmation=['schema'=>1,'session_id'=>42,'buyer_user_id'=>99,'choice_hash'=>DeliveryData::fingerprint($choice),'cart_fingerprint'=>str_repeat('a',64),'policy_fingerprint'=>str_repeat('b',64),'delivery'=>$delivery,'notes'=>'','confirmed_at'=>1];
		return $this->session(['delivery_choice'=>$choice?json_encode($choice):null,'delivery_confirmation'=>json_encode($confirmation)]);
	}
	private function refuse(callable $call):void{try{$call();self::fail('Unconfirmed or invalid delivery was accepted.');}catch(\DomainException $error){self::assertTrue(strlen($error->getMessage())<256);self::assertStringNotContainsString('PRIVATE',$error->getMessage());}}
	public function test_quote_has_exact_storable_delivery_and_separate_native_offers():void{
		$result=$this->quote();$delivery=$result['delivery'];
		self::assertSame(['status','amount_cents','currency','code','emit','rates','freight'],array_keys($delivery));
		self::assertSame('quoted',$delivery['status']);self::assertSame(1250,$delivery['amount_cents']);self::assertTrue($delivery['emit']);
		self::assertSame(['supplier_part_id'=>'DELIVERY','uom'=>'EA','classification_domain'=>'supplier','classification'=>'freight'],$delivery['freight']);
		self::assertSame($delivery,$this->confirmed($delivery)->delivery_confirmation()['delivery']);
		self::assertCount(3,$result['packages'][0]['rates']);self::assertSame('flat_rate:1',$result['packages'][0]['selected_rate_id']);self::assertSame('Native display Standard',$result['packages'][0]['rates'][0]['display_label']);
		self::assertTrue($result['can_confirm']);self::assertFalse($result['requires_unknown_acknowledgement']);
	}
	public function test_valid_expensive_selection_survives_native_recalculation():void{
		$this->wc->session->set('chosen_shipping_methods',[0=>'flat_rate:2']);
		$result=$this->quote();self::assertSame(2250,$result['delivery']['amount_cents']);self::assertSame('flat_rate:2',$result['packages'][0]['selected_rate_id']);self::assertSame([0=>'flat_rate:2'],$this->wc->session->get('chosen_shipping_methods'));self::assertSame(2,$this->wc->cart->calls);
	}
	public function test_native_default_and_blocks_context_are_preserved_without_cheapest_sort():void{
		$this->wc->cart->cart_context='store-api';$this->wc->cart->defaults[0]='flat_rate:2';
		$result=$this->quote();self::assertSame(2250,$result['delivery']['amount_cents']);self::assertSame('store-api',$this->wc->cart->cart_context);self::assertSame(['flat_rate:1','flat_rate:2','local_pickup:3'],array_column($result['packages'][0]['rates'],'rate_id'));
	}
	public function test_selected_pickup_and_native_zero_are_real_quoted_rates():void{
		$this->wc->session->set('chosen_shipping_methods',[0=>'local_pickup:3']);$delivery=$this->quote()['delivery'];
		self::assertSame('quoted',$delivery['status']);self::assertSame(0,$delivery['amount_cents']);self::assertSame('local_pickup',$delivery['rates'][0]['method_id']);self::assertSame(0,DeliveryEstimate::poom_line($delivery)['unit_price_cents']);
	}
	public function test_multiple_packages_sum_rounded_ex_tax_cents_once():void{
		$this->wc->cart->offers[4]=['package_name'=>'Parcel 2','rates'=>['flat_rate:2'=>new Rate('flat_rate:2','22.50',[],'Express','flat_rate',2)]];$this->wc->cart->defaults[4]='flat_rate:2';
		$delivery=$this->quote()['delivery'];self::assertSame(3500,$delivery['amount_cents']);self::assertSame([0,4],array_column($delivery['rates'],'package_key'));self::assertSame([1=>'1.875'],$delivery['rates'][0]['taxes']);
		$line=DeliveryEstimate::poom_line($delivery);self::assertSame(3500,$line['unit_price_cents']);self::assertSame(1,$line['quantity']);self::assertSame('freight',$line['line_type']);self::assertSame('',$line['aux_id']);
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->cost='2.005';$this->wc->cart->offers[4]['rates']['flat_rate:2']->cost=2.005;
		self::assertSame(402,$this->quote()['delivery']['amount_cents']);
	}
	public function test_freight_description_is_the_selected_rate_label_with_its_estimate():void{
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->label='Courier (3-5 working days)';
		$delivery=$this->quote()['delivery'];
		self::assertSame('Courier (3-5 working days)',$delivery['rates'][0]['label']);
		self::assertSame('Courier (3-5 working days)',DeliveryEstimate::poom_line($delivery)['description']);
	}
	public function test_multi_package_freight_description_joins_labels_in_package_order():void{
		$this->wc->cart->offers[4]=['package_name'=>'Parcel 2','rates'=>['flat_rate:2'=>new Rate('flat_rate:2','22.50',[],'Express (next day)','flat_rate',2)]];$this->wc->cart->defaults[4]='flat_rate:2';
		$delivery=$this->quote()['delivery'];
		self::assertSame('Standard; Express (next day)',DeliveryEstimate::poom_line($delivery)['description']);
	}
	public function test_freight_description_is_plain_text_and_falls_back_to_delivery():void{
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->label='<b>Road</b> &amp; rail (2&ndash;4 days)';
		$delivery=$this->quote()['delivery'];
		self::assertSame('Road & rail (2–4 days)',DeliveryEstimate::poom_line($delivery)['description']);
		// A label that strips to nothing leaves the generic description.
		$delivery['rates'][0]['label']='<i> </i>';
		self::assertSame('',DeliveryData::method_label($delivery));
		self::assertSame('Delivery',DeliveryEstimate::poom_line($delivery)['description']);
	}
	public function test_missing_one_package_is_unknown_not_partial_sum_or_zero():void{
		$this->wc->cart->offers[4]=['package_name'=>'Unserved','rates'=>[]];$result=$this->quote();
		self::assertSame('unknown',$result['delivery']['status']);self::assertNull($result['delivery']['amount_cents']);self::assertFalse($result['delivery']['emit']);self::assertFalse($result['can_confirm']);self::assertNull(DeliveryEstimate::poom_line($result['delivery']));self::assertCount(2,$result['packages']);
	}
	public function test_quote_separately_exposes_required_ack_outside_snapshot():void{
		$this->wc->cart->offers[0]['rates']=[];$partner=$this->partner(['delivery_unknown_policy'=>'quote_separately']);$result=$this->quote($partner);
		self::assertTrue($result['can_confirm']);self::assertTrue($result['requires_unknown_acknowledgement']);self::assertNull($result['delivery']['amount_cents']);self::assertSame($result['delivery'],$this->confirmed($result['delivery'])->delivery_confirmation()['delivery']);
		self::assertSame($result['delivery'],$this->producer->for_return($this->confirmed($result['delivery']),$partner,$this->destination()));
	}
	public function test_emission_disabled_keeps_estimate_and_native_choices_locally():void{
		$result=$this->quote($this->partner(['emit_delivery_line'=>false]));
		self::assertSame('disabled',$result['delivery']['status']);self::assertSame(1250,$result['delivery']['amount_cents']);self::assertFalse($result['delivery']['emit']);self::assertTrue($result['can_confirm']);self::assertNull(DeliveryEstimate::poom_line($result['delivery']));
	}
	public function test_virtual_and_empty_carts_need_no_address_or_shipping_mutation():void{
		$this->wc->cart->physical=false;$before=$this->wc->customer->address;
		foreach([false,true] as $empty){$this->wc->cart->empty=$empty;$result=$this->producer->quote($this->session(),$this->partner(),null);self::assertSame('not_required',$result['delivery']['status']);self::assertNull($result['delivery']['amount_cents']);self::assertSame([],$result['delivery']['rates']);self::assertSame($result['delivery'],$this->confirmed($result['delivery'])->delivery_confirmation()['delivery']);}
		self::assertSame($before,$this->wc->customer->address);self::assertSame(0,$this->wc->cart->calls);
	}
	public function test_physical_cart_with_shipping_disabled_is_not_virtual():void{
		$this->wc->cart->enabled=false;$result=$this->quote();self::assertSame('unknown',$result['delivery']['status']);self::assertNull($result['delivery']['amount_cents']);self::assertFalse($result['can_confirm']);
	}
	public function test_destination_is_saved_to_acting_buyer_session_and_empty_fields_clear():void{
		$this->wc->customer->address['phone']='Old phone';$this->quote();
		self::assertSame($this->address(),$this->wc->customer->get_shipping('edit'));self::assertSame('',$this->wc->session->get('customer')['shipping_phone']);self::assertTrue($this->wc->customer->calculated);
	}
	public function test_native_shipping_field_order_does_not_make_identical_values_a_failure():void{
		$this->wc->customer->native_order=true;self::assertSame(1250,$this->quote()['delivery']['amount_cents']);
	}
	public function test_old_native_package_cache_is_invalidated_before_requoting():void{
		$this->wc->session->set('shipping_for_package_0',['stale'=>'rate']);
		$this->wc->cart->on_calculate=function(){if($this->wc->session->get('shipping_for_package_0')){throw new \RuntimeException('Stale native package cache');}};
		self::assertSame(1250,$this->quote()['delivery']['amount_cents']);
	}
	public function test_missing_invalid_or_rewritten_destination_refuses_before_rate_callbacks():void{
		$this->refuse(fn()=>$this->producer->quote($this->session(),$this->partner(),null));
		$this->refuse(fn()=>$this->quote(null,$this->destination(['country'=>'XX'])));
		$this->refuse(fn()=>$this->quote(null,$this->destination(['city'=>' <b>Pretoria</b> '])));
		self::assertSame(0,$this->wc->cart->calls);
	}
	public function test_wrong_actor_customer_visit_key_or_non_session_datastore_cannot_write():void{
		$before=$this->wc->customer->address;
		$this->wc->actor=20;$this->refuse(fn()=>$this->quote());$this->wc->actor=99;
		$this->wc->customer->id=20;$this->refuse(fn()=>$this->quote());$this->wc->customer->id=99;
		// Same bound account, another visit's cart: the key is the only thing that can tell them apart.
		$this->wc->session->key=self::OTHER_KEY;$this->refuse(fn()=>$this->quote());$this->wc->session->key=self::KEY;
		$this->refuse(fn()=>$this->producer->quote($this->session(['wc_session_key'=>null]),$this->partner(),$this->destination()));
		$this->wc->customer->wrong_store=true;$this->refuse(fn()=>$this->quote());self::assertSame($before,$this->wc->customer->address);self::assertSame(0,$this->wc->cart->calls);
	}
	/** One bound login, two visits: each quote writes its destination and rate snapshot into the handler it is holding, and neither writes through the account's shared profile. */
	public function test_destination_and_rates_are_written_to_this_visits_row_only():void{
		$first=$this->wc->session;
		self::assertSame(1250,$this->quote()['delivery']['amount_cents']);
		self::assertSame('Pretoria',$first->get('customer')['shipping_city']);
		self::assertSame([0=>'flat_rate:1'],$first->get('chosen_shipping_methods'));
		// The same account punches in a second time: its own handler, its own key, its own destination.
		$second=new \POW\Tests\DeliveryEstimate\NativeSession();
		$second->key=self::OTHER_KEY;
		$this->wc->session=$second;
		$this->wc->cart->defaults=[0=>'flat_rate:2'];
		$quote=$this->producer->quote($this->session(['id'=>43,'wc_session_key'=>self::OTHER_KEY]),$this->partner(),$this->destination(['city'=>'Durban']));
		self::assertSame(2250,$quote['delivery']['amount_cents']);
		self::assertSame('Durban',$second->get('customer')['shipping_city']);
		self::assertSame([0=>'flat_rate:2'],$second->get('chosen_shipping_methods'));
		self::assertSame('Pretoria',$first->get('customer')['shipping_city'],'The other visit keeps its own destination.');
		self::assertSame([0=>'flat_rate:1'],$first->get('chosen_shipping_methods'),'The other visit keeps its own chosen rate.');
		// WC_Customer::save() goes through the session data store the producer insists on, so the shared billing_*/shipping_* profile is untouched.
		self::assertSame(['shipping_city'=>'Head office'],$this->wc->customer->profile);
	}
	public function test_partial_customer_or_rate_failure_restores_prior_local_state_and_refuses():void{
		$this->wc->session->set('customer',['old'=>'value']);$this->wc->session->set('chosen_shipping_methods',[0=>'flat_rate:2']);$before=$this->wc->customer->address;$totals=$this->wc->cart->totals;
		$this->wc->customer->fail=true;$this->refuse(fn()=>$this->quote());self::assertSame($before,$this->wc->customer->address);self::assertSame(['old'=>'value'],$this->wc->session->get('customer'));
		$this->wc->customer->fail=false;$this->wc->cart->fail=true;$this->refuse(fn()=>$this->quote());self::assertSame($before,$this->wc->customer->address);self::assertSame($totals,$this->wc->cart->totals);self::assertSame([0=>'flat_rate:2'],$this->wc->session->get('chosen_shipping_methods'));
	}
	public function test_invalid_native_money_taxes_ids_or_rate_objects_refuse():void{
		foreach([-1,INF,NAN,'PRIVATE invalid','1e1000',str_repeat('9',80)] as $bad){$this->wc->cart->offers[0]['rates']['flat_rate:1']->cost=$bad;$this->refuse(fn()=>$this->quote());}
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->cost='12.50';$this->wc->cart->offers[0]['rates']['flat_rate:1']->taxes=[1=>-1];$this->refuse(fn()=>$this->quote());
		$this->wc->cart->offers[0]['rates']['flat_rate:1']=new Rate('wrong-id');$this->refuse(fn()=>$this->quote());
		$this->wc->cart->offers[0]['rates']['flat_rate:1']=(object)[];$this->refuse(fn()=>$this->quote());
	}
	public function test_currency_change_during_native_callbacks_refuses():void{
		$this->wc->cart->on_calculate=function(){$this->wc->currency='USD';};$this->refuse(fn()=>$this->quote());
	}
	public function test_late_display_callbacks_cannot_return_stale_native_offers():void{
		$cases = [
			'cost'=>function(){$this->wc->shipping->packages[0]['rates']['flat_rate:1']->cost='99.00';},
			'selection'=>function(){$this->wc->session->set('chosen_shipping_methods',[0=>'flat_rate:2']);},
			'destination'=>function(){$this->wc->customer->address['city']='Changed city';},
			'session_customer'=>function(){$this->wc->session->set('customer',['id'=>'20']);},
			'customer_replacement'=>function(){$this->wc->customer=clone $this->wc->customer;},
			'actor'=>function(){$this->wc->actor=20;},
			'visit_key'=>function(){$this->wc->session->key=self::OTHER_KEY;},
			'currency'=>function(){$this->wc->currency='USD';},
			'package'=>function(){$this->wc->shipping->packages[0]['destination']=['city'=>'Changed city'];},
			'replacement'=>function(){$this->wc->shipping->packages[0]['rates']['flat_rate:1']=clone $this->wc->shipping->packages[0]['rates']['flat_rate:1'];},
		];
		foreach($cases as $change){
			$this->wc->actor=99;$this->wc->currency='ZAR';$this->wc->session->key=self::KEY;$this->wc->cart->offers[0]['rates']['flat_rate:1']->cost='12.50';
			$this->wc->on_label=function()use($change){$this->wc->on_label=null;$change();};
			$this->refuse(fn()=>$this->quote());
		}
	}
	public function test_final_currency_callback_cannot_mutate_native_offer_while_returning_same_currency():void{
		$this->wc->on_label=function(){
			$this->wc->on_label=null;
			$this->wc->on_currency=function(){$this->wc->on_currency=null;$this->wc->shipping->packages[0]['rates']['flat_rate:1']->cost='99.00';};
		};
		$this->refuse(fn()=>$this->quote());
	}
	public function test_pre_label_currency_callback_cannot_replace_cached_rates_before_baseline():void{
		$accepted=$this->confirmed($this->quote()['delivery']);$reads=0;
		$this->wc->on_currency=function()use(&$reads){if(++$reads===2){$this->wc->shipping->packages[0]['rates']['flat_rate:1']=new Rate(cost:'99.00');}};
		$this->refuse(fn()=>$this->producer->for_return($accepted,$this->partner(),$this->destination()));
	}
	public function test_default_selection_callback_cannot_rewrite_an_already_calculated_offer():void{
		$reads=0;
		$this->wc->on_default=function()use(&$reads){if(++$reads===2){$this->wc->shipping->packages[0]['rates']['flat_rate:1']->cost='99.00';}};
		$this->refuse(fn()=>$this->quote());
	}
	public function test_stable_filtered_cost_need_not_equal_its_raw_native_value():void{
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->filtered_cost='10.00';
		self::assertSame(1000,$this->quote()['delivery']['amount_cents']);
		self::assertSame('12.50',$this->wc->shipping->packages[0]['rates']['flat_rate:1']->cost);
	}
	public function test_virtual_classification_callback_cannot_replace_the_acting_buyer():void{
		$this->wc->cart->physical=false;$before=$this->wc->customer->address;
		$this->wc->on_needs_shipping=function(){$this->wc->actor=20;};
		$this->refuse(fn()=>$this->producer->quote($this->session(),$this->partner(),null));
		self::assertSame(0,$this->wc->cart->calls);self::assertSame($before,$this->wc->customer->address);
	}
	public function test_foreign_package_currency_and_overflowing_package_sum_refuse():void{
		$this->wc->cart->offers[0]['currency']='USD';$this->refuse(fn()=>$this->quote());unset($this->wc->cart->offers[0]['currency']);
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->cost='50000000000000000';$this->wc->cart->offers[4]=$this->wc->cart->offers[0];$this->wc->cart->defaults[4]='flat_rate:1';$this->refuse(fn()=>$this->quote());
	}
	public function test_return_requires_stored_confirmation_and_exact_destination():void{
		$this->refuse(fn()=>$this->producer->for_return($this->session(),$this->partner(),$this->destination()));
		$delivery=$this->quote()['delivery'];$session=$this->confirmed($delivery);
		self::assertSame($delivery,$this->producer->for_return($session,$this->partner(),$this->destination()));
		$this->refuse(fn()=>$this->producer->for_return($session,$this->partner(),$this->destination(['city'=>'Changed'])));
	}
	public function test_return_refuses_changed_rate_amount_package_tax_currency_or_emission_policy():void{
		$delivery=$this->quote()['delivery'];$session=$this->confirmed($delivery);
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->cost='13.00';$this->refuse(fn()=>$this->producer->for_return($session,$this->partner(),$this->destination()));$this->wc->cart->offers[0]['rates']['flat_rate:1']->cost='12.50';
		$this->wc->session->set('chosen_shipping_methods',[0=>'flat_rate:2']);$this->refuse(fn()=>$this->producer->for_return($session,$this->partner(),$this->destination()));$this->wc->session->set('chosen_shipping_methods',[0=>'flat_rate:1']);
		$this->wc->cart->offers[4]=$this->wc->cart->offers[0];$this->wc->cart->defaults[4]='flat_rate:1';$this->refuse(fn()=>$this->producer->for_return($session,$this->partner(),$this->destination()));unset($this->wc->cart->offers[4]);
		$this->wc->cart->offers[0]['rates']['flat_rate:1']->taxes=[1=>2];$this->refuse(fn()=>$this->producer->for_return($session,$this->partner(),$this->destination()));$this->wc->cart->offers[0]['rates']['flat_rate:1']->taxes=[1=>'1.875'];
		$this->wc->currency='USD';$this->refuse(fn()=>$this->producer->for_return($session,$this->partner(),$this->destination()));$this->wc->currency='ZAR';
		$this->refuse(fn()=>$this->producer->for_return($session,$this->partner(['emit_delivery_line'=>false]),$this->destination()));
	}
	public function test_unknown_return_under_require_rate_refuses_even_if_stored_as_confirmed():void{
		$this->wc->cart->offers[0]['rates']=[];$delivery=$this->quote()['delivery'];$this->refuse(fn()=>$this->producer->for_return($this->confirmed($delivery),$this->partner(),$this->destination()));
	}
	public function test_freight_configuration_is_frozen_and_changed_configuration_requires_reconfirmation():void{
		$partner=$this->partner(['freight_supplier_part_id'=>'CARRIAGE','freight_uom'=>'EA','freight_classification_domain'=>'UNSPSC','freight_classification'=>'78101800']);$delivery=$this->quote($partner)['delivery'];$line=DeliveryEstimate::poom_line($delivery);
		self::assertSame('CARRIAGE',$line['supplier_part_id']);self::assertSame('78101800',$line['classification']);self::assertSame('UNSPSC',$line['classification_domain']);$this->refuse(fn()=>$this->producer->for_return($this->confirmed($delivery),$this->partner(),$this->destination()));
	}
	public function test_typed_line_refuses_inconsistent_emitted_totals_instead_of_fabricating_a_charge():void{
		$delivery=$this->quote()['delivery'];$delivery['amount_cents']=999;$this->refuse(fn()=>DeliveryEstimate::poom_line($delivery));
		$delivery=$this->quote()['delivery'];$delivery['emit']=true;$delivery['status']='unknown';$delivery['amount_cents']=null;$this->refuse(fn()=>DeliveryEstimate::poom_line($delivery));
	}
	public function test_existing_confirmation_decoder_retains_multiline_notes_with_crlf_and_tab():void{
		$session=$this->confirmed($this->quote()['delivery']);
		$data=json_decode($session->delivery_confirmation_json,true,16,JSON_THROW_ON_ERROR);
		$data['notes']="First line\r\n\tSecond line\nThird line";
		$with_notes=$this->session(['delivery_choice'=>$session->delivery_choice_json,'delivery_confirmation'=>json_encode($data,JSON_THROW_ON_ERROR)]);
		self::assertSame("First line\r\n\tSecond line\nThird line",$with_notes->delivery_confirmation()['notes']);
	}
}
}
