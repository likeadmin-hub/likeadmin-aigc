<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaInputContract as Contract, ShortDramaEpisodeDuration as Timing, ShortDramaTimedScriptGeneration as Timed, ShortDramaDialogueSplit as Dialogue};
use PHPUnit\Framework\TestCase;

class ShortDramaProviderTimingTest extends TestCase
{
    private function request(): array
    {
        $request = Contract::begin(['episode_duration_policy' => Timing::snapshot([], 4, 0, [])]);
        $request['_input_contract_version'] = 14; // Isolate provider timing from the new scene-script contract.
        return $request;
    }

    public function testProviderFourSecondDialogueSurvivesWithoutLocalRetimingOrRepair(): void
    {
        $shot = ['shot_id'=>'s1_1', 'scene_ref_id'=>'room', 'subject_ref_ids'=>['a'],
            'visual_description'=>'阿杰与孙悟空相视而笑', 'dialogue'=>"阿杰：你本可以离开。\n孙悟空：俺老孙欠你一次，这边界，我来守。", 'recommended_duration_seconds'=>4];
        $skeleton = ['title'=>'两界', 'story_outline'=>'共同守护两界', 'script_lines'=>['相视而笑'],
            'subjects'=>[['id'=>'a','name'=>'阿杰']], 'locations'=>[['id'=>'room','name'=>'数字空间']],
            'scene_beats'=>[['scene_ref_id'=>'room','goal'=>'守护','entry'=>'平静','exit'=>'微笑','key_events'=>['共同守护'], 'duration_seconds'=>4,'shot_durations'=>[4]]]];
        foreach ([false, true] as $complete) {
            $calls=[];
            $result=Timed::generate($this->request(), ['system_prompt'=>'创作','content'=>'4秒'],
                static function($key) use (&$calls, $complete, $skeleton, $shot) {
                    $calls[]=$key;
                    if (str_contains($key,'skeleton')) return $complete ? $skeleton+['storyboard'=>[$shot]] : $skeleton;
                    return ['storyboard'=>[$shot]];
                }, null);
            self::assertSame($shot, $result['storyboard'][0]);
            self::assertCount($complete ? 1 : 2, $calls);
        }
        self::assertSame(['storyboard'=>[$shot]], Dialogue::adapt(['storyboard'=>[$shot]],$this->request(),static function(){self::fail('No speech-estimate repair call');},'test'));
    }

    public function testNewBudgetsAreReturnedToProviderForRepairRatherThanLocallyRedistributed(): void
    {
        $plan=['scene_beats'=>[['duration_seconds'=>20,'shot_durations'=>[2,18]]]];
        self::assertSame($plan,Timed::normalizeSkeletonBudgets($plan,$this->request()));
        $legacy=$this->request(); $legacy['_input_contract_version']=8;
        self::assertNotSame($plan,Timed::normalizeSkeletonBudgets($plan,$legacy));
    }

    public function testDefaultOversizedShotSplitsWithoutChangingSceneOrEpisodeBudget(): void
    {
        $request=$this->request(); $request['episode_duration_policy']=['version'=>1,'source'=>'default','max_seconds'=>180];
        $plan=['title'=>'火眼金睛：数据真相', 'scene_beats'=>[
            ['duration_seconds'=>45,'shot_durations'=>[8,10,12,15]],
            ['duration_seconds'=>50,'shot_durations'=>[10,15,12,13]],
            ['duration_seconds'=>40,'shot_durations'=>[10,12,18], 'key_events'=>['发现数据真相']],
            ['duration_seconds'=>35,'shot_durations'=>[12,10,13]],
        ]];
        $result=Timed::normalizeSkeletonBudgets($plan,$request);
        self::assertSame([10,12,9.0,9.0],$result['scene_beats'][2]['shot_durations']);
        self::assertSame($plan['scene_beats'][2]['key_events'],$result['scene_beats'][2]['key_events']);
        self::assertSame([45,50,40,35],array_column($result['scene_beats'],'duration_seconds'));
        self::assertSame(170,array_sum(array_column($result['scene_beats'],'duration_seconds')));
        self::assertSame($result,Timed::normalizeSkeletonBudgets($result,$request));

        $fractional=['scene_beats'=>[['duration_seconds'=>21,'shot_durations'=>[4.5,16.5]]]];
        self::assertSame([4.5,8.25,8.25],Timed::normalizeSkeletonBudgets($fractional,$request)['scene_beats'][0]['shot_durations']);
    }

    public function testTotalDurationStillMustMatchUserRequest(): void
    {
        $this->expectException(\RuntimeException::class);
        Timing::assertPlan(['storyboard'=>[['recommended_duration_seconds'=>5]]],$this->request());
    }

    public function testOldTasksKeepTheirFrozenTimingContract(): void
    {
        self::assertFalse(Contract::providerTiming(['_input_contract_version'=>8]));
        self::assertTrue(Contract::providerTiming($this->request()));
    }
    public function testDefaultAggregateMismatchIsRepairedWithoutChangingAnyShotOrText(): void
    {
        $request=$this->request(); $request['episode_duration_policy']=['version'=>1,'source'=>'default','max_seconds'=>180];
        $durations=[8,10,8,10,12,10,8,10,12,8,10,12,8,10,12,12];
        $plan=['title'=>'保留剧本','scene_beats'=>[['duration_seconds'=>150,'shot_durations'=>$durations,'goal'=>'保留全部对白和动作']]];
        $result=Timed::normalizeSkeletonBudgets($plan,$request);
        self::assertSame(160,$result['scene_beats'][0]['duration_seconds']);
        $expected=$plan; $expected['scene_beats'][0]['duration_seconds']=160;
        self::assertSame($expected,$result);
        self::assertSame($result,Timed::normalizeSkeletonBudgets($result,$request));
    }

    public function testExplicitTimingInvalidShotsAndOverBudgetPlansAreNeverRelaxed(): void
    {
        $base=$this->request(); $base['episode_duration_policy']=['version'=>1,'source'=>'default','max_seconds'=>180];
        $plan=['scene_beats'=>[['duration_seconds'=>9,'shot_durations'=>[5,5]]]];
        $explicit=$base; $explicit['episode_duration_policy']['source']='user';
        self::assertSame($plan,Timed::normalizeSkeletonBudgets($plan,$explicit));
        $timeline=$base; $timeline['episode_duration_policy']['timeline_segments']=[['duration_seconds'=>9]];
        self::assertSame($plan,Timed::normalizeSkeletonBudgets($plan,$timeline));
        foreach ([[0,10],[-1,11],['bad',10],[2,18]] as $durations) {
            $invalid=$plan; $invalid['scene_beats'][0]['shot_durations']=$durations;
            self::assertSame($invalid,Timed::normalizeSkeletonBudgets($invalid,$base));
        }
        $tooMany=['scene_beats'=>[['duration_seconds'=>174,'shot_durations'=>array_merge(array_fill(0,39,4),[18])]]];
        self::assertSame($tooMany,Timed::normalizeSkeletonBudgets($tooMany,$base));
        $over=$base; $over['episode_duration_policy']['max_seconds']=9;
        self::assertSame($plan,Timed::normalizeSkeletonBudgets($plan,$over));
    }

}
