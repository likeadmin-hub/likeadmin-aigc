<?php
namespace app\common\service\app\smart_clip;

use app\common\model\ai\{AiAppTask,AiTaskResultAsset};
use app\common\model\app\smart_clip\{SmartClipTask,SmartClipResult,SmartClipBilling};
use app\common\service\ai\{AiTaskJobService,AiTaskResultAssetService,GenerationTaskResultService,UpstreamErrorMessageService};
use think\facade\Db;

/** Native clipping keeps its ledger; durable jobs and typed assets share the common result contract. */
final class SmartClipTaskRuntime
{
    public static function link(SmartClipTask $native): int
    {
        if(!in_array(strtolower((string)$native['provider']),['xhadmin','smart_clip'],true))return 0;
        return Db::transaction(function()use($native){
            $task=SmartClipTask::where(['id'=>$native['id'],'tenant_id'=>$native['tenant_id'],'user_id'=>$native['user_id']])->lock(true)->findOrEmpty();
            if($task->isEmpty()||(int)$task['delete_time']>0)throw new \RuntimeException('剪辑任务不存在');
            $key='smart_clip:'.$task['id'];
            $app=AiAppTask::where(['tenant_id'=>$task['tenant_id'],'idempotency_key'=>$key])->lock(true)->findOrEmpty();
            if($app->isEmpty())$app=AiAppTask::create([
                'task_no'=>'CL'.bin2hex(random_bytes(16)),'tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],
                'app_code'=>'smart_clip','action_code'=>$task['clip_type'],'business_table'=>'smart_clip_task','business_id'=>$task['id'],
                'status'=>'running','progress'=>5,'request_summary'=>['runtime_adapter'=>'native_smart_clip'],
                'result_summary'=>[],'estimated_tenant_cost'=>$task['tenant_cost_points'],'estimated_user_price'=>$task['user_charge_points'],
                'actual_tenant_cost'=>0,'actual_user_price'=>0,'idempotency_key'=>$key,'create_time'=>time(),'update_time'=>time(),'finish_time'=>0,
            ]);
            if((int)$app['user_id']!==(int)$task['user_id']||$app['business_table']!=='smart_clip_task'||(int)$app['business_id']!==(int)$task['id'])throw new \RuntimeException('剪辑任务关联不一致');
            AiTaskJobService::enqueueSmartClip((int)$app['id']);
            return (int)$app['id'];
        });
    }

    /** Repairs accepted legacy/crash-gap jobs without submitting any supplier generation. */
    public static function sweep(int $limit=100): int
    {
        $count=0;
        $linked=AiAppTask::alias('a')->join('ai_task_job j','j.app_task_id=a.id')->where(['a.app_code'=>'smart_clip','a.business_table'=>'smart_clip_task','j.job_type'=>AiTaskJobService::TYPE_SMART_CLIP])->field('a.business_id')->buildSql();
        foreach(SmartClipTask::where(['status'=>'running','delete_time'=>0])->whereIn('provider',['xhadmin','smart_clip'])->where('provider_task_id','<>','')->whereNotIn('id',Db::raw($linked))->order('id')->limit(max(1,min(500,$limit)))->select()as$task){
            self::link($task);$count++;
        }
        return $count;
    }

