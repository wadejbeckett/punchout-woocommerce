<?php
/** Admin I/O and SQL boundaries; no native runtime claims. */
declare(strict_types=1);
namespace POW\Admin {
 function delete_transient(string $key): bool { unset($GLOBALS['pow_test_transients'][$key]);return true; }
 function wp_nonce_url(string $url,string $action): string { return $url.'&_wpnonce=valid'; }
 function current_user_can(string $cap): bool { return \user_can(\get_userdata(\get_current_user_id()),$cap); }
 function absint(mixed $value): int { return abs((int)$value); }
 function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/','',strtolower($value)); }
 function wp_die(mixed $message='',string $title='',array $args=[]): void {
  if(($args['response']??0)===200){$headers=['Cache-Control'=>'no-cache, must-revalidate, max-age=0, no-store, private'];foreach($GLOBALS['pow_admin_test']['filters']['nocache_headers']??[] as $filter){$headers=$filter($headers);}header('Cache-Control: '.$headers['Cache-Control']);}
  throw new \AdminResponse((string)$message,$args);
 }
 function add_filter(string $hook,callable $fn,int $priority=10): void { $GLOBALS['pow_admin_test']['filters'][$hook][]=$fn; }
 function remove_filter(string $hook,callable $fn,int $priority=10): void { foreach($GLOBALS['pow_admin_test']['filters'][$hook]??[] as $k=>$filter){if($filter===$fn)unset($GLOBALS['pow_admin_test']['filters'][$hook][$k]);} }
 function wp_safe_redirect(string $url): void { throw new \AdminResponse('', ['redirect'=>$url]); }
 function header(string $value,bool $replace=true): void { $GLOBALS['pow_admin_test']['headers'][]=$value; }
 function add_query_arg(array $args,string $url): string { return $url.'?'.http_build_query($args); }
 function selected(mixed $a,mixed $b,bool $echo=false): string { return $a===$b?' selected="selected"':''; }
 function checked(mixed $a,mixed $b,bool $echo=false): string { return $a===$b?' checked="checked"':''; }
 function submit_button(string $text='Save',string $type='primary',string $name='submit',bool $wrap=true): void { echo '<button>'.\esc_html($text).'</button>'; }
 function add_action(string $hook,callable $fn): void { $GLOBALS['pow_admin_test']['hooks'][$hook]=$fn; }
 /** No user directory in this suite: the admin screens list nobody unless a suite seeds its own. Lives here, not in one test file, because every suite that eval()s Admin\Page binds it. */
 function get_users(array $args): array { return []; }
}
namespace {
 final class AdminResponse extends RuntimeException {
  public function __construct(public string $html,public array $args) { parent::__construct('Admin response intercepted'); }
 }
 final class AdminDatabase {
  public string $prefix='admin_fixture_'; public string $last_error=''; public int $insert_id=30;
  public array $rows=[]; public array $writes=[]; public array $locks=[]; public array $lock_history=[];
  public bool $fail_write=false; public bool $fail_sessions=false; public mixed $on_lock=null;
  private array $queries=[]; private bool $suppressed=false;
  public function prepare(string $sql,mixed ...$args): string { $key='q'.count($this->queries);$this->queries[$key]=[$sql,$args];return $key; }
  public function suppress_errors(bool $value=true): bool { $old=$this->suppressed;$this->suppressed=$value;return $old; }
  public function get_var(string $key): string {
   [$sql,$args]=$this->queries[$key];
   if(str_contains($sql,'GET_LOCK')) {$this->locks[]=$args[0];$this->lock_history[]=$args[0];if($this->on_lock){$fn=$this->on_lock;$this->on_lock=null;$fn();}return '1';}
   if(str_contains($sql,'RELEASE_LOCK')) {$this->locks=array_values(array_diff($this->locks,[$args[0]]));return '1';}
   throw new LogicException('Unexpected SQL');
  }
  public function get_row(string $key,string $format): ?array {
   [$sql,$args]=$this->queries[$key];$this->last_error='';
   foreach(array_reverse($this->rows) as $row) {
    if(str_contains($sql,'WHERE id =') && $row['id']===$args[0])return $row;
    if(str_contains($sql,'WHERE sender_domain') && $row['sender_domain']===$args[0] && $row['sender_identity']===$args[1])return $row;
   }return null;
  }
  /** @var list<string> The partners table's columns, as SHOW COLUMNS reports them. */
  public array $columns=['id','name','status','visit_endpoints'];
  public function get_results(string $key,string $format): array {
   [$sql,$args]=$this->queries[$key]??[$key,[]];$this->last_error='';
   if(str_starts_with($sql,'SHOW COLUMNS'))return array_map(fn($c)=>['Field'=>$c],$this->columns);
   if(str_contains($sql,'WHERE partner_id')) {if(!$this->locks)throw new LogicException('Unlocked sweep');$this->last_error=$this->fail_sessions?'Injected session failure':'';return [];}
   // The owner lookup reads two rows on purpose: a second connection sharing the account is an ambiguity the registry refuses.
   if(str_contains($sql,'WHERE owner_user_id'))return array_slice(array_values(array_filter($this->rows,fn($r)=>$r['owner_user_id']===$args[0])),0,2);
   if(str_contains($sql,'WHERE status'))return array_values(array_filter($this->rows,fn($r)=>$r['status']===$args[0]));
   return $this->rows;
  }
  public function insert(string $table,array $data): int|false {
   if($this->fail_write)return false;$data['id']=++$this->insert_id;$this->rows[]=$data;$this->writes[]=$data;return 1;
  }
  public function update(string $table,array $data,array $where): int|false {
   if(!$this->locks)throw new LogicException('Unlocked mutation');if($this->fail_write)return false;
   foreach($this->rows as &$row) {foreach($where as $k=>$v){if(($row[$k]??null)!==$v)continue 2;}$row=array_replace($row,$data);$this->writes[]=$data;return 1;}return 0;
  }
 }
 final class AdminAudit extends \POW\Audit\Log {
  public array $events=[]; public bool $fail=false;
  public function __construct() {}
  public function write(string $event,array $context=[]): void { $this->write_checked($event,$context); }
  public function write_checked(string $event,array $context=[]): bool { if($this->fail)throw new RuntimeException('Injected diagnostic failure');$this->events[]=[$event,$context];return true; }
 }
}
