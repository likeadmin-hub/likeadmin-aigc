<?php
declare(strict_types=1);
require __DIR__.'/integration.php';
require $root.'/app/common/service/update/RuntimeRetentionService.php';
use app\common\service\update\RuntimeRetentionService;

// Use the real filesystem and kernel leases; only the process inventory is
// injected so unrelated PHP services on the test host are not prerequisites.
class RetentionFixture extends RuntimeRetentionService {
    public array $processes = [];
    public array $unknown = [];
    public bool $restricted = false;
    protected function phpProcesses(): array { if($this->restricted)throw new RuntimeException('process_inventory_restricted');return ['boot'=>'test-boot', 'processes'=>$this->processes,'unknown'=>$this->unknown]; }
}
$retention = new RetentionFixture($site);
$retention->prepare();
$store=$site.'/.composer-runtime';
$current=json_decode(file_get_contents($store.'/current.json'),true)['generation'];
$journal=json_decode(file_get_contents(glob($store.'/journals/*.json')[0]),true);
$rollback=$journal['previous_pointer']['generation'];
$old='baseline-'.str_repeat('a',24);
put($store.'/generations/'.$old.'/generation.json',json_encode(['schema'=>1,'vendor'=>'site']));
put($store.'/generations/'.$old.'/app/Lazy.php','<?php namespace app; class Lazy { const VALUE="old-task"; }');
$oldBackup=str_repeat('b',64); put($store.'/backups/'.$oldBackup.'/test','obsolete');
$retention->prepare();
$before=[file_get_contents($site.'/.env'), file_get_contents($site.'/public/uploads/task.bin'),file_get_contents($site.'/runtime/tasks/queue.json')];
$failed=$retention->collect(fn()=>throw new RuntimeException('health unavailable'));
check($failed['status']==='deferred' && is_dir($store.'/generations/'.$old),'failed health check deleted a runtime');

// An actual child keeps an old version pinned while its task continues. It
// loads code only after cleanup tried, exercising lazy autoload safety.
$task=$workspace.'/retention-task.php';
put($task,'<?php require $argv[1]."/runtime-retention.php"; $guard=AigcRuntimeGenerationLease::begin($argv[1]);$guard->pin($argv[2]);$guard->selected(); echo "ready\n";fflush(STDOUT);fgets(STDIN);require $argv[1]."/.composer-runtime/generations/".$argv[2]."/app/Lazy.php";echo app\\Lazy::VALUE;');
put($site.'/runtime-retention.php',file_get_contents($root.'/runtime-retention.php'));
$pipes=[];$taskProcess=proc_open([PHP_BINARY,$task,$site,$old],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
check(trim(fgets($pipes[1]))==='ready','task lease failed to start');
$retention->collect(fn()=>['passed'=>true]);
check(is_dir($store.'/generations/'.$old),'cleanup removed a generation still used by a task');
fwrite($pipes[0],"continue\n");fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($taskProcess)===0 && $out==='old-task','running task code became unavailable: '.$err);
$result=$retention->collect(fn()=>['passed'=>true]);
check($result['status']==='complete','cleanup did not complete after the old task exited');
check(!is_dir($store.'/generations/'.$old) && !is_dir($store.'/backups/'.$oldBackup),'obsolete code or backup was retained without readers');
check(is_dir($store.'/generations/'.$current) && is_dir($store.'/generations/'.$rollback) && is_dir($store.'/backups/'.$journal['backup']),'current runtime or latest rollback group deleted');
check($before===[file_get_contents($site.'/.env'),file_get_contents($site.'/public/uploads/task.bin'),file_get_contents($site.'/runtime/tasks/queue.json')],'cleanup changed business state');

// Never collect during recovery, an indeterminate SQL result, or a live writer.
put($store.'/generations/'.$old.'/generation.json','{}');$retention->prepare();
put($store.'/active.json','{}');check($retention->collect(fn()=>['passed'=>true])['status']==='deferred','active update was not deferred');unlink($store.'/active.json');
$unsafe=str_repeat('c',64);put($store.'/journals/'.$unsafe.'.json',json_encode(['status'=>'rolled_back','sql_state'=>'running']));
check($retention->collect(fn()=>['passed'=>true])['status']==='deferred' && is_dir($store.'/generations/'.$old),'unknown SQL outcome allowed cleanup');unlink($store.'/journals/'.$unsafe.'.json');
$writer=fopen($store.'/update.lock','c');flock($writer,LOCK_EX);check($retention->collect(fn()=>['passed'=>true])['status']==='deferred','cleanup competed with an update writer');fclose($writer);
$selection=fopen($store.'/retention.lock','r');flock($selection,LOCK_SH);check($retention->collect(fn()=>['passed'=>true])['status']==='deferred' && is_dir($store.'/generations/'.$old),'collector raced a reader selecting its generation');fclose($selection);

// First deployment must drain pre-protocol interpreters, including old FPM
// masters which may still cache the previous bootstrap. PID reuse is distinct.
unlink($store.'/retention-readers.json');$retention->processes=['42'=>'100'];
check($retention->collect(fn()=>['passed'=>true])['status']==='deferred','pre-protocol process did not block cleanup');
$retention->processes=['42'=>'101'];check($retention->collect(fn()=>['passed'=>true])['status']==='complete','reused PID was treated as the old interpreter');
$retention->processes=[];$retention->unknown=['43'=>'200'];check($retention->collect(fn()=>['passed'=>true])['status']==='deferred','legacy child forked after the original snapshot was overlooked');$retention->unknown=[];
$retention->restricted=true;check($retention->collect(fn()=>['passed'=>true])['status']==='deferred','restricted process inventory allowed cleanup');$retention->restricted=false;

// If a reader cannot obtain its generation lease, bootstrap keeps the global
// selection lease, serving business code while preventing unsafe collection.
put($store.'/generations/'.$old.'/generation.json','{}');$retention->prepare();unlink($store.'/leases/'.$old.'.lock');
require $root.'/runtime-retention.php';$guard=AigcRuntimeGenerationLease::begin($site);$guard->pin($old);$guard->selected();
check($retention->collect(fn()=>['passed'=>true])['status']==='deferred' && is_dir($store.'/generations/'.$old),'missing reader lease did not preserve the fallback gate');unset($guard);
check($retention->collect(fn()=>['passed'=>true])['status']==='complete','fallback gate did not release at reader shutdown');

// A symlink anywhere in an obsolete tree must not reach outside the store.
put($store.'/generations/'.$old.'/generation.json','{}');symlink($site.'/public/uploads',$store.'/generations/'.$old.'/escape');$retention->prepare();
check($retention->collect(fn()=>['passed'=>true])['status']==='deferred' && is_file($site.'/public/uploads/task.bin'),'linked tree was traversed');
unlink($store.'/generations/'.$old.'/escape');
echo "PASS: health-gated retention, real task leases, rollback group, writer/recovery/legacy barriers, PID reuse and protected business state\n";
