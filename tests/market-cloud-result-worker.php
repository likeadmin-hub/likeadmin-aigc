<?php
/** Configured OSS writes only synthetic owned output objects; no provider AI calls. Secrets never leave server memory/owned SQL/cache. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
require __DIR__.'/Support/MarketWorkerFixture.php';
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\AiTaskResultAssetService as Assets;
use app\common\service\storage\{StorageConfigService,Driver};
$original=config('database');
if(($argv[1]??'')==='--worker'){
    MarketWorkerFixture::activate($original,$argv[2],$argv[3]);
    // The owner alone creates this one-shot barrier. Stop after OSS accepted the
    // bytes and before the file/receipt transaction can commit.
    $dir=$argv[3];
    if(is_file($dir.'/cloud-crash-next')&&rename($dir.'/cloud-crash-next',$dir.'/cloud-crash-claimed')){
        Db::event('after_insert',static function($query)use($dir){
            if(!str_ends_with($query->getTable(),'tenant_file'))return;
            file_put_contents($dir.'/cloud-uploaded-before-commit',(string)getmypid());
            while(!is_file($dir.'/cloud-release'))usleep(20000);
        });
    }
    $command=new app\common\command\AiTaskWorker();
    exit($command->run(new think\console\Input(['--app='.$argv[4],'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
$kind=$argv[1]??'';MarketWorkerFixture::check(in_array($kind,['image','video','audio'],true),'Pass image/video/audio');
$mode=$argv[2]??'normal';MarketWorkerFixture::check(in_array($mode,['normal','crash-after-upload'],true),'Pass normal or crash-after-upload');
$cloud=StorageConfigService::getConfiguredStorageConfig(1,'aliyun');
$names=['ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset','tenant_file','tenant','user','config','tenant_config','app','tenant_app_config','aigc_image_task','aigc_image_result','aigc_image_billing','aigc_image_quota','aigc_short_drama_config','power_market_product','power_market_sku','tenant_power_market_sku_price','power_market_price_rule','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_llm_config','aigc_video_task','aigc_video_result','aigc_video_billing','aigc_video_quota','aigc_video_config','aigc_product_promo_video_task','aigc_music_task','aigc_music_result','aigc_music_billing','aigc_music_quota'];

$fixture=null;$objects=[];$oss=null;$report=['kind'=>$kind,'mode'=>$mode,'scope'=>'Configured OSS, owned synthetic objects, disposable SQL and production result worker only','provider_ai_calls'=>0,'actual_wallet_mutations'=>0];
try{
    $fixture=new MarketWorkerFixture($original,$names);$tenant=$fixture->tenant;$user=$fixture->user;$prefix=$fixture->prefix;$dir=$fixture->dir;
    foreach(['enable'=>1,'default'=>'aliyun','aliyun'=>$cloud['engine']['aliyun']]as$name=>$value)Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>'storage','name'=>$name,'value'=>json_encode($value,JSON_THROW_ON_ERROR)]);
    $effective=StorageConfigService::getEffectiveConfig($tenant);MarketWorkerFixture::check($effective['scope']==='tenant'&&$effective['default']==='aliyun','Fixture did not use configured tenant OSS');
    $ext=['image'=>'png','video'=>'mp4','audio'=>'wav'][$kind];
    if($kind==='image')$media=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
    else{$path=getenv('AIGC_WORKER_MEDIA_DIR').'/synthetic.'.$ext;MarketWorkerFixture::check(is_file($path),'Generate private fixtures first');$media=file_get_contents($path);}
    $uri='data:'.$kind.'/'.$ext.';base64,'.base64_encode($media);$appCode=['image'=>'aigc_image','video'=>'aigc_video','audio'=>'aigc_music'][$kind];
    $key=['image'=>'images','video'=>'videos','audio'=>'items'][$kind];$uriKey=['image'=>'image_uri','video'=>'video_uri','audio'=>'audio_uri'][$kind];$businessTable='aigc_'.$kind.'_task';$resultTable='aigc_'.$kind.'_result';if($kind==='audio'){$businessTable='aigc_music_task';$resultTable='aigc_music_result';}
    $summary=[$key=>[[$uriKey=>$uri]+($kind==='video'?['width'=>32,'height'=>32]:['title'=>'Synthetic silence'])]];
    $task=AiAppTask::create(['task_no'=>$prefix.'task','tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','business_table'=>$businessTable,'business_id'=>0,'status'=>'success','progress'=>100,'idempotency_key'=>$prefix.'task','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
    $c=AiConsumptionLog::create(['consume_no'=>$prefix.'consumption','app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','resource_type'=>'model','provider'=>'power_market','protocol'=>$kind.'_generate','quantity'=>1,'price_snapshot'=>['platform_price'=>0,'tenant_price'=>0],'usage_snapshot'=>[],'request_summary'=>[],'response_summary'=>$summary,'run_status'=>'success','billing_status'=>'settled','reserved_tenant_cost'=>0,'reserved_user_price'=>0,'actual_tenant_cost'=>0,'actual_user_price'=>0,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
    $business=Db::name($businessTable)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['id'],'provider'=>$kind==='audio'?'market_music_generation':'power_market','channel'=>'offline-fixture','quality'=>'fixture','ratio'=>'1:1','quantity'=>1,'prompt'=>'Synthetic terminal receipt; no generation','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]+($kind==='image'?[]:($kind==='audio'?['title'=>'Synthetic silence']:['model_json'=>json_encode(['resource_type'=>'model','duration'=>1])])));if($kind!=='image')Db::name($businessTable)->where('id',$business)->update(['consumption_id'=>$c['id']]);$task->save(['business_id'=>$business]);
    $assets=Assets::recordConsumptionAssets((int)$c['id'],true);MarketWorkerFixture::check(count($assets)===1,'Missing owned result asset');$id=(int)$assets[0]['id'];$job=Db::name('ai_task_job')->where('result_asset_id',$id)->find();$report['transfer_job_app_linked']=(int)$job['app_task_id']===(int)$task['id'];

    $expected='uploads/ai_result/'.$tenant.'/'.$user.'/asset-'.$id.'-'.hash('sha256',$media).'.'.$ext;$objects[]=$expected;
    $cfg=$cloud['engine']['aliyun'];$oss=new OSS\OssClient($cfg['access_key'],$cfg['secret_key'],$cfg['domain'],true);app\common\service\storage\StorageOperationTimeout::oss($oss,$cfg);
    $frozen=AiConsumptionLog::find($c['id'])->toArray();
    if($mode==='crash-after-upload')file_put_contents($dir.'/cloud-crash-next','1');
    $fixture->start(__FILE__,$appCode);
    if($mode==='crash-after-upload'){
        MarketWorkerFixture::wait(fn()=>is_file($dir.'/cloud-uploaded-before-commit'),'Cloud worker did not reach receipt barrier',45);
        $held=Db::name('ai_task_job')->where('id',$job['id'])->find();
        MarketWorkerFixture::check($held['status']==='running'&&(int)$held['lease_expire_time']>time(),'Missing active cloud transfer lease');
        MarketWorkerFixture::check((int)Db::name('tenant_file')->count()===0,'Uncommitted cloud receipt became visible');
        MarketWorkerFixture::check(hash('sha256',$oss->getObject($cfg['bucket'],$expected))===hash('sha256',$media),'Cloud bytes missing at receipt barrier');
        $fixture->stop('worker',true);
        MarketWorkerFixture::check(AiTaskResultAsset::find($id)['transfer_status']==='external','Killed cloud worker committed the asset');
        $report+=['killed_after_cloud_upload_before_receipt_commit'=>true,'cloud_bytes_verified_before_kill'=>true,'uncommitted_receipt_invisible'=>true];
        $fixture->start(__FILE__,$appCode,'recovery');
    }
    MarketWorkerFixture::wait(fn()=>Db::name($businessTable)->where('id',$business)->value('status')==='success','Cloud business result did not finish',45);
    $fixture->stop($mode==='normal'?'worker':'recovery');
    $asset=AiTaskResultAsset::find($id)->toArray();$result=Db::name($resultTable)->where('task_id',$business)->find();$receipt=$asset['storage_meta'];
    MarketWorkerFixture::check($asset['transfer_status']==='stored'&&$asset['local_uri']===$expected&&$result[$uriKey]===$expected,'Cloud URI missing in business result');
    $bytes=$oss->getObject($cfg['bucket'],$expected);MarketWorkerFixture::check(hash('sha256',$bytes)===hash('sha256',$media),'Cloud readback checksum mismatch');
    $file=Db::name('tenant_file')->where('id',$receipt['file_id'])->find();MarketWorkerFixture::check((int)$file['tenant_id']===$tenant&&(int)$file['source_id']===$user,'Cloud file owner mismatch');
    foreach(['storage_scope','storage_engine','storage_domain']as$field)MarketWorkerFixture::check($result[$field]===$asset[$field]&&$file[$field]===$asset[$field],'Cloud storage metadata diverged');
    $snapshot=[$asset,Db::name($resultTable)->where('task_id',$business)->select()->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray()];
    Assets::transfer($id);Assets::transfer($id);$fixture->start(__FILE__,$appCode,'repeat');usleep(1200000);$fixture->stop('repeat');
    MarketWorkerFixture::check($snapshot===[AiTaskResultAsset::find($id)->toArray(),Db::name($resultTable)->where('task_id',$business)->select()->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray()],'Cloud replay changed terminal rows');
    MarketWorkerFixture::check((float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===100.&&(float)Db::name('user')->where('id',$user)->value('user_money')===100.,'Fixture wallets changed');
    $afterConsumption=AiConsumptionLog::find($c['id'])->toArray();
    $report['consumption_changed_fields']=array_values(array_filter(array_keys($frozen),static fn($field)=>($frozen[$field]??null)!==($afterConsumption[$field]??null)));
    // Successful transfer deliberately hydrates response_summary with the
    // managed URI. Every identity, request and billing field stays frozen.
    $projectionFields=['response_summary'=>true,'update_time'=>true];
    MarketWorkerFixture::check(array_diff_key($frozen,$projectionFields)===array_diff_key($afterConsumption,$projectionFields),'Cloud recovery changed frozen billing or request fields');
    MarketWorkerFixture::check(($afterConsumption['response_summary'][$key][0][$uriKey]??null)===$expected,'Cloud consumption did not project its managed result');
    $attempts=(int)Db::name('ai_task_job')->where('id',$job['id'])->value('attempts');
    if($mode==='crash-after-upload')MarketWorkerFixture::check($attempts>=2,'Cloud recovery did not reclaim the naturally expired lease');
    foreach(['tenant_file','ai_task_result_asset']as$table)MarketWorkerFixture::check((int)Db::name($table)->count()===1,'Duplicate cloud result registration');
    MarketWorkerFixture::check((int)Db::name($resultTable)->where('task_id',$business)->count()===1,'Duplicate cloud business result');
    $report+=['storage_scope'=>$asset['storage_scope'],'storage_engine'=>$asset['storage_engine'],'storage_domain_sha256'=>hash('sha256',$asset['storage_domain']),
        'object_uri_sha256'=>hash('sha256',$expected),'readback_sha256'=>hash('sha256',$bytes),'readback_bytes'=>strlen($bytes),'file_records'=>(int)Db::name('tenant_file')->count(),
        'result_assets'=>(int)Db::name('ai_task_result_asset')->count(),'business_results'=>(int)Db::name($resultTable)->where('task_id',$business)->count(),'receipt_mime'=>$receipt['mime_type']??'',
        'ownership_and_storage_match'=>true,'terminal_replay_unchanged'=>true,'wallets_unchanged'=>true,'frozen_billing_and_request_unchanged'=>true,'managed_consumption_projection'=>true,'transfer_attempts'=>$attempts,
        'natural_lease_expiry_recovered'=>$mode==='crash-after-upload'];
}catch(Throwable $error){$failure=$error;$message=$error->getMessage();foreach($cloud['engine']['aliyun']as$value)if(is_string($value)&&$value!=='')$message=str_replace($value,'[redacted]',$message);$report['failure_class']=get_class($error);$report['failure_reason']=mb_substr($message,0,200);$report['failure_fingerprint']=hash('sha256',$error->getMessage());$report['stage_failed']=true;
    if($fixture)foreach(glob($fixture->dir.'/*.log')?:[]as$log)fwrite(STDERR,basename($log).': '.mb_substr(preg_replace('~https?://[^\s]+~','[storage-url]',file_get_contents($log)),-2000)."\n");
}finally{
    $deleted=0;if($fixture){$fixture->stop();$driver=new Driver($cloud);
        foreach($objects as$object){MarketWorkerFixture::check(str_starts_with($object,'uploads/ai_result/'.$fixture->tenant.'/'.$fixture->user.'/asset-'),'Not an owned cleanup key');
            try{if(!$driver->delete($object))throw new RuntimeException('Owned object cleanup failed');
                if($oss&&$oss->doesObjectExist($cloud['engine']['aliyun']['bucket'],$object))throw new RuntimeException('Owned object still exists after deletion');
                $deleted++;}catch(Throwable $error){$failure=$error;$report['cleanup_failed']=true;}}
        $fixture->cleanup();}
    $report['owned_cloud_objects_deleted']=$deleted;$report['owned_cloud_objects_created']=count($objects);
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";if(isset($failure))exit(1);
