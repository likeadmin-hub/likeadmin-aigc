<?php
namespace app\common\service\ai;

use app\common\model\ai\{AiAppTask, AiConsumptionLog, AiTaskResultAsset};
use app\common\service\FileService;
use InvalidArgumentException;

/** Versioned user-facing task view, built only from owned durable records. No provider polling or task writes. */
final class GenerationTaskResultService
{
    /** For an identity verified before supplier transport; never infer a task ID from a provider response. */
    public static function pending(int $id): array
    {
        return ['schema_version'=>1,'task_ref'=>'app_task:'.$id,'app_task_id'=>$id,'status'=>'running','stage'=>'reconciling',
            'polling_required'=>true,'results'=>[],'error'=>null];
    }

    public static function read(int $id, int $tenantId, int $userId): array
    {
        $views=self::readMany([$id],$tenantId,$userId);
        if (!isset($views[$id])) throw new InvalidArgumentException('生成任务不存在或无权访问');
        return $views[$id];
    }

    /** Batch by owned task/consumption/asset, so a multi-image application does not perform per-child lookups. */
    public static function readMany(array $ids, int $tenantId, int $userId): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
        if (!$ids || $tenantId<=0 || $userId<=0) return [];
        $requested=$ids;
        $owner=['tenant_id'=>$tenantId,'user_id'=>$userId];
        $tasks=AiAppTask::where($owner)->whereIn('id',$ids)->field('id,app_code,business_table,business_id,status,progress,create_time,finish_time')->select()->toArray();
        if (!$tasks) return [];
        $ids=array_column($tasks,'id');
        // Inspect only media presence in SQL: inline result bytes and large pricing/request snapshots do not belong in this view.
        $presence=[];
        foreach (['images','videos','items','audio','assets'] as $key) $presence[]="(JSON_TYPE(JSON_EXTRACT(response_summary, '$.".$key."')) IN ('ARRAY','OBJECT') AND COALESCE(JSON_LENGTH(response_summary, '$.".$key."'),0)>0)";
        foreach (['image_count','video_count','audio_count','asset_count'] as $key) $presence[]="COALESCE(JSON_EXTRACT(response_summary, '$.".$key."'),0)>0";
        $fields="id,app_task_id,provider,run_status,billing_status,error_message,CASE WHEN JSON_VALID(response_summary) THEN (".implode(' OR ',$presence).") ELSE 0 END AS has_result_assets";
        $consumptions=AiConsumptionLog::where($owner)->whereIn('app_task_id',$ids)->fieldRaw($fields)->order('id','asc')->select()->toArray();
        $consumptionIds=array_column($consumptions,'id');
        $assetFields="id,app_task_id,consumption_id,asset_type,local_uri,storage_scope,storage_engine,storage_domain,transfer_status,storage_meta,CASE WHEN transfer_status='stored' OR LEFT(external_url,5)='data:' THEN '' ELSE external_url END AS external_url";
        $assets=$consumptionIds ? AiTaskResultAsset::where($owner)->whereIn('app_task_id',array_merge([0],$ids))->whereIn('consumption_id',$consumptionIds)->fieldRaw($assetFields)->order('id','asc')->select()->toArray() : [];
        $result=[];
        $byId=array_column($tasks,null,'id');
        foreach ($requested as $id) {
            if (!isset($byId[$id])) continue;
            $task=$byId[$id];$owned=array_values(array_filter($consumptions,static fn($c)=>(int)$c['app_task_id']===$id));
            $result[$id]=self::project($id,$task,$owned,array_values(array_filter($assets,static fn($a)=>(int)$a['app_task_id']===0||(int)$a['app_task_id']===$id)));
        }
        return $result;
    }

    private static function project(int $id, array $task, array $consumptions, array $assets): array
    {
        $results = []; $transferPending = false; $billingPending = false; $executionPending = false; $unknown = false;
        foreach ($consumptions as $c) {
            $executionPending = $executionPending || !in_array($c['run_status'], ['success','failed','canceled','cancelled'], true);
            $unknown = $unknown || in_array($c['run_status'], ['submitting','pending_confirmation','needs_reconciliation'], true);
            $billingPending = $billingPending || !in_array($c['billing_status'], ['settled','refunded','none'], true);
            $required = AiTaskBusinessResultService::requiresForcedTransferFor($c,(string)$task['app_code'],(string)$task['business_table']);
            $expected = !empty($c['has_result_assets']);
            $ownAssets = array_values(array_filter($assets, static fn($a)=>(int)$a['consumption_id']===(int)$c['id']));
            if ($required && $c['run_status']==='success' && $expected && !$ownAssets) $transferPending = true;
            foreach ($ownAssets as $asset) {
                $stored = $asset['transfer_status']==='stored' && trim((string)$asset['local_uri'])!=='';
                if (!$stored && $required) { if ($c['run_status']==='success') $transferPending = true; continue; }
                if ($c['run_status']!=='success' || !in_array($c['billing_status'],['settled','none'],true)) continue;
                $uri = (string)($stored ? $asset['local_uri'] : $asset['external_url']);
                if ($uri==='' || preg_match('~^(?:data|blob|javascript|file):~i', $uri)) continue;
                $meta = (array)$asset['storage_meta'];
                $scope = (string)$asset['storage_scope']; $engine = (string)$asset['storage_engine']; $domain = (string)$asset['storage_domain'];
                if ($stored && ($scope==='' || $engine==='')) { $transferPending = true; continue; }
                $results[] = ['result_asset_id'=>(int)$asset['id'], 'type'=>(string)$asset['asset_type'], 'uri'=>$uri,
                    'url'=>$stored ? FileService::getFileUrlByStorage($uri,$scope,$engine,$domain) : $uri,
                    'storage_scope'=>$scope, 'storage_engine'=>$engine, 'storage_domain'=>$domain,
                    'transfer_status'=>(string)$asset['transfer_status'], 'width'=>(int)($meta['width']??0),
                    'height'=>(int)($meta['height']??0), 'duration'=>(float)($meta['duration']??0),
                    'mime_type'=>(string)($meta['mime_type']??''), 'file_size'=>(int)($meta['file_size']??0)];
            }
        }
        $raw = (string)$task['status'];
        $status = match ($raw) {'success','succeeded','completed'=>'success', 'failed','error'=>'failed', 'canceled','cancelled'=>'canceled', default=>'running'};
        if ($status==='success' && ($executionPending || $billingPending || $transferPending)) $status='running';
        $stage = match (true) {$unknown=>'confirmation', $status==='failed'=>'failed', $status==='canceled'=>'canceled',
            $executionPending=>'generating', $billingPending=>'settling', $transferPending=>'transferring', $status==='success'=>'ready', default=>'queued'};
        $error = '';
        foreach ($consumptions as $c) if (!empty($c['error_message'])) $error = (string)$c['error_message'];
        return ['schema_version'=>1, 'task_ref'=>'app_task:'.$id, 'app_task_id'=>$id, 'app_code'=>(string)$task['app_code'],
            'result_contract'=>array_filter($consumptions,static fn($c)=>$c['provider']==='power_market') ? 'managed_assets' : 'legacy_business',
            'status'=>$status, 'execution_status'=>$raw, 'stage'=>$stage, 'progress'=>$status==='success'?100:min(99,(int)$task['progress']),
            'polling_required'=>$status==='running' || $billingPending || $transferPending,
            'billing_status'=>$billingPending?'pending':($consumptions ? (string)end($consumptions)['billing_status'] : 'none'),
            'delivery_status'=>$transferPending?'pending':'ready', 'results'=>$results,
            'error'=>$error===''?null:['code'=>'generation_failed','message'=>UpstreamErrorMessageService::normalize($error)],
            'created_at'=>self::timestamp($task['create_time']), 'finished_at'=>self::timestamp($task['finish_time'])];
    }

    private static function timestamp(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : (is_string($value) && $value!=='' ? (strtotime($value) ?: 0) : 0);
    }

    public static function appendConsumption(array $response, int $id, int $tenantId, int $userId): array
    {
        $c = AiConsumptionLog::where(['id'=>$id,'tenant_id'=>$tenantId,'user_id'=>$userId])->find();
        if (!$c || (int)$c['app_task_id']<=0) return $response;
        return self::appendBusiness($response,(int)$c['app_task_id'],$tenantId,$userId);
    }

    /** A native/legacy business ID keeps its meaning; the canonical task reference is a separate field. */
    public static function appendBusiness(array $response, int $appTaskId, int $tenantId, int $userId): array
    {
        if ($appTaskId>0 && $tenantId>0 && $userId>0) {
            try { $response['generation_task']=self::read($appTaskId,$tenantId,$userId); }
            catch (InvalidArgumentException) { $response['generation_task_unavailable']=true; }
            catch (\Throwable) { $response['generation_task']=self::pending($appTaskId); }
        }
        return $response;
    }

    /** Carry only our versioned view through legacy result formatters; legacy task_id/results retain their meanings. */
    public static function carry(array $response, array $source): array
    {
        $task=$source['generation_task']??null;
        if (is_array($task) && ($task['schema_version']??null)===1 && (int)($task['app_task_id']??0)>0
            && ($task['task_ref']??'')==='app_task:'.$task['app_task_id']) $response['generation_task']=$task;
        if (isset($source['generation_tasks']) && is_array($source['generation_tasks'])) {
            $children=[];
            foreach ($source['generation_tasks'] as $child) {
                $copy=self::carry([],['generation_task'=>$child]);
                if (isset($copy['generation_task'])) $children[]=$copy['generation_task'];
            }
            $response['generation_tasks']=$children;
            $response['generation_task_refs']=array_column($children,'task_ref');
        }
        return $response;
    }

    /** A live delivery view must not become part of a frozen request/provider receipt or its idempotency comparison. */
    public static function withoutView(array $payload): array
    {
        unset($payload['generation_task'],$payload['generation_tasks'],$payload['generation_task_refs'],
            $payload['generation_task_unavailable'],$payload['generation_tasks_pending']);
        return $payload;
    }

    public static function appendNative(array $response, string $kind, int $nativeId, int $tenantId, int $userId): array
    {
        if ($nativeId<=0 || $tenantId<=0 || $userId<=0) return $response;
        $table=match($kind){'image'=>'aigc_image_task','video'=>'aigc_video_task','audio'=>'aigc_music_task',default=>''};
        if ($table==='') return $response;
        try {
            $id=(int)\think\facade\Db::name($table)->where(['id'=>$nativeId,'tenant_id'=>$tenantId,'user_id'=>$userId,'delete_time'=>0])->value('app_task_id');
            return self::appendBusiness($response,$id,$tenantId,$userId);
        } catch (\Throwable) { return $response+['generation_task_unavailable'=>true]; }
    }
    /** The caller supplies child IDs from an already-owned business record, never from browser payloads. */
    public static function appendRelated(array $response, string $kind, array $ids, int $tenantId, int $userId): array
    {
        if ($tenantId<=0 || $userId<=0 || !$ids) return $response;
        $table=match($kind){'image'=>'aigc_image_task','video'=>'aigc_video_task',default=>throw new InvalidArgumentException('不支持的生成子任务类型')};
        try {
            $rows=\think\facade\Db::name($table)->where(['tenant_id'=>$tenantId,'user_id'=>$userId,'delete_time'=>0])->whereIn('id',array_map('intval',$ids))->field('id,app_task_id')->select()->toArray();
            $links=array_column($rows,'app_task_id','id');
            $views=self::readMany(array_map(static fn($nativeId)=>(int)($links[$nativeId]??0),$ids),$tenantId,$userId);
            $response['generation_tasks']=array_values($views);
            $response['generation_task_refs']=array_column($response['generation_tasks'],'task_ref');
        } catch (\Throwable) {
            // Parent/children may already be accepted. A projection outage is not permission to create another batch.
            $response['generation_tasks_pending']=true;
        }
        return $response;
    }

    /** List views batch by owner, rather than querying each card or child individually. */
    public static function appendNativeList(array $rows): array
    {
        $groups=[];
        foreach ($rows as $row) if ((int)($row['app_task_id']??0)>0 && (int)($row['tenant_id']??0)>0 && (int)($row['user_id']??0)>0) {
            $key=$row['tenant_id'].':'.$row['user_id'];$groups[$key]['owner']=[(int)$row['tenant_id'],(int)$row['user_id']];$groups[$key]['ids'][]=(int)$row['app_task_id'];
        }
        $views=[];$outages=[];
        foreach ($groups as $key=>$group) {try{$views[$key]=self::readMany($group['ids'],...$group['owner']);}catch(\Throwable){$views[$key]=[];$outages[$key]=true;}}
        foreach ($rows as &$row) {
            $key=($row['tenant_id']??0).':'.($row['user_id']??0);$id=(int)($row['app_task_id']??0);
            if (isset($views[$key][$id])) $row['generation_task']=$views[$key][$id];
            elseif ($id>0 && !empty($outages[$key])) $row['generation_task']=self::pending($id);
        }
        unset($row);return $rows;
    }

    public static function appendRelatedList(array $rows, string $kind): array
    {
        $table=match($kind){'image'=>'aigc_image_task','video'=>'aigc_video_task',default=>throw new InvalidArgumentException('不支持的生成子任务类型')};
        $groups=[];$keys=[];
        foreach ($rows as $index=>$row) {
            $tenant=(int)($row['tenant_id']??0);$user=(int)($row['user_id']??0);if($tenant<=0||$user<=0)continue;
            $ids=$kind==='video'?[(int)($row['video_task_id']??0)]:($row['image_task_ids']??[(int)($row['image_task_id']??0)]);
            if(is_string($ids))$ids=json_decode($ids,true)?:[];
            $ids=array_values(array_filter(array_map('intval',(array)$ids),static fn($id)=>$id>0));
            $key=$tenant.':'.$user;$keys[$index]=[$key,$ids];$groups[$key]['owner']=[$tenant,$user];$groups[$key]['ids']=array_merge($groups[$key]['ids']??[],$ids);
        }
        $links=[];$views=[];$outages=[];
        foreach($groups as$key=>$group){
            try{
                $native=\think\facade\Db::name($table)->where(['tenant_id'=>$group['owner'][0],'user_id'=>$group['owner'][1],'delete_time'=>0])->whereIn('id',array_unique($group['ids']))->field('id,app_task_id')->select()->toArray();
                $links[$key]=array_column($native,'app_task_id','id');$views[$key]=self::readMany(array_values($links[$key]),...$group['owner']);
            }catch(\Throwable){$views[$key]=[];$links[$key]=[];$outages[$key]=true;}
        }
        foreach($keys as$index=>[$key,$ids]){
            $children=[];foreach($ids as$id){$taskId=(int)($links[$key][$id]??0);if(isset($views[$key][$taskId]))$children[]=$views[$key][$taskId];}
            if($ids && !empty($outages[$key]))$rows[$index]['generation_tasks_pending']=true;
            $rows[$index]['generation_tasks']=$children;$rows[$index]['generation_task_refs']=array_column($children,'task_ref');
        }
        return $rows;
    }

}
