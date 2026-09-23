<?php
/** DB-free storage boundary tests; SQL conditions are interpreted, never assumed. */
declare(strict_types=1);

/**
 * wp_woocommerce_sessions as MariaDB actually presents it to these statements:
 * session_key is char(32) with a UNIQUE index in the table's own collation, so
 * `=` on it is case-insensitive and PAD SPACE and BINARY on the column would be
 * a cast that costs the index. The fixture therefore refuses BINARY on
 * session_key outright, matches the way the collation matches, and hands back
 * the stored key so the byte-exact comparison has to happen in PHP.
 */
class NativeSessionRowDatabase {
	public string $prefix='wp_'; public string $last_error=''; public array $rows=[]; public array $queries=[]; public bool $fail=false; public bool $fail_read=false; public mixed $before_write=null;
	/** Every statement this fixture was asked to run, in order. */
	public array $log=[];
	public function prepare(string $sql,mixed ...$args):string{$key='q'.count($this->queries);$this->queries[$key]=[$sql,$args];return $key;}
	/** Rows the collation considers equal to $wanted, keyed by their stored key. */
	private function collated(string $wanted):array{$found=[];foreach($this->rows as $key=>$row){if(0===strcasecmp(rtrim($key,' '),rtrim($wanted,' '))){$found[$key]=$row;}}return $found;}
	/** The one row a compare-and-set would hit: collation on the key, bytes on the value. */
	private function hit(string $wanted,string $expected):?string{foreach($this->collated($wanted) as $key=>$row){if($row['session_value']===$expected){return $key;}}return null;}
	private function indexed(string $sql):void{
		$this->log[]=$sql;
		if(str_contains($sql,'BINARY session_key')){throw new LogicException('BINARY on the indexed column costs the UNIQUE index: '.$sql);}
		if(!str_contains($sql,'session_key = %s')){throw new LogicException('Missing the native key predicate: '.$sql);}
	}
	public function get_results(string $key,mixed $format):?array{if($this->fail_read){$this->last_error='read failed';return null;}[$sql,$args]=$this->queries[$key];
		$this->indexed($sql);
		if(!str_contains($sql,'SELECT session_key,')){throw new LogicException('The stored key must come back so PHP can verify it byte for byte');}
		return array_slice(array_map(static fn(string $k,array $r):array=>['session_key'=>$k]+$r,array_keys($this->collated((string)$args[0])),array_values($this->collated((string)$args[0]))),0,2);}
	public function query(string $key):int|false{if($this->fail){$this->last_error='write failed';return false;}if($this->before_write){$f=$this->before_write;$this->before_write=null;$f();}[$sql,$a]=$this->queries[$key];
		if(str_starts_with($sql,'INSERT')){$this->log[]=$sql;if(str_contains($sql,'ON DUPLICATE')){throw new LogicException('No overwrite on insert');}[$k,$value,$expiry]=$a;if([]!==$this->collated($k)){return 0;}$this->rows[$k]=['session_value'=>$value,'session_expiry'=>(string)$expiry];return 1;}
		$this->indexed($sql);
		if(!str_contains($sql,'BINARY session_value = BINARY %s')){throw new LogicException('Missing exact CAS on the value: '.$sql);}
		if(str_starts_with($sql,'DELETE')){[$k,$expected]=$a;$hit=$this->hit($k,$expected);if(null===$hit){return 0;}unset($this->rows[$hit]);return 1;}
		[$value,$expiry,$k,$expected]=$a;$hit=$this->hit($k,$expected);if(null===$hit){return 0;}$changed=$this->rows[$hit]!==['session_value'=>$value,'session_expiry'=>(string)$expiry];$this->rows[$hit]=['session_value'=>$value,'session_expiry'=>(string)$expiry];return (int)$changed;
	}
}

