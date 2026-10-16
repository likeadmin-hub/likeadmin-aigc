<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiAppTask;
use think\facade\Db;

/** Micro-specific durable admission around the existing shared market transport. */
final class MicroVideoSubmission
{
    public static function applies(array $consumption): bool{return ($consumption['app_code']??'')===MicroVideoStore::APP;}
    public static function ambiguous(string $message): bool
    {
        return (bool)preg_match('/curl\s*(?:error\s*)?(?:18|28|35|52|55|56)|timed?\s*out|timeout|connection.*(?:reset|closed|aborted)|empty reply|recv failure|发送后.*断|未返回.*任务号|受理.*不明/iu',$message);
    }
    public static function begin(int $id): bool
    {
        return Db::transaction(function()use($id){$c=AiConsumptionLog::lock(true)->findOrEmpty($id);if($c->isEmpty()||!self::applies($c->toArray()))return true;if($c['run_status']!=='reserved')return false;$c->save(['run_status'=>'submitting','update_time'=>time()]);return true;});
    }
    public static function capture(int $id,array $response,string $taskId,string $requestId): void
    {
        Db::transaction(function()use($id,$response,$taskId,$requestId){$c=AiConsumptionLog::lock(true)->findOrEmpty($id);if($c->isEmpty()||!self::applies($c->toArray())||!in_array($c['billing_status'],['reserved','pending_usage'],true))return;
            $summary=MicroVideoStore::json($c['response_summary']);$summary['micro_provider_receipt']=$response;
            $c->save(['run_status'=>'running','upstream_task_id'=>$taskId,'upstream_request_id'=>$requestId,'response_summary'=>$summary,'update_time'=>time()]);});
    }
    public static function hold(int $id,string $message): bool
    {
        return Db::transaction(function()use($id,$message){$c=AiConsumptionLog::lock(true)->findOrEmpty($id);if($c->isEmpty()||!self::applies($c->toArray()))return false;
            $receipt=self::receipt($c->toArray());if(!$receipt&&!self::ambiguous($message))return false;
            if(!in_array($c['billing_status'],['reserved','pending_usage'],true))return true;
            $c->save(['run_status'=>$receipt?'running':'pending_confirmation','error_code'=>'micro_submission_pending','error_message'=>'模型调用状态待核对，已保留进度','finish_time'=>0,'update_time'=>time()]);
            AiAppTask::where('id',$c['app_task_id'])->update(['status'=>'running','finish_time'=>0,'update_time'=>time()]);return true;});
    }
    public static function receipt(array $c): ?array
    {
        if(!self::applies($c))return null;$r=MicroVideoStore::json($c['response_summary']??'')['micro_provider_receipt']??null;return is_array($r)?$r:null;
    }
    public static function pending(array $c): bool{return self::applies($c)&&in_array($c['run_status']??'',['submitting','pending_confirmation'],true)&&empty($c['upstream_task_id'])&&self::receipt($c)===null;}
}
