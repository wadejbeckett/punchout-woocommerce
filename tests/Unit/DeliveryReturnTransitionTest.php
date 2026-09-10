<?php
/** Real Store transition SQL against an exact-predicate recorder, not native database evidence. */
declare( strict_types = 1 );
use PHPUnit\Framework\TestCase;
use POW\Sessions\Store;
require_once dirname(__DIR__).'/Support/return-database.php';

final class DeliveryReturnTransitionTest extends TestCase {
	private ReturnDatabase $db;
	private mixed $previous;
	protected function setUp():void{$this->previous=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=$this->db=new ReturnDatabase();$this->db->session+=['expires'=>gmdate('Y-m-d H:i:s',time()+3600),'delivery_choice'=>'{"key":"Depot O\'Neil"}','delivery_confirmation'=>'{"review":"Accepted"}'];}
	protected function tearDown():void{if(null===$this->previous){unset($GLOBALS['wpdb']);}else{$GLOBALS['wpdb']=$this->previous;}}
	private function guard(array $overrides=[]):array{return array_replace(['user_id'=>99,'wp_session_token'=>'test-login','delivery_choice'=>$this->db->session['delivery_choice'],'delivery_confirmation'=>$this->db->session['delivery_confirmation']],$overrides);}
	private function transition(?array $guard=null):bool{return (new Store())->transition(42,'active','returned',[],$guard??$this->guard());}
	public function test_exact_raw_pair_and_login_select_one_winner():void{self::assertTrue($this->transition());self::assertSame(1,$this->db->guarded_updates);self::assertFalse($this->transition());}
	public function test_case_whitespace_and_null_differences_cannot_match():void{foreach([['delivery_choice'=>'{"key":"depot O\'Neil"}'],['delivery_confirmation'=>'{"review":"accepted"}'],['delivery_confirmation'=>'{"review":"Accepted"} '],['delivery_choice'=>null],['user_id'=>100],['wp_session_token'=>'Test-login']] as $changes){self::assertFalse($this->transition($this->guard($changes)));self::assertSame('active',$this->db->session['status']);}}
	public function test_virtual_sql_null_is_distinct_from_empty_text():void{$this->db->session['delivery_choice']=null;$guard=$this->guard();$this->db->session['delivery_choice']='';self::assertFalse($this->transition($guard));$this->db->session['delivery_choice']=null;self::assertTrue($this->transition($guard));self::assertStringContainsString('delivery_choice IS NULL',end($this->db->queries));}
	public function test_expired_active_session_cannot_win():void{$this->db->session['expires']=gmdate('Y-m-d H:i:s',time()-1);self::assertFalse($this->transition());}
	public function test_absent_or_malformed_expected_confirmation_refuses_before_sql():void{foreach([[],['user_id'=>'99'],['wp_session_token'=>''],['delivery_confirmation'=>null],['delivery_confirmation'=>''],['delivery_choice'=>[]]] as $changes){$guard=[]===$changes?[]:$this->guard($changes);self::assertFalse($this->transition($guard));}self::assertSame([],$this->db->queries);}
	public function test_raw_change_immediately_before_update_cannot_win():void{$guard=$this->guard();$this->db->before_transition=function(){$this->db->session['delivery_confirmation']=null;};self::assertFalse($this->transition($guard));self::assertSame('active',$this->db->session['status']);}
	public function test_legacy_transition_without_guard_retains_its_contract():void{self::assertTrue((new Store())->transition(42,'active','ordered'));self::assertSame('ordered',$this->db->session['status']);self::assertSame(0,$this->db->guarded_updates);}
	private function recovery_store():Store{return new class extends Store {public array $destroyed=[];public function destroy_login_checked(\POW\Sessions\Session $s):bool{$this->destroyed[]=[$s->user_id,$s->wp_session_token];return true;}};}
	public function test_active_recovery_cas_revokes_only_verified_exact_login():void{
		$store=$this->recovery_store();$session=$store->find(42);$this->db->session['expires']='2000-01-01 00:00:00';
		self::assertTrue($store->expire_active_login_locked($session));self::assertSame('expired',$this->db->session['status']);self::assertSame([[99,'test-login']],$store->destroyed);
		self::assertFalse($store->expire_active_login_locked($session));self::assertCount(1,$store->destroyed);
	}
	public function test_recovery_preserves_paid_winner_between_read_and_sql():void{
		$store=$this->recovery_store();$session=$store->find(42);$this->db->before_transition=function(){$this->db->session['status']='ordered';$this->db->session['order_id']=314;};
		self::assertFalse($store->expire_active_login_locked($session));self::assertSame('ordered',$this->db->session['status']);self::assertSame(314,$this->db->session['order_id']);self::assertSame([],$store->destroyed);
	}
	public function test_recovery_never_expires_terminal_or_replacement_login():void{
		$store=$this->recovery_store();$session=$store->find(42);$original=$this->db->session;
		foreach([['status'=>'ordered'],['status'=>'returned'],['status'=>'closed'],['wp_session_token'=>'Test-login'],['wp_session_token'=>'test-login '],['user_id'=>100],['partner_id'=>8]] as $change){$this->db->session=array_replace($original,$change);self::assertFalse($store->expire_active_login_locked($session));self::assertSame(array_replace($original,$change),$this->db->session);}
		self::assertSame([],$store->destroyed);
	}
	public function test_recovery_failed_readback_does_not_authorize_token_cleanup():void{
		$store=$this->recovery_store();$session=$store->find(42);$this->db->after_transition=function(){$this->db->session['wp_session_token']='replacement';};
		self::assertFalse($store->expire_active_login_locked($session));self::assertSame([],$store->destroyed);
	}
	public function test_recovery_failed_update_does_not_authorize_token_cleanup():void{$store=$this->recovery_store();$this->db->transition_fails=true;self::assertFalse($store->expire_active_login_locked($store->find(42)));self::assertSame([],$store->destroyed);self::assertSame('active',$this->db->session['status']);}

}
