<?php
/** Exit entitlement rules and checked native boundaries; no native persistence claim. @package POW */
declare( strict_types = 1 );

namespace POW\Checkout {
	function add_user_meta( int $id, string $key, mixed $value, bool $unique = false ): mixed { return $GLOBALS['pow_exit_db']->write_meta( $id, $key, $value, null ); }
	function update_user_meta( int $id, string $key, mixed $value, mixed $previous = '' ): mixed { return $GLOBALS['pow_exit_db']->write_meta( $id, $key, $value, $previous ); }
}
namespace {
final class ExitPolicyDatabase {
	public string $prefix = 'exit_fixture_';
	public string $usermeta = 'exit_fixture_usermeta';
	public string $last_error = '';
	public array $row = [ 'id' => 12, 'status' => 'active', 'owner_user_id' => 20, 'exit_policy' => 'punchout_and_checkout' ];
	public array $meta = [ 30 => [ '_pow_partner_id' => ['12'] ], 31 => [ '_pow_partner_id' => ['12'] ] ];
	public bool $held = false;
	public bool $lock_fail = false;
	public bool $read_fail = false;
	public string $write_mode = '';
	public int $writes = 0;
	public mixed $on_lock = null;
	private array $queries = [];
	public function prepare( string $sql, mixed ...$args ): string { $key = 'q' . count($this->queries); $this->queries[$key] = [$sql,$args]; return $key; }
	public function suppress_errors( bool $value = true ): bool { return false; }
	public function get_var( string $key ): string { [$sql] = $this->queries[$key]; if (str_contains($sql,'GET_LOCK')) { if ($this->lock_fail) return '0'; $this->held = true; if ($this->on_lock) { ($this->on_lock)(); $this->on_lock = null; } return '1'; } $this->held = false; return '1'; }
	public function get_row( string $key, string $format ): ?array { [, $args] = $this->queries[$key]; return ($this->row['id'] ?? 0) === $args[0] ? $this->row : null; }
	public function get_results( string $key, string $format ): ?array { [, $args] = $this->queries[$key]; $this->last_error = $this->read_fail ? 'injected' : ''; return $this->read_fail ? null : array_map(fn($v)=>['meta_value'=>$v],$this->meta[$args[0]][$args[1]] ?? []); }
	public function write_meta( int $id, string $key, mixed $value, mixed $previous ): mixed { if (!$this->held) throw new LogicException('Unlocked exit mutation'); ++$this->writes; if ('false' === $this->write_mode) return false; if ('lie' === $this->write_mode) return true; if (null !== $previous && ($this->meta[$id][$key] ?? []) !== [$previous]) return false; $this->meta[$id][$key] = [$value]; return true; }
	public function update( string $table, array $data, array $where ): int|false { if (!$this->held) throw new LogicException('Unlocked partner mutation'); if ('false' === $this->write_mode) return false; if ('lie' !== $this->write_mode) $this->row = array_replace($this->row,$data); return 1; }
}
final class ExitPolicyStore extends POW\Sessions\Store {
 public ?POW\Sessions\Session $session = null;
 public function find_for_login(int $user_id,string $wp_session_token,array $statuses=[POW\Sessions\Session::ACTIVE]): ?POW\Sessions\Session { return $this->session && in_array($this->session->status,$statuses,true) && $user_id === $this->session->user_id ? $this->session : null; }
}
final class ExitPolicyOrder extends WC_Order {
 public int $customer = 30;
 public function get_customer_id(): int { return $this->customer; }
 public function is_paid(): bool { return false; }
}
final class ExitPolicyTest extends PHPUnit\Framework\TestCase {
	private array $saved = [];
	private ExitPolicyDatabase $db;
	private POW\Partners\Registry $registry;
	private function policy(): POW\Checkout\ExitPolicy { self::assertTrue(class_exists(POW\Checkout\ExitPolicy::class),'ExitPolicy implementation must exist'); return new POW\Checkout\ExitPolicy(new POW\Settings(),$this->registry); }
	protected function setUp(): void {
		foreach (['wpdb','pow_exit_db','pow_test_current_user_id','pow_test_options','pow_test_users','pow_test_user_meta','pow_test_option_readers'] as $key) { $this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null]; unset($GLOBALS[$key]); }
		$this->db = new ExitPolicyDatabase(); $GLOBALS['wpdb'] = $GLOBALS['pow_exit_db'] = $this->db;
		$this->registry = new POW\Partners\Registry(new POW\Partners\Secrets(str_repeat('e',32)));
		$GLOBALS['pow_test_current_user_id'] = 1;
		$GLOBALS['pow_test_options'] = [POW\Settings::OPTION_KEY => ['exit_policy'=>'inherit']];
		$GLOBALS['pow_test_users'] = [];
		foreach ([1=>['administrator'],20=>['customer'],30=>['punchout_buyer'],31=>['punchout_buyer']] as $id=>$roles) $GLOBALS['pow_test_users'][$id] = (object)['ID'=>$id,'roles'=>$roles,'allcaps'=>['read'=>true,'manage_woocommerce'=>1===$id]];
	}
	protected function tearDown(): void { foreach($this->saved as $key=>[$exists,$value]) { if($exists)$GLOBALS[$key]=$value; else unset($GLOBALS[$key]); } }

