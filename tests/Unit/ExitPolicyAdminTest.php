<?php
/** Exit policy admin presentation and HTTP gates, using the existing admin I/O owner. @package POW */
declare(strict_types=1);
namespace {
require_once dirname(__DIR__).'/Admin/doubles.php';
final class ExitPolicyAdminTest extends PHPUnit\Framework\TestCase {
 private array $saved=[];
 private POW\Partners\Registry $registry;
 private AdminAudit $audit;
 protected function setUp(): void {
  foreach(['wpdb','pow_admin_test','pow_test_current_user_id','pow_test_users','pow_test_user_meta','pow_test_options','pow_test_valid_nonce','_GET','_POST','_SERVER'] as $key) { $this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];unset($GLOBALS[$key]); }
  $GLOBALS['pow_test_current_user_id']=1; $GLOBALS['pow_admin_test']=['headers'=>[]]; $GLOBALS['pow_test_user_meta']=[];
  $GLOBALS['pow_test_users']=[1=>(object)['ID'=>1,'roles'=>['administrator'],'allcaps'=>['manage_woocommerce'=>true,'read'=>true]],20=>(object)['ID'=>20,'roles'=>['customer'],'allcaps'=>['read'=>true]]];
  $GLOBALS['wpdb']=new AdminDatabase(); $GLOBALS['wpdb']->rows=[['id'=>20,'status'=>'active','owner_user_id'=>20,'mode'=>'dual_exit','exit_policy'=>'inherit']];
  $this->registry=new POW\Partners\Registry(new POW\Partners\Secrets(str_repeat('x',32))); $this->audit=new AdminAudit();
  $_GET=['tab'=>'partners','action'=>'edit','partner'=>20]; $_POST=['partner'=>'20','buyer'=>'30','exit_policy'=>'inherit','_wpnonce'=>'valid']; $_SERVER['REQUEST_METHOD']='POST'; $GLOBALS['pow_test_valid_nonce']='valid';
 }
 protected function tearDown(): void {foreach($this->saved as $key=>[$exists,$value]){if($exists)$GLOBALS[$key]=$value;else unset($GLOBALS[$key]);}}
 public function test_company_form_exposes_one_punchout_only_switch_and_buyer_restrictions(): void {
  ob_start(); try { (new POW\Admin\Page(new POW\Settings(),$this->registry,$this->audit))->render(); $html=ob_get_contents(); } finally { ob_end_clean(); }
  foreach(['name="exit_policy"','value="punchout_only"','value="punchout_and_checkout"','PunchOut only','Normal checkout is available','Buyer restrictions'] as $text) self::assertStringContainsString($text,$html);
  self::assertStringNotContainsString('value="inherit"',$html);
  self::assertStringNotContainsString('name="mode"',$html);
 }
 public function test_settings_screen_has_no_global_checkout_policy(): void {
  $page=new POW\Admin\Page(new POW\Settings(),$this->registry,$this->audit);
  self::assertFalse(array_key_exists('exit_policy',$page->sanitize_settings(['exit_policy'=>'punchout_only'])));
 }
 public function test_buyer_handler_has_post_nonce_actual_admin_gates(): void {
  $a=new POW\Admin\Actions($this->registry,$this->audit,new POW\Partners\Registration($this->registry,new POW\Sessions\Store(),$this->audit));
  self::assertTrue(method_exists($a,'save_buyer_exit'),'Buyer restriction HTTP handler must exist');
  foreach(['get','nonce','owner','structured'] as $case) {
   $_SERVER['REQUEST_METHOD']=$case==='get'?'GET':'POST'; $_POST['_wpnonce']=$case==='nonce'?'bad':'valid'; $_POST['exit_policy']=$case==='structured'?[]:'inherit'; $GLOBALS['pow_test_current_user_id']=$case==='owner'?20:1;
   try { $a->save_buyer_exit(); self::fail('Missing rejection'); } catch(AdminResponse $r) { self::assertSame($case==='get'?405:($case==='structured'?400:403),$r->args['response']); }
  }
 }
}
}
