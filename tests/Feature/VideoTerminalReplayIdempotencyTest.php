<?php
namespace Tests\Feature;

use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\service\app\aigc_video\AigcVideoService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** New synthetic rows inside complete rollback; never refresh/submit a provider. */
class VideoTerminalReplayIdempotencyTest extends TestCase
{
    private array $ids=[];
    protected function setUp(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        Db::startTrans();
    }
    protected function tearDown(): void
    {
        if(!$this->ids)return;
        Db::rollback();
        foreach($this->ids as$id)self::assertSame(0,(int)Db::name('aigc_video_task')->where('id',$id)->count());
    }
    private function task(string $status): AigcVideoTask
    {
        $task=AigcVideoTask::create(['tenant_id'=>1,'user_id'=>1999999383,'provider'=>'power_market','status'=>$status,
            'prompt'=>'Synthetic terminal replay','channel'=>'synthetic-batch31','provider_task_id'=>'synthetic-provider-id',
            'error'=>$status==='failed'?'Synthetic failure':'','finish_time'=>$status==='running'?0:1700000001,
            'create_time'=>1700000000,'update_time'=>1700000002,'delete_time'=>0,
            'model_json'=>['generation_method'=>'omni_reference'],'pricing_snapshot'=>['synthetic_frozen'=>['audio'=>false,'seed'=>0]]]);
        $this->ids[]=(int)$task['id'];return$task;
    }
    private function replay(AigcVideoTask $task,array $result): array
    {
        return (new \ReflectionMethod(AigcVideoService::class,'applyMarketVideoResult'))->invoke(null,$task,$result,[]);
    }
    private function row(AigcVideoTask $task): array {return Db::name('aigc_video_task')->where('id',$task['id'])->find();}
    public function testRepeatedSuccessWithExistingResultNeverTouchesTheTask(): void
    {
        $task=$this->task('success');
        Db::name('aigc_video_result')->insert(['tenant_id'=>1,'user_id'=>1999999383,'task_id'=>$task['id'],
            'video_uri'=>'synthetic/batch31.mp4','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','create_time'=>1700000001,'delete_time'=>0]);
        $before=$this->row($task);$result=['status'=>'success','provider_task_id'=>'synthetic-provider-id','videos'=>[['video_uri'=>'synthetic/batch31.mp4']]];
        for($i=0;$i<2;$i++){self::assertSame('success',$this->replay($task,$result)['status']);self::assertSame($before,$this->row($task));}
        self::assertSame(1,(int)Db::name('aigc_video_result')->where('task_id',$task['id'])->count());
    }
    public function testRepeatedFailurePreservesFinishTimeAndEverySavedField(): void
    {
        $task=$this->task('failed');$before=$this->row($task);
        for($i=0;$i<2;$i++){$this->replay($task,['status'=>'failed','error'=>'Synthetic failure']);self::assertSame($before,$this->row($task));}
    }
    public function testDelayedFailureStillTransitionsAndThenBecomesStable(): void
    {
        $task=$this->task('running');$frozen=$task['pricing_snapshot'];
        $this->replay($task,['status'=>'failed','error'=>'Synthetic failure']);$after=$this->row($task);
        self::assertSame('failed',$after['status']);self::assertGreaterThan(0,(int)$after['finish_time']);
        self::assertSame($frozen,json_decode($after['pricing_snapshot'],true));
        $this->replay($task,['status'=>'failed','error'=>'Synthetic failure']);self::assertSame($after,$this->row($task));
    }
    public function testLateProviderIdentityIsRecoveredOnceWithoutRedatingFailure(): void
    {
        $task=$this->task('failed');$this->replay($task,['status'=>'failed','error'=>'Synthetic failure','provider_task_id'=>'recovered-id']);$after=$this->row($task);
        self::assertSame('recovered-id',$after['provider_task_id']);self::assertSame(1700000001,(int)$after['finish_time']);
        $this->replay($task,['status'=>'failed','error'=>'Synthetic failure','provider_task_id'=>'recovered-id']);self::assertSame($after,$this->row($task));
    }
}
