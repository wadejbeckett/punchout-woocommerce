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
	private mixed $previous; private NativeSessionRowDatabase $db;
	protected function setUp():void{$this->previous=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=$this->db=new NativeSessionRowDatabase();$this->db->rows['99']=['session_value'=>'A','session_expiry'=>'9999999999'];}
	protected function tearDown():void{$GLOBALS['wpdb']=$this->previous;}
	private function row():object{self::assertTrue(class_exists(POW\Cart\NativeSessionRow::class),'Native writes need an expected-value guard');return new POW\Cart\NativeSessionRow('99');}
	public function test_loaded_value_cannot_overwrite_another_request():void{$r=$this->row();self::assertSame('A',$r->load());$this->db->rows['99']['session_value']='M';self::assertFalse($r->commit_locked('R',9999999999));self::assertSame('M',$this->db->rows['99']['session_value']);}
	public function test_own_verified_save_advances_baseline_for_second_save():void{$r=$this->row();$r->load();self::assertTrue($r->commit_locked('B',9999999999));self::assertTrue($r->commit_locked('C',9999999999));self::assertSame('C',$this->db->rows['99']['session_value']);}
	public function test_conflict_between_saves_is_sticky_even_if_row_later_matches():void{$r=$this->row();$r->load();self::assertTrue($r->commit_locked('B',9999999999));$this->db->rows['99']['session_value']='M';self::assertFalse($r->commit_locked('C',9999999999));$this->db->rows['99']['session_value']='B';self::assertFalse($r->commit_locked('shutdown',9999999999));self::assertSame('B',$this->db->rows['99']['session_value']);}
	public function test_unchanged_save_requires_fresh_matching_row():void{$r=$this->row();$r->load();self::assertTrue($r->commit_locked('A',9999999999));$this->db->rows['99']['session_value']='a';self::assertFalse($r->commit_locked('A',9999999999));}
	public function test_absent_row_insert_never_replaces_concurrent_creator():void{unset($this->db->rows['99']);$r=$this->row();self::assertNull($r->load());$this->db->before_write=function(){$this->db->rows['99']=['session_value'=>'M','session_expiry'=>'9999999999'];};self::assertFalse($r->commit_locked('R',9999999999));self::assertSame('M',$this->db->rows['99']['session_value']);}
	public function test_absent_row_can_be_created_and_then_updated():void{unset($this->db->rows['99']);$r=$this->row();$r->load();self::assertTrue($r->commit_locked('B',9999999999));self::assertTrue($r->commit_locked('C',9999999999));}
	public function test_readback_failure_after_committed_write_never_retries():void{$r=$this->row();$r->load();$this->db->before_write=function(){$this->db->fail_read=true;};self::assertFalse($r->commit_locked('B',9999999999));$this->db->fail_read=false;$this->db->last_error='';self::assertFalse($r->commit_locked('C',9999999999));self::assertSame('B',$this->db->rows['99']['session_value']);}
	public function test_second_load_cannot_rebase_stale_in_memory_state():void{$r=$this->row();$r->load();$this->db->rows['99']['session_value']='M';self::assertSame('A',$r->load());self::assertFalse($r->matches_locked());}
	public function test_sql_error_refuses_and_poisoned_guard_stays_refused():void{$r=$this->row();$r->load();$this->db->fail=true;self::assertFalse($r->commit_locked('B',9999999999));$this->db->fail=false;$this->db->last_error='';self::assertFalse($r->commit_locked('C',9999999999));}
}
