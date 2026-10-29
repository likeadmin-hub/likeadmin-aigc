<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\power\MarketTextUsageReview;
final class MarketTextUsageReviewTest extends TestCase
{
    public function testMissingUsageReviewPreservesDeliveredOutputAndNeverInventsACharge():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();Db::startTrans();try{
            $key='review'.bin2hex(random_bytes(10));$t=AiAppTask::create(['tenant_id'=>1999999759,'user_id'=>7,'task_no'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'script_plan','status'=>'success','idempotency_key'=>$key]);
            $c=AiConsumptionLog::create(['tenant_id'=>1999999759,'user_id'=>7,'app_task_id'=>$t['id'],'consume_no'=>$key,'app_code'=>'aigc_short_drama','run_status'=>'success','billing_status'=>'pending_usage','upstream_request_id'=>'provider-fixture',
                'usage_snapshot'=>['provider_reported'=>false],'response_summary'=>['output_length'=>99],'actual_user_price'=>0,'actual_tenant_cost'=>0]);
            foreach([1,2]as$attempt)MarketTextUsageReview::flag(1999999759,7,(int)$c['id'],'provider-fixture',['candidate_only'=>true]);
            $saved=AiConsumptionLog::find($c['id']);self::assertSame('success',$saved['run_status']);self::assertSame('settlement_failed',$saved['billing_status']);self::assertSame(['provider_reported'=>false],$saved['usage_snapshot']);
            self::assertSame(99,$saved['response_summary']['output_length']);self::assertEquals(0,$saved['actual_user_price']);
            self::assertSame(1,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$c['id'],'event_type'=>'usage_review'])->count());
            self::assertSame('success',AiAppTask::find($t['id'])['status']);
        }finally{Db::rollback();}
    }
}