final class NativeSessionRowTest extends PHPUnit\Framework\TestCase {
	/** One visit's key: 'pow_' plus 28 hex digits, exactly the width of wp_woocommerce_sessions.session_key. */
	public const KEY='pow_1a2b3c4d5e6f708192a3b4c5d6e7';
	/** A second visit of the same shared login; its basket must be a different row. */
	public const OTHER_KEY='pow_00112233445566778899aabbccdd';
	/** Not a visit key (is_visit_key() is lowercase-only) but the collation cannot tell it from KEY. */
	public const CASE_VARIANT='pow_1A2B3C4D5E6F708192A3B4C5D6E7';
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

	// ------------------------------- the indexed key predicate, verified in PHP

	/**
	 * Every statement a visit's basket costs must be able to use the column's
	 * own UNIQUE index. `BINARY session_key` is a cast on the column, so
	 * MariaDB cannot, and a visit issues five of these per request — on a store
	 * carrying the usual two days of guest sessions that is five full scans of
	 * wp_woocommerce_sessions for every catalog page a buyer opens, while an
	 * ordinary shopper on the same store pays nothing. Byte-exactness moves to
	 * PHP: the SELECT brings the stored key back and hash_equals() decides.
	 */
	public function test_every_statement_uses_the_indexed_key_predicate():void{
		$r=$this->row();$r->load();
		self::assertTrue($r->commit_locked('B',9999999999));
		self::assertTrue($r->delete_locked());
		self::assertTrue(count($this->db->log)>=4,'Read, write and readback all reach the fixture');
		foreach($this->db->log as $sql){
			if(str_starts_with($sql,'INSERT')){continue;}
			self::assertStringNotContainsString('BINARY session_key',$sql,'A cast on the indexed column forces a full scan');
			self::assertStringContainsString('session_key = %s',$sql,'The indexed column is compared directly');
		}
		$selects=array_values(array_filter($this->db->log,static fn(string $sql):bool=>str_starts_with($sql,'SELECT')));
		self::assertStringContainsString('SELECT session_key, session_value, session_expiry',$selects[0]);
		foreach(array_filter($this->db->log,static fn(string $sql):bool=>str_starts_with($sql,'UPDATE')||str_starts_with($sql,'DELETE')) as $write){
			self::assertStringContainsString('BINARY session_value = BINARY %s',$write,'The un-indexed longtext keeps its byte-exact predicate');
		}
	}
	/**
	 * The collation is case-insensitive, so a row that is not this visit's can
	 * come back from an indexed `=`. It is not an answer: the key is compared in
	 * PHP and a near-miss is a storage refusal, never a foreign basket read and
	 * never a write that lands on someone else's row.
	 */
	public function test_a_row_matching_only_by_collation_is_refused_rather_than_read():void{
		$this->db->rows=[self::CASE_VARIANT=>['session_value'=>'FOREIGN','session_expiry'=>'9999999999']];
		$r=$this->row();
		try{$r->load();self::fail('A key that is not this visit\'s was read as its basket');}
		catch(RuntimeException $e){self::assertSame('Native cart storage unavailable.',$e->getMessage());}
		self::assertFalse($r->commit_locked('B',9999999999));
		self::assertFalse($r->delete_locked());
		self::assertSame(['session_value'=>'FOREIGN','session_expiry'=>'9999999999'],$this->db->rows[self::CASE_VARIANT],'No write reached the foreign row');
	}
	/** Two rows the collation cannot separate is a refusal, not a guess at which one is the visit's. */
	public function test_a_collation_collision_refuses_before_any_write():void{
		$this->db->rows[self::CASE_VARIANT]=['session_value'=>'A','session_expiry'=>'9999999999'];
		$r=$this->row();
		try{$r->load();self::fail('Two candidate rows answered as one basket');}
		catch(RuntimeException $e){self::assertSame('Native cart storage unavailable.',$e->getMessage());}
		self::assertFalse($r->commit_locked('B',9999999999));
		self::assertFalse($r->delete_locked());
		self::assertSame(['A','A'],[$this->db->rows[self::KEY]['session_value'],$this->db->rows[self::CASE_VARIANT]['session_value']]);
	}
}
