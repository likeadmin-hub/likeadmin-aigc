<?php
namespace app\common\service\power;
use app\common\model\ai\{AiConsumptionLog,AiConsumptionEvent};
use app\common\service\open_platform\OpenVault;
use think\facade\Db;

/** Operator reconciliation marker. No estimated usage, balance write or paid replay. */
final class MarketTextUsageReview
{
    public static function flag(int $tenant,int $user,int $id,string $requestId,array $evidence):void
    {
        Db::transaction(static function()use($tenant,$user,$id,$requestId,$evidence):void{
            $c=AiConsumptionLog::where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user])->lock(true)->find();
            if(!$c||$requestId===''||$c['upstream_request_id']!==$requestId)throw new \RuntimeException('用量核对记录归属或请求标识不一致');
            if($c['billing_status']==='settlement_failed'&&$c['error_code']==='provider_usage_missing')return;
            if($c['run_status']!=='success'||$c['billing_status']!=='pending_usage')throw new \RuntimeException('该记录不属于已交付但缺失用量的待核对记录');
            $c->save(['billing_status'=>'settlement_failed','error_code'=>'provider_usage_missing',
                'error_message'=>'已保留创作结果；上游未返回最终用量，费用待核对，未估算扣费','update_time'=>time()]);
            AiConsumptionEvent::create(['consumption_id'=>$id,'event_type'=>'usage_review','event_status'=>'pending','attempt_no'=>1,
                'payload_summary'=>['reason'=>'missing_final_usage','request_id'=>$requestId,'requires_exact_receipt'=>true],
                'payload_ciphertext'=>OpenVault::seal(json_encode(['consumption_id'=>$id,'request_id'=>$requestId,'evidence'=>$evidence],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
                'http_status'=>0,'elapsed_ms'=>0,'create_time'=>time()]);
        });
    }
}
