<?php
/** Native request policy and real HTTP/UI early boundaries; DB-free, no native runtime. @package POW @license AGPL-3.0-or-later */
declare(strict_types=1);
namespace POW\Support {
	// The real WP fatal response terminates the request; record it without exiting the focused runner.
	function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void { $GLOBALS['pow_transport_hooks'][$hook][$priority][]=$callback; }
	function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void { add_action($hook,$callback,$priority,$args); }
	function wp_die($message, $title = '', $args = []): never { throw new \RuntimeException((string)$message, (int)($args['response'] ?? 500)); }
}
namespace {
use PHPUnit\Framework\TestCase;
use POW\Sessions\Session;
use POW\Support\Transport;

/** The one partners read protected_actor() makes, without a database: owner_user_id answers or the lookup breaks. */
final class TransportTestDatabase {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $owner = 0;
	public bool $unreachable = false;
	public array $queries = [];
	public function prepare( string $sql, ...$args ): string { return vsprintf( str_replace( [ '%d', '%s' ], '%s', $sql ), $args ); }
	public function get_row( string $sql, string $output = 'OBJECT' ): ?array {
		$this->queries[] = $sql;
		if ( $this->unreachable ) { $this->last_error = 'MySQL server has gone away'; return null; }
		return $this->owner > 0 && str_contains( $sql, 'owner_user_id = ' . $this->owner ) ? [ 'id' => 7, 'name' => 'Example Buyer Company', 'status' => 'active' ] : null;
	}
}

final class TransportTest extends TestCase {
	private array $saved;
	protected function setUp(): void {
		$this->saved = [$_SERVER, $_POST, $GLOBALS['pow_test_environment_type'] ?? null];
		$_SERVER = ['HTTPS'=>'off','SERVER_PORT'=>'80','REQUEST_METHOD'=>'POST']; $_POST=[];
		$GLOBALS['pow_test_environment_type']='production';
	}
	protected function tearDown(): void {
		[$_SERVER,$_POST,$environment]=$this->saved;
		if(null===$environment){unset($GLOBALS['pow_test_environment_type']);}else{$GLOBALS['pow_test_environment_type']=$environment;}
	}
	public function test_only_explicit_native_local_or_development_allows_http(): void {
		foreach(['production','staging','', 'invalid', 'test', 'local', 'development'] as $environment){
			$GLOBALS['pow_test_environment_type']=$environment;
			self::assertSame(in_array($environment,['local','development'],true),Transport::request_allowed());
		}
	}
	public function test_native_https_passes_and_forwarded_headers_never_grant_access(): void {
		$_SERVER['HTTP_X_FORWARDED_PROTO']='https'; $_SERVER['HTTP_FORWARDED']='proto=https';
		self::assertFalse(Transport::request_allowed());
		$_SERVER['HTTPS']='on'; self::assertTrue(Transport::request_allowed());
		unset($_SERVER['HTTPS']); $_SERVER['SERVER_PORT']='443'; self::assertTrue(Transport::request_allowed());
	}
	public function test_receivers_are_validated_without_rewriting_and_supplier_urls_are_canonical(): void {
		$http='http://buyer.example.test/receive?opaque=one%2Ftwo';
		$supplier='http://supplier.example.test/shop/punchout/setup';
		foreach(['production','staging','local','development'] as $environment){
			$GLOBALS['pow_test_environment_type']=$environment;
			$local=in_array($environment,['local','development'],true);
			self::assertSame($local,Transport::receiver_allowed($http));
			self::assertTrue(Transport::receiver_allowed('https://buyer.example.test/receive'));
			self::assertSame($local?$supplier:str_replace('http:','https:',$supplier),Transport::supplier_url($supplier));
			self::assertSame('http://buyer.example.test/receive?opaque=one%2Ftwo',$http);
		}
		foreach(['javascript:alert(1)','//buyer.example.test','http://','https:///path',"https://buyer.example.test/\r\n",'https://user:pass@buyer.example.test/'] as $url){self::assertFalse(Transport::receiver_allowed($url));}
	}
	/**
	 * The signals are a live visit and the account a connection is bound to.
	 * Neither the removed buyer role nor the removed _pow_partner_id user
	 * meta is consulted any more: the bound login is an ordinary customer
	 * account, so a test of what the USER is would answer "ordinary
	 * shopper" and switch the policy off for every buyer.
	 */
	public function test_early_native_shopping_guard_precedes_woo_and_covers_visits_and_the_bound_account(): void {
		$plugin = \POW\Plugin::instance();
		$visit = new \ReflectionProperty(\POW\Plugin::class,'current_session');
		$resolved = new \ReflectionProperty(\POW\Plugin::class,'session_resolved');
		$partners = new \ReflectionProperty(\POW\Plugin::class,'registry');
		$before = [$GLOBALS['pow_test_current_user_id']??null,$GLOBALS['pow_test_users']??null,$GLOBALS['pow_test_user_meta']??null,$GLOBALS['wpdb']??null];
		$state = [$visit->getValue($plugin),$resolved->getValue($plugin),$partners->getValue($plugin)];
		try {
			$GLOBALS['wpdb'] = $database = new TransportTestDatabase();
			$partners->setValue($plugin,new \POW\Partners\Registry(new \POW\Partners\Secrets(str_repeat('x',32))));
			$resolved->setValue($plugin,true); $visit->setValue($plugin,null);
			$GLOBALS['pow_transport_hooks']=[]; Transport::register();
			$hooks=$GLOBALS['pow_transport_hooks'];
			self::assertTrue(min(array_keys($hooks['init']))<0); // Installed Woo initialization is init:0; cart POST handling is wp_loaded:20.
			$guard=$hooks['init'][min(array_keys($hooks['init']))][0];

			$guard(); // Nobody is signed in: no visit lookup, no partners read, no policy.
			self::assertSame([],$database->queries);

			$GLOBALS['pow_test_current_user_id']=99;
			$guard(); // Signed in, no visit, no connection bound to this account.

			// The removed signals must not resurrect the policy on their own:
			// no role at all is consulted now (the buyer role is gone), and
			// the legacy company association meta is not a signal either.
			$GLOBALS['pow_test_users'][99]=(object)['ID'=>99,'roles'=>['customer','legacy_buyer_role']];
			$GLOBALS['pow_test_user_meta'][99]['_pow_partner_id']=7;
			$guard();
			unset($GLOBALS['pow_test_user_meta'][99]['_pow_partner_id']);

			// A live visit: buyer catalog, cart and Store API traffic in cleartext is refused.
			$visit->setValue($plugin,Session::from_row(['id'=>42,'partner_id'=>7,'user_id'=>99,'status'=>Session::ACTIVE]));
			foreach(['/shop/','/cart/?add-to-cart=12','/?wc-ajax=add_to_cart','/wp-json/wc/store/v1/cart/add-item'] as $path){
				$_SERVER['REQUEST_URI']=$path; $this->denied($guard);
			}
			$_SERVER['HTTPS']='on'; $guard();
			$_SERVER['HTTPS']='off'; $GLOBALS['pow_test_environment_type']='local'; $guard();
			$GLOBALS['pow_test_environment_type']='production';
			$rest=$hooks['rest_pre_dispatch'][min(array_keys($hooks['rest_pre_dispatch']))][0];
			$error=$rest(null);
			self::assertInstanceOf(\WP_Error::class,$error);
			self::assertSame('pow_https_required',$error->get_error_code());

			// Outside every visit the bound account is still covered, because it is the login every buyer of that connection arrives on.
			$visit->setValue($plugin,null);
			$guard();
			$database->owner=99; $this->denied($guard);
			$GLOBALS['pow_test_current_user_id']=100; $guard(); // Another shopper on the same shop is not.

			// A lookup that cannot run is not proof of an ordinary shopper.
			$GLOBALS['pow_test_current_user_id']=99; $database->unreachable=true; $this->denied($guard);
		} finally {
			foreach(['pow_test_current_user_id','pow_test_users','pow_test_user_meta','wpdb'] as $i=>$key){if(null===$before[$i]){unset($GLOBALS[$key]);}else{$GLOBALS[$key]=$before[$i];}}
			$visit->setValue($plugin,$state[0]); $resolved->setValue($plugin,$state[1]); $partners->setValue($plugin,$state[2]);
			unset($GLOBALS['pow_transport_hooks']);
		}
	}

