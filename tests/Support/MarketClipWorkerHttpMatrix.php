<?php
/** Owned normal-login native clipping jobs. No browser polling and no real supplier work. */
function runMarketClipWorkerHttp($f,$api,$suffix,$token,$supplier,array &$report): void
{
    $db=think\facade\Db::class;$t=$f->tenant;$u=$f->user;$file=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $post=fn($route,$p)=>dataHttp($api,'/api/app.smart_clip.'.$route.$suffix,$p,$token);
    $get=fn($id)=>dataHttp($api,'/api/app.smart_clip.task/detail'.$suffix.'&id='.$id,[],$token,'GET');
    $billCount=(int)$db::name('ai_consumption_log')->count();
    $db::name('smart_clip_channel')->insert(['tenant_id'=>0,'code'=>'private-clip','name'=>'Private clip','provider'=>'xhadmin','model'=>'smart_clip','status'=>1,'config_json'=>'{}']);
    $db::name('smart_clip_channel_spec')->insert(['tenant_id'=>0,'channel_code'=>'private-clip','quality'=>'1','quality_label'=>'1秒','ratio'=>'duration','platform_unit_cost'=>1,'tenant_unit_price'=>1,'status'=>1,'provider_params_json'=>'{}']);
    $form=fn($kind,$title)=>['api'=>$kind,'channel'=>'private-clip','styleId'=>'private-template','title'=>$title,'videoUrl'=>$supplier.'/fixture/result.mp4','audioUrl'=>$supplier.'/fixture/input.wav','materials'=>[['type'=>'video','url'=>$supplier.'/fixture/result.mp4','duration'=>2]],'processRules'=>['videoDuration'=>2],'duration'=>2];
    $completed=[];$lastNative=null;
    foreach(['realman_broadcast','broadcast_mixcut','news_mixcut']as$i=>$kind){
        $input=$form($kind,'Synthetic background '.$kind);$q=$post('generate/estimate',$input);$check((float)$q['user_charge_points']===2.,'Private clipping quote differs');
        $accepted=$post('generate/index',$input);$id=(int)$accepted['task_id'];$ref=$accepted['generation_task']['task_ref']??'';$app=(int)($accepted['generation_task']['app_task_id']??0);
        $check($ref==='app_task:'.$app&&$app>0,'Clip accepted no canonical identity');$same=$post('generate/index',$input);$check($same['task_id']===$id&&$same['generation_task']['task_ref']===$ref,'Clip duplicate changed accepted identity');
        $provider=(string)$db::name('smart_clip_task')->where('id',$id)->value('provider_task_id');
        if($i===1)file_put_contents($f->dir.'/clip-hold-'.$provider,'1');
        if($i===2){$db::name('smart_clip_channel')->where('code','private-clip')->update(['status'=>0]);file_put_contents($f->dir.'/clip-download-fail','1');}
        $f->start($file,'smart_clip');
        if($i===1){
            MarketWorkerFixture::wait(fn()=>is_file($f->dir.'/clip-query-entered-'.$provider),'Worker did not enter held supplier query',20);
            $job=$db::name('ai_task_job')->where(['app_task_id'=>$app,'job_type'=>'native_smart_clip'])->find();$check($job['status']==='running','Held job not leased');
            $f->stop('worker',true);unlink($f->dir.'/clip-hold-'.$provider);
            MarketWorkerFixture::wait(fn()=>time()>=(int)$job['lease_expire_time'],'Killed worker lease did not naturally expire',15);
            $f->start($file,'smart_clip');
        }
        if($i===2){
            MarketWorkerFixture::wait(fn()=>$db::name('ai_task_job')->where(['app_task_id'=>$app,'status'=>'retrying'])->count()>0,'Download fault not retained by durable job',25);
            $asset=(int)$db::name('ai_task_result_asset')->where('app_task_id',$app)->value('id');$check($asset>0&&$db::name('smart_clip_billing')->where('task_id',$id)->count()===0,'Pending transfer was billed');
            $f->stop();unlink($f->dir.'/clip-download-fail');$f->start($file,'smart_clip');
        }
        // Poll only private SQL here. No task/detail/list HTTP request drives completion.
        MarketWorkerFixture::wait(fn()=>$db::name('ai_app_task')->where('id',$app)->value('status')==='success','Clipping did not complete without browser polling',45);
        MarketWorkerFixture::wait(fn()=>$db::name('ai_task_job')->where(['app_task_id'=>$app,'status'=>'success'])->count()===1,'Clip result job not complete',15);$f->stop();
        $done=$get($id);$view=$done['generation_task'];$check($done['status']==='success'&&$done['error']===''&&$view['status']==='success'&&!$view['polling_required']&&count($view['results'])===1,'Ready clipping view inconsistent');
        $output=$view['results'][0];$check($output['type']==='video'&&$output['transfer_status']==='stored'&&hash_file('sha256',$f->dir.'/public/'.$output['uri'])===hash_file('sha256',$f->dir.'/synthetic.mp4'),'Shared clipping asset checksum differs');
        $check(count($done['results'])===1&&$done['results'][0]['video_uri']===$output['uri'],'Native and shared result diverged');
        if($i===1)$check((int)$db::name('ai_task_job')->where('id',$job['id'])->value('attempts')>=2,'Restart did not reclaim original job');
        if($i===2)$check($output['result_asset_id']===$asset,'Transfer retry created another asset');
        $counts=[];foreach(['smart_clip_task','smart_clip_result','smart_clip_billing','ai_app_task','ai_task_result_asset','ai_task_job']as$table)$counts[$table]=$db::name($table)->order('id')->select()->toArray();
        $get($id);dataHttp($api,'/api/app.smart_clip.result/lists'.$suffix,[],$token,'GET');
        foreach($counts as$table=>$rows)$check($rows===$db::name($table)->order('id')->select()->toArray(),'Read-only clipping history changed '.$table);
        $completed[]=$id;$lastNative=$db::name('smart_clip_task')->where('id',$id)->find();
        $report['smart_clip'][$kind]=['canonical_ref'=>$ref,'normal_login_http'=>true,'completed_without_http_polling'=>true,'same_task_same_key_and_single_native_bill'=>true,'shared_and_native_video_checksum'=>true,'kill_and_natural_lease_reclaim'=>$i===1,'download_failure_restart_and_disabled_channel'=>$i===2];
    }
    $db::name('smart_clip_channel')->where('code','private-clip')->update(['status'=>1]);
    $failed=$post('generate/index',$form('realman_broadcast','Synthetic explicit rejection'));$failedId=(int)$failed['task_id'];$failedApp=(int)$failed['generation_task']['app_task_id'];$provider=$db::name('smart_clip_task')->where('id',$failedId)->value('provider_task_id');file_put_contents($f->dir.'/clip-reject-'.$provider,'1');
    $f->start($file,'smart_clip');MarketWorkerFixture::wait(fn()=>$db::name('ai_app_task')->where('id',$failedApp)->value('status')==='failed','Explicit clipping failure not terminal',20);$f->stop();
    $denied=$get($failedId);$check($denied['generation_task']['status']==='failed'&&$denied['generation_task']['billing_status']==='none'&&$db::name('smart_clip_billing')->where('task_id',$failedId)->count()===0,'Failed clipping was charged');
    // An owned accepted legacy row has no canonical task/job yet. Discovery must never submit it again.
    unset($lastNative['id']);$lastNative['status']='running';$lastNative['title']='Synthetic admitted legacy';$lastNative['provider_task_id']='recovery-clip-legacy';$lastNative['provider_payload']=json_encode(['accepted_selection'=>json_decode($lastNative['provider_payload'],true)['accepted_selection']]);$lastNative['finish_time']=0;$legacy=$db::name('smart_clip_task')->insertGetId($lastNative);
    $f->start($file,'smart_clip');MarketWorkerFixture::wait(fn()=>$db::name('smart_clip_task')->where('id',$legacy)->value('status')==='success','Accepted legacy clipping not discovered by worker',35);$f->stop();$completed[]=$legacy;
    $legacyDone=$get($legacy);$check(($legacyDone['generation_task']['status']??'')==='success','Discovered native task has no shared result');
    $deleted=$post('generate/index',$form('news_mixcut','Synthetic deleted before processing'));$deletedId=(int)$deleted['task_id'];$deletedApp=(int)$deleted['generation_task']['app_task_id'];$post('result/delete',['task_id'=>$deletedId]);$f->start($file,'smart_clip');MarketWorkerFixture::wait(fn()=>$db::name('ai_app_task')->where('id',$deletedApp)->value('status')==='canceled','Deleted native task remained active',20);$f->stop();
    try{app\common\service\ai\GenerationTaskResultService::read($deletedApp,$t,$u);throw new RuntimeException('Deleted canonical task exposed');}catch(InvalidArgumentException){}
    try{app\common\service\ai\GenerationTaskResultService::read((int)$accepted['generation_task']['app_task_id'],$t,$u+1);throw new RuntimeException('Foreign canonical task exposed');}catch(InvalidArgumentException){}
    $check($db::name('smart_clip_billing')->where('task_id',$deletedId)->count()===0,'Deleted task was charged');
    $queries=array_map(fn($line)=>json_decode($line,true)['id'],file($f->dir.'/clip-queries.jsonl'));$check(!in_array($deleted['provider_task_id'],$queries,true),'Deleted task queried supplier');
    $check($billCount===(int)$db::name('ai_consumption_log')->count(),'Clipping introduced a second consumption ledger');
    $check((int)$db::name('smart_clip_billing')->count()===4,'Native clipping billing duplicated or missing');
    $before=[];foreach(['smart_clip_task','smart_clip_result','smart_clip_billing','ai_app_task','ai_task_result_asset','ai_task_job']as$table)$before[$table]=$db::name($table)->order('id')->select()->toArray();
    $f->start($file,'smart_clip');usleep(1200000);$f->stop();foreach($before as$table=>$rows)$check($rows===$db::name($table)->order('id')->select()->toArray(),'Idle clip restart changed '.$table);
    $calls=array_map(fn($line)=>json_decode($line,true),file($f->dir.'/deep-calls.jsonl'));$counts=array_count_values(array_column($calls,'kind'));
    $check(($counts['clip_realman_broadcast']??0)===2&&($counts['clip_broadcast_mixcut']??0)===1&&($counts['clip_news_mixcut']??0)===2,'Worker resubmitted a generation');
    $report['smart_clip_accounting']=['native_tasks'=>6,'completed'=>4,'failed_uncharged'=>1,'deleted_unqueried_uncharged'=>1,'native_results'=>4,'canonical_assets'=>4,'supplier_submissions'=>5,'legacy_admitted_without_resubmit'=>1,'canonical_foreign_and_deleted_reads_rejected'=>true,'idle_restart_full_rows_stable'=>true];
}
