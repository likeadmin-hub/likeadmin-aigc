<?php
/** Post-provider synthetic receipt -> production worker -> run -> persisted project node. No AI submission. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();require __DIR__.'/Support/MarketWorkerFixture.php';
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\AiTaskResultAssetService as Assets;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;
$original=config('database');
if(($argv[1]??'')==='--worker'){
    MarketWorkerFixture::activate($original,$argv[2],$argv[3]);$command=new app\common\command\AiTaskWorker();
    exit($command->run(new think\console\Input(['--app='.$argv[4],'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
$entry=$argv[1]??'';$kind=$argv[2]??'';MarketWorkerFixture::check(in_array($entry,['canvas','short_drama'],true)&&in_array($kind,['image','video','audio'],true),'Pass canvas/short_drama and image/video/audio');
$names=['ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset','tenant_file','tenant','user','config','tenant_config','app','tenant_app_config','aigc_image_task','aigc_image_result','aigc_image_billing','aigc_image_quota','aigc_short_drama_config','power_market_product','power_market_sku','tenant_power_market_sku_price','power_market_price_rule','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_llm_config','aigc_video_task','aigc_video_result','aigc_video_billing','aigc_video_quota','aigc_video_config','aigc_product_promo_video_task','aigc_music_task','aigc_music_result','aigc_music_billing','aigc_music_quota'];


foreach(Db::query('SHOW TABLES')as$row)foreach($row as$table)foreach(['aigc_canvas_','aigc_short_drama_']as$group){$base=$original['connections']['mysql']['prefix'];if(str_starts_with($table,$base.$group))$names[]=substr($table,strlen($base));}
$fixture=null;$report=['entry'=>$entry,'kind'=>$kind,'scope'=>'Disposable complete production post-receipt chain. No authenticated generation HTTP submission or supplier call.','provider_ai_calls'=>0,'actual_wallet_mutations'=>0];
try{
    $fixture=new MarketWorkerFixture($original,array_unique($names));$tenant=$fixture->tenant;$user=$fixture->user;$prefix=$fixture->prefix;$dir=$fixture->dir;
    $ext=['image'=>'png','video'=>'mp4','audio'=>'wav'][$kind];
    if($kind==='image')$media=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
    else{$path=getenv('AIGC_WORKER_MEDIA_DIR').'/synthetic.'.$ext;MarketWorkerFixture::check(is_file($path),'Generate private media first');$media=file_get_contents($path);}
    $uri='data:'.$kind.'/'.$ext.';base64,'.base64_encode($media);$node=['id'=>'1','type'=>$kind,'position'=>['x'=>0,'y'=>0],'width'=>320,'height'=>320,'metadata'=>['prompt'=>'Synthetic colored block or silence','channel'=>'offline-fixture']];
    $documentTable=$entry==='canvas'?'aigc_canvas_project':'aigc_short_drama_canvas';$runTable=$entry==='canvas'?'aigc_canvas_run':'aigc_short_drama_canvas_run';
    $doc=Db::name($documentTable)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,($entry==='canvas'?'name':'title')=>$prefix.'project','nodes_json'=>json_encode([$node]),'edges_json'=>'[]','viewport_json'=>json_encode(['x'=>12,'y'=>24,'zoom'=>1]),'create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    $frozen=['fixture'=>'accepted-post-receipt','parameters'=>['audio'=>false,'quality'=>'fixture','duration'=>1]];
    if($entry==='canvas'){
        $run=Db::name($runTable)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$doc,'node_id'=>'1','run_type'=>$kind==='audio'?'music':$kind,'provider'=>'power_market','source_task_id'=>0,'status'=>'running','prompt'=>$node['metadata']['prompt'],'params_json'=>json_encode($frozen),'result_json'=>'{}','create_time'=>time(),'update_time'=>time(),'finish_time'=>0,'delete_time'=>0]);
        $appCode='aigc_canvas';$action=['image'=>'generate','video'=>'video_generate','audio'=>'music_generate'][$kind];
        $task=AiAppTask::create(['task_no'=>$prefix.'task','tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>$action,'business_table'=>$runTable,'business_id'=>$run,'status'=>'success','progress'=>100,'idempotency_key'=>$prefix.'task','create_time'=>time(),'update_time'=>time()]);
        $key=['image'=>'images','video'=>'videos','audio'=>'items'][$kind];$uriKey=['image'=>'image_uri','video'=>'video_uri','audio'=>'audio_uri'][$kind];
        $c=AiConsumptionLog::create(['consume_no'=>$prefix.'consume','app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>$action,'resource_type'=>'model','provider'=>'power_market','protocol'=>$kind==='audio'?'application_api':$kind.'_generate','quantity'=>1,'price_snapshot'=>$frozen,'response_summary'=>[$key=>[[$uriKey=>$uri,'width'=>32,'height'=>32]]],'run_status'=>'success','billing_status'=>'settled','create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
        Db::name($runTable)->where('id',$run)->update(['source_task_id'=>$c['id']]);$node['metadata']+=['taskId'=>(string)$c['id'],'status'=>'running','pending'=>true,'generationStartedAt'=>time()*1000,'mediaItems'=>[]];Db::name($documentTable)->where('id',$doc)->update(['nodes_json'=>json_encode([$node])]);
    }else{
        $input=['prompt'=>$node['metadata']['prompt'],'duration'=>1];$intent=Intent::reserve($tenant,$user,$doc,$prefix.'request','1',$kind,$input);$claim=Intent::claim($tenant,$user,(int)$intent['id']);$run=(int)$claim['canvas_run_id'];
        $appCode=['image'=>'aigc_image','video'=>'aigc_video','audio'=>'aigc_music'][$kind];
    $key=['image'=>'images','video'=>'videos','audio'=>'items'][$kind];$uriKey=['image'=>'image_uri','video'=>'video_uri','audio'=>'audio_uri'][$kind];$businessTable='aigc_'.$kind.'_task';$resultTable='aigc_'.$kind.'_result';if($kind==='audio'){$businessTable='aigc_music_task';$resultTable='aigc_music_result';}
    $summary=[$key=>[[$uriKey=>$uri]+($kind==='video'?['width'=>32,'height'=>32]:['title'=>'Synthetic silence'])]];
    $task=AiAppTask::create(['task_no'=>$prefix.'task','tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','business_table'=>$businessTable,'business_id'=>0,'status'=>'success','progress'=>100,'idempotency_key'=>$prefix.'task','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
    $c=AiConsumptionLog::create(['consume_no'=>$prefix.'consumption','app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','resource_type'=>'model','provider'=>'power_market','protocol'=>$kind.'_generate','quantity'=>1,'price_snapshot'=>['platform_price'=>0,'tenant_price'=>0],'usage_snapshot'=>[],'request_summary'=>[],'response_summary'=>$summary,'run_status'=>'success','billing_status'=>'settled','reserved_tenant_cost'=>0,'reserved_user_price'=>0,'actual_tenant_cost'=>0,'actual_user_price'=>0,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
    $business=Db::name($businessTable)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['id'],'provider'=>$kind==='audio'?'market_music_generation':'power_market','channel'=>'offline-fixture','quality'=>'fixture','ratio'=>'1:1','quantity'=>1,'prompt'=>'Synthetic terminal receipt; no generation','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]+($kind==='image'?[]:($kind==='audio'?['title'=>'Synthetic silence']:['model_json'=>json_encode(['resource_type'=>'model','duration'=>1])])));if($kind!=='image')Db::name($businessTable)->where('id',$business)->update(['consumption_id'=>$c['id']]);$task->save(['business_id'=>$business]);

        Intent::bindLocalTask($tenant,$user,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],$business);
        Intent::accepted($tenant,$user,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],(string)$business,[],false);
    }
    $price=AiConsumptionLog::find($c['id'])['price_snapshot'];$request=Db::name($runTable)->where('id',$run)->value($entry==='canvas'?'params_json':'request_json');$graphBefore=Db::name($documentTable)->where('id',$doc)->find();
    $assets=Assets::recordConsumptionAssets((int)$c['id'],true);MarketWorkerFixture::check(count($assets)===1,'No owned output asset');$assetId=(int)$assets[0]['id'];
    $fixture->start(__FILE__,$entry==='canvas'?'aigc_canvas':'');
    MarketWorkerFixture::wait(fn()=>AiTaskResultAsset::find($assetId)['transfer_status']==='stored','Managed result not stored');
    MarketWorkerFixture::wait(function()use($documentTable,$doc){$nodes=json_decode(Db::name($documentTable)->where('id',$doc)->value('nodes_json'),true);return ($nodes[0]['metadata']['status']??'')==='success'&&!empty($nodes[0]['metadata']['url']);},'Worker did not persist generated result into project node',$entry==='canvas'?12:45);
    $fixture->stop();$graph=Db::name($documentTable)->where('id',$doc)->find();$history=Db::name($runTable)->where('id',$run)->find();$node=json_decode($graph['nodes_json'],true)[0];$asset=AiTaskResultAsset::find($assetId)->toArray();
    $nodeUri=(string)($node['metadata']['uri']??$node['metadata']['url']??'');if(preg_match('~^https?://~',$nodeUri))$nodeUri=ltrim(parse_url($nodeUri,PHP_URL_PATH),'/');
    MarketWorkerFixture::check($nodeUri===$asset['local_uri']&&$history['status']==='success','Node/run uses an incorrect result');
    MarketWorkerFixture::check($price===AiConsumptionLog::find($c['id'])['price_snapshot']&&$request===Db::name($runTable)->where('id',$run)->value($entry==='canvas'?'params_json':'request_json'),'Frozen input/billing changed');
    $stable=[$graph,$history,AiTaskResultAsset::find($assetId)->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray()];$fixture->start(__FILE__,$entry==='canvas'?'aigc_canvas':'','repeat');usleep(1200000);$fixture->stop('repeat');
    $report['worker_restart_preserved_rows']=$stable===[Db::name($documentTable)->where('id',$doc)->find(),Db::name($runTable)->where('id',$run)->find(),AiTaskResultAsset::find($assetId)->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray()];
    MarketWorkerFixture::check($report['worker_restart_preserved_rows'],'Worker restart changed completed projection');
    $report+=['node_projected'=>true,'run_success'=>true,'node_result_uri_matches_asset'=>true,'stored_checksum_valid'=>hash_file('sha256',$dir.'/public/'.$asset['local_uri'])===hash('sha256',$media),
        'file_records'=>(int)Db::name('tenant_file')->count(),'result_assets'=>(int)Db::name('ai_task_result_asset')->count(),'frozen_input_and_price_preserved'=>true,'edges_unchanged'=>$graphBefore['edges_json']===$graph['edges_json'],'viewport_unchanged'=>$graphBefore['viewport_json']===$graph['viewport_json'],
        'wallets_unchanged'=>(float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===100.&&(float)Db::name('user')->where('id',$user)->value('user_money')===100.];
}catch(Throwable $error){$failure=$error;$report+=['failure_class'=>get_class($error),'failure'=>$error->getMessage()];if($fixture)foreach(glob($fixture->dir.'/*.log')?:[]as$log)fwrite(STDERR,basename($log).":\n".substr(file_get_contents($log),-3000)."\n");}
finally{if($fixture)$fixture->cleanup();}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";if(isset($failure))exit(1);
