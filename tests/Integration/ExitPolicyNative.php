<?php
/**
 * Opt-in exit-policy native acceptance, using real WP/Woo hooks, metadata, SQL and REST dispatch.
 * Rationale: standalone doubles cannot establish migration preservation, native checkout/payment vetoes or persistent buyer isolation.
 * Coordinator must grant exclusive ownership of a disposable local fixture before running.
 * Enable the plugin master switch in a separate process before this test boots WordPress; restore the original option after this process exits.
 * POW_NATIVE_TESTS=disposable POW_NATIVE_SCRIPT=<this file> wp --user=<fixture-admin> eval 'require getenv("POW_NATIVE_SCRIPT");'
 * POW_EXIT_RESULT optionally names a private result JSON for independent-process readback. Fixtures remain for inspection.
 * No gateway transport: the payment sentinel stops before any real gateway. External mail/HTTP capture must already be installed.
 * @package POW @license AGPL-3.0-or-later
 */
declare(strict_types=1);
if (!defined('WP_CLI') || !WP_CLI || getenv('POW_NATIVE_TESTS') !== 'disposable' || wp_get_environment_type() !== 'local' || !current_user_can('manage_woocommerce') || !function_exists('WC') || !has_filter('pre_wp_mail') || !has_filter('pre_http_request') || function_exists('mail')) {
 throw new RuntimeException('Requires opted-in disposable local WP/Woo, admin and existing outbound guards.');
}
// Updating settings below cannot register callbacks that were skipped during boot.
$pay_exit_registered = false;
global $wp_filter;
foreach (($wp_filter['woocommerce_payment_complete']->callbacks ?? []) as $callbacks) {
 foreach ($callbacks as $callback) {
  $handler = $callback['function'] ?? null;
  if (is_array($handler) && ($handler[0] ?? null) instanceof POW\Checkout\PayExit && ($handler[1] ?? null) === 'payment_complete') { $pay_exit_registered = true; }
 }
}
if (!POW\Plugin::instance()->enabled() || !$pay_exit_registered) {
 throw new RuntimeException('Enable PunchOut before WordPress boots this fixture so the native payment callback is registered.');
}
final class ExitNativeResponse extends RuntimeException { public function __construct(public array $args=[]) { parent::__construct('Native response intercepted'); } }
final class ExitPolicyNative {
 private POW\Plugin $plugin;
 private POW\Partners\Registry $registry;
 private POW\Checkout\ExitPolicy $policy;
 private int $admin;
 private int $passed=0;
 private array $original;
 private array $fixture=[];
 public function __construct() { $this->plugin=POW\Plugin::instance(); $this->registry=$this->plugin->registry(); $this->admin=get_current_user_id(); $this->original=get_option(POW\Settings::OPTION_KEY,[]); }
 private function check(bool $yes,string $label): void { if(!$yes)throw new RuntimeException('FAIL '.$label); ++$this->passed; echo 'PASS '.$label."\n"; }
 private function user(string $role): int { $s=bin2hex(random_bytes(8)); $id=wp_insert_user(['user_login'=>'exit-'.$s,'user_email'=>$s.'@example.invalid','user_pass'=>wp_generate_password(),'role'=>$role]); if(is_wp_error($id))throw new RuntimeException('Fixture user creation failed'); return (int)$id; }
 private function login(int $id): void {
  wp_set_current_user($id); $token=WP_Session_Tokens::get_instance($id)->create(time()+3600); $_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($id,time()+3600,'logged_in',$token);
  foreach(['session_resolved'=>false,'current_session'=>null,'settings'=>new POW\Settings()] as $key=>$v) (new ReflectionProperty($this->plugin,$key))->setValue($this->plugin,$v);
 }
 private function settings(string $value): void { update_option(POW\Settings::OPTION_KEY,array_replace($this->original,['enabled'=>'yes','exit_policy'=>$value]),false); (new ReflectionProperty($this->plugin,'settings'))->setValue($this->plugin,new POW\Settings()); }
 private function company(string $value): void { wp_set_current_user($this->admin); $this->check($this->registry->update($this->fixture['partner'],['exit_policy'=>$value]),'company policy checked write '.$value); }
 private function live_buyer(int $id): void {
  $this->login($id); $sid=$this->plugin->sessions()->create(['partner_id'=>$this->fixture['partner'],'user_id'=>$id,'status'=>'active','wp_session_token'=>wp_get_session_token(),'browser_form_post_url'=>'https://receiver.example.invalid/return','buyer_cookie'=>'exit-fixture','payload_id'=>bin2hex(random_bytes(12)),'one_time_token_hash'=>bin2hex(random_bytes(32)),'body_hash'=>bin2hex(random_bytes(32)),'expires'=>gmdate('Y-m-d H:i:s',time()+3600),'cart_ready'=>1]);
  if(!$sid)throw new RuntimeException('Fixture session creation failed'); $this->fixture['session']=$sid;
 }
 private function denied(callable $fn,string $label): void { try{$fn();}catch(Throwable $e){$this->check($e instanceof ExitNativeResponse || str_contains($e->getMessage(),'Checkout is not available'),$label); return;} throw new RuntimeException('FAIL missing veto '.$label); }
 /** Persist corrupt option states deliberately, then restore the exact native row before any other fixture work. */
 private function global_state_checks(int $partner_id,int $buyer): void {
  global $wpdb;
  $name=POW\Settings::OPTION_KEY;
  $row=$wpdb->get_row($wpdb->prepare('SELECT option_value, autoload FROM '.$wpdb->options.' WHERE option_name = %s',$name),ARRAY_A);
  if(!is_array($row) || $wpdb->last_error!=='')throw new RuntimeException('Native option snapshot unavailable');
  $settings=new POW\Settings();$policy=new POW\Checkout\ExitPolicy($settings,$this->registry);$company=$this->registry->find($partner_id);
  $this->check($company->exit_policy==='punchout_and_checkout' && $policy->buyer_value($partner_id,$buyer)==='inherit','invalid-global probe starts with explicit company checkout and buyer inherit');
  $clear=static function()use($name){wp_cache_delete($name,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');};
  try {
   foreach(['unknown'=>['exit_policy'=>'unknown'],'array'=>['exit_policy'=>[]],'null'=>['exit_policy'=>null],'malformed option'=>'malformed-option','null option'=>null] as $label=>$stored) {
    // Serialize null explicitly so native option decoding returns null rather than an SQL empty-string coercion.
    if(false===$wpdb->update($wpdb->options,['option_value'=>serialize($stored)],['option_name'=>$name]))throw new RuntimeException('Native option fault setup failed');$clear();
    $this->check(get_option($name,['missing'])===$stored,'native option fault readback '.$label);
    $this->check(!POW\Checkout\ExitPolicy::valid($settings->exit_policy()) && $policy->effective($company,$buyer)==='punchout_and_checkout','retired invalid global cannot narrow explicit company checkout '.$label);
   }
   foreach(['inherit','punchout_only','punchout_and_checkout'] as $value) {
    if(false===$wpdb->update($wpdb->options,['option_value'=>serialize(['exit_policy'=>$value])],['option_name'=>$name]))throw new RuntimeException('Native valid option setup failed');$clear();
    $this->check($settings->exit_policy()===$value && $policy->effective($company,$buyer)==='punchout_and_checkout','native valid global '.$value.' retains explicit company override');
   }
   if(false===$wpdb->update($wpdb->options,['option_value'=>serialize([])],['option_name'=>$name]))throw new RuntimeException('Native missing key setup failed');$clear();
   $this->check($settings->exit_policy()==='inherit','native absent policy key is valid secure inheritance');
   if(false===$wpdb->update(POW\Installer::partners_table(),['exit_policy'=>'inherit'],['id'=>$partner_id]))throw new RuntimeException('Native legacy inheritance setup failed');
   $this->check($policy->effective($company,$buyer)==='punchout_only','native absent policy key resolves securely for an inheriting company');
   if(!$this->registry->update($partner_id,['exit_policy'=>'punchout_and_checkout']))throw new RuntimeException('Native explicit company restore failed');
   $this->check($policy->effective($company,$buyer)==='punchout_and_checkout','native valid absent key preserves explicit company override');
   // get_option returns its default after SQL errors; repeated reads must not reuse a false "missing option" cache entry.
   if(false===$wpdb->update($wpdb->options,['option_value'=>serialize(['exit_policy'=>'punchout_and_checkout']),'autoload'=>'off'],['option_name'=>$name]))throw new RuntimeException('Native option read-fault setup failed');$clear();
   $lookup=$wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1",$name);$faults=0;
   $fail_read=static function($sql)use($lookup,&$faults){if($sql!==$lookup)return $sql;++$faults;return str_replace('SELECT option_value','SELECT pow_exit_missing_option_column',$sql);};
   $suppressed=$wpdb->suppress_errors(true);add_filter('query',$fail_read);
   try {
    foreach([1,2] as $attempt)$this->check($settings->exit_policy()==='' && $faults===$attempt,'native unavailable option remains invalid on read '.$attempt);
    $this->check($policy->effective($company,$buyer)==='punchout_and_checkout' && $faults===2,'retired SQL-unavailable global cannot narrow explicit company checkout');
   } finally {remove_filter('query',$fail_read);$wpdb->suppress_errors($suppressed);$clear();}

  } finally {
   if(false===$wpdb->update($wpdb->options,$row,['option_name'=>$name]))throw new RuntimeException('Native option restoration failed');$clear();
   $restored=$wpdb->get_row($wpdb->prepare('SELECT option_value, autoload FROM '.$wpdb->options.' WHERE option_name = %s',$name),ARRAY_A);
   if($restored!==$row || $wpdb->last_error!=='')throw new RuntimeException('Native option restoration unconfirmed');
   if(!$this->registry->update($partner_id,['exit_policy'=>$company->exit_policy]))throw new RuntimeException('Native company policy restoration unconfirmed');
  }
 }
 public function suite(): void {
  global $wpdb,$wp;
  $die=static fn()=>static function($message,$title='',$args=[]){throw new ExitNativeResponse((array)$args);}; add_filter('wp_die_handler',$die,PHP_INT_MAX);
  try {
   $this->check(class_exists(POW\Checkout\ExitPolicy::class),'candidate exit service exists');
   $before=[]; foreach([POW\Installer::partners_table(),POW\Installer::sessions_table(),POW\Installer::log_table()] as $table) $before[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);
   $old_version=get_option(POW\Installer::DB_VERSION_KEY); POW\Installer::maybe_upgrade();
   $this->check('6'===get_option(POW\Installer::DB_VERSION_KEY),'actual schema six installed');
   foreach($before as $table=>$rows) { $after=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A); foreach($after as &$row) { if($table===POW\Installer::partners_table())unset($row['exit_policy']); } unset($row); $expected=$rows; foreach($expected as &$row)unset($row['exit_policy']); unset($row); $this->check($expected===$after,'prior rows preserved '.substr($table,strlen($wpdb->prefix))); }
   if((int)$old_version<5) { $bad=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".POW\Installer::partners_table()." WHERE exit_policy <> CASE WHEN mode = 'dual_exit' THEN 'punchout_and_checkout' ELSE 'punchout_only' END"); $this->check(0===$bad,'legacy mode equivalence migrated for every existing company'); }
   $this->policy=new POW\Checkout\ExitPolicy(new POW\Settings(),$this->registry);
   $owner=$this->user('customer');$buyer=$this->user(POW\Installer::ROLE);$other=$this->user(POW\Installer::ROLE);$suffix=bin2hex(random_bytes(8));
   $id=$this->registry->insert(['name'=>'Example exit company','status'=>'active','owner_user_id'=>$owner,'sender_domain'=>'NetworkID','sender_identity'=>'exit-'.$suffix,'from_domain'=>'NetworkID','from_identity'=>'buyer-'.$suffix,'to_domain'=>'NetworkID','to_identity'=>'supplier','mode'=>'dual_exit']);
   $this->fixture=['partner'=>$id,'buyer'=>$buyer,'other'=>$other,'owner'=>$owner]; update_user_meta($buyer,'_pow_partner_id',$id);update_user_meta($other,'_pow_partner_id',$id);
   $this->check($id>0 && 'punchout_and_checkout'===$this->registry->find($id)->exit_policy,'new companies default to explicit checkout regardless of old mode field');
   POW\Installer::activate(); $this->check('punchout_and_checkout'===$this->registry->find($id)->exit_policy,'reactivation preserves explicit checkout');
   $this->settings('punchout_only');
   if(false===$wpdb->update(POW\Installer::partners_table(),['exit_policy'=>'inherit'],['id'=>$id]))throw new RuntimeException('Native inherited freeze setup failed');
   POW\Installer::freeze_inherited_exit_policies(); POW\Installer::freeze_inherited_exit_policies();
   $this->check('punchout_only'===$this->registry->find($id)->exit_policy,'native inherited denial freezes idempotently');
   $this->settings('punchout_and_checkout');
   if(false===$wpdb->update(POW\Installer::partners_table(),['exit_policy'=>'inherit'],['id'=>$id]))throw new RuntimeException('Native failed freeze setup failed');
   $table=POW\Installer::partners_table();$fail_freeze=static fn($sql)=>str_starts_with($sql,'UPDATE '.$table.' SET exit_policy')?str_replace('exit_policy =','pow_missing_exit_policy =',$sql):$sql;add_filter('query',$fail_freeze);
   $failed=false;try{POW\Installer::freeze_inherited_exit_policies();}catch(RuntimeException $e){$failed=true;}finally{remove_filter('query',$fail_freeze);}
   $this->check($failed && 'inherit'===$this->registry->find($id)->exit_policy,'failed native freeze leaves inherited row and cannot complete');
   POW\Installer::freeze_inherited_exit_policies();$this->check('punchout_and_checkout'===$this->registry->find($id)->exit_policy,'native freeze retry uses the prior effective checkout cap');
   foreach(['inherit','punchout_only','punchout_and_checkout'] as $g) foreach(['punchout_only','punchout_and_checkout'] as $c) {
    $this->settings($g); wp_set_current_user($this->admin); if(!$this->registry->update($id,['exit_policy'=>$c]))throw new RuntimeException('Matrix configuration failed');
    foreach(['inherit','punchout_only','punchout_and_checkout'] as $b) { update_user_meta($buyer,'_pow_exit_policy_'.$id,$b); $want=POW\Checkout\ExitPolicy::resolve($g,$c,$b);$this->check($want===$this->policy->effective($this->registry->find($id),$buyer),'native matrix '.$g.'/'.$c.'/'.$b); }
   }
   if(false===$wpdb->update(POW\Installer::partners_table(),['exit_policy'=>'inherit'],['id'=>$id]))throw new RuntimeException('Native unmigrated policy setup failed');
   $this->check('punchout_only'===$this->policy->effective($this->registry->find($id),$buyer),'native unmigrated company fails closed');
   $this->company('punchout_and_checkout');
   update_user_meta($buyer,'_pow_exit_policy_'.$id,'inherit');$this->global_state_checks($id,$buyer);
   $this->settings('punchout_and_checkout'); $this->company('punchout_and_checkout'); update_user_meta($buyer,'_pow_exit_policy_'.$id,'inherit');
   $this->check(true===$this->policy->save_buyer($id,$this->admin,$buyer,'punchout_only'),'native administrator restriction saves');
   $this->check(true===$this->policy->save_buyer($id,$this->admin,$buyer,'punchout_only'),'native verified no-op saves');
   $this->check('punchout_and_checkout'===$this->policy->effective($this->registry->find($id),$other),'other buyer remains independent');
   wp_set_current_user($owner); $this->check(is_wp_error($this->policy->save_buyer($id,$this->admin,$buyer,'inherit')),'forged admin actor refused'); $this->check(!$this->registry->update($id,['exit_policy'=>'punchout_and_checkout']),'owner cannot grant company entitlement');
   wp_set_current_user($buyer); $this->check(is_wp_error($this->policy->save_buyer($id,$buyer,$buyer,'punchout_and_checkout')),'buyer self grant refused');
   wp_set_current_user($this->admin); $this->company('punchout_only'); $this->check(is_wp_error($this->policy->save_buyer($id,$this->admin,$buyer,'punchout_and_checkout')),'native company cap prevents stored grant');
   $this->company('punchout_and_checkout'); $key='_pow_exit_policy_'.$id;
   $false=static fn($check,$uid,$meta)=>$uid===$buyer && $meta===$key ? false : $check;
   add_filter('update_user_metadata',$false,10,3); $this->check(is_wp_error($this->policy->save_buyer($id,$this->admin,$buyer,'inherit')),'false metadata write refused'); remove_filter('update_user_metadata',$false,10);
   $lie=static fn($check,$uid,$meta)=>$uid===$buyer && $meta===$key ? true : $check;
   add_filter('update_user_metadata',$lie,10,3); $this->check(is_wp_error($this->policy->save_buyer($id,$this->admin,$buyer,'inherit')),'lying metadata acknowledgement refused'); remove_filter('update_user_metadata',$lie,10);
   $this->check(true===$this->policy->save_buyer($id,$this->admin,$buyer,'inherit'),'buyer restriction clears through checked native save');
   $snapshot=$this->registry->find($id);
   update_user_meta($buyer,$key,'unknown');$this->check('punchout_only'===$this->policy->effective($snapshot,$buyer),'native invalid buyer policy fails closed');update_user_meta($buyer,$key,'inherit');
   add_user_meta($buyer,$key,'inherit');$this->check('punchout_only'===$this->policy->effective($snapshot,$buyer),'native duplicate override fails closed');
   $duplicates=$wpdb->get_col($wpdb->prepare('SELECT umeta_id FROM '.$wpdb->usermeta.' WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id',$buyer,$key));delete_metadata_by_mid('user',(int)$duplicates[1]);
   update_user_meta($buyer,'_pow_partner_id',$id+1000000);$this->check('punchout_only'===$this->policy->effective($snapshot,$buyer),'native changed association fails closed');update_user_meta($buyer,'_pow_partner_id',$id);
   update_user_meta($buyer,'_pow_deactivated','1');$this->check('punchout_only'===$this->policy->effective($snapshot,$buyer),'native deactivated membership fails closed');update_user_meta($buyer,'_pow_deactivated','0');
   $this->check('punchout_only'===$this->policy->effective(POW\Partners\Partner::from_row(['id'=>$id+1000000]),$buyer),'native missing partner fails closed');
   // Real admin forms use the same nonce and actual-actor boundary as the persistence service.
   $_SERVER['REQUEST_METHOD']='POST';$_POST=['partner'=>(string)$id,'buyer'=>(string)$buyer,'exit_policy'=>'punchout_only','_wpnonce'=>wp_create_nonce('pow_buyer_exit_'.$id)];
   $redirect=static function($url){throw new ExitNativeResponse(['redirect'=>$url]);};add_filter('wp_redirect',$redirect,-9999);
   try{do_action('admin_post_pow_save_buyer_exit');}catch(ExitNativeResponse $e){$this->check(isset($e->args['redirect']),'native admin restriction POST completes');}finally{remove_filter('wp_redirect',$redirect,-9999);}
   $this->check('punchout_only'===$this->policy->buyer_value($id,$buyer),'native admin POST persisted restriction');
   foreach(['GET','nonce','owner'] as $case){$_SERVER['REQUEST_METHOD']=$case==='GET'?'GET':'POST';$_POST['_wpnonce']=$case==='nonce'?'bad':wp_create_nonce('pow_buyer_exit_'.$id);if($case==='owner')wp_set_current_user($owner);try{do_action('admin_post_pow_save_buyer_exit');throw new RuntimeException('Missing admin veto');}catch(ExitNativeResponse $e){$this->check(($e->args['response']??0)===($case==='GET'?405:403),'native admin '.$case.' veto');}wp_set_current_user($this->admin);}
   $_GET=['tab'=>'partners','action'=>'edit','partner'=>$id];ob_start();(new POW\Admin\Page(new POW\Settings(),$this->registry,$this->plugin->audit()))->render();$admin_html=ob_get_clean();
   $this->check(str_contains($admin_html,'pow_save_buyer_exit') && str_contains($admin_html,'value="'.$buyer.'"') && str_contains($admin_html,'value="'.$other.'"'),'native admin form lists existing company buyers with restriction controls');$_GET=[];
   $_POST=[];$_SERVER['REQUEST_METHOD']='GET';$this->check(true===$this->policy->save_buyer($id,$this->admin,$buyer,'inherit'),'native restore buyer inherit');
   $this->live_buyer($buyer); WC()->initialize_session(); WC()->initialize_cart(); WC()->customer=new WC_Customer($buyer);
   $cart_exits=do_shortcode('[punchout_cart_exits]');$this->check(str_contains($cart_exits,wc_get_checkout_url()) && str_contains($cart_exits,'pow-return-form'),'native BOTH cart control renders checkout and purchasing exits');
   $order=wc_create_order(['customer_id'=>$buyer,'status'=>'pending']); $order->set_total(10); $order->update_meta_data('_pow_session',(string)$this->fixture['session']);$order->update_meta_data('_pow_partner',(string)$id);$order->save();$this->fixture['order']=$order->get_id();
   $guard=new POW\RouteGuard($this->plugin,$this->registry,new POW\Settings());
   $this->check($guard->checkout_allowed($order),'native active exact-login own unpaid payment allowed');
   $order->set_customer_id($other);$this->check(!$guard->checkout_allowed($order),'another buyers order denied');$order->set_customer_id($buyer);
   $this->company('punchout_only');wp_set_current_user($buyer);
   $cart_exits=do_shortcode('[punchout_cart_exits]');$this->check(!str_contains($cart_exits,wc_get_checkout_url()) && str_contains($cart_exits,'pow-return-form'),'native PunchOut-only cart control renders only the purchasing exit');
   $this->check(!$guard->checkout_allowed($order),'tightening blocks existing unpaid order on fresh read');
   wc_clear_notices(); do_action('woocommerce_checkout_process'); $this->check(wc_notice_count('error')>0,'classic forged checkout POST validation veto'); wc_clear_notices();
   $this->denied(fn()=>do_action('woocommerce_checkout_create_order',$order),'classic server order creation veto');
   $this->denied(fn()=>do_action('woocommerce_checkout_order_processed',$order->get_id()),'classic final pre-payment veto');
   $this->denied(fn()=>do_action('woocommerce_store_api_checkout_update_order_from_request',$order,new WP_REST_Request('POST','/wc/store/v1/checkout')),'Store API request hook veto');
   $this->denied(fn()=>do_action('woocommerce_store_api_checkout_order_processed',$order),'Store API final pre-payment veto');
   $context=new Automattic\WooCommerce\StoreApi\Payments\PaymentContext();$context->set_order($order);$result=new Automattic\WooCommerce\StoreApi\Payments\PaymentResult();
   $this->denied(function()use($context,&$result){do_action_ref_array('woocommerce_rest_checkout_process_payment_with_context',[$context,&$result]);},'Store API payment context veto before gateway');
   $wp->query_vars['order-pay']=$order->get_id();$_GET['key']=$order->get_order_key();$_POST=['woocommerce_pay'=>'1'];$_REQUEST['woocommerce-pay-nonce']=wp_create_nonce('woocommerce-pay');
   $level=ob_get_level();$refused=false;try{WC_Form_Handler::pay_action();}catch(ExitNativeResponse $e){$refused=($e->args['response']??0)===403;}finally{while(ob_get_level()>$level)ob_end_clean();}$this->check($refused,'native direct order-pay handler returns 403 before gateway');unset($_GET['key']);$_POST=[];$_REQUEST=[];
   // Native create_order executes the actual hook and translates the policy exception into a WP_Error.
   $created=WC()->checkout()->create_order(['billing_email'=>'buyer@example.invalid','payment_method'=>'','shipping_method'=>[]]);
   $this->check(is_wp_error($created),'native WC_Checkout create_order returns error before order persistence');
   $product=new WC_Product_Simple();$product->set_name('Example virtual item');$product->set_regular_price('10');$product->set_virtual(true);$product->set_status('publish');$product->save();WC()->cart->add_to_cart($product->get_id());WC()->cart->calculate_totals();
   $request=new WP_REST_Request('POST','/wc/store/v1/checkout');$request->set_header('Nonce',wp_create_nonce('wc_store_api'));$request->set_body_params(['billing_address'=>['first_name'=>'Example','last_name'=>'Buyer','email'=>'buyer@example.invalid','address_1'=>'1 Example Street','city'=>'Johannesburg','state'=>'GP','postcode'=>'2000','country'=>'ZA','phone'=>'0115550100'],'payment_method'=>'']);
   $response=rest_do_request($request);$body=$response->get_data();echo 'REST status '.$response->get_status().' code '.($body['code']??'none').' message '.($body['message']??'none')."\n";$this->check($response->get_status()===403 && str_contains(wp_json_encode($body),'pow_requisition_only'),'actual Store API REST checkout submission returns policy denial');
   $pay_request=new WP_REST_Request('POST','/wc/store/v1/checkout/'.$order->get_id());$pay_request->set_header('Nonce',wp_create_nonce('wc_store_api'));$pay_request->set_body_params(['billing_address'=>$request->get_param('billing_address'),'key'=>$order->get_order_key(),'billing_email'=>$order->get_billing_email(),'payment_method'=>'']);$pay_response=rest_do_request($pay_request);$pay_body=$pay_response->get_data();echo 'Pay REST status '.$pay_response->get_status().' code '.($pay_body['code']??'none').' message '.($pay_body['message']??'none')."\n";
   $this->check($pay_response->get_status()===403 && str_contains(wp_json_encode($pay_response->get_data()),'pow_requisition_only'),'actual Store API order payment endpoint denies tightened policy');
   $this->check(!$order->is_paid() && !$this->plugin->sessions()->find($this->fixture['session'])->order_id,'denied payment leaves native order unpaid and session unlinked');
   $order->payment_complete();$paid_session=$this->plugin->sessions()->find($this->fixture['session']);$this->check($paid_session->status==='ordered' && $paid_session->order_id===$order->get_id(),'native late payment notification retains same buyer paid closeout lifecycle');(new ReflectionProperty($this->plugin,'session_resolved'))->setValue($this->plugin,false);
   ob_start();do_action('woocommerce_thankyou',$order->get_id());$html=ob_get_clean();$this->check(str_contains($html,'punchout/return'),'same buyer paid closeout remains visible after tightening');
   $this->check(!$guard->checkout_allowed($order),'paid closeout cannot reopen payment');
   $this->login($other);ob_start();do_action('woocommerce_thankyou',$order->get_id());$html=ob_get_clean();$this->check(!str_contains($html,'punchout/return'),'other buyer cannot see paid closeout');
   $this->login($owner);$this->check($guard->checkout_allowed(),'ordinary shoppers remain native');$ordinary_exits=do_shortcode('[punchout_cart_exits]');$this->check(str_contains($ordinary_exits,wc_get_checkout_url()) && !str_contains($ordinary_exits,'pow-return-form'),'ordinary native cart control renders checkout only');$this->denied(fn()=>do_action('woocommerce_before_pay_action',$order),'ordinary actor cannot pay tagged punchout order');
   wp_set_current_user($this->admin);$this->check(true===$this->policy->save_buyer($id,$this->admin,$buyer,'punchout_only'),'persist final restriction for restart readback');
   $before_reset=$this->registry->find($id);$reg=new POW\Partners\Registration($this->registry,$this->plugin->sessions(),$this->plugin->audit());$identity=[];foreach(['from_domain','from_identity','sender_domain','sender_identity','to_domain','to_identity'] as $field)$identity[$field]=$before_reset->$field;$secret=$reg->reset($id,$identity,$this->admin);
   $after_reset=$this->registry->find($id);$this->check($secret!=='' && $after_reset->exit_policy===$before_reset->exit_policy && $after_reset->owner_user_id===$owner && $this->policy->buyer_value($id,$buyer)==='punchout_only','native connection reset preserves company cap, owner and buyer restriction');unset($secret);
   if(getenv('POW_EXIT_RESULT'))file_put_contents(getenv('POW_EXIT_RESULT'),wp_json_encode($this->fixture));
   echo 'Native ExitPolicy: '.$this->passed." passed, 0 failed, 0 skipped\n";
  } finally { wp_set_current_user($this->admin);update_option(POW\Settings::OPTION_KEY,$this->original,false);remove_filter('wp_die_handler',$die,PHP_INT_MAX); }
 }
}
ob_start();
try { (new ExitPolicyNative())->suite(); } finally { ob_end_flush(); }
