<?php
/** Destination producer tests; native boundaries are doubles, not persistence acceptance. */
declare( strict_types = 1 );

namespace POW\Tests\QuoteAddress {

use POW\Partners\Partner;
use POW\Sessions\Session;

/** The visit this request is inside, and a sibling visit of the very same account. */
const VISIT_KEY = 'pow_1a2b3c4d5e6f708192a3b4c5d6e7';
const SIBLING_KEY = 'pow_00112233445566778899aabbccdd';

/** Sessions\Current's whole surface: which visit, if any, this request is inside. */
final class Current {
	public ?Session $live = null;
	public bool $unreachable = false;
	public function visit( array $statuses = [] ): ?Session { if ( $this->unreachable ) { throw new \RuntimeException( 'PRIVATE session lookup error' ); } return $this->live; }
}
final class Registry {
	public array $rows = [ 7 => [ 'id' => 7, 'owner_user_id' => 20, 'status' => 'active' ] ];
	public function find( int $id ): ?Partner { return isset( $this->rows[$id] ) ? Partner::from_row( $this->rows[$id] ) : null; }
	public function find_by_owner( int $id ): ?Partner { foreach ( $this->rows as $row ) { if ( $row['owner_user_id'] === $id ) { return Partner::from_row( $row ); } } return null; }
}
final class CompanyBook {}
final class Countries {
	public bool $fail = false;
	public function country_exists( string $country ): bool { if ( $this->fail ) { throw new \RuntimeException( 'Native configuration changed' ); } return 'ZA' === $country; }
	public function get_shipping_countries(): array { return [ 'ZA' => 'South Africa' ]; }
	public function get_states( string $country ): array { return [ 'GP' => 'Gauteng' ]; }
	public function get_address_fields( string $country, string $prefix ): array { return [ 'shipping_address_1' => ['required'=>true], 'shipping_city' => ['required'=>true], 'shipping_country' => ['required'=>true] ]; }
}
final class Customer {
	public function __construct( public int $id, public array $address ) {}
	public function get_id(): int { return $this->id; }
	public function get_shipping(): array { return $this->address; }
}
final class Validation {
	public static function is_postcode( string $value, string $country ): bool { return true; }
	public static function is_phone( string $value, string $country ): bool { return true; }
}
function WC(): object { return $GLOBALS['quote_address_wc']; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return isset( $GLOBALS['pow_test_filters'][$hook] ) ? $GLOBALS['pow_test_filters'][$hook]( $value, ...$args ) : $value; }
function wp_cache_delete( mixed $key, string $group = '' ): bool { return true; }
function wc_strtoupper( string $value ): string { return strtoupper( $value ); }
function wc_format_postcode( string $value, string $country ): string { return strtoupper( $value ); }
function load_source(): void {
	if ( class_exists( __NAMESPACE__ . '\\QuoteAddress', false ) ) { return; }
	foreach ( ['Resolver', 'Shape', 'QuoteAddress'] as $name ) {
		if ( class_exists( __NAMESPACE__ . '\\' . $name, false ) ) { continue; }
		$path = dirname( __DIR__, 2 ) . '/includes/Addresses/' . $name . '.php';
		\PHPUnit\Framework\TestCase::assertTrue( is_file( $path ), 'Destination producer implementation must exist.' );
		$source = str_replace(
			[ 'namespace POW\\Addresses;', 'use POW\\Partners\\Registry;', 'use POW\\Sessions\\Current;', 'use WC_Validation;' ],
			[ 'namespace POW\\Tests\\QuoteAddress; use POW\\Addresses\\DeliveryData; use POW\\Addresses\\Codes;', '', '', 'use POW\\Tests\\QuoteAddress\\Validation as WC_Validation;' ],
			file_get_contents( $path )
		);
		eval( substr( $source, 5 ) ); // Native/service bindings only; production method bodies remain unchanged.
	}
}
}

