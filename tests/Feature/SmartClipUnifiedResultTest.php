<?php
namespace Tests\Feature;

use app\common\model\ai\{AiAppTask,AiTaskResultAsset};
use app\common\model\app\smart_clip\{SmartClipTask,SmartClipResult,SmartClipBilling};
use app\common\service\ai\GenerationTaskResultService as View;
use app\common\service\app\smart_clip\SmartClipTaskRuntime as Runtime;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class SmartClipUnifiedResultTest extends TestCase
{
    private function fixture(callable $check): void
    {
        if(getenv('AIGC_TRANSFER_CHECKPOINT_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        Db::startTrans();
        try{
            $owner=['tenant_id'=>199999955,'user_id'=>199999955];
            $native=SmartClipTask::create($owner+['provider'=>'xhadmin','model'=>'smart_clip','clip_type'=>'news_mixcut','status'=>'running','provider_task_id'=>'synthetic-known','duration'=>2,'tenant_cost_points'=>2,'user_charge_points'=>2,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
            $id=Runtime::link($native);$check($native,AiAppTask::find($id),$owner);
        }finally{Db::rollback();}
    }
    public function testLinkCreatesOneDurableNativeJobWithoutInventingConsumptionOrRepeatingBilling(): void
    {
        $this->fixture(function($n,$a,$o){$before=Db::name('ai_consumption_log')->count();self::assertSame((int)$a['id'],Runtime::link($n));self::assertSame(1,Db::name('ai_task_job')->where('app_task_id',$a['id'])->count());self::assertSame($before,Db::name('ai_consumption_log')->count());$r=View::read((int)$a['id'],...array_values($o));self::assertTrue($r['polling_required']);self::assertSame('app_task:'.$a['id'],$r['task_ref']);self::assertSame([],$r['results']);});
    }
    public function testNativeLedgerAndOwnedStoredResultAreRequiredAndReadsNeverPoll(): void
    {
        $this->fixture(function($n,$a,$o){
            $n->save(['status'=>'success','finish_time'=>time()]);$a->save(['status'=>'success']);
            $result=SmartClipResult::create($o+['task_id'=>$n['id'],'video_uri'=>'uploads/native-clip-test.mp4','delete_time'=>0]);
            $asset=AiTaskResultAsset::create($o+['app_task_id'=>$a['id'],'consumption_id'=>0,'asset_type'=>'video','external_url'=>'https://supplier.invalid/expired.mp4','local_uri'=>'uploads/native-clip-test.mp4','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','storage_meta'=>['width'=>32,'file_size'=>500,'provider_private'=>'must-not-return'],'transfer_status'=>'stored']);
            self::assertSame('running',View::read((int)$a['id'],...array_values($o))['status']);
            SmartClipBilling::create($o+['task_id'=>$n['id'],'result_id'=>$result['id'],'billing_status'=>'deducted']);
            $r=View::read((int)$a['id'],...array_values($o));self::assertSame('success',$r['status']);self::assertSame('settled',$r['billing_status']);self::assertSame((int)$asset['id'],$r['results'][0]['result_asset_id']);self::assertStringNotContainsString('must-not-return',json_encode($r));
            $before=[Db::name('ai_task_job')->where('app_task_id',$a['id'])->select()->toArray(),$n->refresh()->toArray(),$a->refresh()->toArray()];View::read((int)$a['id'],...array_values($o));self::assertSame($before,[Db::name('ai_task_job')->where('app_task_id',$a['id'])->select()->toArray(),$n->refresh()->toArray(),$a->refresh()->toArray()]);
            $asset->save(['user_id'=>$o['user_id']+1]);$r=View::read((int)$a['id'],...array_values($o));self::assertSame('transferring',$r['stage']);self::assertSame([],$r['results']);
            $result->save(['delete_time'=>time()]);self::assertSame([],View::read((int)$a['id'],...array_values($o))['results']);
            $n->save(['delete_time'=>time()]);$this->expectException(\InvalidArgumentException::class);View::read((int)$a['id'],...array_values($o));
        });
    }
    public function testCrossOwnerAndDeletedNativeTasksCannotBeReadByCanonicalReference(): void
    {
        $this->fixture(function($n,$a,$o){try{View::read((int)$a['id'],$o['tenant_id'],$o['user_id']+1);self::fail('Foreign owner accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}$n->save(['delete_time'=>time()]);self::assertTrue(Runtime::run((int)$a['id']));self::assertSame('canceled',AiAppTask::find($a['id'])['status']);self::assertSame(0,SmartClipBilling::where('task_id',$n['id'])->count());});
    }
    public function testMixedBatchKeepsRequestedOrderAndNativeReadDoesNotDuplicateTaskCenterRows(): void
    {
        $this->fixture(function($n,$a,$o){
            $key='order-'.bin2hex(random_bytes(8));$other=AiAppTask::create($o+['task_no'=>$key,'app_code'=>'aigc_canvas','business_table'=>'aigc_canvas_run','business_id'=>0,'status'=>'running','idempotency_key'=>$key]);
            self::assertSame([(int)$other['id'],(int)$a['id']],array_keys(View::readMany([(int)$other['id'],(int)$a['id']],...array_values($o))));
            $rows=\app\common\service\ai\AiUsageService::appTaskLists(['user_id'=>$o['user_id'],'exclude_native_smart_clip'=>true],$o['tenant_id']);
            self::assertSame(1,$rows['count']);self::assertSame((int)$other['id'],(int)$rows['rows'][0]['id']);
        });
    }

}