	public function test_account_credential_post_is_refused_before_actor_or_mutation(): void {
		require_once __DIR__.'/AccountIntegrationTest.php';
		$before=$GLOBALS['pow_account_test']??null;
		try {
			$GLOBALS['pow_account_test']=['account'=>true,'endpoint'=>\POW\Account\IntegrationTab::ENDPOINT];
			$_POST=['pow_account_action'=>'download_setup_template','_wpnonce'=>'valid'];
			$this->denied(fn()=> $this->unconstructed(\POW\Account\IntegrationTab::class)->handle_post());
		} finally { if(null===$before){unset($GLOBALS['pow_account_test']);}else{$GLOBALS['pow_account_test']=$before;} }
	}

	private function unconstructed(string $class): object { return (new \ReflectionClass($class))->newInstanceWithoutConstructor(); }
	private function denied(callable $call): void {
		try{$call();self::fail('HTTP action reached downstream processing.');}
		catch(\RuntimeException $error){self::assertSame(403,$error->getCode());self::assertStringContainsString('HTTPS',$error->getMessage());}
	}
	public function test_direct_start_return_and_wc_ajax_alias_refuse_before_dependencies(): void {
		$this->denied(fn()=> $this->unconstructed(\POW\Http\StartEndpoint::class)->handle(str_repeat('a',64)));
		$this->denied(fn()=> $this->unconstructed(\POW\Http\ReturnEndpoint::class)->handle());
		$this->denied(fn()=> $this->unconstructed(\POW\Http\ReturnEndpoint::class)->handle_ajax());
	}
	public function test_router_early_exit_path_and_chooser_shortcode_cannot_bypass_policy(): void {
		$_SERVER['REQUEST_URI']='/punchout/start/'.str_repeat('a',64);
		$this->denied(fn()=> $this->unconstructed(\POW\Http\Router::class)->route());
		$chooser=$this->unconstructed(\POW\Addresses\Chooser::class);
		$this->denied(fn()=> $chooser->handle());
		self::assertStringContainsString('HTTPS',$chooser->markup());
	}
	public function test_secret_pages_actions_and_docs_refuse_before_dependencies(): void {
		$this->denied(fn()=> $this->unconstructed(\POW\Account\IntegrationTab::class)->render());
		$this->denied(fn()=> $this->unconstructed(\POW\Admin\Page::class)->render());
		foreach(['save_partner','approve_partner','reset_partner','associate_partner','delete_partner','rotate_partner','close_rotation'] as $method){
			$this->denied(fn()=> $this->unconstructed(\POW\Admin\Actions::class)->$method());
		}
		self::assertStringContainsString('HTTPS',$this->unconstructed(\POW\Docs\Page::class)->render(true));
		$report=$this->unconstructed(\POW\Docs\SelfTest::class)->run('<credential-bearing-input>');
		self::assertSame(401,$report['verdict']);
	}
}
}
