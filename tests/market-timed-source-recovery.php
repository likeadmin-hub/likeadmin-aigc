<?php
/** Wall-clock expiry of owned loopback URLs. No provider calls, URL rewriting, real DB or system network changes. */
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
require __DIR__.'/Support/MarketTimedSourceFixture.php';
use think\facade\Db;
use app\common\model\ai\{AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\{AiTaskResultAssetService as Assets,GenerationTaskResultService as View};

$isolated=new MarketIsolatedMysqlFixture();
if(($argv[1]??'')==='--worker'){
    $isolated->activateWorker($argv[2],$argv[3]);
    MarketTimedSourceFixture::activateRelay(trim(file_get_contents($argv[3].'/timed-source-root')));
    $command=new app\common\command\AiTaskWorker();
    file_put_contents($argv[3].'/worker-entry-'.getmypid(),(string)getmypid());
    exit($command->run(new think\console\Input(['--app='.$argv[4],'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
$kind=$argv[1]??'';$mode=$argv[2]??'';
MarketIsolatedMysqlFixture::check(in_array($kind,['image','video','audio'],true),'Pass image/video/audio');
MarketIsolatedMysqlFixture::check(in_array($mode,['lease-expired-then-renew','truncate-then-expire-then-renew','fixed-query-expired-no-refresh','stored-then-lease-expired'],true),'Pass an exact timed-source mode');
$s=null;$source=null;$report=['kind'=>$kind,'mode'=>$mode,'scope'=>'Owned loopback wall-clock leases / signed Expires; not a real provider signature or URL-refresh attestation',
    'provider_ai_calls'=>0,'original_database_connections'=>0,'actual_wallet_mutations'=>0,'provider_url_refresh_verified'=>false];
try{
    $stage='schema_source';
    $report+=$isolated->seedEmptySchema();
    $stage='source_setup';
    $source=new MarketTimedSourceFixture($isolated,$kind,$mode);
    $stage='fixture_create';
    $s=$isolated->fixture($kind,$source->url);$f=$s['f'];
    $stage='source_expiry_and_worker_start';
    $s['frozen']=AiConsumptionLog::find($s['c']['id'])->toArray();
    MarketIsolatedMysqlFixture::atomic($f->dir.'/timed-source-root',$source->dir);
    if(in_array($mode,['lease-expired-then-renew','fixed-query-expired-no-refresh'],true))$source->expireNaturally();
    $f->start(__FILE__,$s['app']);
    if($mode!=='stored-then-lease-expired'){
        $stage='expired_source_retry';
        MarketWorkerFixture::wait(function()use($s,$source,$mode){
            $job=Db::name('ai_task_job')->where('id',$s['job']['id'])->find();
            $requests=$source->requests();$expired=array_filter($requests,static fn($row)=>$row['status']===403&&in_array($row['reason'],['lease_expired','query_expired'],true));
            return $job['status']==='retrying'&&$expired!==[]&&($mode!=='fixed-query-expired-no-refresh'||(int)$job['attempts']>=2);
        },'Original worker did not retain the expired source with natural retry',30);
        $blocked=AiTaskResultAsset::find($s['asset'])->toArray();
        $job=Db::name('ai_task_job')->where('id',$s['job']['id'])->find();
        MarketIsolatedMysqlFixture::check($blocked['transfer_status']==='retrying'&&$blocked['local_uri']===''&&empty($blocked['storage_meta']['file_id']),'Expired source was published as managed');
        MarketIsolatedMysqlFixture::check((int)Db::name('tenant_file')->count()===0&&(int)Db::name($s['results'])->count()===0,'Expired/partial source produced a successful receipt');
        MarketIsolatedMysqlFixture::check($s['frozen']===AiConsumptionLog::find($s['c']['id'])->toArray(),'Blocked source changed the original consumption');
        $view=View::read((int)$s['task']['id'],$f->tenant,$f->user);
        MarketIsolatedMysqlFixture::check($view['status']==='running'&&$view['results']===[],'History exposed an expired or partial source');
        if($mode==='truncate-then-expire-then-renew')MarketIsolatedMysqlFixture::check(count(array_filter($source->requests(),static fn($row)=>$row['truncated']))===1,'No actual short HTTP body before expiry');
        $report+=['expired_source_403_observed'=>true,'no_partial_or_formal_receipt_while_blocked'=>true,'original_job_and_asset_retained'=>true,
            'retry_next_run_time_before_recovery'=>(int)$job['next_run_time']];
        if($mode==='fixed-query-expired-no-refresh'){
            // The original signature is permanently expired. No generic source-refresh API exists here.
            $f->stop();
            MarketIsolatedMysqlFixture::check(count(array_filter($source->requests(),static fn($row)=>$row['reason']==='invalid_signature'))===0,'Expiry test lost or changed the signed query');
            $report+=['fixed_signed_query_preserved'=>true,'explicit_unrefreshable_source_remains_pending'=>true,
                'natural_retry_attempts'=>(int)$job['attempts'],'passed'=>true];
        }else{
            $stage='source_renew_and_recovery';
            $beforeUrl=$blocked['external_url'];$source->renewLease();
            MarketIsolatedMysqlFixture::check(AiTaskResultAsset::find($s['asset'])['external_url']===$beforeUrl,'Lease renewal rewrote an asset URL');
            MarketWorkerFixture::wait(fn()=>Db::name($s['table'])->where('id',$s['business'])->value('status')==='success'
                &&(int)Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Original worker did not recover after server-side lease renewal',45);
            $f->stop();
            $success=array_values(array_filter($source->requests(),static fn($row)=>$row['status']===200&&!$row['truncated']));
            MarketIsolatedMysqlFixture::check($success!==[]&&$success[0]['at']>=(int)$job['next_run_time'],'Retry did not respect the original backoff');
            $report+=['server_side_opaque_lease_renewal_only'=>true,'same_literal_source_url'=>true,'same_running_worker_natural_backoff_recovered'=>true];
        }
    }else{
        $stage='stored_source_then_expiry';
        MarketWorkerFixture::wait(fn()=>Db::name($s['table'])->where('id',$s['business'])->value('status')==='success'
            &&(int)Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Initial result did not persist',30);
        $f->stop();$source->expireNaturally();$source->probeExpired();
        $report['exact_original_url_403_after_storage']=true;
    }
    if($mode!=='fixed-query-expired-no-refresh'){
        $stage='managed_result_verify';
        $report+=MarketIsolatedMysqlFixture::verifyStored($s);
        $asset=AiTaskResultAsset::find($s['asset'])->toArray();$path=$f->dir.'/public/'.$asset['local_uri'];$stat=stat($path);
        $snapshot=static fn()=>[AiTaskResultAsset::find($s['asset'])->toArray(),Db::name('tenant_file')->select()->toArray(),Db::name($s['results'])->select()->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray(),AiConsumptionLog::find($s['c']['id'])->toArray()];
        $stage='terminal_history_replay';
        $stable=$snapshot();$count=count($source->requests());
        for($i=0;$i<2;$i++){
            $view=View::read((int)$s['task']['id'],$f->tenant,$f->user);
            MarketIsolatedMysqlFixture::check($view['status']==='success'&&count($view['results'])===1&&$view['results'][0]['uri']===$asset['local_uri'],'History did not keep the managed result');
            Assets::transfer($s['asset']);
        }
        $entries=glob($f->dir.'/worker-entry-*')?:[];$f->start(__FILE__,$s['app'],'repeat');
        MarketWorkerFixture::wait(fn()=>count(array_diff(glob($f->dir.'/worker-entry-*')?:[],$entries))===1,'Repeat worker did not enter',5);
        usleep(1200000);$f->stop('repeat');
        clearstatcache(true,$path);$afterStat=stat($path);
        MarketIsolatedMysqlFixture::check($stable===$snapshot()&&count($source->requests())===$count&&$stat['ino']===$afterStat['ino']&&$stat['mtime']===$afterStat['mtime'],'Terminal replay refetched the source or rewrote managed data');
        $report+=['terminal_history_and_replay_do_not_fetch_original_source'=>true,'managed_inode_and_mtime_retained'=>true,'passed'=>true];
    }
    MarketIsolatedMysqlFixture::check((float)Db::name('tenant')->where('id',$f->tenant)->value('point_balance')===100.
        &&(float)Db::name('user')->where('id',$f->user)->value('user_money')===100.,'Fixture wallets changed');
    $report['source_observations']=$source->requests();$report['wallets_unchanged']=true;
}catch(Throwable $error){$failure=$error;$report+=['passed'=>false]+MarketIsolatedMysqlFixture::diagnostic($error,
    in_array($stage??'',['schema_source','fixture_create'],true)?$isolated->stage:($stage??'owned_fixture'));}
finally{
    $stopped=true;if($s)foreach(['worker','repeat']as$role)try{$s['f']->stop($role);}catch(Throwable $error){$failure=$error;$stopped=false;$report['cleanup_failure']=MarketIsolatedMysqlFixture::diagnostic($error,'cleanup_stop');}
    if($source&&$stopped)try{$source->close();$report['source_root_removed']=!is_dir($source->dir);}catch(Throwable $error){$failure=$error;$stopped=false;$report['cleanup_failure']=MarketIsolatedMysqlFixture::diagnostic($error,'cleanup_source');}
    if($s&&$stopped){
        try{$s['f']->cleanup();$report['fixture_root_removed']=!is_dir($s['f']->dir);
            $names=$isolated->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $report['fixture_tables_remaining']=count(array_filter($names,static fn($name)=>str_starts_with($name,$s['f']->prefix)));
            MarketIsolatedMysqlFixture::check($report['fixture_root_removed']&&$report['fixture_tables_remaining']===0,'Owned fixture cleanup incomplete');
        }catch(Throwable $error){$failure=$error;$report['cleanup_failure']=MarketIsolatedMysqlFixture::diagnostic($error,'cleanup');$report['retained_fixture']=$s['f']->prefix;}
    }elseif($s)$report['retained_fixture']=$s['f']->prefix;
    $report['cleanup_complete']=$stopped&&!empty($report['source_root_removed'])&&!empty($report['fixture_root_removed'])&&($report['fixture_tables_remaining']??-1)===0;
    $report['host_owner_must_remove_exact_containers_and_volume']=true;
}
if(isset($failure))$report['passed']=false;
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
if(isset($failure))exit(1);