	private function guard(?string $status='active'): POW\RouteGuard {
		$plugin=(new ReflectionClass(POW\Plugin::class))->newInstanceWithoutConstructor();
		$store=new ExitPolicyStore(); $store->session=null === $status ? null : POW\Sessions\Session::from_row(['id'=>44,'partner_id'=>12,'user_id'=>30,'status'=>$status,'expires'=>gmdate('Y-m-d H:i:s',time()+3600)]);
		foreach (['registry'=>$this->registry,'sessions'=>$store,'settings'=>new POW\Settings(),'current_session'=>$store->session,'session_resolved'=>true] as $key=>$v) { $property=new ReflectionProperty($plugin,$key); $property->setValue($plugin,$v); }
		$GLOBALS['pow_test_options'][POW\Settings::OPTION_KEY]['enabled']='yes';
		return new POW\RouteGuard($plugin,$this->registry,new POW\Settings());
	}
	public function test_server_enforcement_refuses_unpaid_order_after_tightening(): void {
		$this->policy(); $g=$this->guard(); $GLOBALS['pow_test_current_user_id']=30; $o=new ExitPolicyOrder();
		self::assertTrue($g->checkout_allowed($o)); $this->db->row['exit_policy']='punchout_only'; self::assertFalse($g->checkout_allowed($o));
		$this->expectException(Exception::class); $g->enforce_order($o);
	}
	public function test_server_enforcement_refuses_other_buyer_and_forged_order_tags(): void {
		$this->policy(); $g=$this->guard(); $GLOBALS['pow_test_current_user_id']=30; $o=new ExitPolicyOrder(); $o->customer=31; self::assertFalse($g->checkout_allowed($o)); $o->customer=30; $o->update_meta_data('_pow_session','99'); self::assertFalse($g->checkout_allowed($o));
	}
	public function test_server_enforcement_keeps_ordinary_shoppers_native_and_orphan_buyers_closed(): void {
		$this->policy(); $g=$this->guard(null); $GLOBALS['pow_test_current_user_id']=20; self::assertTrue($g->checkout_allowed()); $GLOBALS['pow_test_current_user_id']=30; self::assertFalse($g->checkout_allowed()); $g=$this->guard('ordered'); self::assertFalse($g->checkout_allowed());
	}
	public function test_order_boundary_blocks_store_api_and_zero_total_checkout(): void {
		$this->policy(); $g=$this->guard(); $GLOBALS['pow_test_current_user_id']=30; $this->db->row['exit_policy']='punchout_only';
		$this->expectException(Exception::class); $g->enforce_order(new ExitPolicyOrder());
	}

