<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService, ShortDramaScriptPreview};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaDraftRecoveryTest extends TestCase
{
    public function testRecoveryInfoUsesSavedCheckpointAndCoarseStageOnly(): void
    {
        $state=['__script_checkpoint'=>['story_outline'=>'已保存故事','storyboard'=>[['shot_id'=>'s1_1']]],
            '__stream_unit'=>'v3_timed_scene_3_5_fields_v1','__stream_content'=>'untrusted raw details'];
        self::assertSame(['has_draft'=>true,'saved_shots'=>1,'recent_stage'=>'第3场，从第5个分镜开始'], ShortDramaScriptPreview::recoveryInfo($state,'failed'));
        self::assertSame([],ShortDramaScriptPreview::recoveryInfo($state,'running'));
        self::assertSame(['has_draft'=>false,'saved_shots'=>0,'recent_stage'=>''],ShortDramaScriptPreview::recoveryInfo(['__stream_content'=>'not validated'],'failed'));
    }

    public function testFailureStatePreservesCheckpointAndFencesOldAttempt(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional local fixtures');
        (new \think\App())->initialize();$tenant=2000000736;$task='draft_recovery_'.bin2hex(random_bytes(8));
        $scope=['tenant_id'=>$tenant,'user_id'=>7,'task_id'=>$task];
        Db::startTrans();
        try {
            Db::name('aigc_short_drama_script_task')->insert($scope+['project_id'=>0,'status'=>'running','retry_count'=>5,'request_json'=>'{}']);
            $method=new \ReflectionMethod(AigcShortDramaService::class,'persistScriptPlanStreamState');$method->setAccessible(true);
            $draft=['story_outline'=>'保留原文','storyboard'=>[['shot_id'=>'s1_1','dialogue'=>'完整台词']]];
            $method->invoke(null,$tenant,7,$task,'incomplete raw','','失败',3,$draft,'v3_timed_scene_1_2',5);
            $saved=json_decode(Db::name('aigc_short_drama_script_task')->where($scope)->value('result_json'),true);
            self::assertSame($draft,$saved['__script_checkpoint']);self::assertSame('v3_timed_scene_1_2',$saved['__stream_unit']);self::assertSame(3,$saved['__stream_revision']);
            $method->invoke(null,$tenant,7,$task,'old response','','旧失败',0,[],'',4);
            self::assertSame($saved,json_decode(Db::name('aigc_short_drama_script_task')->where($scope)->value('result_json'),true));
        } finally { Db::rollback(); }
    }
}