    public static function run(int $appId): bool
    {
        $app=AiAppTask::findOrEmpty($appId);
        if($app->isEmpty()||$app['app_code']!=='smart_clip'||$app['business_table']!=='smart_clip_task')throw new \RuntimeException('剪辑作业关联无效');
        $owner=['tenant_id'=>(int)$app['tenant_id'],'user_id'=>(int)$app['user_id']];
        $task=SmartClipTask::where($owner+['id'=>$app['business_id']])->findOrEmpty();
        if($task->isEmpty()||(int)$task['delete_time']>0){self::terminal($app,'canceled');return true;}
        if(in_array($task['status'],['failed','canceled'],true)){self::terminal($app,(string)$task['status']);return true;}
        if($app['status']==='success')return true;
        if($task['status']==='pending_confirmation'||trim((string)$task['provider_task_id'])==='')return false;
        $asset=AiTaskResultAsset::where($owner+['app_task_id'=>$appId,'consumption_id'=>0,'asset_type'=>'video'])->findOrEmpty();
        if($asset->isEmpty()&&$task['status']==='running'){
            $result=SmartClipService::queryAccepted($task);
            if(!$result->success){
                Db::transaction(function()use($task,$app,$result){
                    $current=SmartClipTask::where('id',$task['id'])->lock(true)->findOrEmpty();
                    if($current->isEmpty()||$current['status']!=='running'||(int)$current['delete_time']>0)return;
                    $current->save(['status'=>'failed','error'=>$result->error,'finish_time'=>time(),'update_time'=>time()]);
                    self::terminal($app,'failed');
                });return true;
            }
            if(!$result->videos)return false;
            $video=$result->videos[0];$url=trim((string)($video['url']??''));
            if($url==='')throw new \RuntimeException('剪辑结果地址待核对');
            $asset=Db::transaction(function()use($task,$appId,$owner,$url,$video){
                $current=SmartClipTask::where('id',$task['id'])->lock(true)->findOrEmpty();
                if($current->isEmpty()||$current['status']!=='running'||(int)$current['delete_time']>0)return null;
                $row=AiTaskResultAsset::where($owner+['app_task_id'=>$appId,'consumption_id'=>0,'asset_type'=>'video'])->findOrEmpty();
                if($row->isEmpty())$row=AiTaskResultAsset::create($owner+[
                    'app_task_id'=>$appId,'consumption_id'=>0,'asset_type'=>'video','external_url'=>$url,'local_uri'=>'',
                    'storage_scope'=>'','storage_engine'=>'','storage_domain'=>'','storage_meta'=>[],
                    'transfer_status'=>'external','transfer_attempts'=>0,'last_error'=>'','create_time'=>time(),'update_time'=>time(),
                ]);
                $history=(array)$current['provider_payload'];$history['accepted_result']=array_intersect_key($video,array_flip(['duration','cover_url']));
                $current->save(['provider_payload'=>$history,'update_time'=>time()]);
                return $row;
            });
            if(!$asset)return false;
        }
        if($asset->isEmpty())throw new \RuntimeException('剪辑历史结果尚未登记');
        // Existing stable-key transfer/checkpoint code resumes stored files after a worker dies.
        AiTaskResultAssetService::transfer((int)$asset['id']);$asset->refresh();
        if($asset['transfer_status']!=='stored')return false;
        return Db::transaction(function()use($task,$app,$asset){
            $current=SmartClipTask::where('id',$task['id'])->lock(true)->findOrEmpty();
            if($current->isEmpty()||(int)$current['delete_time']>0){self::terminal($app,'canceled');return true;}
            if(in_array($current['status'],['failed','canceled'],true)){self::terminal($app,(string)$current['status']);return true;}
            $selection=SmartClipService::acceptedSelection($current);$meta=(array)$asset['storage_meta'];
            $accepted=(array)($current['provider_payload']['accepted_result']??[]);
            $video=array_merge($accepted,$meta,['uri'=>$asset['local_uri'],'storage_scope'=>$asset['storage_scope'],'storage_engine'=>$asset['storage_engine'],'storage_domain'=>$asset['storage_domain'],'provider_task_id'=>$current['provider_task_id']]);
            $results=SmartClipService::finishTaskWithVideos($current,$selection,[$video]);
            if(!$results)return false;
            $fresh=AiAppTask::where('id',$app['id'])->lock(true)->findOrEmpty();
            if($fresh['status']!=='success')$fresh->save(['status'=>'success','progress'=>100,'actual_tenant_cost'=>$current['tenant_cost_points'],'actual_user_price'=>$current['user_charge_points'],'result_summary'=>['native_result_id'=>(int)$results[0]['id']],'finish_time'=>time(),'update_time'=>time()]);
            return true;
        });
    }

    private static function terminal(AiAppTask $app,string $state): void
    {
        AiAppTask::where('id',$app['id'])->whereNotIn('status',['success','failed','canceled'])->update(['status'=>$state,'finish_time'=>time(),'update_time'=>time()]);
    }

