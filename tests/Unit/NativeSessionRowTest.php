<?php
/** DB-free storage boundary tests; SQL conditions are interpreted, never assumed. */
declare(strict_types=1);

class NativeSessionRowDatabase {
	public string $prefix='wp_'; public string $last_error=''; public array $rows=[]; public array $queries=[]; public bool $fail=false; public bool $fail_read=false; public mixed $before_write=null;
	public function prepare(string $sql,mixed ...$args):string{$key='q'.count($this->queries);$this->queries[$key]=[$sql,$args];return $key;}
	public function get_results(string $key,mixed $format):?array{if($this->fail_read){$this->last_error='read failed';return null;}[$sql,$args]=$this->queries[$key];if(!str_contains($sql,'BINARY session_key = BINARY %s')){throw new LogicException('Missing exact native key');}return isset($this->rows[$args[0]])?[$this->rows[$args[0]]]:[];}
	public function query(string $key):int|false{if($this->fail){$this->last_error='write failed';return false;}if($this->before_write){$f=$this->before_write;$this->before_write=null;$f();}[$sql,$a]=$this->queries[$key];if(str_starts_with($sql,'INSERT')){if(str_contains($sql,'ON DUPLICATE')){throw new LogicException('No overwrite on insert');}[$key,$value,$expiry]=$a;if(isset($this->rows[$key])){return 0;}$this->rows[$key]=['session_value'=>$value,'session_expiry'=>(string)$expiry];return 1;}
		if(!str_contains($sql,'BINARY session_value = BINARY %s')||!str_contains($sql,'BINARY session_key = BINARY %s')){throw new LogicException('Missing exact CAS');}
		if(str_starts_with($sql,'DELETE')){[$key,$expected]=$a;if(!isset($this->rows[$key])||$this->rows[$key]['session_value']!==$expected){return 0;}unset($this->rows[$key]);return 1;}
		[$value,$expiry,$key,$expected]=$a;if(!isset($this->rows[$key])||$this->rows[$key]['session_value']!==$expected){return 0;}$changed=$this->rows[$key]!==['session_value'=>$value,'session_expiry'=>(string)$expiry];$this->rows[$key]=['session_value'=>$value,'session_expiry'=>(string)$expiry];return (int)$changed;
	}
}

