<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\model\app\aigc_music_cover\AigcMusicCoverTask;
use app\common\service\app\aigc_music_cover\AigcMusicCoverService as Cover;
use think\facade\Db;

final class MusicCoverManagedLifecycleTest extends TestCase
{
    private function fixture(callable $check): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        if(!in_array(config('database.connections.mysql.database'),['x_cn','z_cn'],true))self::markTestSkipped('Local rollback only');
        Db::startTrans();
        try{
            $owner=['tenant_id'=>1,'user_id'=>2147482200];$key='cover-managed-'.bin2hex(random_bytes(6));
            $task=AigcMusicCoverTask::create($owner+['title'=>'Synthetic cover','status'=>'running']);
            $app=AiAppTask::create($owner+['task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_music_cover','business_table'=>'aigc_music_cover_task','business_id'=>$task['id'],'status'=>'success']);
            $bill=AiConsumptionLog::create($owner+['consume_no'=>$key,'app_task_id'=>$app['id'],'app_code'=>'aigc_music_cover','provider'=>'power_market','protocol'=>'application_api','price_snapshot'=>['app_code'=>'seedsvc'],'run_status'=>'success','billing_status'=>'reserved','actual_tenant_cost'=>1,'actual_user_price'=>3,'response_summary'=>['items'=>[['audio_uri'=>'uploads/managed-cover.wav']]]]);
            $task->save(['app_task_id'=>$app['id'],'consumption_id'=>$bill['id']]);
            $asset=AiTaskResultAsset::create($owner+['app_task_id'=>$app['id'],'consumption_id'=>$bill['id'],'asset_type'=>'audio','external_url'=>'https://fixtures.invalid/expired.wav','local_uri'=>'uploads/managed-cover.wav','storage_scope'=>'platform','storage_engine'=>'local','transfer_status'=>'stored','storage_meta'=>['mime_type'=>'audio/wav','duration'=>1,'file_size'=>100]]);
            $check($task,$app,$bill,$asset);
        }finally{Db::rollback();}
    }
    public function testUnsettledAndUnstoredAudioCannotCompleteAndReadyReplayDoesNotRewrite(): void
    {
        $this->fixture(function($task,$app,$bill,$asset){
            Cover::syncConsumption($task['id']);self::assertSame('running',$task->refresh()['status']);
            self::assertSame(0,Db::name('aigc_music_cover_result')->where('task_id',$task['id'])->count());
            $bill->save(['billing_status'=>'settled']);$asset->save(['transfer_status'=>'retrying']);
            Cover::syncConsumption($task['id']);self::assertSame('running',$task->refresh()['status']);
            $asset->save(['transfer_status'=>'stored']);Cover::syncConsumption($task['id']);
            self::assertSame('success',$task->refresh()['status']);self::assertEquals(3,$task['actual_user_price']);
            $rows=Db::name('aigc_music_cover_result')->where('task_id',$task['id'])->select()->toArray();self::assertCount(1,$rows);
            self::assertSame('uploads/managed-cover.wav',$rows[0]['audio_uri']);self::assertStringNotContainsString('fixtures.invalid',$rows[0]['audio_url']);
            $before=Db::name('aigc_music_cover_task')->find($task['id']);Cover::syncConsumption($task['id']);
            self::assertSame($before,Db::name('aigc_music_cover_task')->find($task['id']));
            self::assertSame($rows,Db::name('aigc_music_cover_result')->where('task_id',$task['id'])->select()->toArray());
        });
    }
    public function testForeignOrStaleConsumptionCannotProject(): void
    {
        $this->fixture(function($task,$app,$bill,$asset){
            $bill->save(['billing_status'=>'settled']);
            foreach(['foreign','stale'] as $case){
                if($case==='foreign')$app->save(['business_id'=>$task['id']+1]);else $app->save(['business_id'=>$task['id']]);
                try{Cover::syncConsumption($task['id'],0,$case==='stale'?$bill['id']+1:$bill['id']);self::fail('Invalid association projected');}
                catch(\Exception $e){self::assertStringContainsString('任务',$e->getMessage());}
                self::assertSame('running',$task->refresh()['status']);
            }
        });
    }
    public function testRefundMustFinishBeforeBusinessFailure(): void
    {
        $this->fixture(function($task,$app,$bill,$asset){
            $bill->save(['run_status'=>'canceled']);Cover::syncConsumption($task['id']);self::assertSame('running',$task->refresh()['status']);
            $bill->save(['billing_status'=>'refunded']);Cover::syncConsumption($task['id']);self::assertSame('canceled',$task->refresh()['status']);
        });
    }
}
