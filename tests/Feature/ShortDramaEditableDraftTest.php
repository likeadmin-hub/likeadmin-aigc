<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaEditableDraft as Draft;
use app\common\service\app\aigc_short_drama\ShortDramaScriptPreview as Preview;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaEditableDraftTest extends TestCase
{
    private function plan(): array
    {
        return ['title'=>'故事', 'subjects'=>[['id'=>'c1','name'=>'甲']], 'storyboard'=>[
            ['shot_id'=>'s1_1','visual_description'=>'原画面','dialogue'=>'甲：原台词','voice_role'=>'甲','speech_type'=>'character']]];
    }

    public function testEditsMergeOnlyAgainstTheirOriginalFieldsAndRequireReview(): void
    {
        $plan=$this->plan();
        $edits=['edits'=>['s1_1'=>['visual_description'=>['before'=>'原画面','after'=>'新画面']]]];
        $merged=Draft::merge($plan,$edits,['_input_contract_version'=>25]);
        self::assertSame('新画面',$merged['storyboard'][0]['visual_description']);
        self::assertSame('甲：原台词',$merged['storyboard'][0]['dialogue']);
        self::assertSame('needs_review',$merged['script_review']['status']);
        self::assertSame('blocked',$merged['_continuity']['dependency_status']);
        $plan['storyboard'][0]['visual_description']='已经变化';
        $this->expectExceptionCode(409);
        Draft::merge($plan,$edits,[]);
    }

    public function testMissingOrDuplicateIdentitiesCannotBeMerged(): void
    {
        $plan=$this->plan(); $plan['storyboard'][]=$plan['storyboard'][0];
        $this->expectExceptionCode(409);
        Draft::merge($plan,['edits'=>['s1_1'=>['dialogue'=>['before'=>'甲：原台词','after'=>'新台词']]]],[]);
    }

    public function testFailureLocationUsesOnlyRecognizedSafeDetails(): void
    {
        $state=['__stream_unit'=>'v3_scene_2_3', '__script_checkpoint'=>$this->plan()];
        $info=Preview::recoveryInfo($state,'failed','分镜 s2_3 的对白缺少说话人');
        self::assertSame('s2_3',$info['shot_id']); self::assertSame('对白或说话人',$info['field_label']);
        self::assertSame('内容校验未通过',$info['issue']); self::assertSame('第2场，从第3个分镜开始',$info['recent_stage']);
    }

    public function testTransactionalDraftSaveChecksOwnershipRevisionAndTaskState(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to transactional local DB tests');
        (new \think\App())->initialize(); Db::startTrans();
        try {
            $scope=['tenant_id'=>2000000719,'user_id'=>7,'task_id'=>'test_draft_'.bin2hex(random_bytes(8))];
            $plan=$this->plan(); $state=['__script_checkpoint'=>$plan];
            Db::name('aigc_short_drama_script_task')->insert($scope+['project_id'=>17,'status'=>'failed',
                'request_json'=>'{"_generation_version":3}', 'result_json'=>json_encode($state,JSON_UNESCAPED_UNICODE),
                'create_time'=>1,'update_time'=>1]);
            $original=Db::name('aigc_short_drama_script_task')->where($scope)->find();
            $view=Draft::view($scope['tenant_id'],7,$scope['task_id'],$plan);
            $saved=Draft::save($scope['tenant_id'],7,$scope['task_id'],17,$view['token'],'s1_1',['visual_description'=>'新画面']);
            self::assertTrue($saved['edited']); self::assertSame('新画面',$saved['plan']['storyboard'][0]['visual_description']);
            self::assertSame($original,Db::name('aigc_short_drama_script_task')->where($scope)->find());
            self::assertSame('原画面',Draft::load($scope['tenant_id'],7,$scope['task_id'])['edits']['s1_1']['visual_description']['before']);
            foreach ([[$scope['tenant_id'],7,$view['token'],409],[$scope['tenant_id']+1,7,$saved['token'],404],[$scope['tenant_id'],8,$saved['token'],404]] as [$tenant,$user,$token,$expected]) {
                try { Draft::save($tenant,$user,$scope['task_id'],17,$token,'s1_1',['dialogue'=>'新台词']); self::fail('Unsafe save accepted'); }
                catch (\RuntimeException $error) { self::assertSame($expected,$error->getCode()); }
            }
            Db::name('aigc_short_drama_script_task')->where($scope)->update(['status'=>'running']);
            try { Draft::save($scope['tenant_id'],7,$scope['task_id'],17,$saved['token'],'s1_1',['dialogue'=>'新台词']); self::fail('Running task edited'); }
            catch (\RuntimeException $error) { self::assertSame(409,$error->getCode()); }
            self::assertSame('新画面',Draft::load($scope['tenant_id'],7,$scope['task_id'])['plan']['storyboard'][0]['visual_description']);
        } finally { Db::rollback(); }
    }
}
