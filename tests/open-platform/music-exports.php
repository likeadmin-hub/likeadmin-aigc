<?php
/** Rollback fixtures use a loopback supplier and pre-stored receipts, never real media generation. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenStore as S,OpenVault as Vault,OpenCallContext as Context,OpenVerification};
use app\common\service\power\MarketMusicOperationRuntimeService as Runtime;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\aigc_music\AigcMusicTask;
use app\common\service\app\aigc_music\AigcMusicExportStorageService as Storage;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$config=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$config['hostname']==='127.0.0.1'&&$config['database']==='z_cn','Local rollback only');
$wallets=static fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
Db::startTrans();try{
    request()->tenantId=1;request()->userId=1;
    $ownedId=(int)Db::name('aigc_music_result')->where(['tenant_id'=>1,'user_id'=>1,'delete_time'=>0])->order('id','desc')->value('id');check($ownedId>0,'Owned music fixture absent');
    $reserve=new ReflectionMethod(Runtime::class,'reserve');$capture=new ReflectionMethod(Runtime::class,'capture');
    foreach(['wav','midi','mp4','vox'] as $api)foreach(['completed','processing','failed','query-error','storage-error'] as $scenario){
        if($api==='mp4'&&\app\common\service\app\aigc_music\MusicExportDecoder::binary()===''){try{Runtime::input('mp4',['result_id'=>$ownedId],1,1);throw new RuntimeException('Missing MP4 verifier accepted submission');}catch(app\common\service\open_platform\OpenException $e){check($e->errorType==='capability_unavailable','Wrong missing verifier rejection');}continue;}
        if($api==='mp4'&&in_array($scenario,['processing','query-error'],true)||$api==='vox'&&$scenario==='storage-error')continue;
        $params=['result_id'=>$ownedId];$budget=Runtime::budget(1,1,$api,$params);check($budget['user']>0&&$budget['tenant']>0,'Missing export-specific quote');
        $denied=false;try{Runtime::input($api,$params,1,2);}catch(Exception){$denied=true;}check($denied,'Cross-user result accepted');
        $rid='req_export_fixture_'.bin2hex(random_bytes(16));
        S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/'.Runtime::ROUTES[$api],'status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'snapshot'=>S::encode($budget['snapshot']),'consumption_ids'=>'[]','payload_hash'=>hash('sha256',$rid),'response_blob'=>'']+S::timestamp());Context::enter($rid);
        $selection=Runtime::selection(1,1,$api,$params);$cid=$reserve->invoke(null,1,1,$api,$selection['payload'],$selection);$c=AiConsumptionLog::findOrEmpty($cid);$snapshot=S::json($c['price_snapshot']);
        $job=\app\common\model\ai\AiTaskJob::where(['consumption_id'=>$cid,'job_type'=>'music_operation'])->findOrEmpty();check(!$job->isEmpty(),'Dedicated music recovery job missing');
        check(AiConsumptionLog::where('id',$cid)->whereIn('run_status',['reserved','submitted','running','success'])->whereIn('billing_status',['reserved','pending_usage'])->count()===0,'Legacy reconciler discovers new music operation');
        check(\app\common\model\ai\AiTaskJob::where('id',$job['id'])->whereIn('job_type',['query_result','process_result','transfer_result','settle','refund','admin_action'])->count()===0,'Legacy worker can claim new operation');
        $legacyDenied=false;try{\app\common\service\power\MarketMusicAppRuntimeService::refresh($cid);}catch(Exception){$legacyDenied=true;}check($legacyDenied,'Legacy music generation can settle export');
        $snapshot['music_source_blob']=Vault::seal(S::encode(['base_url'=>'http://127.0.0.1:19873','api_key'=>'fixture-server-key','ssl_verify'=>false]));
        if($scenario==='completed'&&$api!=='vox')$snapshot['music_export_blob']=Vault::seal(S::encode(['export_id'=>1,'file_id'=>'of_fixture','file_url'=>'https://example.com/stored.'.$api,'results'=>[['stored'=>true]]]));
        $c->save(['price_snapshot'=>$snapshot]);
        $task='music-operation-'.$api.'-'.($scenario==='storage-error'?'completed':$scenario);
        $wire=['code'=>1,'data'=>['task_id'=>$task,'status'=>'processing']];
        if($api==='mp4'||$scenario==='storage-error')$wire=['code'=>1,'data'=>['result'=>['status'=>$scenario==='failed'?'failed':'completed','file_url'=>$scenario==='storage-error'?'https://127.0.0.1/blocked.'.$api:'https://example.com/stored.'.$api]]];
        $capture->invoke(null,$cid,['status'=>200,'body'=>S::encode($wire)]);
        if($api!=='mp4'&&$scenario!=='storage-error'){$c->refresh();check($c['upstream_task_id']===$task&&AigcMusicTask::where('consumption_id',$cid)->value('provider_task_id')===$task,'Accepted async task ID not durably linked');}
        $error=null;try{\app\common\service\ai\AiTaskJobService::run($job->toArray());Runtime::refresh($cid);}catch(app\common\service\open_platform\OpenException $caught){$error=$caught;}
        $c->refresh();
        if($scenario==='completed'){
            check($c['run_status']==='success'&&$c['billing_status']==='settled'&&(float)$c['actual_user_price']===(float)$budget['user'],'Stored export or vox ID did not settle exactly once');
            $native=AigcMusicTask::where('consumption_id',$cid)->findOrEmpty();
            $lists=\app\common\service\app\aigc_music\AigcMusicService::taskLists(1,1,['sync_running'=>false]);
            $listed=array_values(array_filter($lists,static fn($task)=>(int)$task['id']===(int)$native['id']));
            check(count($listed)===1&&$listed[0]['billing_status']==='settled','Settled export missing from native user task list');
            check($api==='vox'?($listed[0]['vox_audio_id']??'')!=='':($listed[0]['file_id']??'')==='of_fixture'&&!empty($listed[0]['results']),'Native list lost auxiliary operation result');
            $other=\app\common\service\app\aigc_music\AigcMusicService::taskLists(1,2,['sync_running'=>false]);
            check(!array_filter($other,static fn($task)=>(int)$task['id']===(int)$native['id']),'Export leaked through another user task list');
            $row=S::table('request')->where('request_id',$rid)->find();OpenVerification::assertLedger($row);check(count(app\common\service\open_platform\OpenManagement::detail(1,1,$rid)['point_logs'])===1,'Repeated export recovery charged again');
            $capture->invoke(null,$cid,['status'=>503,'body'=>'{"code":0}']);$c->refresh();check($c['run_status']==='success'&&$c['billing_status']==='settled','Late receipt reopened terminal export');
        }elseif($scenario==='failed'){check($c['billing_status']==='refunded','Definitive async failure did not refund');OpenVerification::assertLedger(S::table('request')->where('request_id',$rid)->find());}
        else check($c['billing_status']==='reserved'&&(float)$c['actual_user_price']===0.,'Query/storage uncertainty refunded or settled incorrectly');
        if($scenario==='query-error')check($error&&$error->errorType==='query_unavailable'&&$c['upstream_task_id']===$task,'Temporary query failure lost accepted ID');
        if($scenario==='storage-error')check($error&&$error->errorType==='invalid_url','Nonpublic export URL was fetched or billed');
        Context::leave();
    }
    foreach(['wav','midi','mp4'] as $type){$denied=false;try{Storage::validate($type,'<html>not a media file</html>');}catch(Exception){$denied=true;}check($denied,'Export accepted HTML as media');}
    echo "PASS owned music exports freeze exact SKU; accepted async IDs persist and query loopback only; stored receipt/vox ID settle once; failed task refunds, query/storage failure retains reserve and terminal receipts stay closed\n";
}finally{Context::leave();Db::rollback();}
check($wallets()===$before,'Export fixtures changed local wallets');