    /** Read only owned native rows and their canonical assets; deletion cannot leak a saved reference. */
    public static function views(array $apps,int $tenant,int $user): array
    {
        if(!$apps)return [];$owner=['tenant_id'=>$tenant,'user_id'=>$user];
        $native=SmartClipTask::where($owner+['delete_time'=>0])->whereIn('id',array_column($apps,'business_id'))->field('id,status,error,provider_task_id,finish_time')->select()->toArray();
        $byId=array_column($native,null,'id');$ids=array_column($native,'id');
        $visible=SmartClipResult::where($owner+['delete_time'=>0])->whereIn('task_id',$ids)->field('task_id,video_uri')->select()->toArray();
        $bills=SmartClipBilling::where($owner+['billing_status'=>'deducted'])->whereIn('task_id',$ids)->column('task_id');
        $assets=AiTaskResultAsset::where($owner+['consumption_id'=>0,'asset_type'=>'video'])->whereIn('app_task_id',array_column($apps,'id'))->select()->toArray();$out=[];
        foreach($apps as$app){
            $row=$byId[$app['business_id']]??null;if(!$row)continue;
            $state=match((string)$row['status']){'success'=>'success','failed'=>'failed','canceled'=>'canceled',default=>'running'};
            $results=[];$paid=in_array((int)$row['id'],array_map('intval',$bills),true);
            if($state==='success'&&$app['status']==='success'&&$paid)foreach($assets as$asset){
                if((int)$asset['app_task_id']!==(int)$app['id']||$asset['transfer_status']!=='stored')continue;
                $published=array_filter($visible,fn($r)=>(int)$r['task_id']===(int)$row['id']&&$r['video_uri']===$asset['local_uri']);
                if($published&&($dto=GenerationTaskResultService::resultAsset($asset,true)))$results[]=$dto;
            }
            $deliveryPending=$state==='success'&&array_filter($visible,fn($r)=>(int)$r['task_id']===(int)$row['id'])&&!$results;
            $pending=$state==='running'||($state==='success'&&($app['status']!=='success'||!$paid||$deliveryPending));
            if($pending)$state='running';
            $stage=match(true){$state==='failed'=>'failed',$state==='canceled'=>'canceled',$state==='success'=>'ready',$deliveryPending=>'transferring',$row['provider_task_id']===''||$row['status']==='pending_confirmation'=>'confirmation',default=>'generating'};
            $error=$state==='failed'?UpstreamErrorMessageService::normalize((string)$row['error']):'';
            $out[(int)$app['id']]=['schema_version'=>1,'task_ref'=>'app_task:'.$app['id'],'app_task_id'=>(int)$app['id'],'app_code'=>'smart_clip','result_contract'=>'managed_assets','status'=>$state,'execution_status'=>$row['status'],'stage'=>$stage,'progress'=>$state==='success'?100:(int)$app['progress'],'polling_required'=>$pending,'billing_status'=>$paid?'settled':($pending?'pending':'none'),'delivery_status'=>$pending?'pending':'ready','results'=>$results,'error'=>$error===''?null:['code'=>'generation_failed','message'=>$error],'created_at'=>(int)$app['create_time'],'finished_at'=>(int)$row['finish_time']];
        }return $out;
    }

    public static function appendViews(array $rows): array
    {
        $groups=[];foreach($rows as$i=>$row)$groups[$row['tenant_id'].':'.$row['user_id']][$i]=$row;
        foreach($groups as$group){try{$one=reset($group);$apps=AiAppTask::where(['tenant_id'=>$one['tenant_id'],'user_id'=>$one['user_id'],'app_code'=>'smart_clip','business_table'=>'smart_clip_task'])->whereIn('business_id',array_column($group,'id'))->select()->toArray();
            $views=GenerationTaskResultService::readMany(array_column($apps,'id'),(int)$one['tenant_id'],(int)$one['user_id']);$links=array_column($apps,'id','business_id');
            foreach($group as$i=>$row)if(isset($links[$row['id']],$views[$links[$row['id']]]))$rows[$i]['generation_task']=$views[$links[$row['id']]];
        }catch(\Throwable){foreach($group as$i=>$row)$rows[$i]['generation_task_unavailable']=true;}}return $rows;
    }
}
