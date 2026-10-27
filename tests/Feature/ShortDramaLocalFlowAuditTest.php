<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service, ShortDramaContentAcceptance as Acceptance, ShortDramaEpisodeDuration as Duration, ShortDramaPlanningContext as Context, ShortDramaSceneBinding as Binding, ShortDramaScriptGeneration as Script, ShortDramaStoryWorkflow as Story};
use PHPUnit\Framework\TestCase;

class ShortDramaLocalFlowAuditTest extends TestCase
{
    private function invoke(string $class, string $method, ...$args)
    {
        $m = new \ReflectionMethod($class, $method); $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    private function plan(): array
    {
        return ['title'=>'等候', 'story_outline'=>'陈溪等候后进入办公室', 'script_lines'=>['陈溪等候后进入办公室'],
            'subjects'=>[['id'=>'p','name'=>'陈溪','description'=>'年轻职员']],
            'locations'=>[['id'=>'hall','name'=>'走廊','description'=>'办公室外的走廊','interior_exterior'=>'interior'],
                ['id'=>'office','name'=>'办公室','description'=>'室内办公场所','interior_exterior'=>'interior']]];
    }

    /** Both independent scripts and queued episodes share this generator. */
    public function testTimelineCheckpointsAcceptPrefixesAndRetainCompleteFinalTiming(): void
    {
        foreach ([[], ['series_context'=>['current_episode'=>['episode_number'=>2]]]] as $context) {
            $request = $context + ['_input_contract_version'=>17, 'episode_duration_policy'=>Duration::snapshot([],0,0,[
                ['start_seconds'=>10,'end_seconds'=>15,'duration_seconds'=>5],
                ['start_seconds'=>15,'end_seconds'=>20,'duration_seconds'=>5]])];
            $plan = $this->plan();
            $plan['scene_beats'] = [
                ['scene_ref_id'=>'hall','scene_name'=>'走廊','goal'=>'等候','entry'=>'陈溪站在走廊','exit'=>'陈溪等候通知','key_events'=>['陈溪等候通知'],'duration_seconds'=>5,'shot_durations'=>[5]],
                ['scene_ref_id'=>'office','scene_name'=>'办公室','goal'=>'进入','entry'=>'陈溪走进办公室','exit'=>'陈溪坐下','key_events'=>['陈溪坐下'],'duration_seconds'=>5,'shot_durations'=>[5]]];
            $calls = []; $checkpoints = [];
            $out = Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'保留剧情','content'=>'等候后进入办公室'],
                static function($key,$input) use (&$calls,$plan) {
                    $calls[]=$key;
                    if (str_contains($key,'skeleton')) $payload=$plan;
                    else {
                        $first=str_contains($key,'scene_1_');
                        $payload=['storyboard'=>[['shot_id'=>$first?'s1_1':'s2_1','scene_ref_id'=>$first?'hall':'office',
                            'scene_name'=>$first?'走廊':'办公室','subject_ref_ids'=>['p'],'interior_exterior'=>'interior',
                            'visual_description'=>$first?'陈溪站在办公室门外，等候通知。':'陈溪走进办公室，坐下。',
                            'dialogue'=>'','recommended_duration_seconds'=>5]]];
                    }
                    return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
                }, static function($event,$payload) use (&$checkpoints) {
                    if ($event==='script_checkpoint' && !empty($payload['storyboard'])) $checkpoints[]=$payload['storyboard'];
                });
            self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_2_1'],$calls);
            self::assertCount(1,$checkpoints[0]);
            self::assertEquals(10,$checkpoints[0][0]['start_seconds']);
            self::assertEquals(15,$checkpoints[0][0]['end_seconds']);
            self::assertEquals(20,$out['payload']['storyboard'][1]['end_seconds']);
            Duration::assertPlan($out['payload'],$request);
            try {
                Duration::assertPlan(['storyboard'=>$checkpoints[0]],$request);
                self::fail('Partial checkpoint must still fail final acceptance');
            } catch (\RuntimeException $e) { self::assertSame(422,$e->getCode()); }
        }
    }

    public function testRelativePlacesAndNegatedMovementDoNotOverrideExplicitScene(): void
    {
        foreach (['陈溪站在办公室门外，等候通知。','办公室门口，陈溪等候通知。','陈溪站在办公室外，等候通知。','陈溪没有走进办公室，停留在走廊。'] as $text) {
            $plan=$this->plan();
            $shot=['scene_ref_id'=>'hall','scene_name'=>'走廊','visual_description'=>$text,'interior_exterior'=>'interior'];
            $plan['storyboard']=[$shot];
            self::assertSame($shot,Binding::normalize($plan,['_input_contract_version'=>17])['storyboard'][0]);
        }
        $plan=$this->plan(); $plan['locations'][]=['id'=>'door','name'=>'办公室门外'];
        $plan['storyboard']=[['scene_ref_id'=>'door','scene_name'=>'办公室门外','visual_description'=>'陈溪站在办公室门外，等候通知。']];
        self::assertSame('door',Binding::normalize($plan,['_input_contract_version'=>17])['storyboard'][0]['scene_ref_id']);
    }

    public function testActualInteriorConflictStillFails(): void
    {
        $plan=$this->plan(); $plan['storyboard']=[['scene_ref_id'=>'hall','scene_name'=>'走廊','visual_description'=>'陈溪站在办公室内，等候通知。']];
        $this->expectExceptionMessage('场景语义不一致'); Binding::normalize($plan,['_input_contract_version'=>17]);
    }

    public function testOldSceneContractRemainsFrozen(): void
    {
        $plan=$this->plan(); $plan['storyboard']=[['scene_ref_id'=>'hall','scene_name'=>'走廊','visual_description'=>'陈溪站在办公室门外，等候通知。']];
        $this->expectExceptionCode(422); Binding::normalize($plan,['_input_contract_version'=>16]);
    }

    public function testLastOutlineBatchRetainsPlanningProjectionAndOriginalRequirements(): void
    {
        $base=$this->plan()+['runtime_marker'=>'不应发给模型'];
        $base['subjects'][0]['image_url']='https://example.invalid/private-media';
        $request=['_input_contract_version'=>17,'_generation_version'=>3,'workflow_variant'=>Story::VARIANT,'multi_episode'=>true,
            'multi_episode_stage'=>'episodes','episode_count'=>1,'episode_total_count'=>11,'episode_batch_start'=>11,'episode_batch_end'=>11,
            '_short_drama_episode_batch'=>true,'prompt'=>'第11集才揭露秘密','revision_base_result'=>$base];
        self::assertTrue(Context::isOutline($request));
        $data=Context::templateRequest($request);
        self::assertSame($request['prompt'],$data['prompt']);
        self::assertSame(11,$data['episode_batch_start']);
        self::assertStringNotContainsString('runtime_marker',json_encode($data));
        self::assertStringNotContainsString('private-media',json_encode($data));
        self::assertSame('陈溪',$data['revision_base_result']['subjects'][0]['name']);
        self::assertFalse(Context::isOutline(array_replace($request,['_input_contract_version'=>16])));
        self::assertFalse(Story::enabled(array_replace($request,['multi_episode'=>false,'multi_episode_stage'=>'production'])));
        self::assertFalse(Story::enabled(array_replace($request,['_short_drama_episode_batch'=>false])));
    }

    public function testSavedResultReviewUsesGenerationTimingContract(): void
    {
        $request=['_input_contract_version'=>17,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];
        $plan=$this->plan(); $plan['storyboard']=[];
        for($i=1;$i<=14;$i++) $plan['storyboard'][]=['shot_id'=>'s'.$i,'scene_ref_id'=>'hall','subject_ref_ids'=>['p'],
            'visual_description'=>'陈溪在走廊等待通知。','dialogue'=>'','recommended_duration_seconds'=>10];
        Duration::assertPlan($plan,$request);
        $result=$this->invoke(Service::class,'normalizeGeneratedPlanResult',$plan,'按实际剧情安排时长',$request,'等候');
        self::assertSame(17,$result['generation_settings']['input_contract_version']);
        self::assertEquals(140,array_sum(array_column($result['storyboard'],'recommended_duration_seconds')));
        $review=$this->invoke(Service::class,'reviewPlanResult',$result);
        self::assertNotContains('storyboard.duration.mismatch',array_column($review['issues'],'code'));
        $result['generation_settings']['episode_duration_policy']=Duration::snapshot([],120,0,[]);
        $review=$this->invoke(Service::class,'reviewPlanResult',$result);
        self::assertContains('storyboard.duration.mismatch',array_column($review['issues'],'code'));
    }

    public function testRequirementSubmissionDoesNotStripOtherEpisodeScope(): void
    {
        $source="共同设定。\n第1集：找到信。拆开信。\n第2集：追到车站。母亲上车。\n全剧要求：不增加角色。";
        $seen=[];
        $request=['_input_contract_version'=>17,'prompt'=>$source,'revision_message'=>'本集不拆信','series_context'=>['current_episode'=>['episode_number'=>1]]];
        $this->invoke(Acceptance::class,'requirements',$request,static function($key,$input,$tokens,$validate) use (&$seen) {
            $seen[]=json_decode($input['content'],true); $reply=['requirements'=>[]]; $validate($reply); return $reply;
        });
        self::assertCount(1,$seen);
        self::assertSame($source."\n最新用户修改（冲突时以此为准）：本集不拆信",$seen[0]['source']);
        self::assertSame(preg_replace('/\s+/u','',$seen[0]['source']),preg_replace('/\s+/u','',implode('',array_column($seen[0]['sources'],'text'))));
        self::assertSame(1,$seen[0]['episode_number']);
        self::assertSame('',$seen[0]['preceding_scope_heading']);
    }

    public function testLongRequirementsKeepExactTextAndScopeAcrossChunks(): void
    {
        $source="第2集：母亲上车。\n".str_repeat('母亲在车上等候。',1000)."\n全剧要求：".str_repeat('不增加角色。',1000);
        $chunks=Acceptance::requirementSourceChunks($source);
        self::assertSame($source,implode('',array_column($chunks,'source')));
        self::assertSame('第2集',$chunks[1]['preceding_scope_heading']);
        self::assertSame('全剧要求',end($chunks)['preceding_scope_heading']);
        foreach($chunks as $chunk) self::assertLessThanOrEqual(6000,mb_strlen($chunk['source']));
    }

    public function testResumedLegacyTaskRetainsItsExplicitRecoveryTimingPolicy(): void
    {
        $request=['_generation_recovery_version'=>1,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];
        $plan=$this->plan(); $plan['storyboard']=array_map(static fn($i)=>['shot_id'=>'s'.$i,'scene_ref_id'=>'hall',
            'subject_ref_ids'=>['p'],'visual_description'=>'陈溪在走廊等待。','recommended_duration_seconds'=>10],range(1,14));
        Duration::assertPlan($plan,$request);
        $result=$this->invoke(Service::class,'normalizeGeneratedPlanResult',$plan,'按实际剧情安排时长',$request,'等候');
        self::assertTrue($result['generation_settings']['timing_recovery_enabled']);
        Duration::assertPlan($result,$result);
        unset($result['generation_settings']['timing_recovery_enabled']);
        $this->expectExceptionCode(422);
        Duration::assertPlan($result,$result);
    }
}
