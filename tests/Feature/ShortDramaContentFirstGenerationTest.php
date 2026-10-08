<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaContentFirstGeneration as Engine,
    ShortDramaEpisodeDuration as Duration, ShortDramaShotDuration as ShotDuration,
    ShortDramaDialogueSplit as Speech, ShortDramaEpisodeTimingRepair as Timing};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ShortDramaContentFirstGenerationTest extends TestCase
{
    private function plan(): array
    {
        return ['title'=>'廊下告别','story_outline'=>'父亲在大厅谈话，女儿到花廊送客',
            'subjects'=>[['id'=>'daughter','name'=>'女儿']],
            'locations'=>[['id'=>'hall','name'=>'大厅'],['id'=>'gallery','name'=>'花廊']],
            'scene_beats'=>[['scene_ref_id'=>'hall','goal'=>'谈话告别','entry'=>'客人到来','exit'=>'廊下告别',
                'key_events'=>['大厅谈话','廊下告别']]], 'storyboard'=>[]];
    }
    private function shot(string $location = 'gallery'): array
    {
        return ['scene_ref_id'=>$location,'subject_ref_ids'=>['daughter'],'visual_description'=>'女儿挥手告别',
            'dialogue'=>'女儿：再见。','voice_role'=>'女儿','speech_type'=>'character','recommended_duration_seconds'=>4];
    }
    private function request(): array
    {
        return ['_input_contract_version'=>9,'quality_review_enabled'=>false,'episode_duration_policy'=>Duration::snapshot([], 0, 0, [])];
    }
    public function testUnitMayMoveAcrossRegisteredSetsWithoutFixedShotCount(): void
    {
        $plan = Engine::mergeUnit($this->plan(), ['storyboard'=>[$this->shot('hall'),$this->shot(),$this->shot()]], 'u1');
        self::assertSame(['hall','gallery','gallery'],array_column($plan['storyboard'],'scene_ref_id'));
        self::assertSame(['u1_s1','u1_s2','u1_s3'],array_column($plan['storyboard'],'shot_id'));
        self::assertCount(1,$plan['scene_beats']);
    }
    public function testMissingActorCanBeRegisteredWithoutOverwritingKnownIdentity(): void
    {
        $shot=$this->shot();$shot['subject_ref_ids']=['father'];$shot['dialogue']='父亲：一路平安。';$shot['voice_role']='父亲';
        $part=['subjects'=>[['id'=>'father','name'=>'父亲','category'=>'character']], 'storyboard'=>[$shot]];
        $plan=Engine::mergeUnit($this->plan(),$part,'u1');
        self::assertCount(2,$plan['subjects']);
        self::assertSame('daughter',$plan['subjects'][0]['id']);
        $this->expectException(RuntimeException::class);
        Engine::mergeUnit($plan,['subjects'=>[['id'=>'daughter','name'=>'新身份']], 'storyboard'=>[$shot]],'u2');
    }
    public function testUnknownReferenceCannotBeAccepted(): void
    {
        $this->expectException(RuntimeException::class);
        Engine::mergeUnit($this->plan(),['storyboard'=>[$this->shot('unknown')]],'u1');
    }
    public function testDuplicateUnitDoesNotAppendAgain(): void
    {
        $plan=Engine::mergeUnit($this->plan(),['storyboard'=>[$this->shot()]],'u1');
        $this->expectException(RuntimeException::class);
        Engine::mergeUnit($plan,['storyboard'=>[$this->shot()]],'u1');
    }
    public function testPriorCheckpointSurvivesLaterProviderFailure(): void
    {
        $skeleton=$this->plan();$skeleton['scene_beats'][]=$skeleton['scene_beats'][0];$saved=[];
        try {
            Engine::generate($this->request(),['system_prompt'=>'创作','content'=>'故事'], function($key)use($skeleton){
                if($key==='content_v9_skeleton')return $skeleton;
                if($key==='content_v9_u1')return ['storyboard'=>[$this->shot()]];
                throw new RuntimeException('provider unavailable',503);
            }, static function($event,$value)use(&$saved){if($event==='script_checkpoint')$saved[]=$value;});
            self::fail('Provider failure must be surfaced');
        }catch(RuntimeException $e){self::assertSame(503,$e->getCode());}
        self::assertCount(1,$saved);self::assertSame(['u1_s1'],array_column($saved[0]['storyboard'],'shot_id'));
    }
    public function testTruncatedUnitSplitsOnlyItsRemainingEvents(): void
    {
        $calls=[];$snapshots=[];
        $result=Engine::generate($this->request(),['system_prompt'=>'创作','content'=>'故事'],function($key,$input)use(&$calls){
            $calls[]=$key;
            if($key==='content_v9_skeleton')return $this->plan();
            if($key==='content_v9_u1')throw new RuntimeException('truncated',413);
            $data=json_decode($input['content'],true);
            self::assertCount(1,$data['current_event']['key_events']);
            return ['storyboard'=>[$this->shot()]];
        },static function($event,$value)use(&$snapshots){if($event==='script_checkpoint')$snapshots[]=$value;});
        self::assertSame(['content_v9_skeleton','content_v9_u1','content_v9_u1a','content_v9_u1b'],$calls);
        self::assertSame(['u1a_s1','u1b_s1'],array_column($result['storyboard'],'shot_id'));
        self::assertCount(2,$snapshots);
    }
    public function testVersionTimelineAndLocalRevisionRouting(): void
    {
        self::assertTrue(Engine::enabled($this->request()));
        self::assertFalse(Engine::enabled(array_replace($this->request(),['_input_contract_version'=>8])));
        self::assertFalse(Engine::enabled($this->request()+['revision_message'=>'改第一个镜头']));
        self::assertFalse(Engine::enabled(array_replace($this->request(),['episode_duration_policy'=>['timeline_segments'=>[['duration_seconds'=>5]]]])));
        self::assertSame(1,ShotDuration::rule($this->request())['min_seconds']);
        self::assertSame(4,ShotDuration::rule(['_input_contract_version'=>8])['min_seconds']);
    }
    public function testApproximateDurationNeverRelaxesExactSelectionOrTimeline(): void
    {
        self::assertTrue(Duration::approximateText('每集约60秒'));
        self::assertTrue(Duration::approximateText('每集1分钟左右'));
        self::assertFalse(Duration::approximateText('必须精确60秒'));
        $elastic=Duration::snapshot([],0,60,[],false,true);
        self::assertEquals(54,$elastic['min_seconds']);self::assertEquals(66,$elastic['max_seconds']);
        $exact=Duration::snapshot([],60,60,[],false,true);
        self::assertEquals(60,$exact['min_seconds']);self::assertEquals(60,$exact['max_seconds']);
        $timeline=Duration::snapshot([],0,60,[['duration_seconds'=>60]],false,true);
        self::assertEquals(60,$timeline['min_seconds']);
        self::assertEquals(60,Duration::snapshot([],0,60,[])['min_seconds']);
    }

    public function testNewSpeechEstimateExcludesLabelsAndCountsEnglishWords(): void
    {
        $rule=ShotDuration::rule($this->request());
        self::assertSame(Speech::requiredSeconds('你好世界',$rule), Speech::requiredSeconds('名字很长的人：你好世界',$rule));
        self::assertLessThan(Speech::requiredSeconds('This extraordinarily complicated explanation is unnecessarily long.'),
            Speech::requiredSeconds('This extraordinarily complicated explanation is unnecessarily long.',$rule));
        self::assertSame(2.0,Speech::requiredSeconds('女儿：你好世界。'));
    }

    public function testNewTimingProjectionDoesNotPayModelForArithmetic(): void
    {
        $request=$this->request();$request['episode_duration_policy']=Duration::snapshot([],12,0,[]);
        $plan=Engine::mergeUnit($this->plan(),['storyboard'=>[$this->shot(),$this->shot()]],'u1');
        $result=Timing::reconcile($plan,$request,static function(){self::fail('Feasible allocation should not call provider');});
        self::assertEquals(12,array_sum(array_column($result['storyboard'],'recommended_duration_seconds')));
        self::assertSame(array_column($plan['storyboard'],'dialogue'),array_column($result['storyboard'],'dialogue'));
        self::assertSame('bounded_projection',$result['timing_reallocation']['method']);
    }

    public function testDensityRevisionCannotDeleteSpeakerTurnOrTouchVisuals(): void
    {
        $plan=Engine::mergeUnit($this->plan(),['storyboard'=>[$this->shot()]],'u1');
        $reply=['dialogues'=>[['shot_id'=>'u1_s1','before'=>'女儿：再见。','after'=>'女儿：慢走。']]];
        $result=Engine::applyDialogueDensity($plan,$reply);
        self::assertSame($plan['storyboard'][0]['visual_description'],$result['storyboard'][0]['visual_description']);
        $reply['dialogues'][0]['after']='父亲：慢走。';
        $this->expectException(RuntimeException::class);
        Engine::applyDialogueDensity($plan,$reply);
    }
}
