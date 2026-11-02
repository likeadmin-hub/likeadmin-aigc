<?php
namespace Tests\Feature;
use app\common\service\ai\AiTaskJobService as Queue;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class AiTaskScopedResultClaimTest extends TestCase
{
    public function testConsumptionScopeIncludesItsTransfersAndCannotClaimOtherJobs():void
    {
        if(getenv('AI_QUEUE_TEST')!=='local-existing')self::markTestSkipped('Claim only, rollback, no provider');
        (new \think\App())->initialize();self::assertSame('127.0.0.1',config('database.connections.mysql.hostname'));
        Db::startTrans();
        try {
            $id=random_int(1000000000,1900000000);
            $target=Queue::enqueueQueryResult($id,1000000);
            $other=Queue::enqueueQueryResult($id+1,2000000);
            $assetId=Db::name('ai_task_result_asset')->insertGetId(['consumption_id'=>$id,'app_task_id'=>0,'tenant_id'=>1,'user_id'=>3]);
            $transfer=Queue::enqueueTransfer($assetId);
            $jobs=Queue::claim('scoped-fixture',30,2,'',$id);
            self::assertEqualsCanonicalizing([$target,$transfer],array_column($jobs,'id'));
            self::assertSame('pending',Db::name('ai_task_job')->where('id',$other)->value('status'));
        } finally {Db::rollback();}
    }
}
