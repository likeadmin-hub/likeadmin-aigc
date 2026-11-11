<?php
namespace app\common\service\ai;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiAppTask;
use think\facade\Db;

/** Durable video admission shared by composed creation apps. Receipt keys remain backward compatible. */
final class MarketVideoSubmissionService
{
    public static function applies(array $consumption): bool{return in_array($consumption['app_code']??'',['aigc_micro_video','aigc_product_video','aigc_motion_sync','aigc_story_commerce'],true);}
    public static function ambiguous(string $message): bool
    {
        return (bool)preg_match('/curl\s*(?:error\s*)?(?:16|18|28|35|52|55|56|92)|timed?\s*out|timeout|connection.*(?:reset|closed|aborted)|empty (?:reply|response)|recv failure|transfer closed|outstanding read data|视频 API 响应格式错误|图片模型未返回任务 ID 或结果|图片 API 响应格式错误|图片模型返回格式错误|图片模型响应格式错误|文本模型返回格式异常|文本模型未返回有效内容|超时|发送后.*断|未返回.*任务号|受理.*不明/iu',$message);
    }
    public static function begin(int $id): bool
    {
        return Db::transaction(function()use($id){$c=AiConsumptionLog::lock(true)->findOrEmpty($id);if($c->isEmpty()||!self::applies($c->toArray()))return true;if($c['run_status']!=='reserved')return false;$c->save(['run_status'=>'submitting','update_time'=>time()]);return true;});
    }
    public static function capture(int $id,array $response,string $taskId,string $requestId): void
    {
        Db::transaction(function()use($id,$response,$taskId,$requestId){$c=AiConsumptionLog::lock(true)->findOrEmpty($id);if($c->isEmpty()||!self::applies($c->toArray())||!in_array($c['billing_status'],['reserved','pending_usage'],true))return;
            $summary=\app\common\service\power\MarketGenerationSchema::array($c['response_summary']);$summary['micro_provider_receipt']=$response;
            $c->save(['run_status'=>'running','upstream_task_id'=>$taskId,'upstream_request_id'=>$requestId,'response_summary'=>$summary,'update_time'=>time()]);});
    }
    public static function hold(int $id,string $message): bool
    {
        return Db::transaction(function()use($id,$message){$c=AiConsumptionLog::lock(true)->findOrEmpty($id);if($c->isEmpty()||!self::applies($c->toArray()))return false;
            $summary=\app\common\service\power\MarketGenerationSchema::array($c['response_summary']);
            $receipt=self::receipt($c->toArray());$accepted=$receipt||(string)$c['upstream_task_id']!==''||!empty($summary['videos']);
            if(!$accepted&&!self::ambiguous($message))return false;
            if(!in_array($c['billing_status'],['reserved','pending_usage'],true))return true;
            $c->save(['run_status'=>$accepted?'running':'pending_confirmation','error_code'=>'micro_submission_pending','error_message'=>'模型调用状态待核对，已保留进度','finish_time'=>0,'update_time'=>time()]);
            AiAppTask::where('id',$c['app_task_id'])->update(['status'=>'running','finish_time'=>0,'update_time'=>time()]);return true;});
    }
    public static function receipt(array $c): ?array
    {
        if(!self::applies($c))return null;$r=\app\common\service\power\MarketGenerationSchema::array($c['response_summary']??'')['micro_provider_receipt']??null;return is_array($r)?$r:null;
    }
    public static function pending(array $c): bool{return self::applies($c)&&in_array($c['run_status']??'',['submitting','pending_confirmation'],true)&&empty($c['upstream_task_id'])&&self::receipt($c)===null;}
}