final class NativeSessionRowTest extends PHPUnit\Framework\TestCase {
	/** One visit's key: 'pow_' plus 28 hex digits, exactly the width of wp_woocommerce_sessions.session_key. */
	public const KEY='pow_1a2b3c4d5e6f708192a3b4c5d6e7';
	/** A second visit of the same shared login; its basket must be a different row. */
	public const OTHER_KEY='pow_00112233445566778899aabbccdd';
	private mixed $previous; private NativeSessionRowDatabase $db;
	protected function setUp():void{$this->previous=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=$this->db=new NativeSessionRowDatabase();$this->db->rows[self::KEY]=['session_value'=>'A','session_expiry'=>'9999999999'];}
	protected function tearDown():void{$GLOBALS['wpdb']=$this->previous;}
	private function row(string $key=self::KEY):object{self::assertTrue(class_exists(POW\Cart\NativeSessionRow::class),'Native writes need an expected-value guard');return new POW\Cart\NativeSessionRow($key);}
	public function test_loaded_value_cannot_overwrite_another_request():void{$r=$this->row();self::assertSame('A',$r->load());$this->db->rows[self::KEY]['session_value']='M';self::assertFalse($r->commit_locked('R',9999999999));self::assertSame('M',$this->db->rows[self::KEY]['session_value']);}
	public function test_own_verified_save_advances_baseline_for_second_save():void{$r=$this->row();$r->load();self::assertTrue($r->commit_locked('B',9999999999));self::assertTrue($r->commit_locked('C',9999999999));self::assertSame('C',$this->db->rows[self::KEY]['session_value']);}
	public function test_conflict_between_saves_is_sticky_even_if_row_later_matches():void{$r=$this->row();$r->load();self::assertTrue($r->commit_locked('B',9999999999));$this->db->rows[self::KEY]['session_value']='M';self::assertFalse($r->commit_locked('C',9999999999));$this->db->rows[self::KEY]['session_value']='B';self::assertFalse($r->commit_locked('shutdown',9999999999));self::assertSame('B',$this->db->rows[self::KEY]['session_value']);}
	public function test_unchanged_save_requires_fresh_matching_row():void{$r=$this->row();$r->load();self::assertTrue($r->commit_locked('A',9999999999));$this->db->rows[self::KEY]['session_value']='a';self::assertFalse($r->commit_locked('A',9999999999));}
	public function test_absent_row_insert_never_replaces_concurrent_creator():void{unset($this->db->rows[self::KEY]);$r=$this->row();self::assertNull($r->load());$this->db->before_write=function(){$this->db->rows[self::KEY]=['session_value'=>'M','session_expiry'=>'9999999999'];};self::assertFalse($r->commit_locked('R',9999999999));self::assertSame('M',$this->db->rows[self::KEY]['session_value']);}
	public function test_absent_row_can_be_created_and_then_updated():void{unset($this->db->rows[self::KEY]);$r=$this->row();$r->load();self::assertTrue($r->commit_locked('B',9999999999));self::assertTrue($r->commit_locked('C',9999999999));}
	public function test_readback_failure_after_committed_write_never_retries():void{$r=$this->row();$r->load();$this->db->before_write=function(){$this->db->fail_read=true;};self::assertFalse($r->commit_locked('B',9999999999));$this->db->fail_read=false;$this->db->last_error='';self::assertFalse($r->commit_locked('C',9999999999));self::assertSame('B',$this->db->rows[self::KEY]['session_value']);}
	public function test_second_load_cannot_rebase_stale_in_memory_state():void{$r=$this->row();$r->load();$this->db->rows[self::KEY]['session_value']='M';self::assertSame('A',$r->load());self::assertFalse($r->matches_locked());}
	public function test_sql_error_refuses_and_poisoned_guard_stays_refused():void{$r=$this->row();$r->load();$this->db->fail=true;self::assertFalse($r->commit_locked('B',9999999999));$this->db->fail=false;$this->db->last_error='';self::assertFalse($r->commit_locked('C',9999999999));}
	/** The column is char(32): a wider key would be truncated on the way in and never match the byte-exact readback, so it is refused before any statement is prepared. */
	public function test_a_key_wider_than_the_column_is_refused_before_any_sql():void{
		foreach(['pow_'.str_repeat('a',32),'pow_1A2B3C4D5E6F708192A3B4C5D6E7','pow_1a2b3c4d5e6f708192a3b4c5d6e','99','t_'.str_repeat('a',30),''] as $refused){
			try{new POW\Cart\NativeSessionRow($refused);self::fail('Accepted a key that is not a visit key: '.$refused);}
			catch(RuntimeException $e){self::assertSame('Native cart row key unsupported.',$e->getMessage(),$refused);}
		}
		self::assertSame([],$this->db->queries,'No statement may be prepared for a refused key');
	}
	/** Two visits of one shared login are two independent rows: neither compare-and-set can see the other. */
	public function test_two_visit_keys_are_independent_rows():void{
		$mine=$this->row();$theirs=$this->row(self::OTHER_KEY);
		self::assertSame('A',$mine->load());self::assertNull($theirs->load());
		self::assertTrue($theirs->commit_locked('B',9999999999));
		self::assertSame('A',$this->db->rows[self::KEY]['session_value'],'A sibling visit cannot touch this basket');
		self::assertTrue($mine->commit_locked('C',9999999999));
		self::assertSame(['B','C'],[$this->db->rows[self::OTHER_KEY]['session_value'],$this->db->rows[self::KEY]['session_value']]);
		self::assertTrue($theirs->delete_locked());
		self::assertSame('C',$this->db->rows[self::KEY]['session_value'],'Deleting a sibling visit leaves this basket');
	}
}
