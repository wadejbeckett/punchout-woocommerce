<?php
/** Account controller/template unit checks; native acceptance is coordinated separately. */
declare( strict_types = 1 );

namespace POW\Account {
	// Namespace-local I/O doubles do not claim global WordPress/WooCommerce functions.
	function is_account_page(): bool { return isset($GLOBALS['pow_account_test']) ? $GLOBALS['pow_account_test']['account'] : \is_account_page(); }
	function is_wc_endpoint_url( string $endpoint ): bool { return isset($GLOBALS['pow_account_test']) ? $endpoint === $GLOBALS['pow_account_test']['endpoint'] : \is_wc_endpoint_url($endpoint); }
	function wp_create_nonce( string $action ): string { return isset($GLOBALS['pow_account_test']) ? 'account-nonce' : \wp_create_nonce($action); }
	function wc_get_page_permalink( string $page ): string { return isset($GLOBALS['pow_account_test']) ? 'https://shop.example.test/account/' : \wc_get_page_permalink($page); }
	function wc_get_endpoint_url( string $endpoint, string $value, string $url ): string { return isset($GLOBALS['pow_account_test']) ? $url . $endpoint . '/' : \wc_get_endpoint_url($endpoint,$value,$url); }
	function get_posts( array $args ): array {
		if ( ! isset($GLOBALS['pow_account_test']) ) { return \get_posts($args); }
		if ( $GLOBALS['pow_account_test']['fail_pages'] ?? false ) { throw new \RuntimeException( 'private page failure' ); }
		return $GLOBALS['pow_account_test']['pages'] ?? [];
	}
	function has_shortcode( string $content, string $tag ): bool { return isset($GLOBALS['pow_account_test']) ? str_contains($content,'[' . $tag . ']') : \has_shortcode($content,$tag); }
	function get_permalink( int $id ): string { return isset($GLOBALS['pow_account_test']) ? 'https://shop.example.test/integration-guide/' : (string) \get_permalink($id); }
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		if ( ! isset($GLOBALS['pow_account_test']) ) { return \add_action($hook,$callback,$priority,$accepted_args); }
		$GLOBALS['pow_account_test']['hooks'][$hook]=$callback; return true;
	}
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		if ( ! isset($GLOBALS['pow_account_test']) ) { return \add_filter($hook,$callback,$priority,$accepted_args); }
		$GLOBALS['pow_account_test']['hooks'][$hook]=$callback; return true;
	}
	function header( string $header, bool $replace = true ): void {
		if ( isset( $GLOBALS['pow_account_test'] ) ) { $GLOBALS['pow_account_test']['headers'][] = $header; return; }
		\header( $header, $replace );
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use POW\Account\IntegrationTab;
	use POW\Audit\Log;
	use POW\Http\RateLimiter;
	use POW\Partners\{Registry, Registration, Secrets};
	use POW\Sessions\Store;
	use POW\Support\Templates;

	/** Exercises real Registry methods against a small recording SQL boundary, not native persistence. */
	final class AccountDatabase {
		public string $prefix = 'account_fixture_';
		public string $last_error = '';
		public int $insert_id = 0;
		public array $rows = [];
		public array $writes = [];
		public bool $held = false;
		public bool $fail_write = false;
		public bool $fail_lookup = false;
		public bool $release_error = false;
		public bool $fail_sessions = false;
		public mixed $on_lock = null;
		private bool $suppressed = false;
		private array $queries = [];
		public function prepare( string $sql, mixed ...$args ): string { $key = 'q' . count( $this->queries ); $this->queries[$key] = [$sql, $args]; return $key; }
		public function suppress_errors( bool $value = true ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
		public function get_var( string $key ): string {
			[$sql] = $this->queries[$key];
			if ( str_contains( $sql, 'GET_LOCK' ) ) {
				$this->held = true;
				if ( $this->on_lock ) { ($this->on_lock)(); $this->on_lock = null; }
				return '1';
			}
			if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $this->held = false; return $this->release_error ? '0' : '1'; }
			throw new RuntimeException( 'Unexpected account query' );
		}
		public function get_row( string $key, string $format ): ?array {
			[$sql, $args] = $this->queries[$key];
			$this->last_error = $this->fail_lookup ? 'private lookup error' : '';
			if ( $this->fail_lookup ) { return null; }
			foreach ( array_reverse( $this->rows ) as $row ) {
				if ( str_contains( $sql, 'WHERE owner_user_id' ) && $row['owner_user_id'] === $args[0] ) { return $row; }
				if ( str_contains( $sql, 'WHERE id =' ) && $row['id'] === $args[0] ) { return $row; }
				if ( str_contains( $sql, 'WHERE sender_domain' ) && $row['sender_domain'] === $args[0] && $row['sender_identity'] === $args[1] ) { return $row; }
			}
			return null;
		}
		public function get_results( string $key, string $format ): array {
			[$sql, $args] = $this->queries[$key];
			if ( ! $this->held || ! str_contains( $sql, 'WHERE partner_id = ' ) || 20 !== $args[0] ) { throw new LogicException( 'Unexpected unlocked session query' ); }
			$this->last_error = $this->fail_sessions ? 'private session error' : '';
			return [];
		}
		public function insert( string $table, array $data ): int|false {
			if ( ! $this->held ) { throw new LogicException( 'Unserialized account insert' ); }
			if ( $this->fail_write ) { return false; }
			$data['id'] = ++$this->insert_id;
			$this->rows[] = $data;
			$this->writes[] = $data;
			return 1;
		}
		public function update( string $table, array $data, array $where ): int|false {
			if ( ! $this->held ) { throw new LogicException( 'Unserialized account update' ); }
			if ( $this->fail_write ) { return false; }
			foreach ( $this->rows as &$row ) {
				foreach ( $where as $key => $value ) { if ( ($row[$key] ?? null) !== $value ) { continue 2; } }
				$row = array_replace( $row, $data ); $this->writes[] = $data; return 1;
			}
			return 0;
		}
	}
	final class AccountAudit extends Log {
		public array $events = [];
		public bool $fail = false;
		public function __construct() {}
		public function last_success( int $partner_id, string $event = 'setup_ok' ): ?string { return null; }
		public function write_checked( string $event, array $context = [] ): bool {
			if ( $this->fail ) { throw new RuntimeException( 'private audit failure' ); }
			$this->events[] = [$event, $context]; return true;
		}
	}
	final class AccountIntegrationTest extends TestCase {
		private array $saved = [];
		private AccountDatabase $db;
		private AccountAudit $audit;
		private Registry $registry;
		private IntegrationTab $tab;
		protected function setUp(): void {
			self::assertTrue( class_exists( IntegrationTab::class ), 'Account controller is not implemented' );
			foreach ( ['wpdb','pow_account_test','pow_test_current_user_id','pow_test_users','pow_test_user_meta','pow_test_options','pow_test_transients','pow_test_transient_expirations','pow_test_valid_nonce','pow_test_mail','pow_test_nocache_headers','pow_test_status_headers','_SERVER','_POST'] as $key ) {
				$this->saved[$key] = [array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null]; unset($GLOBALS[$key]);
			}
			$GLOBALS['pow_account_test'] = ['account'=>true,'endpoint'=>'punchout-integration','headers'=>[], 'pages'=>[(object)['ID'=>12,'post_content'=>'[punchout_docs]']]];
			$GLOBALS['pow_test_options'] = ['pow_settings'=>['enabled'=>'yes']];
			$GLOBALS['pow_test_current_user_id'] = 7;
			$GLOBALS['pow_test_users'][7] = (object)['ID'=>7,'roles'=>['customer'],'allcaps'=>['read'=>true]];
			$GLOBALS['pow_test_valid_nonce'] = 'account-nonce';
			$_SERVER = ['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'192.0.2.7'];
			$_POST = ['pow_account_action'=>'rotate','_wpnonce'=>'account-nonce'];
			$GLOBALS['wpdb'] = $this->db = new AccountDatabase();
			$this->registry = new Registry( new Secrets(str_repeat('a',32)) );
			$this->audit = new AccountAudit();
			$this->tab = $this->make_tab();
		}
		protected function tearDown(): void { foreach ($this->saved as $key=>[$exists,$value]) { if ($exists) {$GLOBALS[$key]=$value;} else {unset($GLOBALS[$key]);} } }
		private function make_tab( int $limit = 5 ): IntegrationTab {
			$plugin = (new ReflectionClass(POW\Plugin::class))->newInstanceWithoutConstructor();
			$rate = [];
			return new IntegrationTab($plugin,$this->registry,new Registration($this->registry,new Store(),$this->audit),$this->audit,new RateLimiter(RateLimiter::public_limit($limit,5),static function(string $key)use(&$rate):int{return $rate[$key]??0;},static function(string $key,int $count)use(&$rate):void{$rate[$key]=$count;},3600));
		}
		private function invoke( string $method, mixed ...$args ): mixed { return (new ReflectionMethod($this->tab,$method))->invoke($this->tab,...$args); }
		private function seed( array $overrides = [] ): void {
			$this->db->rows = [array_replace(['id'=>20,'owner_user_id'=>7,'name'=>'Example Company','status'=>'active','from_domain'=>'NetworkID','from_identity'=>'EXAMPLE','sender_domain'=>'NetworkID','sender_identity'=>'EXAMPLE','to_domain'=>'NetworkID','to_identity'=>'STORE','secret_current'=>(new Secrets(str_repeat('a',32)))->seal('old-secret'),'secret_previous'=>''],$overrides)];
		}
		public function test_template_resolves_and_displays_request_and_identity_guidance(): void {
			self::assertSame('account/integration',Templates::normalise_name('account/integration'));
			$html = Templates::render('account/integration', $this->view());
			foreach (['Request connection','UserEmail','UniqueUsername','UniqueName','Contact/Email','purchasing system','integration-guide'] as $text) { self::assertStringContainsString($text,$html); }
			self::assertStringNotContainsString('name="owner_user_id"',$html);
		}
		private function view( array $overrides = [] ): array {
			return array_replace(['state'=>'none','connection'=>[],'notice'=>null,'secret'=>'','setup_url'=>'https://shop.example.test/punchout/setup','last_setup'=>null,'action_url'=>'https://shop.example.test/account/punchout-integration/','docs_url'=>'https://shop.example.test/integration-guide/','nonce'=>'account-nonce','rotation_open'=>false],$overrides);
		}
		public function test_template_escapes_values_and_explains_immediate_deactivation(): void {
			$html = Templates::render('account/integration',$this->view(['state'=>'active','secret'=>'<script>private</script>','connection'=>['name'=>'<img>','from'=>'<from>','sender'=>'<sender>','to'=>'<to>','deployment_mode'=>'<test>','cxml_version'=>'1.2.008','return_encoding'=>'base64']]));
			self::assertStringContainsString('&lt;script&gt;private&lt;/script&gt;',$html);
			self::assertStringNotContainsString('<script>',$html);
			self::assertStringContainsString('Deactivate connection now',$html);
			self::assertStringContainsString('immediately',$html);
			self::assertStringNotContainsString('Request deactivation',$html);
		}
		public function test_template_states_expose_only_allowed_actions(): void {
			foreach (['pending','disabled'] as $state) {
				$html = Templates::render('account/integration',$this->view(['state'=>$state]));
				self::assertStringNotContainsString('value="rotate"',$html); self::assertStringNotContainsString('value="submit"',$html);
			}
			$html = Templates::render('account/integration',$this->view(['state'=>'active','rotation_open'=>true,'template_ready'=>true]));
			self::assertStringContainsString('value="finish_rotation"',$html); self::assertStringNotContainsString('value="rotate"',$html);
			self::assertStringContainsString('value="download_setup_template"',$html);
		}
		public function test_download_button_appears_only_for_an_active_connection_whose_template_can_be_built(): void {
			foreach (['none','pending','disabled','unavailable'] as $state) {
				$html = Templates::render('account/integration',$this->view(['state'=>$state,'template_ready'=>true]));
				self::assertStringNotContainsString('value="download_setup_template"',$html,$state);
			}
			$html = Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>false]));
			self::assertStringNotContainsString('value="download_setup_template"',$html);
			self::assertStringContainsString('No setup template yet',$html);
			foreach ([true,false] as $rotation) {
				$html = Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>true,'rotation_open'=>$rotation]));
				self::assertStringContainsString('value="download_setup_template"',$html);
				self::assertStringNotContainsString('No setup template yet',$html);
			}
		}
		public function test_view_reports_whether_the_template_can_be_built(): void {
			$this->seed(); self::assertTrue($this->invoke('view_vars')['template_ready']);
			$this->seed(['from_domain'=>'','from_identity'=>'','to_domain'=>'','to_identity'=>'']); self::assertFalse($this->invoke('view_vars')['template_ready']);
			$this->seed(['status'=>'pending']); self::assertFalse($this->invoke('view_vars')['template_ready']);
		}
		public function test_download_of_a_half_configured_connection_names_the_missing_fields_instead_of_asking_for_a_reload(): void {
			$this->seed(['from_domain'=>'','from_identity'=>'']);
			$_POST=['pow_account_action'=>'download_setup_template','_wpnonce'=>'account-nonce'];
			$result=$this->invoke('template_download_result');
			self::assertSame(409,$result['status']);
			self::assertStringContainsString('From, To and Sender identities',$result['notice']['text']);
			self::assertStringNotContainsString('try again',$result['notice']['text']);
			self::assertSame('',$result['xml']);
		}
		public function test_download_emission_sends_an_xml_attachment_on_success_and_the_page_with_a_notice_otherwise(): void {
			$this->seed();
			$_POST=['pow_account_action'=>'download_setup_template','_wpnonce'=>'account-nonce'];
			$ok=$this->invoke('download_emission',$this->invoke('template_download_result'));
			self::assertSame(200,$ok['status']);
			self::assertContains('Content-Type: application/xml; charset=UTF-8',$ok['headers']);
			self::assertContains('Content-Disposition: attachment; filename="punchout-setup-20.xml"',$ok['headers']);
			self::assertContains('X-Content-Type-Options: nosniff',$ok['headers']);
			self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>',$ok['body']);
			self::assertStringContainsString('<Identity>EXAMPLE</Identity>',$ok['body']);
			self::assertStringNotContainsString('<html',$ok['body']);

			$this->seed(['owner_user_id'=>8]);
			$refused=$this->invoke('download_emission',$this->invoke('template_download_result'));
			self::assertSame(403,$refused['status']);
			self::assertSame([],$refused['headers']);
			self::assertStringContainsString('cannot be managed from this account',$refused['body']);
			self::assertStringNotContainsString('<?xml',$refused['body']);
		}
		public function test_active_owner_download_uses_company_fields_without_revealing_secret_material(): void {
			$this->seed(['from_domain'=>'Buyer&"<','from_identity'=>'FROM<&','sender_domain'=>'Sender&"<','sender_identity'=>'SENDER<&','to_domain'=>'Supplier&"<','to_identity'=>'TO<&','deployment_mode'=>'production','cxml_version'=>'1.2.008','secret_current'=>'SEALED-CURRENT','secret_previous'=>'SEALED-PREVIOUS']);
			$_POST=['pow_account_action'=>'download_setup_template','_wpnonce'=>'account-nonce'];
			$result=$this->invoke('template_download_result');
			self::assertSame(200,$result['status']);
			self::assertSame('punchout-setup-20.xml',$result['filename']);
			self::assertStringContainsString('domain="Buyer&amp;&quot;&lt;"',$result['xml']);
			self::assertStringContainsString('<Identity>SENDER&lt;&amp;</Identity>',$result['xml']);
			self::assertStringContainsString('<SupplierSetup><URL>https://shop.example.test/punchout/setup</URL></SupplierSetup>',$result['xml']);
			self::assertStringContainsString('deploymentMode="production"',$result['xml']);
			self::assertStringContainsString('REPLACE-WITH-ISSUED-SHARED-SECRET',$result['xml']);
			self::assertStringContainsString('payloadID="" timestamp=""',$result['xml']);
			self::assertStringContainsString('<BuyerCookie />',$result['xml']);
			self::assertStringContainsString('<BrowserFormPost><URL /></BrowserFormPost>',$result['xml']);
			self::assertStringNotContainsString('<Extrinsic name="UserEmail">',$result['xml']);
			foreach(['buyer.example.invalid','buyer.user@example.invalid','BUYER-SYSTEM-RUNTIME-VALUE'] as $invented) { self::assertStringNotContainsString($invented,$result['xml']); }
			foreach(['SEALED-CURRENT','SEALED-PREVIOUS','old-secret'] as $secret) { self::assertStringNotContainsString($secret,$result['xml']); }
		}
		public function test_template_download_rejects_other_owners_pending_connections_and_bad_nonce(): void {
			$_POST=['pow_account_action'=>'download_setup_template','_wpnonce'=>'account-nonce','partner_id'=>20];
			$this->seed(['owner_user_id'=>8]); self::assertSame(403,$this->invoke('template_download_result')['status']);
			$this->seed(['status'=>'pending']); self::assertSame(403,$this->invoke('template_download_result')['status']);
			$this->seed(); $_POST['_wpnonce']='forged'; self::assertSame(403,$this->invoke('template_download_result')['status']);
			self::assertSame([],$this->db->writes);
		}
		public function test_request_boundaries_refuse_before_lookup_or_mutation(): void {
			foreach (['method','endpoint','account','nonce','nonce_missing','nonce_array','action_array','unknown','anonymous','role','metadata','capability','disabled'] as $case) {
				$_SERVER['REQUEST_METHOD']='POST'; $_POST=['pow_account_action'=>'rotate','_wpnonce'=>'account-nonce'];
				$GLOBALS['pow_account_test']['account']=true; $GLOBALS['pow_account_test']['endpoint']='punchout-integration';
				$GLOBALS['pow_test_current_user_id']=7; $GLOBALS['pow_test_users'][7]->roles=['customer']; $GLOBALS['pow_test_users'][7]->allcaps=['read'=>true];
				$GLOBALS['pow_test_user_meta']=[]; $GLOBALS['pow_test_options']['pow_settings']['enabled']='yes'; $this->tab=$this->make_tab();
				match($case) {
					'method'=>$_SERVER['REQUEST_METHOD']='GET', 'endpoint'=>$GLOBALS['pow_account_test']['endpoint']='orders','account'=>$GLOBALS['pow_account_test']['account']=false,
					'nonce'=>$_POST['_wpnonce']='bad','nonce_missing'=>$_POST['_wpnonce']=null,'nonce_array'=>$_POST['_wpnonce']=[], 'action_array'=>$_POST['pow_account_action']=[], 'unknown'=>$_POST['pow_account_action']='approve',
					'anonymous'=>$GLOBALS['pow_test_current_user_id']=0,'role'=>$GLOBALS['pow_test_users'][7]->roles=['punchout_buyer'],'metadata'=>$GLOBALS['pow_test_user_meta'][7]['_pow_partner_id']=20,
					'capability'=>$GLOBALS['pow_test_users'][7]->allcaps=[], 'disabled'=>$GLOBALS['pow_test_options']['pow_settings']['enabled']='no'
				};
				$result=$this->invoke('post_result'); self::assertTrue(null===$result || $result['status']>=400,$case);
				self::assertSame([],$this->db->writes); self::assertSame([],$GLOBALS['pow_test_transients'] ?? []);
			}
		}
		public function test_owner_is_resolved_on_server_and_owner_zero_is_not_claimable(): void {
			foreach ([0,8] as $owner) { $this->seed(['owner_user_id'=>$owner]); $_POST['partner_id']=20; $_POST['owner_user_id']=$owner; self::assertSame(403,$this->invoke('post_result')['status']); }
			self::assertSame([],$this->db->writes);
		}
		public function test_rotation_refuses_open_overlap_and_rechecks_owner_under_lock(): void {
			$this->seed(['secret_previous'=>'existing-overlap']); self::assertSame(409,$this->invoke('post_result')['status']); self::assertSame([],$this->db->writes);
			$this->seed(); $this->db->on_lock=function() {$this->db->rows[0]['owner_user_id']=8;};
			self::assertSame(403,$this->invoke('post_result')['status']); self::assertSame([],$this->db->writes);
		}
		public function test_rotation_issues_once_and_finish_revokes_previous_only(): void {
			$this->seed(); $result=$this->invoke('post_result'); self::assertSame(200,$result['status']); self::assertTrue(strlen($result['secret'])>20);
			$p=$this->registry->find(20); self::assertSame(Secrets::SLOT_PREVIOUS,$this->registry->verify_secret($p,'old-secret')); self::assertSame(Secrets::SLOT_CURRENT,$this->registry->verify_secret($p,$result['secret']));
			self::assertSame(409,$this->invoke('post_result')['status']); $_POST['pow_account_action']='finish_rotation'; self::assertSame(200,$this->invoke('post_result')['status']);
			self::assertNull($this->registry->verify_secret($this->registry->find(20),'old-secret')); self::assertSame('rotation_closed',$this->audit->events[1][0]);
			$stores=[$GLOBALS['pow_test_transients'] ?? [],$GLOBALS['pow_test_options'] ?? [],$GLOBALS['pow_test_user_meta'] ?? [],$GLOBALS['pow_test_mail'] ?? [],$this->audit->events,$this->db->writes];
			self::assertStringNotContainsString($result['secret'],json_encode($stores));
		}
		public function test_failed_rotation_and_empty_finish_do_not_report_success(): void {
			$this->seed(); $this->db->fail_write=true; $result=$this->invoke('post_result'); self::assertSame(503,$result['status']); self::assertSame('',$result['secret']); self::assertSame([],$this->audit->events);
			$_POST['pow_account_action']='finish_rotation'; self::assertSame(409,$this->invoke('post_result')['status']);
		}
		public function test_confirmed_rotation_survives_diagnostic_and_unlock_failure(): void {
			$this->seed(); $this->audit->fail=true; $this->db->release_error=true; $result=$this->invoke('post_result'); self::assertSame(200,$result['status']); self::assertTrue(''!==$result['secret']);
			self::assertSame(Secrets::SLOT_CURRENT,$this->registry->verify_secret($this->registry->find(20),$result['secret']));
		}
		public function test_hourly_user_and_ip_limits_share_actions_and_preserve_low_limits(): void {
			foreach ([0=>5,1=>1,2=>2,3=>3,4=>4,5=>5,6=>6,7=>7,8=>8,9=>9] as $configured=>$expected) {
				$GLOBALS['pow_test_transients']=[]; $GLOBALS['pow_test_transient_expirations']=[]; $this->tab=$this->make_tab($configured);
				for($i=0;$i<$expected;$i++) { $_POST['pow_account_action']=['rotate','finish_rotation','deactivate'][$i%3]; self::assertSame(403,$this->invoke('post_result')['status']); }
				self::assertSame(429,$this->invoke('post_result')['status']);
				foreach($GLOBALS['pow_test_transient_expirations'] as $times) { self::assertSame([3600],array_values(array_unique($times))); }
				$GLOBALS['pow_test_current_user_id']=8; $GLOBALS['pow_test_users'][8]=(object)['ID'=>8,'roles'=>['customer'],'allcaps'=>['read'=>true]];
				self::assertSame(429,$this->invoke('post_result')['status']); $GLOBALS['pow_test_current_user_id']=7;
			}
		}
		public function test_submission_whitelists_fields_and_defaults_blank_sender(): void {
			$_POST += ['name'=>'Example','from_domain'=>'NetworkID','from_identity'=>'BUYER','sender_domain'=>'','sender_identity'=>'','status'=>'active','owner_user_id'=>99,'secret'=>'forged','mode'=>'dual_exit']; $_POST['pow_account_action']='submit';
			$result=$this->invoke('post_result'); self::assertSame(200,$result['status']);
			self::assertSame(7,$this->db->rows[0]['owner_user_id']); self::assertSame('pending',$this->db->rows[0]['status']); self::assertSame('BUYER',$this->db->rows[0]['sender_identity']); self::assertSame('',$this->db->rows[0]['secret_current']); self::assertFalse(isset($this->db->rows[0]['mode']));
		}
		public function test_typed_submission_and_lookup_error_do_not_create_application(): void {
			$_POST['pow_account_action']='submit'; $_POST['name']=[]; self::assertSame(400,$this->invoke('post_result')['status']); self::assertSame([],$this->db->writes);
			$_POST['pow_account_action']='rotate'; $this->db->fail_lookup=true; $result=$this->invoke('post_result'); self::assertSame(503,$result['status']); self::assertStringNotContainsString('private',json_encode($result));
		}
		public function test_application_name_avoids_native_wordpress_query_var(): void {
			$_POST=['pow_account_action'=>'submit','_wpnonce'=>'account-nonce','pow_name'=>'Company','from_domain'=>'NetworkID','from_identity'=>'BUYER'];
			self::assertSame('Company',$this->invoke('posted_fields')['name']);
			ob_start(); $this->tab->render(); $html=ob_get_clean();
			self::assertStringContainsString('name="pow_name"',$html);
			self::assertStringNotContainsString('name="name"',$html);
		}
		public function test_menu_excludes_provisioned_and_disabled_accounts(): void {
			$items=['orders'=>'Orders','customer-logout'=>'Log out']; self::assertSame(['orders','punchout-integration','customer-logout'],array_keys($this->tab->add_menu_item($items)));
			$GLOBALS['pow_test_users'][7]->roles=['punchout_buyer']; self::assertSame($items,$this->tab->add_menu_item($items)); ob_start(); $this->tab->render(); self::assertSame('',ob_get_clean());
		}
		public function test_credentials_are_projected_without_sealed_slots(): void {
			$this->seed(); $view=$this->invoke('view_vars'); self::assertSame('active',$view['state']); self::assertSame('',$view['secret']); self::assertFalse(isset($view['partner'])); self::assertStringNotContainsString($this->db->rows[0]['secret_current'],json_encode($view));
		}
		public function test_confirmed_post_result_survives_view_and_link_lookup_failure(): void {
			$this->seed(); $result=$this->invoke('post_result'); self::assertSame(200,$result['status']);
			$this->db->fail_lookup=true; $GLOBALS['pow_account_test']['fail_pages']=true;
			$view=$this->invoke('result_vars',$result);
			self::assertSame('unavailable',$view['state']); self::assertSame($result['secret'],$view['secret']);
			$html=Templates::render('account/integration',$view);
			self::assertStringContainsString($result['secret'],$html); self::assertStringNotContainsString('value="rotate"',$html);
			self::assertStringNotContainsString('private page failure',$html);
		}
		public function test_get_after_rotation_does_not_reveal_plaintext_or_slots(): void {
			$this->seed(); $result=$this->invoke('post_result'); self::assertSame(200,$result['status']); $_SERVER['REQUEST_METHOD']='GET';
			$view=$this->invoke('view_vars'); self::assertSame('',$view['secret']); self::assertTrue($view['rotation_open']);
			ob_start(); $this->tab->render(); $html=ob_get_clean();
			self::assertStringNotContainsString($result['secret'],$html); self::assertStringContainsString('value="finish_rotation"',$html);
		}
		public function test_direct_response_header_contract(): void {
			$this->invoke('private_headers');
			self::assertSame(1,$GLOBALS['pow_test_nocache_headers']);
			self::assertSame(['Cache-Control: no-store, private, max-age=0','Referrer-Policy: no-referrer'],$GLOBALS['pow_account_test']['headers']);
		}
		public function test_immediate_deactivation_uses_current_owner_and_revokes_credentials(): void {
			$this->seed(); $_POST['pow_account_action']='deactivate'; $_POST['partner_id']=999; $_POST['owner_user_id']=8;
			$result=$this->invoke('post_result'); self::assertSame(200,$result['status']); self::assertSame('',$result['secret']);
			$p=$this->registry->find(20); self::assertSame('disabled',$p->status); self::assertSame('',$p->secret_current); self::assertSame('',$p->secret_previous);
			self::assertSame('registration_deactivated',$this->audit->events[1][0]); self::assertSame(7,$this->audit->events[1][1]['user_id']);
		}
		public function test_partial_deactivation_reports_failure_while_remaining_disabled(): void {
			$this->seed(); $_POST['pow_account_action']='deactivate'; $this->db->fail_sessions=true;
			$result=$this->invoke('post_result'); self::assertSame(503,$result['status']); self::assertSame('disabled',$this->registry->find(20)->status);
			self::assertSame('error',$result['notice']['type']); self::assertStringNotContainsString('private',json_encode($result));
		}
		public function test_disabled_connection_and_changed_actor_cannot_rotate(): void {
			$this->seed(['status'=>'disabled']); self::assertSame(403,$this->invoke('post_result')['status']);
			$this->seed(); $this->db->on_lock=function() {$GLOBALS['pow_test_users'][7]->roles=['punchout_buyer'];};
			self::assertSame(403,$this->invoke('post_result')['status']); self::assertSame([],$this->db->writes);
		}
		public function test_another_owners_connection_is_not_in_view(): void {
			$this->seed(['owner_user_id'=>8]); $view=$this->invoke('view_vars');
			self::assertSame('none',$view['state']); self::assertSame([],$view['connection']);
			self::assertStringNotContainsString('Example Company',Templates::render('account/integration',$view));
		}
		public function test_endpoint_hooks_are_registered_even_when_feature_is_disabled(): void {
			$GLOBALS['pow_test_options']['pow_settings']['enabled']='no'; $this->tab=$this->make_tab(); $this->tab->register();
			$hooks=$GLOBALS['pow_account_test']['hooks'];
			foreach (['init'=>'add_endpoint','query_vars'=>'add_query_var','woocommerce_account_menu_items'=>'add_menu_item','woocommerce_account_punchout-integration_endpoint'=>'render','template_redirect'=>'handle_post'] as $hook=>$method) { self::assertSame([$this->tab,$method],$hooks[$hook]); }
			self::assertSame(['orders','punchout-integration'],$this->tab->add_query_var(['orders','punchout-integration']));
			self::assertSame(['orders','punchout-integration'],$this->tab->add_query_var(['orders']));
			self::assertSame([$this->tab,'add_wc_query_var'],$hooks['woocommerce_get_query_vars']);
			self::assertSame(['orders'=>'orders','punchout-integration'=>'punchout-integration'],$this->tab->add_wc_query_var(['orders'=>'orders']));
			self::assertSame([],$this->tab->add_menu_item([])); ob_start(); $this->tab->render(); self::assertSame('',ob_get_clean());
		}
	}
}
