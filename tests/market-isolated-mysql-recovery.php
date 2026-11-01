<?php
/** Dedicated MySQL process SIGKILL only. Requires the separately owned PHP/MySQL network+PID namespace. */
if(PHP_SAPI!=='cli'||is_file(dirname(__DIR__).'/.env')){fwrite(STDERR,"Use only the isolated CLI source mount without .env\n");exit(1);}
require dirname(__DIR__).'/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function(Throwable $error): void {
    $facts=class_exists('MarketIsolatedMysqlFixture',false)?MarketIsolatedMysqlFixture::diagnostic($error,'bootstrap_or_worker'):
        ['failure_class'=>get_class($error),'failure_fingerprint'=>hash('sha256',$error->getMessage()),'failure_stage'=>'bootstrap'];
    fwrite(STDERR,json_encode($facts)."\n");exit(1);
});
require __DIR__.'/Support/MarketWorkerFixture.php';
require __DIR__.'/Support/MarketIsolatedMysqlFixture.php';
require __DIR__.'/Support/MarketDbCommitBarrier.php';
use think\facade\Db;
use app\common\model\ai\{AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\AiTaskResultAssetService as Assets;

$isolated=new MarketIsolatedMysqlFixture();
if(($argv[1]??'')==='--worker'){
    $isolated->activateWorker($argv[2],$argv[3],is_file($argv[3].'/db-boundary.json'));
    $command=new app\common\command\AiTaskWorker();
    exit($command->run(new think\console\Input(['--app='.$argv[4],'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
$kind=$argv[1]??''; $phase=$argv[2]??'';
MarketIsolatedMysqlFixture::check(in_array($kind,['image','video','audio'],true),'Pass image/video/audio');
MarketIsolatedMysqlFixture::check(in_array($phase,['before-receipt-commit','after-receipt-commit','after-asset-commit'],true),'Pass an exact DB commit boundary');
$s=null; $report=['scope'=>'Owned standalone MySQL process interruption; not host power loss, multi-node or mixed-version certification',
    'kind'=>$kind,'phase'=>$phase,'provider_ai_calls'=>0,'original_database_connections'=>0,'actual_wallet_mutations'=>0];
try{
    $stage='schema_source';
    $report+=$isolated->seedEmptySchema();
    $stage='fixture_create';
    $s=$isolated->fixture($kind); $f=$s['f'];
    $stage='worker_boundary';
    $s['frozen']=AiConsumptionLog::find($s['c']['id'])->toArray();
    MarketIsolatedMysqlFixture::atomic($f->dir.'/db-boundary.json',json_encode([
        'owner'=>$isolated->owner,'phase'=>$phase,'asset_id'=>$s['asset']],JSON_THROW_ON_ERROR));
    $f->start(__FILE__,$s['app']);
    MarketWorkerFixture::wait(function()use($f,$isolated){
        $worker=$isolated->workerDiagnostics($f)['roles']['worker'];
        MarketIsolatedMysqlFixture::check($worker['startup_failures']===[]&&$worker['running'],'Owned worker exited or failed before the DB boundary');
        return is_file($f->dir.'/db-paused.json');
    },'Original worker did not reach the DB boundary',30);
    $beforeAsset=AiTaskResultAsset::find($s['asset'])->toArray();
    $held=Db::name('ai_task_job')->where('id',$s['job']['id'])->find();
    MarketIsolatedMysqlFixture::check($held['status']==='running'&&$held['lease_token']!==''&&(int)$held['lease_expire_time']>time(),'No original committed worker lease');
    $files=(int)Db::name('tenant_file')->count();
    MarketIsolatedMysqlFixture::check($files===($phase==='before-receipt-commit'?0:1),'Pre-crash committed receipt visibility differs');
    MarketIsolatedMysqlFixture::check((int)Db::name($s['results'])->count()===0,'Business projection passed the intended boundary');
    $canonical=$f->dir.'/public/'.($beforeAsset['local_uri']?:($beforeAsset['storage_meta']['transfer_checkpoint']['uri']??''));
    MarketIsolatedMysqlFixture::check(is_file($canonical) && !is_link($canonical) && hash_file('sha256',$canonical)===hash('sha256',$s['bytes']),'No complete managed bytes at the checkpoint');
    $stat=stat($canonical);
    $stage='db_fault_and_restart';
    $report+=$isolated->interruptAndRestart($f);
    $stage='committed_state_check';
    $restored=AiTaskResultAsset::find($s['asset'])->toArray();
    MarketIsolatedMysqlFixture::check($beforeAsset===$restored && (int)Db::name('tenant_file')->count()===$files,'Committed state did not survive / uncommitted receipt did not roll back');
    MarketIsolatedMysqlFixture::check($held===Db::name('ai_task_job')->where('id',$s['job']['id'])->find(),'Committed queue lease changed during DB recovery');
    MarketIsolatedMysqlFixture::check((int)Db::name($s['results'])->count()===0,'Unexpected business result after DB restart');
    // Remove only our one-shot test barrier. Do not change job leases, attempt counts or next_run_time.
    unlink($f->dir.'/db-boundary.json');
    $stage='natural_lease_recovery';
    $f->start(__FILE__,$s['app'],'recovery');
    MarketWorkerFixture::wait(fn()=>Db::name($s['table'])->where('id',$s['business'])->value('status')==='success'
        && (int)Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Original queue did not recover naturally',45);
    $f->stop('recovery');
    MarketIsolatedMysqlFixture::check((int)Db::name('ai_task_job')->where('id',$s['job']['id'])->value('attempts')>=(int)$held['attempts']+1,'The original expired lease was not reclaimed');
    $stage='managed_result_verify';
    $report+=MarketIsolatedMysqlFixture::verifyStored($s);
    clearstatcache(true,$canonical); $afterStat=stat($canonical);
    MarketIsolatedMysqlFixture::check($stat['ino']===$afterStat['ino'] && $stat['mtime']===$afterStat['mtime'],'Recovery rewrote complete managed bytes');
    $stable=[AiTaskResultAsset::find($s['asset'])->toArray(),Db::name('tenant_file')->select()->toArray(),Db::name($s['results'])->select()->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray()];
    $stage='terminal_replay';
    Assets::transfer($s['asset']); $f->start(__FILE__,$s['app'],'repeat');
    MarketWorkerFixture::wait(function()use($f,$isolated){$role=$isolated->workerDiagnostics($f)['roles']['repeat'];
        MarketIsolatedMysqlFixture::check($role['running']&&$role['startup_failures']===[],'Repeat worker failed before its real loop');return$role['started_line'];},'Repeat worker did not enter its real loop',5);
    usleep(1200000);$repeat=$isolated->workerDiagnostics($f)['roles']['repeat'];
    MarketIsolatedMysqlFixture::check($repeat['running']&&$repeat['started_line']&&$repeat['startup_failures']===[],'Repeat worker did not remain in its real loop');$f->stop('repeat');
    MarketIsolatedMysqlFixture::check($stable===[AiTaskResultAsset::find($s['asset'])->toArray(),Db::name('tenant_file')->select()->toArray(),Db::name($s['results'])->select()->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray()],'Terminal replay changed rows');
    $report+=['committed_state_retained_uncommitted_receipt_rolled_back'=>true,'natural_original_lease_recovery'=>true,
        'complete_file_inode_and_mtime_preserved'=>true,'terminal_replay_unchanged'=>true,'passed'=>true];
}catch(Throwable $error){$failure=$error;$report+=['passed'=>false]+MarketIsolatedMysqlFixture::diagnostic($error,
    in_array($stage??'',['schema_source','fixture_create'],true)?$isolated->stage:($stage??'owned_fixture'));}
finally{
    if($s){
        try{$report['worker_diagnostics_before_cleanup']=$isolated->workerDiagnostics($s['f'],'before_cleanup');}catch(Throwable$error){$failure=$error;$report['diagnostic_failure']=MarketIsolatedMysqlFixture::diagnostic($error,'diagnostic_capture');}
        $stopped=true;foreach(['worker','recovery','repeat']as$role)try{$s['f']->stop($role);}catch(Throwable $error){$stopped=false;$failure=$error;$report['cleanup_failure']=MarketIsolatedMysqlFixture::diagnostic($error,'cleanup_stop');}
        try{$report['worker_diagnostics_after_stop']=$isolated->workerDiagnostics($s['f'],'after_stop');}catch(Throwable$error){$failure=$error;$report['diagnostic_failure']=MarketIsolatedMysqlFixture::diagnostic($error,'diagnostic_capture');}
        if($stopped){
            try{$isolated->reconnect($s['f']);$s['f']->cleanup();$report['fixture_root_removed']=!is_dir($s['f']->dir);
                $names=$isolated->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                $report['fixture_tables_remaining']=count(array_filter($names,static fn($name)=>str_starts_with($name,$s['f']->prefix)));
                MarketIsolatedMysqlFixture::check($report['fixture_root_removed']&&$report['fixture_tables_remaining']===0,'Owned cleanup incomplete');
            }catch(Throwable $error){$failure=$error;$report['cleanup_failure']=MarketIsolatedMysqlFixture::diagnostic($error,'cleanup');$report['retained_fixture']=$s['f']->prefix;$report['cleanup_complete']=false;}
        }else $report['retained_fixture']=$s['f']->prefix;
    }
    $report['cleanup_complete']=($stopped??false) && !empty($report['fixture_root_removed']) && ($report['fixture_tables_remaining']??-1)===0;
    $report['host_owner_must_remove_exact_containers_and_volume']=true;
}
if(isset($failure))$report['passed']=false;
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
if(isset($failure))exit(1);
