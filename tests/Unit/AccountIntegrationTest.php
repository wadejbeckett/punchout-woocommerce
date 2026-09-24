<?php
/** Account controller/template unit checks; native acceptance is coordinated separately. */
declare( strict_types = 1 );

namespace POW\Account {
	// Namespace-local I/O doubles do not claim global WordPress/WooCommerce functions.
	function is_account_page(): bool { return isset($GLOBALS['pow_account_test']) ? $GLOBALS['pow_account_test']['account'] : \is_account_page(); }
	function is_wc_endpoint_url( string $endpoint ): bool { return isset($GLOBALS['pow_account_test']) ? $endpoint === $GLOBALS['pow_account_test']['endpoint'] : \is_wc_endpoint_url($endpoint); }
	// Nonces are action-derived and checked against their action, so the download form's nonce cannot stand in for the reset form's.
	function wp_create_nonce( string $action ): string { return isset($GLOBALS['pow_account_test']) ? 'nonce-' . $action : \wp_create_nonce($action); }
	function wp_verify_nonce( string $nonce, string $action ): int|false { return isset($GLOBALS['pow_account_test']) ? ( $nonce === wp_create_nonce($action) ? 1 : false ) : \wp_verify_nonce($nonce,$action); }
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
	use POW\Partners\{Registry, Secrets};
	use POW\Sessions\Session;
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
				if ( str_contains( $sql, 'WHERE id =' ) && $row['id'] === $args[0] ) { return $row; }
				if ( str_contains( $sql, 'WHERE sender_domain' ) && $row['sender_domain'] === $args[0] && $row['sender_identity'] === $args[1] ) { return $row; }
			}
			return null;
		}
		public function get_results( string $key, string $format ): array {
			[$sql, $args] = $this->queries[$key];
			// The owner lookup asks for two rows deliberately: a second connection
			// sharing the account is an ambiguity the registry must refuse.
			if ( str_contains( $sql, 'WHERE owner_user_id' ) ) {
				$this->last_error = $this->fail_lookup ? 'private lookup error' : '';
				if ( $this->fail_lookup ) { return []; }
				return array_slice( array_values( array_filter( $this->rows, static fn( array $row ): bool => $row['owner_user_id'] === $args[0] ) ), 0, 2 );
			}
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
	/**
	 * The tab is one read-only surface: the setup-XML download for the
	 * account that holds an active connection, and nothing else. Every
	 * management action moved to the admin screens, so what is proved here
	 * is the download, the boundary that refuses it, and the absence of
	 * the removed surface.
	 */
	final class AccountIntegrationTest extends TestCase {
		private const VISIT_KEY = 'pow_1a2b3c4d5e6f708192a3b4c5d6e7';
		private const DOWNLOAD_NONCE = 'nonce-' . IntegrationTab::NONCE;
		private const RESET_NONCE = 'nonce-' . IntegrationTab::RESET_NONCE;
		private array $saved = [];
		private AccountDatabase $db;
		private AccountAudit $audit;
		private Registry $registry;
		private POW\Plugin $plugin;
		private IntegrationTab $tab;
		protected function setUp(): void {
			self::assertTrue( class_exists( IntegrationTab::class ), 'Account controller is not implemented' );
			foreach (['wpdb','pow_account_test','pow_test_current_user_id','pow_test_users','pow_test_user_meta','pow_test_options','pow_test_transients','pow_test_transient_expirations','pow_test_valid_nonce','pow_test_mail','pow_test_nocache_headers','pow_test_status_headers','_SERVER','_POST'] as $key) {
				$this->saved[$key] = [array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null]; unset($GLOBALS[$key]);
			}
			$GLOBALS['pow_account_test'] = ['account'=>true,'endpoint'=>'punchout-integration','headers'=>[], 'pages'=>[(object)['ID'=>12,'post_content'=>'[punchout_docs]']]];
			$GLOBALS['pow_test_options'] = ['pow_settings'=>['enabled'=>'yes']];
			$GLOBALS['pow_test_current_user_id'] = 7;
			$GLOBALS['pow_test_users'][7] = (object)['ID'=>7,'roles'=>['customer'],'allcaps'=>['read'=>true]];
			$_SERVER = ['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'192.0.2.7'];
			$_POST = ['pow_account_action'=>'download_setup_template','_wpnonce'=>self::DOWNLOAD_NONCE];
			$GLOBALS['wpdb'] = $this->db = new AccountDatabase();
			$this->registry = new Registry( new Secrets(str_repeat('a',32)) );
			$this->audit = new AccountAudit();
			$this->plugin = (new ReflectionClass(POW\Plugin::class))->newInstanceWithoutConstructor();
			$this->tab = $this->make_tab();
		}
		protected function tearDown(): void { foreach ($this->saved as $key=>[$exists,$value]) { if ($exists) {$GLOBALS[$key]=$value;} else {unset($GLOBALS[$key]);} } }
		private function make_tab(): IntegrationTab { return new IntegrationTab($this->plugin,$this->registry,$this->audit); }
		/** The request is inside a visit of this very account: the tab must vanish, not merely refuse to write. */
		private function enter_visit(): void {
			(new ReflectionProperty(POW\Plugin::class,'current_session'))->setValue($this->plugin,Session::from_row(['id'=>81,'partner_id'=>20,'user_id'=>7,'status'=>Session::ACTIVE,'wc_session_key'=>self::VISIT_KEY]));
			(new ReflectionProperty(POW\Plugin::class,'session_resolved'))->setValue($this->plugin,true);
		}
		private function invoke( string $method, mixed ...$args ): mixed { return (new ReflectionMethod($this->tab,$method))->invoke($this->tab,...$args); }
		private function seed( array $overrides = [] ): void {
			$this->db->rows = [array_replace(['id'=>20,'owner_user_id'=>7,'name'=>'Example Company','status'=>'active','from_domain'=>'NetworkID','from_identity'=>'EXAMPLE','sender_domain'=>'NetworkID','sender_identity'=>'EXAMPLE','to_domain'=>'NetworkID','to_identity'=>'STORE','secret_current'=>(new Secrets(str_repeat('a',32)))->seal('old-secret'),'secret_previous'=>''],$overrides)];
		}
		private function view( array $overrides = [] ): array {
			return array_replace(['state'=>'none','connection'=>[],'notice'=>null,'setup_url'=>'https://shop.example.test/punchout/setup','last_setup'=>null,'action_url'=>'https://shop.example.test/account/punchout-integration/','docs_url'=>'https://shop.example.test/integration-guide/','nonce'=>self::DOWNLOAD_NONCE,'template_ready'=>false],$overrides);
		}
		public function test_template_names_the_identity_fields_and_offers_no_management_control(): void {
			self::assertSame('account/integration',Templates::normalise_name('account/integration'));
			$html = Templates::render('account/integration', $this->view());
			foreach (['UserEmail','UniqueUsername','UniqueName','Contact/Email','purchasing system','integration-guide'] as $text) { self::assertStringContainsString($text,$html); }
			foreach (['Request connection','name="owner_user_id"','name="pow_name"','value="submit"','<form'] as $gone) { self::assertStringNotContainsString($gone,$html); }
		}
		public function test_template_escapes_values_and_shows_no_secret_or_management_action(): void {
			$html = Templates::render('account/integration',$this->view(['state'=>'active','connection'=>['name'=>'<img>','from'=>'<from>','sender'=>'<sender>','to'=>'<to>','deployment_mode'=>'<test>','cxml_version'=>'1.2.008','return_encoding'=>'base64']]));
			self::assertStringContainsString('&lt;img&gt;',$html);
			self::assertStringNotContainsString('<img>',$html);
			foreach (['Deactivate connection now','Rotate secret','Finish rotation','Shared secret','value="deactivate"','value="rotate"','value="finish_rotation"'] as $gone) { self::assertStringNotContainsString($gone,$html); }
		}
		public function test_template_states_expose_only_the_download(): void {
			foreach (['none','pending','disabled','unavailable'] as $state) {
				$html = Templates::render('account/integration',$this->view(['state'=>$state,'template_ready'=>true]));
				self::assertStringNotContainsString('<form',$html,$state);
				self::assertStringNotContainsString('value="download_setup_template"',$html,$state);
			}
			$html = Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>true]));
			self::assertSame(1,substr_count($html,'<form'));
			self::assertStringContainsString('value="download_setup_template"',$html);
		}
		public function test_download_button_appears_only_for_an_active_connection_whose_template_can_be_built(): void {
			$html = Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>false]));
			self::assertStringNotContainsString('value="download_setup_template"',$html);
			self::assertStringContainsString('No setup template yet',$html);
			$html = Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>true]));
			self::assertStringContainsString('value="download_setup_template"',$html);
			self::assertStringNotContainsString('No setup template yet',$html);
		}
		public function test_view_reports_whether_the_template_can_be_built(): void {
			$this->seed(); self::assertTrue($this->invoke('view_vars')['template_ready']);
			$this->seed(['from_domain'=>'','from_identity'=>'','to_domain'=>'','to_identity'=>'']); self::assertFalse($this->invoke('view_vars')['template_ready']);
			$this->seed(['status'=>'pending']); self::assertFalse($this->invoke('view_vars')['template_ready']);
		}
		public function test_download_of_a_half_configured_connection_names_the_missing_fields_instead_of_asking_for_a_reload(): void {
			$this->seed(['from_domain'=>'','from_identity'=>'']);
			$result=$this->invoke('template_download_result');
			self::assertSame(409,$result['status']);
			self::assertStringContainsString('From, To and Sender identities',$result['notice']['text']);
			self::assertStringNotContainsString('try again',$result['notice']['text']);
			self::assertSame('',$result['xml']);
		}
		public function test_download_emission_sends_an_xml_attachment_on_success_and_the_page_with_a_notice_otherwise(): void {
			$this->seed();
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
			$_POST['partner_id']=20;
			$this->seed(['owner_user_id'=>8]); self::assertSame(403,$this->invoke('template_download_result')['status']);
			$this->seed(['status'=>'pending']); self::assertSame(403,$this->invoke('template_download_result')['status']);
			$this->seed(); $_POST['_wpnonce']='forged'; self::assertSame(403,$this->invoke('template_download_result')['status']);
			self::assertSame([],$this->db->writes);
		}
		public function test_request_boundaries_refuse_before_lookup_or_mutation(): void {
			foreach (['method','endpoint','account','nonce','nonce_missing','nonce_array','action_array','unknown','anonymous','capability','disabled','visit'] as $case) {
				$_SERVER['REQUEST_METHOD']='POST'; $_POST=['pow_account_action'=>'download_setup_template','_wpnonce'=>self::DOWNLOAD_NONCE];
				$GLOBALS['pow_account_test']['account']=true; $GLOBALS['pow_account_test']['endpoint']='punchout-integration';
				$GLOBALS['pow_test_current_user_id']=7; $GLOBALS['pow_test_users'][7]->allcaps=['read'=>true];
				$GLOBALS['pow_test_user_meta']=[]; $GLOBALS['pow_test_options']['pow_settings']['enabled']='yes';
				$this->plugin=(new ReflectionClass(POW\Plugin::class))->newInstanceWithoutConstructor(); $this->tab=$this->make_tab(); $this->seed();
				match($case) {
					'method'=>$_SERVER['REQUEST_METHOD']='GET', 'endpoint'=>$GLOBALS['pow_account_test']['endpoint']='orders','account'=>$GLOBALS['pow_account_test']['account']=false,
					'nonce'=>$_POST['_wpnonce']='bad','nonce_missing'=>$_POST['_wpnonce']=null,'nonce_array'=>$_POST['_wpnonce']=[], 'action_array'=>$_POST['pow_account_action']=[], 'unknown'=>$_POST['pow_account_action']='rotate',
					'anonymous'=>$GLOBALS['pow_test_current_user_id']=0,
					'capability'=>$GLOBALS['pow_test_users'][7]->allcaps=[], 'disabled'=>$GLOBALS['pow_test_options']['pow_settings']['enabled']='no',
					'visit'=>$this->enter_visit()
				};
				$result=$this->invoke('template_download_result'); self::assertTrue(null===$result || $result['status']>=400,$case);
				self::assertSame('',$result['xml'] ?? '',$case);
				self::assertSame([],$this->db->writes); self::assertSame([],$GLOBALS['pow_test_transients'] ?? []);
			}
		}
		public function test_owner_is_resolved_on_server_and_owner_zero_is_not_claimable(): void {
			foreach ([0,8] as $owner) { $this->seed(['owner_user_id'=>$owner]); $_POST['partner_id']=20; $_POST['owner_user_id']=$owner; self::assertSame(403,$this->invoke('template_download_result')['status']); }
			self::assertSame([],$this->db->writes);
		}
		public function test_credentials_are_projected_without_sealed_slots_or_any_secret(): void {
			$this->seed(); $view=$this->invoke('view_vars'); self::assertSame('active',$view['state']);
			self::assertFalse(isset($view['secret'])); self::assertFalse(isset($view['rotation_open'])); self::assertFalse(isset($view['delivery_addresses'])); self::assertFalse(isset($view['partner']));
			self::assertStringNotContainsString($this->db->rows[0]['secret_current'],json_encode($view));
		}
		public function test_refused_download_page_survives_view_and_link_lookup_failure(): void {
			$this->seed(['owner_user_id'=>8]); $result=$this->invoke('template_download_result'); self::assertSame(403,$result['status']);
			$this->db->fail_lookup=true; $GLOBALS['pow_account_test']['fail_pages']=true;
			$view=$this->invoke('result_vars',$result);
			self::assertSame('unavailable',$view['state']);
			$html=Templates::render('account/integration',$view);
			self::assertStringContainsString('cannot be managed from this account',$html);
			self::assertStringNotContainsString('private page failure',$html);
			self::assertStringNotContainsString('<form',$html);
		}
		public function test_direct_response_header_contract(): void {
			$this->invoke('private_headers');
			self::assertSame(1,$GLOBALS['pow_test_nocache_headers']);
			self::assertSame(['Cache-Control: no-store, private, max-age=0','Referrer-Policy: no-referrer'],$GLOBALS['pow_account_test']['headers']);
		}
		/** The owner reads which My Account pages the store opened for its visits; it cannot change them here. */
		public function test_summary_names_the_account_pages_a_visit_may_open(): void {
			$this->seed(['visit_endpoints'=>'bulkorder,purchase-lists']); $view=$this->invoke('view_vars');
			self::assertSame('bulkorder, purchase-lists',$view['connection']['visit_endpoints']);
			$html=Templates::render('account/integration',$view);
			self::assertStringContainsString('My Account pages open during a visit',$html); self::assertStringContainsString('<code>bulkorder, purchase-lists</code>',$html);
			self::assertStringNotContainsString('name="visit_endpoints"',$html);
			$this->seed(); $html=Templates::render('account/integration',$this->invoke('view_vars'));
			self::assertStringContainsString('My Account pages open during a visit</th><td><code>None</code>',$html);
		}
		public function test_another_owners_connection_is_not_in_view(): void {
			$this->seed(['owner_user_id'=>8]); $view=$this->invoke('view_vars');
			self::assertSame('none',$view['state']); self::assertSame([],$view['connection']);
			self::assertStringNotContainsString('Example Company',Templates::render('account/integration',$view));
		}
		/** Model item 6: the tab does not exist inside a visit, and an unanswerable visit lookup refuses too. */
		public function test_menu_and_render_are_suppressed_during_a_live_visit_and_when_disabled(): void {
			$items=['orders'=>'Orders','customer-logout'=>'Log out'];
			$this->seed();
			self::assertSame(['orders','punchout-integration','customer-logout'],array_keys($this->tab->add_menu_item($items)));
			$this->enter_visit();
			self::assertSame($items,$this->tab->add_menu_item($items));
			ob_start(); $this->tab->render(); self::assertSame('',ob_get_clean());
			self::assertSame(403,$this->invoke('template_download_result')['status']);
			$this->plugin=(new ReflectionClass(POW\Plugin::class))->newInstanceWithoutConstructor(); $this->tab=$this->make_tab();
			$GLOBALS['pow_test_options']['pow_settings']['enabled']='no';
			self::assertSame($items,$this->tab->add_menu_item($items));
			ob_start(); $this->tab->render(); self::assertSame('',ob_get_clean());
		}
		/** The self-service surface is gone as code, not merely unreachable. */
		public function test_no_action_handler_survives_beside_the_download(): void {
			foreach (['post_result','rotation_result','posted_fields','refused'] as $gone) { self::assertFalse(method_exists($this->tab,$gone),$gone); }
			$this->seed(); $_POST=['pow_account_action'=>'rotate','_wpnonce'=>self::DOWNLOAD_NONCE];
			ob_start(); try { $this->tab->handle_post(); } finally { self::assertSame('',ob_get_clean()); }
			self::assertSame([],$this->db->writes); self::assertSame([],$this->audit->events);
		}
		/** The opt-in reset: a second, separate form, only when the view says the connection exposes it. */
		public function test_reset_form_renders_only_when_the_connection_exposes_it(): void {
			$html = Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>true,'can_reset'=>true,'reset_nonce'=>self::RESET_NONCE]));
			self::assertSame(2,substr_count($html,'<form'));
			self::assertStringContainsString('value="reset_connection"',$html);
			self::assertStringContainsString('name="pow_confirm_reset" value="1" required',$html);
			self::assertStringContainsString('<h3>Reset connection</h3>',$html);
			self::assertStringNotContainsString('New shared secret',$html);
			foreach (['none','pending','disabled','unavailable'] as $state) {
				self::assertStringNotContainsString('value="reset_connection"',Templates::render('account/integration',$this->view(['state'=>$state,'can_reset'=>true,'reset_nonce'=>self::RESET_NONCE])),$state);
			}
			self::assertStringNotContainsString('value="reset_connection"',Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>true,'can_reset'=>false])));
		}
		public function test_view_exposes_reset_only_for_an_opted_in_active_owner(): void {
			$this->seed(['owner_settings'=>'reset_connection']); $view=$this->invoke('view_vars');
			self::assertTrue($view['can_reset']); self::assertSame(self::RESET_NONCE,$view['reset_nonce']); self::assertSame(self::DOWNLOAD_NONCE,$view['nonce']); self::assertSame('',$view['issued_secret']);
			$this->seed(['owner_settings'=>'']); self::assertFalse($this->invoke('view_vars')['can_reset']);
			$this->seed(); self::assertFalse($this->invoke('view_vars')['can_reset']);
			$this->seed(['owner_settings'=>'reset_connection','status'=>'disabled']); self::assertFalse($this->invoke('view_vars')['can_reset']);
			$this->seed(['owner_settings'=>'reset_connection','owner_user_id'=>8]); self::assertFalse($this->invoke('view_vars')['can_reset']);
		}
		private function reset_post( array $extra = [] ): void { $_POST = array_replace(['pow_account_action'=>'reset_connection','_wpnonce'=>self::RESET_NONCE,'pow_confirm_reset'=>'1'],$extra); }
		public function test_reset_post_without_the_permission_produces_nothing(): void {
			foreach ([[],['owner_settings'=>''],['owner_settings'=>'reset_connection','owner_user_id'=>8],['owner_settings'=>'reset_connection','status'=>'disabled'],['owner_settings'=>'reset_connection','status'=>'pending']] as $case) {
				$this->seed($case); $this->reset_post();
				self::assertNull($this->invoke('owner_reset_result'),json_encode($case));
				ob_start(); try { $this->tab->handle_post(); } finally { self::assertSame('',ob_get_clean()); }
			}
			$this->seed(['owner_settings'=>'reset_connection']); $this->reset_post(['pow_account_action'=>'download_setup_template']);
			self::assertNull($this->invoke('owner_reset_result'),'Another action is not a reset');
			$this->db->fail_lookup=true; $this->reset_post();
			self::assertNull($this->invoke('owner_reset_result'),'An unanswerable lookup refuses silently');
			self::assertSame([],$this->db->writes); self::assertSame([],$this->audit->events); self::assertSame([],$GLOBALS['pow_test_mail'] ?? []);
		}
		public function test_reset_is_refused_inside_a_visit(): void {
			$this->seed(['owner_settings'=>'reset_connection']); $this->reset_post(); $this->enter_visit();
			self::assertNull($this->invoke('owner_reset_result'));
			ob_start(); $this->tab->render(); self::assertSame('',ob_get_clean());
			ob_start(); try { $this->tab->handle_post(); } finally { self::assertSame('',ob_get_clean()); }
			self::assertSame([],$this->db->writes); self::assertSame([],$this->audit->events);
		}
		public function test_reset_needs_the_confirmation_tick_and_its_own_nonce(): void {
			$this->seed(['owner_settings'=>'reset_connection']);
			foreach ([['pow_confirm_reset'=>null],['pow_confirm_reset'=>'0'],['pow_confirm_reset'=>['1']]] as $case) {
				$this->reset_post($case); $result=$this->invoke('owner_reset_result');
				self::assertSame(400,$result['status']); self::assertSame('Tick the box to confirm the reset, then try again.',$result['notice']['text']); self::assertSame('',$result['secret']);
			}
			foreach ([['_wpnonce'=>'forged'],['_wpnonce'=>null],['_wpnonce'=>[self::RESET_NONCE]]] as $case) {
				$this->reset_post($case); $result=$this->invoke('owner_reset_result');
				self::assertSame(403,$result['status']); self::assertSame('This reset could not be authorised. Reload the integration page and try again.',$result['notice']['text']);
			}
			self::assertSame([],$this->db->writes); self::assertSame([],$this->audit->events);
		}
		/** The download form's nonce is a valid nonce of this tab, but for another action: it must not authorise a reset. */
		public function test_reset_refuses_the_download_forms_nonce(): void {
			$this->seed(['owner_settings'=>'reset_connection']);
			$download = POW\Account\wp_create_nonce(IntegrationTab::NONCE);
			self::assertSame($this->invoke('view_vars')['nonce'],$download,'This is the nonce the download form posts');
			self::assertNotSame(POW\Account\wp_create_nonce(IntegrationTab::RESET_NONCE),$download);
			$this->reset_post(['_wpnonce'=>$download]); $result=$this->invoke('owner_reset_result');
			self::assertSame(403,$result['status']); self::assertSame('This reset could not be authorised. Reload the integration page and try again.',$result['notice']['text']); self::assertSame('',$result['secret']);
			self::assertSame([],$this->db->writes); self::assertSame([],$this->audit->events); self::assertSame([],$GLOBALS['pow_test_mail'] ?? []);
		}
		public function test_reset_emission_shows_the_secret_once(): void {
			$this->seed(['owner_settings'=>'reset_connection']);
			$reset=$this->invoke('reset_result',200,'Connection reset. The previous shared secret and every open punchout visit have been revoked. Copy the new shared secret now: it is shown only once. Paste it into your purchasing system in place of the old one.','s3cr<t&');
			$emission=$this->invoke('reset_emission',$reset);
			self::assertSame(200,$emission['status']);
			self::assertStringContainsString('<code>s3cr&lt;t&amp;</code>',$emission['body']);
			self::assertStringNotContainsString('s3cr<t&',$emission['body']);
			self::assertStringContainsString('shown only once',$emission['body']);
			self::assertStringContainsString('New shared secret',$emission['body']);
			$failed=$this->invoke('reset_emission',$this->invoke('reset_result',409,'The reset did not complete and no new secret was issued. The connection may now be disabled; contact the store.'));
			self::assertSame(409,$failed['status']); self::assertStringNotContainsString('New shared secret',$failed['body']); self::assertStringContainsString('no new secret was issued',$failed['body']);
			self::assertStringNotContainsString('New shared secret',Templates::render('account/integration',$this->invoke('view_vars')));
			self::assertStringNotContainsString('New shared secret',Templates::render('account/integration',$this->view(['state'=>'active','template_ready'=>true,'can_reset'=>true])));
		}
		/** With the flag off the tab is byte-for-byte today's markup: the new view keys render nothing. */
		public function test_default_markup_is_unchanged_by_the_reset_keys(): void {
			$this->seed(); $view=$this->invoke('view_vars');
			$plain=array_diff_key($view,array_flip(['can_reset','reset_nonce','issued_secret']));
			self::assertSame(Templates::render('account/integration',$plain),Templates::render('account/integration',$view));
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