	public function test_all_27_combinations_obey_company_cap(): void {
		$this->policy(); $values=['inherit','punchout_only','punchout_and_checkout'];
		foreach ($values as $g) foreach ($values as $c) foreach ($values as $b) {
			$cap = 'inherit' === $c ? ('inherit' === $g ? 'punchout_only' : $g) : $c;
			$expected = 'punchout_only' === $cap || 'punchout_only' === $b ? 'punchout_only' : 'punchout_and_checkout';
			self::assertSame($expected,POW\Checkout\ExitPolicy::resolve($g,$c,$b));
		}
	}
	public function test_invalid_values_fail_closed_at_every_level(): void { $this->policy(); foreach (['','unknown','DUAL_EXIT'] as $bad) foreach ([[$bad,'punchout_and_checkout','inherit'],['punchout_and_checkout',$bad,'inherit'],['punchout_and_checkout','inherit',$bad]] as $v) self::assertSame('punchout_only',POW\Checkout\ExitPolicy::resolve(...$v)); }
	/** These exercise the real Settings reader, not only the pure hierarchy helper. */
	private function assert_invalid_global_refused( mixed $stored ): void {
		$GLOBALS['pow_test_options'][POW\Settings::OPTION_KEY] = $stored;
		$settings = new POW\Settings();
		$policy = new POW\Checkout\ExitPolicy( $settings, $this->registry );
		$company = $this->registry->find( 12 );
		self::assertSame( 'punchout_and_checkout', $company->exit_policy );
		self::assertSame( 'punchout_only', $policy->effective( $company, 30 ) );
		self::assertFalse( POW\Checkout\ExitPolicy::valid( $settings->exit_policy() ), 'Invalid global state must remain distinguishable from a valid default' );
		self::assertTrue( is_wp_error( $policy->save_buyer( 12, 1, 30, 'punchout_and_checkout' ) ), 'Invalid global state cannot authorize a stored buyer grant' );
	}
	public function test_actual_settings_unknown_global_cannot_be_overridden_by_company_checkout(): void { $this->assert_invalid_global_refused( [ 'exit_policy' => 'unknown' ] ); }
	public function test_actual_settings_array_global_cannot_be_overridden_by_company_checkout(): void { $this->assert_invalid_global_refused( [ 'exit_policy' => [] ] ); }
	public function test_actual_settings_null_global_cannot_be_overridden_by_company_checkout(): void { $this->assert_invalid_global_refused( [ 'exit_policy' => null ] ); }
	public function test_actual_settings_malformed_option_cannot_be_overridden_by_company_checkout(): void {
		foreach ( [ 'malformed-option', false, (object) [ 'exit_policy' => 'punchout_and_checkout' ] ] as $stored ) { $this->assert_invalid_global_refused( $stored ); }
	}
	public function test_actual_settings_null_option_is_invalid_not_absent(): void {
		// The existing shared stub coalesces a null option to its default; native pre_option can return null.
		$GLOBALS['pow_test_option_readers'][POW\Settings::OPTION_KEY] = static fn( $default ) => null;
		$this->assert_invalid_global_refused( null );
	}
	public function test_actual_settings_unavailable_option_fails_closed(): void {
		$GLOBALS['pow_test_option_readers'][POW\Settings::OPTION_KEY] = static function ( $default ) { throw new RuntimeException( 'Injected option read failure' ); };
		$this->assert_invalid_global_refused( [] );
	}
	public function test_actual_settings_sql_read_failure_default_is_not_valid_absence(): void {
		$GLOBALS['pow_test_option_readers'][POW\Settings::OPTION_KEY] = static function ( $default ) { $GLOBALS['wpdb']->last_error = 'Injected option SQL failure'; return $default; };
		$this->assert_invalid_global_refused( [] );
	}
	public function test_actual_settings_absent_option_and_key_keep_secure_inheritance(): void {
		$settings = new POW\Settings(); $policy = new POW\Checkout\ExitPolicy( $settings, $this->registry );
		foreach ( [ null, [], [ 'enabled' => 'yes' ] ] as $stored ) {
			if ( null === $stored ) { unset( $GLOBALS['pow_test_options'][POW\Settings::OPTION_KEY] ); }
			else { $GLOBALS['pow_test_options'][POW\Settings::OPTION_KEY] = $stored; }
			self::assertSame( 'inherit', $settings->exit_policy() );
			$this->db->row['exit_policy'] = 'inherit';
			self::assertSame( 'punchout_only', $policy->effective( $this->registry->find( 12 ), 30 ) );
			$this->db->row['exit_policy'] = 'punchout_and_checkout';
			self::assertSame( 'punchout_and_checkout', $policy->effective( $this->registry->find( 12 ), 30 ) );
		}
	}
	public function test_actual_settings_valid_company_override_remains_and_reader_does_not_cache_policy(): void {
		$settings = new POW\Settings(); $policy = new POW\Checkout\ExitPolicy( $settings, $this->registry );
		$company = $this->registry->find( 12 );
		foreach ( [ 'inherit', 'punchout_only', 'punchout_and_checkout' ] as $global ) {
			$GLOBALS['pow_test_options'][POW\Settings::OPTION_KEY] = [ 'exit_policy' => $global ];
			self::assertSame( $global, $settings->exit_policy() );
			self::assertSame( 'punchout_and_checkout', $policy->effective( $company, 30 ) );
		}
		$GLOBALS['pow_test_options'][POW\Settings::OPTION_KEY]['exit_policy'] = 'unknown';
		self::assertSame( 'punchout_only', $policy->effective( $company, 30 ) );
	}
	public function test_effective_rereads_partner_and_buyer_overrides(): void { $p=$this->policy(); $snapshot=$this->registry->find(12); self::assertSame('punchout_and_checkout',$p->effective($snapshot,30)); $this->db->row['exit_policy']='punchout_only'; self::assertSame('punchout_only',$p->effective($snapshot,30)); $this->db->row['exit_policy']='punchout_and_checkout'; $this->db->meta[30]['_pow_exit_policy_12']=['punchout_only']; self::assertSame('punchout_only',$p->effective($snapshot,30)); self::assertSame('punchout_and_checkout',$p->effective($snapshot,31)); }
	public function test_missing_disabled_and_wrong_membership_fail_closed(): void { $p=$this->policy(); $snapshot=$this->registry->find(12); foreach (['disabled','pending','bad'] as $status) { $this->db->row['status']=$status; self::assertSame('punchout_only',$p->effective($snapshot,30)); } $this->db->row['status']='active'; foreach ([[],['99'],['12','12'],['12bad']] as $meta) { $this->db->meta[30]['_pow_partner_id']=$meta; self::assertSame('punchout_only',$p->effective($snapshot,30)); } $this->db->row=[]; self::assertSame('punchout_only',$p->effective($snapshot,30)); }
	public function test_corrupt_and_failed_reads_do_not_become_inherit(): void { $p=$this->policy(); $s=$this->registry->find(12); foreach ([['bad'],['inherit','inherit']] as $v) { $this->db->meta[30]['_pow_exit_policy_12']=$v; self::assertSame('punchout_only',$p->effective($s,30)); } $this->db->read_fail=true; self::assertSame('punchout_only',$p->effective($s,30)); }
	public function test_admin_save_checked_noop_and_two_buyer_isolation(): void { $p=$this->policy(); self::assertTrue($p->save_buyer(12,1,30,'punchout_only')); self::assertSame(['punchout_only'],$this->db->meta[30]['_pow_exit_policy_12']); self::assertTrue($p->save_buyer(12,1,30,'punchout_only')); self::assertSame(1,$this->db->writes); self::assertSame('punchout_and_checkout',$p->effective($this->registry->find(12),31)); self::assertTrue($p->save_buyer(12,1,30,'inherit')); }
	public function test_actor_spoof_owner_self_grant_and_cross_company_refused(): void { $p=$this->policy(); foreach ([20,30,0] as $actor) { $GLOBALS['pow_test_current_user_id']=$actor; self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'punchout_and_checkout'))); self::assertTrue(is_wp_error($p->save_buyer(12,$actor,30,'inherit'))); } $GLOBALS['pow_test_current_user_id']=1; self::assertTrue(is_wp_error($p->save_buyer(12,1,20,'inherit'))); $this->db->meta[30]['_pow_partner_id']=['99']; self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'inherit'))); self::assertSame(0,$this->db->writes); }
	public function test_cap_invalid_value_lock_failure_and_membership_recheck(): void { $p=$this->policy(); $this->db->row['exit_policy']='punchout_only'; self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'punchout_and_checkout'))); self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'bad'))); $this->db->lock_fail=true; self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'inherit'))); $this->db->lock_fail=false; $this->db->on_lock=function(){ $this->db->meta[30]['_pow_partner_id']=['99']; }; self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'inherit'))); self::assertSame(0,$this->db->writes); }
	public function test_false_lying_and_duplicate_writes_are_not_success(): void { $p=$this->policy(); foreach (['false','lie'] as $mode) { $this->db->write_mode=$mode; self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'punchout_only'))); } $this->db->write_mode=''; $this->db->meta[30]['_pow_exit_policy_12']=['inherit','inherit']; self::assertTrue(is_wp_error($p->save_buyer(12,1,30,'punchout_only'))); }
	public function test_company_policy_updates_require_real_admin_and_legacy_mode_maps(): void { $this->policy(); $GLOBALS['pow_test_current_user_id']=20; self::assertFalse($this->registry->update(12,['exit_policy'=>'punchout_and_checkout'])); $GLOBALS['pow_test_current_user_id']=1; self::assertTrue($this->registry->update(12,['exit_policy'=>'inherit'])); self::assertSame('inherit',$this->registry->find(12)->exit_policy); self::assertTrue($this->registry->update(12,['mode'=>'dual_exit'])); self::assertSame('punchout_and_checkout',$this->registry->find(12)->exit_policy); }
	public function test_lifecycle_primitive_cannot_be_used_by_owner_to_grant_exit_policy(): void {
		$this->policy(); $GLOBALS['pow_test_current_user_id']=20;
		self::assertFalse($this->registry->with_partner_lock(12,fn()=>$this->registry->transition_status(12,'active',['exit_policy'=>'punchout_and_checkout'])));
	}

	public function test_new_row_snapshot_defaults_inherit_and_schema_is_five(): void { $this->policy(); self::assertSame('inherit',POW\Partners\Partner::from_row(['id'=>2])->exit_policy); self::assertSame('5',POW\Installer::DB_VERSION); }
}
}
