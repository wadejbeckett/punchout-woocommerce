<?php
/** Real return endpoint, mapper, builder, quote service and Store against recorded WP/Woo/DB boundaries.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Tests\ReturnShipping {
/** Use the existing shipping-capable order fixture; production Quote/endpoint method bodies remain unchanged. */
function load_source():void {
	if(class_exists(__NAMESPACE__.'\\ReturnEndpoint',false)){return;}
	require_once dirname(__DIR__).'/Unit/QuoteOrderCreateTest.php';
	$root=dirname(__DIR__,2).'/includes/';
	$source=str_replace(['namespace POW\\Orders;','use WC_Order_Item_Shipping;'],['namespace POW\\Tests\\ReturnShipping; use POW\\Orders\\Status;','use POW\\Tests\\QuoteShipping\\ShippingItem as WC_Order_Item_Shipping;'],file_get_contents($root.'Orders/QuoteOrder.php'));
	eval(substr($source,5));
	$source=str_replace(['namespace POW\\Http;','use POW\\Orders\\QuoteOrder;'],['namespace POW\\Tests\\ReturnShipping;',''],file_get_contents($root.'Http/ReturnEndpoint.php'));
	eval(substr($source,5));
}
function header(string $header,bool $replace=true,int $response_code=0):void{\POW\Http\header($header,$replace,$response_code);}
function sanitize_textarea_field(string $value):string{return \POW\Tests\QuoteShipping\sanitize_textarea_field($value);}
function wp_slash(string $value):string{return \POW\Tests\QuoteShipping\wp_slash($value);}
function add_filter(string $hook,callable $callback,int $priority=10,int $args=1):void{\POW\Tests\QuoteShipping\add_filter($hook,$callback,$priority,$args);}
function remove_filter(string $hook,callable $callback,int $priority=10):void{\POW\Tests\QuoteShipping\remove_filter($hook,$callback,$priority);}
function wc_create_order(array $args=[]):\WC_Order|\WP_Error {
	if(isset($GLOBALS['pow_test_create_order_error'])){return new \WP_Error('db_error',(string)$GLOBALS['pow_test_create_order_error']);}
	return \POW\Tests\QuoteShipping\wc_create_order($args);
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Audit\Log;
use POW\Cart\PoomMapper;
use POW\Cxml\Builder;
use POW\Tests\ReturnShipping\ReturnEndpoint;
use POW\Orders\QuoteOrder;
use POW\Partners\Registry;
use POW\Partners\Secrets;
use POW\Sessions\Store;
require_once dirname( __DIR__ ) . '/Support/return-database.php';

/** Explicit interface seam exercises the real mapper outside the mutex, including the nested contender callback. */
final class ReturnConfirmationFixture implements \POW\Addresses\ReturnConfirmation {
	public ?WP_Error $error = null;
	public bool|WP_Error $valid = true;
	public mixed $on_validate = null;
	public int $prepared_calls = 0;
	public int $validated_calls = 0;
	public function __construct(private PoomMapper $mapper){}
	public function for_return(\POW\Sessions\Session $session,\POW\Partners\Partner $partner):array|WP_Error{
		++$this->prepared_calls;
		if($this->error){return $this->error;}
		$confirmation=$session->delivery_confirmation();
		if(null===$confirmation){return new WP_Error('delivery_review_required','Review delivery before returning.');}
		$mapped=$this->mapper->from_cart($partner);$choice=$session->delivery_choice();$delivery=$confirmation['delivery'];
		$mapped['merchandise_total_cents']=$mapped['total_cents'];$mapped['total_cents']+=$delivery['emit']?$delivery['amount_cents']:0;
		return $mapped+['delivery_destination'=>\POW\Addresses\QuoteAddress::payload($choice),'delivery_choice'=>$choice,'delivery_confirmation'=>$confirmation,'delivery'=>$delivery,'delivery_notes'=>$confirmation['notes'],'_guard'=>['cart'=>'fixture-cart','policy'=>'fixture-policy','choice_json'=>$session->delivery_choice_json,'confirmation_json'=>$session->delivery_confirmation_json]];
	}
	public function validate_prepared_locked(\POW\Sessions\Session $session,\POW\Partners\Partner $partner,array $prepared):bool|WP_Error{
		++$this->validated_calls;
		if($prepared['_guard']['choice_json']!==$session->delivery_choice_json||$prepared['_guard']['confirmation_json']!==$session->delivery_confirmation_json){return new WP_Error('changed','Delivery changed.');}
		if($this->on_validate){($this->on_validate)();}
		return $this->valid;
	}
}

final class ReturnIntegrationTest extends TestCase {
	private ReturnDatabase $db;
	private ReturnEndpoint $endpoint;
	private ReturnConfirmationFixture $delivery;
	private array $saved = [];
	private array $post;
	private array $server;
	protected function setUp(): void {
		if ( ! extension_loaded( 'dom' ) ) { self::markTestSkipped( 'ext-dom required for handoff XML' ); }
		\POW\Tests\ReturnShipping\load_source();
		\POW\Tests\QuoteShipping\Boundary::$failure = '';
		foreach ( array_keys( $GLOBALS ) as $key ) {
			if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { $this->saved[$key] = $GLOBALS[$key]; unset( $GLOBALS[$key] ); }
		}
		$this->post = $_POST; $this->server = $_SERVER;
		$GLOBALS['wpdb'] = $this->db = new ReturnDatabase();
		$this->db->session['expires'] = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$GLOBALS['pow_test_session_tokens'][99]['test-login'] = [ 'expiration' => time() + 3600 ];
		$GLOBALS['pow_test_current_user_id'] = 99;
		$GLOBALS['pow_test_roles'] = [ POW\Installer::ROLE ];
		$GLOBALS['pow_test_valid_nonce'] = 'valid';
		$GLOBALS['pow_test_login_token'] = 'test-login';
		$GLOBALS['pow_test_orders'] = [];
		$GLOBALS['pow_test_mail'] = [];
		$GLOBALS['pow_test_setup_io'] = [ 'headers' => [] ];
		$GLOBALS['pow_test_wc'] = new POW_Test_WC();
		$GLOBALS['pow_test_wc']->cart = new class {
			public function get_cart(): array { return []; }
			public function empty_cart( bool $persistent ): void { $GLOBALS['pow_test_cart_emptied'][] = true; }
		};
		$GLOBALS['pow_test_filters']['pow_poom_lines'] = static fn( $value ) => [ 'currency' => 'ZAR', 'total_cents' => 37533, 'skipped' => [], 'items' => [ [ 'quantity' => 3, 'supplier_part_id' => 'SKU-1001', 'aux_id' => '412|0', 'unit_price_cents' => 12511, 'description' => 'Example item', 'uom' => 'EA', 'classification' => '' ] ] ];
		if ( ! defined( 'POW\VERSION' ) ) { define( 'POW\VERSION', 'test' ); }
		$settings = new QuoteOrderTestSettings(); $logger = new QuoteOrderTestLogger();
		$this->make_endpoint();
		$this->set_delivery();
		$_POST = [ 'pow_nonce' => 'valid' ]; $_SERVER['REQUEST_METHOD'] = 'POST';
	}
	private function make_endpoint(bool $with_confirmation=true):void{
		$settings=new QuoteOrderTestSettings();$logger=new QuoteOrderTestLogger();$store=new Store();$audit=new Log($logger);$mapper=new PoomMapper($settings,$logger);$this->delivery=new ReturnConfirmationFixture($mapper);
		$this->endpoint=new ReturnEndpoint($store,new Registry(new Secrets(str_repeat('x',32))),$mapper,new Builder(),$audit,new \POW\Tests\ReturnShipping\QuoteOrder($store,$audit,$settings,$logger),$with_confirmation?$this->delivery:null);
	}
	private function set_delivery(bool $physical=false,bool $emit=false):void{
		$choice=$physical?['schema'=>1,'partner_id'=>7,'storage_user_id'=>20,'provider'=>'customer','key'=>'candidate','code'=>'DEPOT','address'=>['first_name'=>'Ada','last_name'=>'Buyer','company'=>'Example & Co','address_1'=>'1 Main Road','address_2'=>'','city'=>'Pretoria','state'=>'GP','postcode'=>'0001','country'=>'ZA','phone'=>''],'label'=>'Destination','source'=>'customer','book_revision'=>null,'entry_fingerprint'=>null]:null;
		$delivery=['status'=>$physical?($emit?'quoted':'disabled'):'not_required','amount_cents'=>$physical?3500:null,'currency'=>'ZAR','code'=>$physical?'DEPOT':'','emit'=>$emit,'rates'=>$physical?[
			['package_key'=>0,'rate_id'=>'flat:1','method_id'=>'flat_rate','instance_id'=>1,'label'=>'Parcel one','amount_cents'=>1250,'taxes'=>[1=>'1.875']],
			['package_key'=>'parcel-b','rate_id'=>'flat:2','method_id'=>'flat_rate','instance_id'=>2,'label'=>'Parcel two','amount_cents'=>2250,'taxes'=>[1=>'3.375']],
		]:[],'freight'=>['supplier_part_id'=>'DELIVERY','uom'=>'EA','classification_domain'=>'supplier','classification'=>'freight']];
		$c=['schema'=>1,'session_id'=>42,'buyer_user_id'=>99,'choice_hash'=>\POW\Addresses\DeliveryData::fingerprint($choice),'cart_fingerprint'=>str_repeat('a',64),'policy_fingerprint'=>str_repeat('b',64),'delivery'=>$delivery,'notes'=>$physical?"Gate & bell\nCall on arrival":'','confirmed_at'=>time()];
		$this->db->session['delivery_choice']=null===$choice?null:json_encode($choice);$this->db->session['delivery_confirmation']=json_encode($c);
	}
	protected function tearDown(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) { if ( str_starts_with( $key, 'pow_test_' ) || 'wpdb' === $key ) { unset( $GLOBALS[$key] ); } }
		foreach ( $this->saved as $key => $value ) { $GLOBALS[$key] = $value; }
		$_POST = $this->post; $_SERVER = $this->server;
	}
	private function response(): string {
		$level = ob_get_level(); ob_start();
		try { $this->endpoint->handle(); return (string) ob_get_contents(); }
		finally { while ( ob_get_level() > $level ) { ob_end_clean(); } }
	}
	public function test_insecure_saved_receiver_refuses_cart_and_empty_before_preparation_or_winner(): void {
		$GLOBALS['pow_test_environment_type'] = 'production'; $_SERVER['HTTPS'] = 'on';
		$this->db->session['browser_form_post_url'] = 'http://buyer.example.test/return';
		foreach ( ['cart', 'empty'] as $mode ) {
			$_POST['pow_mode'] = $mode;
			$response = $this->response();
			self::assertStringContainsString( 'HTTPS', $response );
			self::assertStringNotContainsString( 'pow-handoff-form', $response );
			self::assertSame( 'active', $this->db->session['status'] );
		}
		self::assertSame( 0, $this->delivery->prepared_calls );
		self::assertSame( 0, $this->delivery->validated_calls );
		self::assertSame( [], $GLOBALS['pow_test_orders'] );
		self::assertSame( [], $GLOBALS['pow_test_destroyed_tokens'] ?? [] );
		self::assertSame( 'http://buyer.example.test/return', $this->db->session['browser_form_post_url'] );
	}

	public function test_secure_return_succeeds_in_production_and_receiver_changes_during_preparation_lose(): void {
		$GLOBALS['pow_test_environment_type']='production'; $_SERVER['HTTPS']='on';
		$mapping=$GLOBALS['pow_test_filters']['pow_poom_lines'];
		$GLOBALS['pow_test_filters']['pow_poom_lines']=function($value) use($mapping){
			$this->db->session['browser_form_post_url']='http://buyer.example.test/changed';
			return $mapping($value);
		};
		$response=$this->response();
		self::assertStringNotContainsString('pow-handoff-form',$response);
		self::assertSame('active',$this->db->session['status']); self::assertSame([],$GLOBALS['pow_test_orders']);
		$GLOBALS['pow_test_filters']['pow_poom_lines']=$mapping;
		$this->db->session['browser_form_post_url']='https://buyer.example.test/return';
		self::assertStringContainsString('pow-handoff-form',$this->response());
		self::assertSame('returned',$this->db->session['status']);
	}

	public function test_two_snapshot_contenders_create_only_the_handoff_winners_quote(): void {
		$other = ''; $ran = false;
		// Both read active/0. B completes after A mapped but before A attempts its transition.
		$mapping = $GLOBALS['pow_test_filters']['pow_poom_lines'];
		$GLOBALS['pow_test_filters']['pow_poom_lines'] = function( $value ) use ( &$other, &$ran, $mapping ) {
			$mapped = $mapping( $value );
			if ( ! $ran ) { $ran = true; $other = $this->response(); }
			return $mapped;
		};
		$first = $this->response();
		self::assertCount( 1, $GLOBALS['pow_test_orders'] );
		self::assertStringContainsString( 'expired', $first );
		self::assertStringContainsString( 'pow-handoff-form', $other );
		self::assertSame( 'returned', $this->db->session['status'] );
		$order = array_values( $GLOBALS['pow_test_orders'] )[0];
		preg_match( '/name="cxml-base64" value="([^"]+)"/', $other, $m );
		self::assertSame( base64_decode( html_entity_decode( $m[1] ), true ), $order->get_meta( QuoteOrder::META_POOM_XML ) );
		self::assertSame( 375.33, $order->items[0]['total'] );
		self::assertSame( $order->get_id(), $this->db->session['order_id'] );
		self::assertCount( 1, $GLOBALS['pow_test_destroyed_tokens'] );
	}
	public function test_template_throw_does_not_consume_session_or_create_quote(): void {
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = static function() { throw new RuntimeException( 'template failed' ); };
		try { $this->response(); } catch ( RuntimeException $e ) { self::assertSame( 'template failed', $e->getMessage() ); }
		self::assertSame( 'active', $this->db->session['status'] ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_partner_disabled_after_mapping_cannot_return_a_stale_snapshot(): void {
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = function ( $copy ) { $this->db->partner_status = 'disabled'; return $copy; };
		$this->response();
		self::assertSame( [], $GLOBALS['pow_test_orders'] );
		self::assertSame( 'active', $this->db->session['status'] );
	}
	public function test_missing_template_does_not_consume_session_or_create_quote(): void {
		$GLOBALS['pow_test_filters']['pow_template_handoff'] = static fn() => '/missing-handoff-template.php';
		$this->response();
		self::assertSame( 'active', $this->db->session['status'] ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_builder_throw_does_not_consume_session_or_create_quote(): void {
		$mapping = $GLOBALS['pow_test_filters']['pow_poom_lines'];
		$GLOBALS['pow_test_filters']['pow_poom_lines'] = static function( $value ) use ( $mapping ) { $mapped = $mapping( $value ); $mapped['items'][0]['classification'] = new stdClass(); return $mapped; };
		try { $this->response(); } catch ( TypeError $e ) { /* The real Builder rejects the malformed mapped total. */ }
		self::assertSame( 'active', $this->db->session['status'] ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_checkout_link_after_snapshot_is_preserved(): void {
		$GLOBALS['pow_test_filters']['pow_handoff_copy'] = function( $copy ) { (new Store())->update( 42, [ 'order_id' => 777 ] ); return $copy; };
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		self::assertSame( 777, $this->db->session['order_id'] );
		$order = array_values( $GLOBALS['pow_test_orders'] )[0]; self::assertSame( '42', $order->get_meta( QuoteOrder::META_SESSION_ID ) );
	}
	public function test_failed_transition_creates_no_quote_and_no_handoff(): void {
		$this->db->transition_fails = true;
		self::assertStringContainsString( '/punchout/confirm', $this->response() );
		self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_diagnostic_throw_does_not_cancel_good_quote_or_block_handoff(): void {
		$this->db->audit_error = new RuntimeException( 'private diagnostic detail' );
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		$order = array_values( $GLOBALS['pow_test_orders'] )[0]; self::assertSame( 'punchout-quote', $order->get_status() );
		self::assertStringContainsString( '<cXML', $order->get_meta( QuoteOrder::META_POOM_XML ) );
		self::assertCount( 1, $GLOBALS['pow_test_destroyed_tokens'] );
	}
	public function test_quote_failure_and_all_reporting_failures_still_handoff(): void {
		$GLOBALS['pow_test_create_order_error'] = 'private failure';
		$GLOBALS['pow_test_logger_error'] = new RuntimeException( 'logger failed' );
		$GLOBALS['pow_test_options']['admin_email'] = 'ops@example.test';
		$GLOBALS['pow_test_mail_error'] = new RuntimeException( 'mail failed' );
		$this->db->audit_error = new RuntimeException( 'audit failed' );
		self::assertStringContainsString( 'pow-handoff-form', $this->response() );
		self::assertSame( [], $GLOBALS['pow_test_orders'] ); self::assertSame( 'returned', $this->db->session['status'] );
	}
	public function test_empty_and_paid_closeout_create_no_quote(): void {
		$_POST['pow_mode'] = 'empty';
		foreach ( [ 'active', 'ordered' ] as $status ) {
			$this->db->session['status'] = $status;
			$this->db->session['order_id'] = 'ordered' === $status ? 777 : 0;
			if ( 'ordered' === $status ) { $GLOBALS['pow_test_orders'][777] = new WC_Order(777); }
			$GLOBALS['pow_test_session_tokens'][99]['test-login'] = [ 'expiration' => time() + 3600 ];
			$response = $this->response(); self::assertStringContainsString( 'pow-handoff-form', $response );
			self::assertSame( 'closed', $this->db->session['status'] );
		}
		self::assertCount( 1, $GLOBALS['pow_test_orders'] ); self::assertSame( 'pending', $GLOBALS['pow_test_orders'][777]->get_status() );
	}
	public function test_invalid_nonce_and_login_never_transition_or_create(): void {
		foreach ( [ 'nonce', 'token', 'role' ] as $failure ) {
			$_POST['pow_nonce'] = 'nonce' === $failure ? 'bad' : 'valid';
			$GLOBALS['pow_test_login_token'] = 'token' === $failure ? 'wrong-login' : 'test-login';
			$GLOBALS['pow_test_roles'] = 'role' === $failure ? [] : [ POW\Installer::ROLE ];
			self::assertStringNotContainsString( 'pow-handoff-form', $this->response() );
		}
		self::assertSame( [], $this->db->queries ); self::assertSame( [], $GLOBALS['pow_test_orders'] );
	}
	public function test_missing_confirmation_service_refuses_cart_with_review_link():void{$this->make_endpoint(false);$response=$this->response();self::assertStringContainsString('/punchout/confirm',$response);self::assertStringNotContainsString('pow-handoff-form',$response);self::assertSame('active',$this->db->session['status']);self::assertSame([],$GLOBALS['pow_test_orders']);}
	public function test_missing_stored_confirmation_refuses_before_handoff():void{$this->db->session['delivery_confirmation']=null;$response=$this->response();self::assertStringContainsString('/punchout/confirm',$response);self::assertStringNotContainsString('pow-handoff-form',$response);self::assertSame([],$this->db->queries);}
	public function test_confirmation_error_is_escaped_and_keeps_session_active():void{$this->delivery->error=new WP_Error('review','Changed <script>alert(1)</script>');$response=$this->response();self::assertStringContainsString('&lt;script&gt;',$response);self::assertStringNotContainsString('<script>alert(1)</script>',$response);self::assertStringContainsString('/punchout/confirm',$response);self::assertSame('active',$this->db->session['status']);}
	public function test_failed_final_delivery_guard_never_claims_or_handoffs():void{$this->delivery->valid=new WP_Error('changed','Review the changed delivery.');$response=$this->response();self::assertStringContainsString('/punchout/confirm',$response);self::assertStringNotContainsString('pow-handoff-form',$response);self::assertSame('active',$this->db->session['status']);self::assertSame(1,$this->delivery->validated_calls);self::assertSame([],$GLOBALS['pow_test_orders']);}
	public function test_changed_raw_confirmation_after_validation_loses_atomic_transition():void{$this->delivery->on_validate=function(){$this->db->before_transition=function(){$this->db->session['delivery_confirmation']=null;};};$response=$this->response();self::assertSame(1,$this->db->guarded_updates);self::assertSame('active',$this->db->session['status']);self::assertStringNotContainsString('pow-handoff-form',$response);self::assertSame([],$GLOBALS['pow_test_orders']);}
	private function xml(string $response):DOMXPath{self::assertSame(1,preg_match('/name="cxml-base64" value="([^"]+)"/',$response,$match));$doc=new DOMDocument();self::assertTrue($doc->loadXML(base64_decode(html_entity_decode($match[1]),true),LIBXML_NONET));return new DOMXPath($doc);}
	public function test_one_wire_freight_matches_two_native_shipping_items_without_double_charge():void{$this->set_delivery(true,true);$this->db->partner_fields=['emit_delivery_line'=>true,'emit_ship_to'=>true,'emit_delivery_code'=>true,'delivery_code_extrinsic_name'=>'DestinationCode','delivery_notes_policy'=>'item_detail_extrinsic','cxml_version'=>'1.2.071'];$response=$this->response();$xml=$this->xml($response);self::assertSame(2,$xml->query('//ItemIn')->length);self::assertSame('35.00',$xml->evaluate('string(//ItemIn[ItemID/SupplierPartID="DELIVERY"]/ItemDetail/UnitPrice/Money)'));self::assertSame('410.33',$xml->evaluate('string(//PunchOutOrderMessageHeader/Total/Money)'));self::assertSame(1,$xml->query('//ShipTo')->length);self::assertSame(1,$xml->query('//ItemDetail/Extrinsic[@name="DeliveryInstructions"]')->length);self::assertSame(2,$xml->query('//ItemIn/Extrinsic[@name="DestinationCode"]')->length);$order=array_values($GLOBALS['pow_test_orders'])[0];self::assertCount(1,$order->items);self::assertCount(2,$order->get_items('shipping'));self::assertSame('410.33',$order->get_total());self::assertSame('35.00',$order->get_shipping_total());self::assertSame('Pretoria',$order->props['shipping_city']);}
	public function test_builder_uses_fresh_company_options_after_confirmation_preparation():void{$this->set_delivery(true,true);$mapping=$GLOBALS['pow_test_filters']['pow_poom_lines'];$GLOBALS['pow_test_filters']['pow_poom_lines']=function($value)use($mapping){$this->db->partner_fields=['emit_delivery_line'=>true,'emit_ship_to'=>true,'delivery_notes_policy'=>'item_detail_extrinsic'];return $mapping($value);};$xml=$this->xml($this->response());self::assertSame(1,$xml->query('//ShipTo')->length);self::assertSame(1,$xml->query('//ItemDetail/Extrinsic[@name="DeliveryInstructions"]')->length);}
	public function test_empty_paid_closeout_never_reads_delivery_or_emits_shipping_or_notes():void{$this->set_delivery(true,true);$this->db->partner_fields=['emit_delivery_line'=>true,'emit_ship_to'=>true,'emit_delivery_code'=>true,'delivery_notes_policy'=>'item_detail_extrinsic','cxml_version'=>'1.2.071'];$this->make_endpoint(false);$_POST['pow_mode']='empty';$this->db->session['status']='ordered';$this->db->session['order_id']=777;$GLOBALS['pow_test_orders'][777]=new WC_Order(777);$xml=$this->xml($this->response());self::assertSame(0,$xml->query('//ShipTo|//ItemIn|//Extrinsic[@name="DeliveryInstructions"]')->length);self::assertSame('0.00',$xml->evaluate('string(//PunchOutOrderMessageHeader/Total/Money)'));self::assertSame(0,$this->delivery->prepared_calls);self::assertSame(0,$this->db->guarded_updates);self::assertSame('closed',$this->db->session['status']);}
}
}
