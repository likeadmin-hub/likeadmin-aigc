<?php
namespace Tests\Feature;
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketImageProviderRequestException;
use app\common\service\ai\AiTaskJobService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
final class MarketImageDeferredSubmitTest extends TestCase
{
    public function testRepeatedProvenUnacceptedErrorsKeepOneReservationAndFrozenJob():void
    {
        if(getenv('IMAGE_RETRY_TEST')!=='local-existing')self::markTestSkipped('Explicit local rollback only; no supplier I/O');
        (new \think\App())->initialize();self::assertSame('127.0.0.1',config('database.connections.mysql.hostname'));
        Db::startTrans();try{
            $key='C'.bin2hex(random_bytes(12));$t=AiAppTask::create(['tenant_id'=>1999999758,'user_id'=>0,'task_no'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'shot_image','business_table'=>'aigc_short_drama_generation_task','status'=>'running','idempotency_key'=>$key]);
            $c=AiConsumptionLog::create(['tenant_id'=>1999999758,'user_id'=>0,'app_task_id'=>$t['id'],'consume_no'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'shot_image','resource_type'=>'model','run_status'=>'submitting','billing_status'=>'reserved','reserved_user_price'=>0,'reserved_tenant_cost'=>0,'price_snapshot'=>[]]);
            $defer=new \ReflectionMethod(Images::class,'deferUnacceptedSubmit');$input=['prompt'=>'完整提示词','reference_images'=>['https://example.com/ref.png']];
            for($i=0;$i<5;$i++)self::assertTrue($defer->invoke(null,(int)$c['id'],$input,new MarketImageProviderRequestException('capacity',true,429),false));
            self::assertSame('retrying_submit',AiConsumptionLog::find($c['id'])['run_status']);self::assertSame('reserved',AiConsumptionLog::find($c['id'])['billing_status']);
            self::assertSame(1,(int)Db::name('ai_task_job')->where(['consumption_id'=>$c['id'],'job_type'=>'image_submit_retry'])->count());
            self::assertFalse($defer->invoke(null,(int)$c['id'],$input,new \RuntimeException('timeout'),false));
            self::assertFalse($defer->invoke(null,(int)$c['id'],$input,new MarketImageProviderRequestException('accepted',true,503,true),false));
            $job=Db::name('ai_task_job')->where('consumption_id',$c['id'])->find();$payload=json_decode($job['payload'],true);
            self::assertStringNotContainsString('完整提示词',json_encode($payload));
            $decoded=json_decode(\app\common\service\open_platform\OpenVault::open($payload['sealed']),true);self::assertSame($input,$decoded['request']);
            self::assertSame((int)$c['id'],$decoded['consumption_id']);
            try{$defer->invoke(null,(int)$c['id'],['prompt'=>'改动'],new MarketImageProviderRequestException('busy',true,429),false);self::fail('Frozen payload changed');}catch(\RuntimeException $e){self::assertStringContainsString('原请求不一致',$e->getMessage());}
            AiConsumptionLog::where('id',$c['id'])->update(['run_status'=>'submitting']);
            self::assertTrue(Images::resumeDeferredSubmit((int)$c['id'],$payload)); // Lost acceptance is never submitted again.
            self::assertSame('submitting',AiConsumptionLog::find($c['id'])['run_status']);

        }finally{Db::rollback();}
    }
}
