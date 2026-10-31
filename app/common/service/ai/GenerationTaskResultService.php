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
        if ($id <= 0 || $tenantId <= 0 || $userId <= 0) throw new InvalidArgumentException('生成任务不存在或无权访问');
        $owner = ['tenant_id'=>$tenantId, 'user_id'=>$userId];
        $task = AiAppTask::where($owner + ['id'=>$id])->find();
        if (!$task) throw new InvalidArgumentException('生成任务不存在或无权访问');
        $consumptions = AiConsumptionLog::where($owner + ['app_task_id'=>$id])->order('id')->select()->toArray();
        $ids = array_column($consumptions, 'id');
        $assets = $ids ? AiTaskResultAsset::where($owner)->whereIn('app_task_id', [0,$id])->whereIn('consumption_id', $ids)->order('id')->select()->toArray() : [];
        $results = []; $transferPending = false; $billingPending = false; $executionPending = false; $unknown = false;
        foreach ($consumptions as $c) {
            $executionPending = $executionPending || !in_array($c['run_status'], ['success','failed','canceled','cancelled'], true);
            $unknown = $unknown || in_array($c['run_status'], ['submitting','pending_confirmation','needs_reconciliation'], true);
            $billingPending = $billingPending || !in_array($c['billing_status'], ['settled','refunded','none'], true);
            $required = AiTaskBusinessResultService::requiresForcedTransfer((int)$c['id']);
            $summary = (array)$c['response_summary'];
            $expected = false;
            foreach (['images','videos','items','audio','assets','image_count','video_count','audio_count','asset_count'] as $key) $expected = $expected || !empty($summary[$key]);
            $ownAssets = array_values(array_filter($assets, static fn($a)=>(int)$a['consumption_id']===(int)$c['id']));
            if ($required && $c['run_status']==='success' && $expected && !$ownAssets) $transferPending = true;
            foreach ($ownAssets as $asset) {
                $stored = $asset['transfer_status']==='stored' && trim((string)$asset['local_uri'])!=='';
                if (!$stored && $required) { if ($c['run_status']==='success') $transferPending = true; continue; }
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
            'status'=>$status, 'execution_status'=>$raw, 'stage'=>$stage, 'progress'=>$status==='success'?100:min(99,(int)$task['progress']),
            'polling_required'=>$status==='running' || $billingPending || $transferPending,
            'billing_status'=>$billingPending?'pending':($consumptions ? (string)end($consumptions)['billing_status'] : 'none'),
            'delivery_status'=>$transferPending?'pending':'ready', 'results'=>$results,
            'error'=>$error===''?null:['code'=>'generation_failed','message'=>UpstreamErrorMessageService::normalize($error)],
            'created_at'=>(int)$task['create_time'], 'finished_at'=>(int)$task['finish_time']];
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
        return $response;
    }
}
