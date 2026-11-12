<?php
namespace app\common\service\power;

use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\open_platform\{OpenCallContext,OpenSubmission};
use think\facade\Db;

/** Durable admission for non-gateway bounded image tasks. Never calls a provider. */
final class MarketBoundedImageSubmission
{
    private static function applies(array $snapshot): bool
    {
        $meter=(array)($snapshot['billing_meter']??[]);
        return MarketGenerationMeter::boundedToken($meter)||MarketGenerationPixelMeter::applies($meter);
    }
    public static function begin(int $id): bool
    {
        return Db::transaction(function()use($id){
            $c=AiConsumptionLog::lock(true)->findOrEmpty($id);if($c->isEmpty())return false;
            // Gateway, admin and composed studios retain their own admission/lease policies.
            if(!self::applies((array)$c['price_snapshot'])||OpenCallContext::id()!==''||OpenSubmission::linked($c->toArray()))return true;
            if($c['run_status']!=='reserved'||$c['billing_status']!=='reserved')return false;
            $c->save(['run_status'=>'submitting','finish_time'=>0,'update_time'=>time()]);return true;
        });
    }
    public static function holdUnknown(int $id): bool
    {
        if(OpenCallContext::id()!=='')return OpenSubmission::holdUnknown($id);
        return Db::transaction(function()use($id){
            $c=AiConsumptionLog::lock(true)->findOrEmpty($id);
            if($c->isEmpty()||!self::applies((array)$c['price_snapshot']))return false;
            if(!in_array($c['billing_status'],['reserved','pending_usage'],true))return true;
            $summary=(array)$c['response_summary'];
            $accepted=trim((string)$c['upstream_task_id'])!==''||!empty($summary['images'])||!empty($summary['pixel_meter_receipt'])||!empty($summary['token_meter_receipt']);
            $c->save(['run_status'=>$accepted?'running':'pending_confirmation','finish_time'=>0,'error_code'=>'pending_confirmation','error_message'=>'图片服务受理状态待核对，禁止自动重试','update_time'=>time()]);
            AiAppTask::where(['id'=>$c['app_task_id'],'tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id']])->update(['status'=>'running','finish_time'=>0,'update_time'=>time()]);return true;
        });
    }
}
