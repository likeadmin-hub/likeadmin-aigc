<?php
namespace Tests\Feature;
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\power\{MarketTextModelRuntimeService as Runtime,TextSettlementPending};
use PHPUnit\Framework\TestCase;
use think\facade\Db;
final class MarketTextValidationReceiptTest extends TestCase
{
    public function testCompletedInvalidDecisionRetainsUsageAndReceiptWithoutRefundOrReplay():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only, no provider');
        (new \think\App())->initialize();Db::startTrans();try{
            $key='C'.bin2hex(random_bytes(12));$t=AiAppTask::create(['tenant_id'=>1999999759,'user_id'=>0,'task_no'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'short_drama_canvas_agent_chat','business_table'=>'aigc_short_drama_canvas_agent_run','status'=>'running','idempotency_key'=>$key]);
            $c=AiConsumptionLog::create(['tenant_id'=>1999999759,'user_id'=>0,'app_task_id'=>$t['id'],'consume_no'=>$key,'app_code'=>'aigc_short_drama','run_status'=>'submitting','billing_status'=>'pending_usage','price_snapshot'=>[]]);
            $r=['content'=>'{"action":"bad-shape"}','finish_reason'=>'stop','provider_request_id'=>'fixture-receipt','usage'=>['prompt_tokens'=>120,'completion_tokens'=>10,'total_tokens'=>130]];
            try{(new \ReflectionMethod(Runtime::class,'validateStructuredResult'))->invoke(null,['consumption'=>$c,'appTask'=>$t],$r,static function(){throw new \RuntimeException('INVALID_AGENT_INTENT');});self::fail('Invalid decision accepted');}
            catch(TextSettlementPending $e){self::assertSame((int)$c['id'],$e->consumptionId);}
            $saved=AiConsumptionLog::find($c['id']);self::assertSame('settlement_failed',$saved['billing_status']);self::assertSame(130,$saved['usage_snapshot']['total_tokens']);
            self::assertSame(0,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$c['id'],'event_type'=>'refund'])->count());
            $event=Db::name('ai_consumption_event')->where(['consumption_id'=>$c['id'],'event_type'=>'validation_receipt'])->find();self::assertNotEmpty($event['payload_ciphertext']);
            $receipt=json_decode(\app\common\service\open_platform\OpenVault::open($event['payload_ciphertext']),true);self::assertSame($r,$receipt['result']);self::assertSame((int)$c['id'],$receipt['consumption_id']);
        }finally{Db::rollback();}
    }
}
