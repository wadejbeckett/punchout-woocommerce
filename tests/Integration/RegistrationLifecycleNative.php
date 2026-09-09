<?php
/**
 * Opt-in native registration lifecycle regressions; creates invented local fixtures.
 * Run with a bootstrapped plugin in a disposable local WordPress installation:
 * POW_NATIVE_TESTS=disposable wp --user=<fixture-admin> eval-file tests/Integration/RegistrationLifecycleNative.php
 * Set POW_NATIVE_CASES=credential-recovery for only compound recovery cases and the existing release regression.
 * No unit stubs, transports, secret output or fixture deletion.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) ) {
	throw new RuntimeException( 'Requires an opted-in disposable local WordPress CLI and a capable fixture administrator.' );
}
global $wpdb, $r, $store, $log, $svc;
$GLOBALS['pow_native_registration_admin'] = get_current_user_id();
function t4_native_admin(): int { return $GLOBALS['pow_native_registration_admin']; }
$r=POW\Plugin::instance()->registry();$store=new POW\Sessions\Store();$log=new POW\Audit\Log(new POW\Logger(new POW\Settings()));$svc=new POW\Partners\Registration($r,$store,$log);wp_set_current_user(t4_native_admin());
$GLOBALS['pow_native_registration_pass']=0;$GLOBALS['pow_native_registration_fail']=0;
function t4_native_check($v){if(!$v)throw new RuntimeException('assertion');}
function t4_native_case($name,$fn){if('credential-recovery'===getenv('POW_NATIVE_CASES')&&!str_starts_with($name,'credential recovery ')&&!str_starts_with($name,'native lock release failure'))return;try{$fn();echo "PASS $name\n";$GLOBALS['pow_native_registration_pass']++;}catch(Throwable $e){echo "FAIL $name ".get_class($e)."\n";$GLOBALS['pow_native_registration_fail']++;}}
function t4_native_fixture($pending=false){global $r,$svc; $suffix=bin2hex(random_bytes(6));$owner=wp_insert_user(['user_login'=>'fault-owner-'.$suffix,'user_email'=>'owner-'.$suffix.'@example.invalid','user_pass'=>wp_generate_password(),'role'=>'customer']);$buyer=wp_insert_user(['user_login'=>'fault-buyer-'.$suffix,'user_email'=>'buyer-'.$suffix.'@example.invalid','user_pass'=>wp_generate_password(),'role'=>POW\Installer::ROLE]);$data=['name'=>'Fault fixture','status'=>'pending','owner_user_id'=>$owner,'from_domain'=>'NetworkID','from_identity'=>'buyer-'.$suffix,'sender_domain'=>'NetworkID','sender_identity'=>'buyer-'.$suffix,'to_domain'=>'NetworkID','to_identity'=>'supplier','company_profile'=>'{"book":"preserve"}'];$id=$r->insert($data);update_user_meta($buyer,'_pow_partner_id',$id);$secret=$pending?'':$svc->approve($id,t4_native_admin());return compact('id','owner','buyer','data','secret');}
function t4_native_login_row($f,$status='active'){global $store;$t=WP_Session_Tokens::get_instance($f['buyer'])->create(time()+3600);$id=$store->create(['partner_id'=>$f['id'],'user_id'=>$f['buyer'],'status'=>$status,'payload_id'=>bin2hex(random_bytes(10)),'one_time_token_hash'=>hash('sha256',random_bytes(32)),'wp_session_token'=>$t,'expires'=>gmdate('Y-m-d H:i:s',time()+3600)]);return $store->find($id);}
function t4_native_filtered($hook,$filter,$fn,$priority=10,$args=1){add_filter($hook,$filter,$priority,$args);try{return $fn();}finally{remove_filter($hook,$filter,$priority);}}
foreach ( [ 'approve', 'reset' ] as $action ) {
	foreach ( [ 'false', 'throw' ] as $read_mode ) {
		foreach ( [ 'false', 'throw' ] as $write_mode ) {
			foreach ( [ 'allowed', 'write_failed', 'read_failed' ] as $recovery ) {
				t4_native_case( "credential recovery $action read-$read_mode compensation-$write_mode recovery-$recovery", function () use ( $action, $read_mode, $write_mode, $recovery, $r, $svc, $wpdb ) {
					$f = t4_native_fixture( 'approve' === $action );
					$before = $r->find( $f['id'] );
					$table = POW\Installer::partners_table();
					$key = 'pow_partner_' . substr( hash( 'sha256', DB_NAME . '|' . $table . '|' . $f['id'] ), 0, 52 );
					$phase = '';
					$events = [];
					$locked = true;
					$filter = function ( $q ) use ( &$phase, &$events, &$locked, $key, $table, $read_mode, $write_mode, $recovery, $wpdb ) {
						$is_update = str_starts_with( $q, 'UPDATE `' . $table . '`' );
						$is_read = str_starts_with( $q, 'SELECT * FROM ' . $table . ' WHERE id =' );
						if ( $is_update && '' === $phase && str_contains( explode( ' WHERE ', $q )[0], "`status` = 'active'" ) && str_contains( $q, 'secret_current' ) ) {
							$phase = 'activation';
							$events[] = 'activation_write';
							return $q;
						}
						if ( $is_read && 'activation' === $phase ) {
							$phase = 'compensation';
							$events[] = 'activation_read';
							if ( 'throw' === $read_mode ) { throw new RuntimeException( 'injected private read detail' ); }
							return 'SELECT * FROM t4_missing_recovery_read';
						}
						if ( $is_update && 'compensation' === $phase ) {
							$phase = 'restoration_read';
							$events[] = 'compensation_write';
							if ( 'throw' === $write_mode ) { throw new RuntimeException( 'injected private compensation detail' ); }
							return '';
						}
						if ( $is_read && 'restoration_read' === $phase ) {
							$events[] = 'restoration_read';
							$phase = 'recovery';
						}
						if ( $is_update && 'recovery' === $phase ) {
							$events[] = 'recovery_write';
							$phase = 'recovery_read';
							$locked = $locked && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $key ) );
							if ( 'write_failed' === $recovery ) {
								if ( 'throw' === $write_mode ) { throw new RuntimeException( 'injected private recovery detail' ); }
								return '';
							}
						}
						if ( $is_read && 'recovery_read' === $phase ) {
							$events[] = 'recovery_read';
							$phase = 'done';
							$locked = $locked && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $key ) );
							if ( 'read_failed' === $recovery ) {
								if ( 'throw' === $read_mode ) { throw new RuntimeException( 'injected private verification detail' ); }
								return 'SELECT * FROM t4_missing_recovery_read';
							}
						}
						return $q;
					};
					$secret = t4_native_filtered( 'query', $filter, fn() => 'approve' === $action ? $svc->approve( $f['id'], t4_native_admin() ) : $svc->reset( $f['id'], $f['data'], t4_native_admin() ) );
					$p = $r->find( $f['id'] );
					t4_native_check( '' === $secret && $locked && [ 'activation_write', 'activation_read', 'compensation_write', 'restoration_read', 'recovery_write', 'recovery_read' ] === $events );
					if ( 'write_failed' === $recovery ) {
						// This fault deliberately leaves the write unavailable; the report must not claim safety.
						t4_native_check( $p->is_active() && '' !== $p->secret_current );
					} else {
						t4_native_check( 'disabled' === $p->status && '' === $p->secret_current && '' === $p->secret_previous );
					}
					t4_native_check( $before->owner_user_id === $p->owner_user_id && $before->company_profile === $p->company_profile );
					$event = 'approve' === $action ? 'registration_approval_failed' : 'registration_reset_failed';
					$detail = $wpdb->get_var( $wpdb->prepare( 'SELECT detail FROM ' . POW\Installer::log_table() . ' WHERE partner_id = %d AND event = %s ORDER BY id DESC LIMIT 1', $f['id'], $event ) );
					$decoded = json_decode( (string) $detail, true );
					t4_native_check( ( 'allowed' === $recovery ? 'recovery_confirmed_disabled' : 'recovery_unconfirmed' ) === ( $decoded['reason'] ?? '' ) && ! str_contains( (string) $detail, 'private' ) );
				} );
			}
		}
	}
}
foreach(['zero','false','throw'] as $mode)t4_native_case('approval '.$mode.' write preserves pending empty slots',function()use($mode,$r,$svc){$f=t4_native_fixture(true);$filter=function($q)use($mode){if(str_starts_with($q,'UPDATE `'.POW\Installer::partners_table().'`')&&str_contains($q,'secret_current')){if($mode==='throw')throw new RuntimeException('injected');return $mode==='zero'?$q.' AND 0=1':'';}return $q;};$s=t4_native_filtered('query',$filter,fn()=>$svc->approve($f['id'],t4_native_admin()));$p=$r->find($f['id']);t4_native_check($s===''&&$p->is_pending()&&$p->secret_current===''&&$p->secret_previous==='');});
foreach(['fence','clear','replacement'] as $phase)t4_native_case('reset '.$phase.' failure never issues or reactivates',function()use($phase,$r,$svc){$f=t4_native_fixture();$before=$r->find($f['id']);$hit=0;$filter=function($q)use($phase,&$hit){if(str_starts_with($q,'UPDATE `'.POW\Installer::partners_table().'`')){$match=match($phase){'fence'=>str_contains($q,"`status` = 'disabled'"),'clear'=>str_contains($q,"`secret_current` = ''"),'replacement'=>str_contains(explode(' WHERE ',$q)[0],"`status` = 'active'")&&str_contains($q,'secret_current')};if($match){$hit++;return '';}}return $q;};t4_native_check(t4_native_filtered('query',$filter,fn()=>$svc->reset($f['id'],$f['data'],t4_native_admin()))==='');$p=$r->find($f['id']);t4_native_check($hit>0&&($phase==='fence'?$p->is_active()&&$p->secret_current===$before->secret_current:$p->status==='disabled'));});
t4_native_case('reset replacement readback failure restores disabled empty slots',function()use($r,$svc){$f=t4_native_fixture();$armed=false;$injected=false;$filter=function($q)use(&$armed,&$injected){if(str_starts_with($q,'UPDATE `'.POW\Installer::partners_table().'`')&&str_contains(explode(' WHERE ',$q)[0],"`status` = 'active'"))$armed=true;if($armed&&!$injected&&str_starts_with($q,'SELECT * FROM '.POW\Installer::partners_table())){$injected=true;return 'SELECT * FROM t4_missing_read';}return $q;};t4_native_check(t4_native_filtered('query',$filter,fn()=>$svc->reset($f['id'],$f['data'],t4_native_admin()))==='');$p=$r->find($f['id']);t4_native_check($injected&&$p->status==='disabled'&&$p->secret_current===''&&$p->secret_previous==='');});
t4_native_case('duplicate Sender rejects before any destructive mutation',function()use($r,$svc){$a=t4_native_fixture();$b=t4_native_fixture();$before=$r->find($a['id']);t4_native_check($svc->reset($a['id'],$b['data'],t4_native_admin())==='');$after=$r->find($a['id']);t4_native_check($after->is_active()&&$after->secret_current===$before->secret_current);});
class RegistrationNativeFaultStore extends POW\Sessions\Store {public bool $fail_query=false;public array $blocked=[];public array $attempts=[];public $on_batch=null;public function revocation_batch(int $p,int $a=0,int $l=500):array{if($this->on_batch){$fn=$this->on_batch;$this->on_batch=null;$fn();}if($this->fail_query)throw new RuntimeException('native read boundary');return parent::revocation_batch($p,$a,$l);}public function transition(int $id,string $from,string $to,array $extra=[]):bool{$this->attempts[$id]=($this->attempts[$id]??0)+1;if(in_array($id,$this->blocked,true))return false;return parent::transition($id,$from,$to,$extra);}}
t4_native_case('failed transition retries once and continues independent login cleanup',function()use($r,$log,$store){$f=t4_native_fixture();$a=t4_native_login_row($f);$b=t4_native_login_row($f);$s=new RegistrationNativeFaultStore();$s->blocked=[$a->id];$svc=new POW\Partners\Registration($r,$s,$log);t4_native_check($svc->reset($f['id'],$f['data'],t4_native_admin())==='');t4_native_check($r->find($f['id'])->status==='disabled'&&$s->attempts[$a->id]===2&&$store->find($b->id)->status==='expired'&&!$store->login_valid_checked($a)&&!$store->login_valid_checked($b));});
t4_native_case('failed revocation reader is not mistaken for exhaustion',function()use($r,$log){$f=t4_native_fixture();$s=new RegistrationNativeFaultStore();$s->fail_query=true;$svc=new POW\Partners\Registration($r,$s,$log);t4_native_check($svc->reset($f['id'],$f['data'],t4_native_admin())===''&&$r->find($f['id'])->status==='disabled');});
t4_native_case('late native unique Sender collision leaves reset fenced',function()use($r,$log){$f=t4_native_fixture();$replacement=$f['data'];$replacement['sender_identity'].='-replacement';$s=new RegistrationNativeFaultStore();$s->on_batch=function()use($r,$replacement){$replacement['owner_user_id']=0;t4_native_check($r->insert($replacement)>0);};$svc=new POW\Partners\Registration($r,$s,$log);t4_native_check($svc->reset($f['id'],$replacement,t4_native_admin())===''&&$r->find($f['id'])->status==='disabled');});
foreach(['false','throw'] as $mode)t4_native_case('native token-map '.$mode.' write prevents activation and allows retry',function()use($mode,$r,$store,$svc){$f=t4_native_fixture();$row=t4_native_login_row($f,'returned');$unrelated=WP_Session_Tokens::get_instance($f['buyer'])->create(time()+3600);$filter=function($check,$id,$key)use($f,$mode){if($id===$f['buyer']&&$key==='session_tokens'){if($mode==='throw')throw new RuntimeException('native token write');return false;}return $check;};t4_native_check(t4_native_filtered('update_user_metadata',$filter,fn()=>$svc->reset($f['id'],$f['data'],t4_native_admin()),10,3)==='');t4_native_check($r->find($f['id'])->status==='disabled'&&$store->login_valid_checked($row));t4_native_check($svc->reset($f['id'],$f['data'],t4_native_admin())!==''&&!$store->login_valid_checked($row));t4_native_check(WP_Session_Tokens::get_instance($f['buyer'])->verify($unrelated));});
t4_native_case('native token-map read error cannot confirm absent login',function()use($r,$store,$svc){$f=t4_native_fixture();$row=t4_native_login_row($f);$filter=function($q)use($f){if(str_contains($q,'meta_key, meta_value FROM')&&str_contains($q,'user_id IN ('.$f['buyer'].')'))return 'SELECT * FROM t4_missing_meta';return $q;};t4_native_check(t4_native_filtered('query',$filter,fn()=>$svc->reset($f['id'],$f['data'],t4_native_admin()))==='');t4_native_check($r->find($f['id'])->status==='disabled');t4_native_check($svc->reset($f['id'],$f['data'],t4_native_admin())!==''&&!$store->login_valid_checked($row));});
t4_native_case('stale admin save cannot revive failed reset; pending edits require approval',function()use($r,$svc){$f=t4_native_fixture(true);t4_native_check($r->update($f['id'],['status'=>'active','to_identity'=>'edited'],'not-issued'));t4_native_check($r->find($f['id'])->is_pending()&&$r->find($f['id'])->secret_current==='');t4_native_check($svc->approve($f['id'],t4_native_admin())!=='');wp_set_current_user($f['owner']);t4_native_check($svc->request_deactivation($f['id'],$f['owner']));wp_set_current_user(t4_native_admin());t4_native_check($r->update($f['id'],['status'=>'active','name'=>'stale'],'not-issued'));$p=$r->find($f['id']);t4_native_check($p->status==='disabled'&&$p->secret_current==='');});
foreach(['false','throw'] as $mode)t4_native_case('mail '.$mode.' does not undo confirmed approval or expose secret',function()use($mode,$r,$svc,$wpdb){$f=t4_native_fixture(true);$filter=function()use($mode){if($mode==='throw')throw new RuntimeException('native mail');return false;};$secret=t4_native_filtered('pre_wp_mail',$filter,fn()=>$svc->approve($f['id'],t4_native_admin()),$mode==='throw'?0:PHP_INT_MAX);t4_native_check($secret!==''&&$r->find($f['id'])->is_active());$rows=$wpdb->get_results($wpdb->prepare('SELECT event,detail FROM '.POW\Installer::log_table().' WHERE partner_id=%d',$f['id']),ARRAY_A);t4_native_check(in_array('registration_notification_failed',array_column($rows,'event'),true)&&!str_contains(json_encode($rows),$secret));});
foreach(['false','throw'] as $mode)t4_native_case('audit '.$mode.' preserves confirmed issuance',function()use($mode,$r,$svc){$f=t4_native_fixture(true);$filter=function($q)use($mode){if(str_starts_with($q,'INSERT INTO `'.POW\Installer::log_table().'`')){if($mode==='throw')throw new RuntimeException('native audit');return '';}return $q;};$secret=t4_native_filtered('query',$filter,fn()=>$svc->approve($f['id'],t4_native_admin()));t4_native_check($secret!==''&&$r->verify_secret($r->find($f['id']),$secret)!==null);});
t4_native_case('native lock release failure retains confirmed issuance without duplicate retry',function()use($r,$svc,$wpdb){$f=t4_native_fixture(true);$filter=fn($q)=>str_starts_with($q,'SELECT RELEASE_LOCK(')?'SELECT 0':$q;$secret=t4_native_filtered('query',$filter,fn()=>$svc->approve($f['id'],t4_native_admin()));$wpdb->query('DO RELEASE_ALL_LOCKS()');t4_native_check($secret!==''&&$r->verify_secret($r->find($f['id']),$secret)!==null&&$svc->approve($f['id'],t4_native_admin())==='');});
t4_native_case('owner deactivation drains 503 mixed open and terminal logins across all batches',function()use($r,$svc,$store){
 $f=t4_native_fixture();$old=[];for($i=0;$i<505;$i++)$old[]=t4_native_login_row($f,$i<503?['pending','active','ordered'][$i%3]:['returned','closed'][$i-503]);
 $unrelated=WP_Session_Tokens::get_instance($f['buyer'])->create(time()+3600);$before=$r->find($f['id']);
 wp_set_current_user($f['owner']);try{t4_native_check($svc->request_deactivation($f['id'],$f['owner']));}finally{wp_set_current_user(t4_native_admin());}
 $after=$r->find($f['id']);t4_native_check($after->status==='disabled'&&$after->secret_current===''&&$after->secret_previous===''&&$after->owner_user_id===$before->owner_user_id&&$after->company_profile===$before->company_profile&&$store->open_for_partner($f['id'],1000)===[]);
 foreach($old as $row)t4_native_check(!$store->login_valid_checked($row));t4_native_check(WP_Session_Tokens::get_instance($f['buyer'])->verify($unrelated));
});
t4_native_case('incomplete owner deactivation is false and native admin mail says incomplete',function()use($r,$store,$log){
 $f=t4_native_fixture();$row=t4_native_login_row($f);$s=new RegistrationNativeFaultStore();$s->blocked=[$row->id];$svc=new POW\Partners\Registration($r,$s,$log);$message='';$capture=function($result,$atts)use(&$message){$message=$atts['message'];return $result;};
 wp_set_current_user($f['owner']);try{t4_native_check(!t4_native_filtered('pre_wp_mail',$capture,fn()=>$svc->request_deactivation($f['id'],$f['owner']),PHP_INT_MAX,2));}finally{wp_set_current_user(t4_native_admin());}
 t4_native_check($r->find($f['id'])->status==='disabled'&&str_contains($message,'cleanup is incomplete')&&!str_contains($message,$f['secret']));
});
t4_native_case('actual actor authorization rejects forged administrator and provisioned buyer',function()use($r,$svc){$f=t4_native_fixture(true);wp_set_current_user($f['owner']);try{t4_native_check($svc->approve($f['id'],t4_native_admin())===''&&$svc->reset($f['id'],$f['data'],t4_native_admin())==='');}finally{wp_set_current_user(t4_native_admin());}wp_set_current_user($f['buyer']);try{t4_native_check(!$svc->request_deactivation($f['id'],$f['buyer'])&&$r->rotate($f['id'])===null);}finally{wp_set_current_user(t4_native_admin());}t4_native_check($r->find($f['id'])->is_pending()&&$r->find($f['id'])->secret_current==='');});
t4_native_case('owner rotation overlaps until close; reset rejects both former secrets',function()use($r,$svc){$f=t4_native_fixture();wp_set_current_user($f['owner']);try{$rotated=$r->rotate($f['id']);$p=$r->find($f['id']);t4_native_check($rotated!==null&&$r->verify_secret($p,$f['secret'])!==null&&$r->verify_secret($p,$rotated)!==null);t4_native_check($r->rotate($f['id'])===null&&$r->close_rotation($f['id']));t4_native_check($r->verify_secret($r->find($f['id']),$f['secret'])===null);}finally{wp_set_current_user(t4_native_admin());}t4_native_check($svc->reset($f['id'],$f['data'],t4_native_admin())!=='');$p=$r->find($f['id']);t4_native_check($r->verify_secret($p,$f['secret'])===null&&$r->verify_secret($p,$rotated)===null&&$p->secret_previous==='');});
t4_native_case('reset preserves historical native order and owner profile',function()use($r,$svc){$f=t4_native_fixture();update_user_meta($f['owner'],'billing_city','Fixture City');$order=wc_create_order(['customer_id'=>$f['buyer']]);$order->set_total('25.75');$order->update_meta_data('_pow_partner',$f['id']);$order->save();$id=$order->get_id();t4_native_check($svc->reset($f['id'],$f['data'],t4_native_admin())!=='');$fresh=wc_get_order($id);t4_native_check($fresh->get_customer_id()===$f['buyer']&&$fresh->get_status()==='pending'&&$fresh->get_total()==='25.75'&&(int)$fresh->get_meta('_pow_partner')===$f['id']&&get_user_meta($f['owner'],'billing_city',true)==='Fixture City');});
t4_native_case('successful native owner mail runs after unlock with no issued secret',function()use($r,$svc,$wpdb){$f=t4_native_fixture(true);$message='';$unlocked=false;$key='pow_partner_'.substr(hash('sha256',DB_NAME.'|'.POW\Installer::partners_table().'|'.$f['id']),0,52);$capture=function($result,$atts)use(&$message,&$unlocked,$key,$wpdb){$message=$atts['message'];$unlocked=(int)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$key))===1;return $result;};$secret=t4_native_filtered('pre_wp_mail',$capture,fn()=>$svc->approve($f['id'],t4_native_admin()),PHP_INT_MAX,2);t4_native_check($secret!==''&&$message!==''&&$unlocked&&!str_contains($message,$secret));});
echo 'Passed: '.$GLOBALS['pow_native_registration_pass'].' Failed: '.$GLOBALS['pow_native_registration_fail']." Skipped: 0\n";if($GLOBALS['pow_native_registration_fail'])WP_CLI::halt(1);