namespace {
use PHPUnit\Framework\TestCase;
use POW\Tests\QuoteAddress\{QuoteAddress,Resolver,Registry,CompanyBook,Countries,Current,Customer};
use POW\Partners\Partner;
use POW\Sessions\Session;
use const POW\Tests\QuoteAddress\{VISIT_KEY,SIBLING_KEY};

final class QuoteAddressTest extends TestCase {
	private array $saved = [];
	private Registry $registry;
	private Current $visits;
	private QuoteAddress $producer;
	protected function setUp(): void {
		\POW\Tests\QuoteAddress\load_source();
		foreach ( ['wpdb','pow_test_users','pow_test_user_meta','pow_test_current_user_id','pow_test_filters','quote_address_wc'] as $key ) { $this->saved[$key] = [array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null]; }
		$GLOBALS['wpdb'] = (object)['last_error'=>''];
		// Connection 7's bound login is user 20, so every visit of it is signed in as 20 and the row, not the
		// user, says which visit this request is. 21 is an unrelated customer with no connection of its own.
		$GLOBALS['pow_test_users'] = [20=>(object)['ID'=>20,'roles'=>['customer'],'allcaps'=>['read'=>true]],21=>(object)['ID'=>21,'roles'=>['customer'],'allcaps'=>['read'=>true]]];
		$GLOBALS['pow_test_user_meta'] = [];
		$GLOBALS['pow_test_current_user_id'] = 20;
		$GLOBALS['pow_test_filters'] = [];
		$GLOBALS['quote_address_wc'] = (object)['countries'=>new Countries(),'customer'=>new Customer(20,$this->address(['city'=>'Company city']))];
		$this->registry = new Registry(); $this->visits = new Current();
		$this->producer = new QuoteAddress(new Resolver($this->registry,new CompanyBook(),$this->visits));
	}
	protected function tearDown(): void { foreach($this->saved as $key=>[$exists,$value]) { if($exists){$GLOBALS[$key]=$value;}else{unset($GLOBALS[$key]);} } }
	private function address( array $changes = [] ): array {
		return array_replace(['first_name'=>'Zoë','last_name'=>"O’Neil",'company'=>'Example & Co','address_1'=>'12 Main Street','address_2'=>'Unit 2','city'=>'Johannesburg','state'=>'GP','postcode'=>'2000','country'=>'ZA','phone'=>'+27 11 555 0100'],$changes);
	}
	private function choice( array $changes = [] ): array {
		return array_replace(['schema'=>1,'partner_id'=>7,'storage_user_id'=>20,'provider'=>'native','key'=>'depot','label'=>'Depot','address'=>$this->address(),'code'=>'','source'=>'company_book','book_revision'=>3,'entry_fingerprint'=>str_repeat('a',64)],$changes);
	}
	private function session( ?array $choice = null, array $changes = [] ): Session {
		return Session::from_row(array_replace(['id'=>42,'partner_id'=>7,'user_id'=>20,'status'=>Session::ACTIVE,'wc_session_key'=>VISIT_KEY,'delivery_choice'=>null===$choice?null:json_encode($choice,JSON_THROW_ON_ERROR)],$changes));
	}
	/** Put the request inside visit 42 and hand back the row a caller would be holding. */
	private function enter( ?array $choice = null, array $changes = [] ): Session { $this->visits->live = $this->session($choice,$changes); return $this->session($choice,$changes); }
	private function resolve( ?Session $session = null, ?Partner $partner = null ): ?array { $session ??= $this->enter(); $this->visits->live ??= $this->session(); return $this->producer->resolve_destination($session,$partner??$this->registry->find(7)); }
	private function refuse( callable $call ): void { try { $call(); self::fail('Malformed destination was accepted.'); } catch (\DomainException $error) { self::assertStringNotContainsString('PRIVATE',$error->getMessage()); } }
	public function test_payload_keeps_all_ten_local_fields_and_only_destination_metadata(): void {
		$choice=$this->choice();
		self::assertSame(['address'=>$this->address(),'code'=>'','source'=>'company_book'],QuoteAddress::payload($choice));
		self::assertSame($this->choice(),$choice);
		self::assertNull(QuoteAddress::payload(null));
	}
	public function test_payload_refuses_malformed_selected_data_instead_of_blank_or_repair(): void {
		foreach ([[],['address'=>[]],$this->choice(['address'=>$this->address(['city'=>['PRIVATE']])]),$this->choice(['source'=>'unknown']),$this->choice(['code'=>null]),$this->choice(['address'=>$this->address(['country'=>'za'])])] as $bad) { $this->refuse(fn()=>QuoteAddress::payload($bad)); }
		$missing=$this->address(); unset($missing['phone']); $this->refuse(fn()=>QuoteAddress::payload($this->choice(['address'=>$missing])));
		$this->refuse(fn()=>QuoteAddress::payload($this->choice(['address'=>$this->address()+['label'=>'PRIVATE']])));
	}
	public function test_wire_maps_postal_lists_without_phone_or_code_and_keeps_unicode_text(): void {
		self::assertSame(['name'=>'Example & Co','deliver_to'=>['Zoë O’Neil'],'street'=>['12 Main Street','Unit 2'],'city'=>'Johannesburg','state'=>'GP','postal_code'=>'2000','iso_country'=>'ZA'],QuoteAddress::to_cxml($this->choice()));
	}
	public function test_wire_uses_person_when_company_blank_and_omits_empty_optional_lines(): void {
		$entry=$this->choice(['address'=>$this->address(['company'=>'','address_2'=>''])]);
		$wire=QuoteAddress::to_cxml($entry);
		self::assertSame('Zoë O’Neil',$wire['name']); self::assertSame(['Zoë O’Neil'],$wire['deliver_to']); self::assertSame(['12 Main Street'],$wire['street']);
		$entry['address']['company']='Company'; $entry['address']['first_name']=''; $entry['address']['last_name']='';
		self::assertSame([],QuoteAddress::to_cxml($entry)['deliver_to']);
	}
	public function test_wire_preserves_maximum_native_lengths_and_zero_text(): void {
		$address=$this->address(['company'=>'','first_name'=>str_repeat('é',190),'last_name'=>str_repeat('Z',190),'address_1'=>str_repeat('x',190),'address_2'=>'0','city'=>str_repeat('c',190),'state'=>str_repeat('s',190),'postcode'=>str_repeat('p',32)]);
		$wire=QuoteAddress::to_cxml(['address'=>$address]); self::assertSame(str_repeat('é',190).' '.str_repeat('Z',190),$wire['name']); self::assertSame('0',$wire['street'][1]);
	}
	public function test_wire_refuses_oversized_or_invalid_xml_text_without_truncation(): void {
		foreach (['first_name'=>191,'last_name'=>191,'company'=>191,'address_1'=>191,'address_2'=>191,'city'=>191,'state'=>191,'postcode'=>33,'phone'=>101] as $key=>$size) { $this->refuse(fn()=>QuoteAddress::to_cxml(['address'=>$this->address([$key=>str_repeat('x',$size)])])); }
		foreach (["PRIVATE\x01","PRIVATE\xff","PRIVATE\u{FFFF}"] as $bad) { $this->refuse(fn()=>QuoteAddress::to_cxml(['address'=>$this->address(['city'=>$bad])])); }
		$this->refuse(fn()=>QuoteAddress::to_cxml(['address'=>$this->address(['address_1'=>'','address_2'=>''])]));
		$this->refuse(fn()=>QuoteAddress::to_cxml(['address'=>$this->address(['address_1'=>'   '])]));
		$this->refuse(fn()=>QuoteAddress::to_cxml(['address'=>$this->address(['company'=>'','first_name'=>'','last_name'=>''])]));
	}
	public function test_selected_snapshot_wins_and_never_revalidates_native_configuration(): void {
		$session=$this->enter($this->choice()); $before=$session->delivery_choice_json;
		$GLOBALS['quote_address_wc']->countries->fail=true;
		self::assertSame(['address'=>$this->address(),'code'=>'','source'=>'company_book'],$this->resolve($session)); self::assertSame($before,$session->delivery_choice_json);
	}
	public function test_no_extension_filter_can_supply_the_delivery_destination(): void {
		// Letting a site file name the delivery destination is site glue, and the plugin no longer offers it.
		$GLOBALS['pow_test_filters']['pow_quote_shipping_address']=static function(){ throw new \RuntimeException('PRIVATE filter must not run'); };
		self::assertSame(['address'=>$this->address(['city'=>'Company city']),'code'=>'','source'=>'customer'],$this->resolve());
		self::assertSame(['address'=>$this->address(),'code'=>'','source'=>'company_book'],$this->resolve($this->enter($this->choice())));
		self::assertStringNotContainsString('apply_filters',file_get_contents(dirname(__DIR__,2).'/includes/Addresses/QuoteAddress.php'));
	}
	public function test_invalid_selected_snapshot_never_falls_back_to_valid_buyer_address(): void {
		foreach ([$this->session(null,['delivery_choice'=>'PRIVATE malformed']),$this->session($this->choice(['storage_user_id'=>21])),$this->session($this->choice(['address'=>$this->address(['city'=>str_repeat('x',191)])]))] as $session) { $this->refuse(fn()=>$this->resolve($session)); }
	}
	public function test_foreign_partner_or_lost_association_refuses_even_without_choice(): void {
		$this->refuse(fn()=>$this->resolve(null,Partner::from_row(['id'=>8,'owner_user_id'=>20])));
		// The connection's login moved to another account, so the visit's own user is nobody's owner any more.
		$this->registry->rows[7]['owner_user_id']=21; $this->refuse(fn()=>$this->resolve());
	}
	public function test_another_visits_row_cannot_produce_a_destination_in_this_request(): void {
		$this->visits->live=$this->session();
		// Visit 43 belongs to a second employee signed in as the same account; its key, not its user, says so.
		foreach ( [ $this->session($this->choice(),['id'=>43,'wc_session_key'=>SIBLING_KEY]), $this->session($this->choice(),['wc_session_key'=>SIBLING_KEY]), $this->session($this->choice(),['wc_session_key'=>null]) ] as $other ) {
			$this->refuse(fn()=>$this->producer->resolve_destination($other,$this->registry->find(7)));
		}
		$this->visits->live=null; $this->refuse(fn()=>$this->producer->resolve_destination($this->session(),$this->registry->find(7)));
		$this->visits->unreachable=true; $this->refuse(fn()=>$this->producer->resolve_destination($this->session(),$this->registry->find(7)));
	}
	public function test_a_candidate_is_normalised_and_never_marks_the_session_confirmed(): void {
		$session=$this->enter();
		$GLOBALS['quote_address_wc']->customer->address=$this->address(['country'=>'za','state'=>'Gauteng']);
		self::assertSame(['address'=>$this->address(),'code'=>'','source'=>'customer'],$this->resolve($session));
		self::assertNull($session->delivery_choice()); self::assertNull($session->delivery_confirmation());
	}
	public function test_empty_or_invalid_candidates_are_absent_rather_than_repaired(): void {
		foreach ([[],$this->address(['country'=>'XX']),$this->address(['city'=>['PRIVATE']]),$this->address(['postcode'=>str_repeat('p',33)])] as $address) {
			$GLOBALS['quote_address_wc']->customer->address=$address;
			self::assertNull($this->resolve());
		}
		$GLOBALS['quote_address_wc']->customer->address=$this->address(['city'=>'Company city']);
		self::assertSame(['address'=>$this->address(['city'=>'Company city']),'code'=>'','source'=>'customer'],$this->resolve());
	}
	public function test_inbound_precedes_the_company_profile_but_remains_unconfirmed(): void {
		if ( ! class_exists(\DOMDocument::class) ) { $this->markTestSkipped('ext-dom not available'); }
		$session=$this->session(null,['ship_to'=>'<ShipTo><Address addressID="IN-1"><Name>Site</Name><PostalAddress><DeliverTo>Recipient</DeliverTo><Street>1 Inbound Road</Street><City>Pretoria</City><Country isoCountryCode="ZA"/></PostalAddress></Address></ShipTo>']);
		$result=$this->resolve($session); self::assertSame('ship_to',$result['source']); self::assertSame('IN-1',$result['code']); self::assertSame('1 Inbound Road',$result['address']['address_1']); self::assertSame('',$result['address']['phone']); self::assertNull($session->delivery_choice());
	}
	public function test_profile_candidate_requires_the_visits_own_account(): void {
		// The company profile address is only a candidate when the loaded customer IS the connection's login.
		$GLOBALS['quote_address_wc']->customer->id=21; self::assertNull($this->resolve());
		$GLOBALS['quote_address_wc']->customer->id=20; self::assertSame('customer',$this->resolve()['source']);
	}
	public function test_no_usable_candidate_is_null_without_session_mutation(): void {
		$GLOBALS['quote_address_wc']->customer->address=[]; $session=$this->enter();
		self::assertNull($this->resolve($session)); self::assertNull($session->delivery_choice()); self::assertNull($session->delivery_confirmation());
	}
	public function test_produced_postal_shape_round_trips_through_both_exact_dtds(): void {
		if ( ! class_exists(\DOMDocument::class) ) { $this->markTestSkipped('ext-dom not available'); }
		require_once dirname(__DIR__).'/Support/ExactCxmlDtd.php';
		$destination=$this->choice(['address'=>$this->address(['company'=>'','first_name'=>str_repeat('é',190),'last_name'=>str_repeat('Z',190),'address_2'=>'Building <B> & C'])]);
		foreach (['1.2.008','1.2.071'] as $version) {
			$args=['version'=>$version,'payload_id'=>'sample@shop.example.test','timestamp'=>'2026-09-09T10:00:00+00:00','from'=>['domain'=>'NetworkID','identity'=>'SUPPLIER'],'to'=>['domain'=>'NetworkID','identity'=>'BUYER'],'sender'=>['domain'=>'NetworkID','identity'=>'SUPPLIER'],'buyer_cookie'=>'cookie','currency'=>'ZAR','total_cents'=>100,'items'=>[['quantity'=>1,'supplier_part_id'=>'PART','aux_id'=>'','unit_price_cents'=>100,'description'=>'Product','uom'=>'EA','classification_domain'=>'supplier','classification'=>'supplies']],'ship_to'=>QuoteAddress::to_cxml($destination),'delivery_code'=>'','emit_ship_to'=>true,'emit_delivery_code'=>true];
			$xml=(new \POW\Cxml\Builder())->poom($args);
			$result=ExactCxmlDtd::validate($xml); self::assertTrue($result['valid'],implode('; ',$result['errors']));
			$doc=new \DOMDocument(); self::assertTrue($doc->loadXML($xml,LIBXML_NONET)); $path=new \DOMXPath($doc);
			self::assertSame(str_repeat('é',190).' '.str_repeat('Z',190),$path->evaluate('string(//ShipTo/Address/Name)'));
			self::assertSame('Building <B> & C',$path->evaluate('string(//PostalAddress/Street[2])'));
			self::assertSame(0,$path->query('//ShipTo//Phone | //ShipTo/Address/@addressID | //ItemIn/Extrinsic')->length);
		}
	}
}
}
