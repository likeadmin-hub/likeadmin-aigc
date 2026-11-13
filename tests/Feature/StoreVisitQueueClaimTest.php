<?php
namespace Tests\Feature;
use app\common\service\ai\AiTaskJobService as Queue;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
final class StoreVisitQueueClaimTest extends TestCase
{
    public function testAppWorkerClaimsNativeJobsBeforeAConsumptionExistsWithoutTakingForeignWork(): void
    {
        if(getenv('AIGC_TEST_DATABASE')!=='x_cn')self::markTestSkipped('Disposable x_cn only');
        (new \think\App())->initialize();self::assertSame('x_cn',Db::connect()->getConfig('database'));self::assertSame('127.0.0.1',Db::connect()->getConfig('hostname'));
        Db::startTrans();try{
            $make=static fn($deleted=0)=>Db::name('aigc_store_visit_job')->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>1,'project_revision'=>1,'action'=>'analysis','idempotency_key'=>bin2hex(random_bytes(16)),'input_hash'=>str_repeat('a',64),'status'=>'queued','request_json'=>'{}','receipt_json'=>'{}','result_json'=>'{}','delete_time'=>$deleted]);
            $id=$make();$target=Queue::enqueue(Queue::TYPE_STORE_VISIT,0,0,['story_job_id'=>$id,'tenant_id'=>1,'user_id'=>1],999999,false,'store_visit:'.$id);
            $deleted=$make(time());$dead=Queue::enqueue(Queue::TYPE_STORE_VISIT,0,0,['story_job_id'=>$deleted,'tenant_id'=>1,'user_id'=>1],999999,false,'store_visit:'.$deleted);
            $wrong=$make();$otherType=Queue::enqueue(Queue::TYPE_QUERY_RESULT,0,0,[],999999,false,'store_visit:'.$wrong);
            $orphan=Queue::enqueue(Queue::TYPE_STORE_VISIT,0,0,['story_job_id'=>2147483647],999999,false,'store_visit:2147483647');
            $foreign=Db::name('ai_app_task')->insertGetId(['task_no'=>bin2hex(random_bytes(12)),'tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_pic_lipsync','idempotency_key'=>bin2hex(random_bytes(12))]);
            $foreignId=$make();$otherApp=Queue::enqueue(Queue::TYPE_STORE_VISIT,0,0,[],999999,false,'store_visit:'.$foreignId);Db::name('ai_task_job')->where('id',$otherApp)->update(['app_task_id'=>$foreign]);
            $claimed=Queue::claim('story-fixture',30,20,'aigc_store_visit');self::assertSame([$target],array_column($claimed,'id'));
            self::assertSame(0,(int)$claimed[0]['consumption_id']);self::assertSame(1,(int)$claimed[0]['attempts']);
            foreach([$dead,$otherType,$orphan,$otherApp]as$job)self::assertSame('pending',Db::name('ai_task_job')->where('id',$job)->value('status'));
            self::assertSame([],Queue::claim('other-app-fixture',30,10,'aigc_motion_sync'));
        }finally{Db::rollback();}
    }
}
