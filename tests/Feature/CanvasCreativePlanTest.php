<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePlan as Plan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePolicy as Policy;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries as Series;
use PHPUnit\Framework\TestCase;

class CanvasCreativePlanTest extends TestCase
{
    private function state(string $brief='程序员大战孙悟空'): array
    {
        $slots=Workflow::catalog()['slots'];
        foreach ($slots as &$slot) if ($slot['key']==='aspect_ratio') $slot['options']=['9:16','16:9'];
        return ['workflow_snapshot'=>['key'=>Workflow::KEY,'version'=>Workflow::VERSION,
            'creative_policy_version'=>Policy::VERSION,'series_core'=>true,'slot_schema'=>$slots],
            'creative_brief'=>$brief,'slot_values'=>$brief==='程序员大战孙悟空'?['story_direction'=>$brief]:[],
            'slot_sources'=>$brief==='程序员大战孙悟空'?['story_direction'=>['value'=>$brief,'source'=>'message','evidence'=>'程序员大战孙悟空']]:[],'creative_settings'=>[], 'state_revision'=>1,
            'stage_state'=>['key'=>'intake','status'=>'collecting']];
    }
    private function draft(array $state): array { return Plan::draft($state,['candidates'=>[],'questions'=>[]]); }

    public function testRawIntentIsNeverPromotedToStoryAndCardWaitsForSemanticDirection(): void
    {
        foreach (['我想生成短剧','帮我做个视频','你好，我想试试创作','程序员大战孙悟空'] as $raw) {
            $state=$this->state($raw);
            $state['slot_values']=[];$state['slot_sources']=[];
            $state=Plan::draft($state,['candidates'=>[
                'episode_duration'=>['value'=>'60秒','source'=>'message','evidence'=>'60秒'],
            ],'questions'=>[]]);
            self::assertArrayNotHasKey('story_direction',$state['slot_values']);
            self::assertFalse(Plan::canShowIntake($state));
            $card=new \ReflectionMethod(Workflow::class,'card');$card->setAccessible(true);
            self::assertNull($card->invoke(null,$state));
            // Same model turn supplies prose and semantically extracted facts.
            $state=Workflow::withIntakeDraft($state,['candidates'=>[
                ['key'=>'story_direction','value'=>'程序员大战孙悟空','source'=>'message','evidence'=>'程序员大战孙悟空','confidence'=>1],
            ],'questions'=>[]]);
            self::assertTrue(Plan::canShowIntake($state));
            self::assertSame('60秒',$state['slot_values']['episode_duration']);
            self::assertSame(['episode_count','visual_style','aspect_ratio'],array_keys($state['intake_candidates']));
            self::assertSame('intake_review',$card->invoke(null,$state)['type']);
        }
    }

    public function testOldAutomaticBriefFactIsReextractedWithoutLosingConfirmedOrRunningWork(): void
    {
        $state=$this->state('我想生成短剧');
        $state['intake_collection_version']=1;
        $state['slot_values']=['story_direction'=>'我想生成短剧'];
        $state['slot_sources']=['story_direction'=>['source'=>'message','value'=>'我想生成短剧','evidence'=>'我想生成短剧']];
        $state['intake_anchor_run_id']=99;
        $updated=Plan::upgradePending($state);
        self::assertFalse(Plan::canShowIntake($updated));
        self::assertSame('我想生成短剧',$updated['creative_brief']);
        self::assertSame(0,$updated['intake_anchor_run_id']);
        self::assertSame($updated,Plan::upgradePending($updated));
        $state['creative_plan_confirmed']=true;
        self::assertSame($state,Plan::upgradePending($state));
        unset($state['creative_plan_confirmed']);$state['series']['tasks']=['episode_1'=>['task_id'=>'existing']];
        self::assertSame($state,Plan::upgradePending($state));
    }

    public function testPendingLegacySuggestionsBecomeMissingQuestionsWithoutReplacingUserTopic(): void
    {
        $state=$this->state("你好\n用户补充：程序员大战孙悟空\n用户补充：程序员大战孙悟空\n用户补充：单独写一份剧本/文案\n用户补充：重新给我生成一部完整短剧");
        $state['intake_candidates']=['episode_count'=>['value'=>'6集','source'=>'suggestion'],
            'episode_duration'=>['value'=>'90秒','source'=>'suggestion'],'aspect_ratio'=>['value'=>'9:16','source'=>'suggestion']];
        $updated=Plan::upgradePending($state);
        self::assertSame('程序员大战孙悟空',$updated['creative_brief']);
        self::assertArrayNotHasKey('story_direction',$updated['slot_values']);
        self::assertArrayHasKey('story_direction',$updated['intake_candidates']);
        self::assertFalse(Plan::canShowIntake($updated));
        foreach ($updated['intake_candidates'] as $candidate) self::assertSame('',$candidate['value']);
        self::assertSame($updated,Plan::upgradePending($updated));
        $state['creative_plan_confirmed']=true;
        self::assertSame($state,Plan::upgradePending($state));
    }

    public function testModeConfirmationDoesNotReplaceOriginalBrief(): void
    {
        $brief=Plan::brief([['role'=>'user','content'=>'程序员大战孙悟空'],
            ['role'=>'assistant','content'=>'建议三集，穿越西游'],['role'=>'user','content'=>'b'],['role'=>'user','content'=>'确认']]);
        self::assertSame('程序员大战孙悟空',$brief);
        $s=$this->draft($this->state($brief));
        self::assertSame('reviewing_intake',$s['stage_state']['status']);
        self::assertSame('程序员大战孙悟空',$s['slot_values']['story_direction']);
        self::assertFalse($s['creative_plan_confirmed']);
        foreach (array_diff(Plan::REQUIRED,['story_direction']) as $key) { self::assertArrayHasKey($key,$s['intake_candidates']);self::assertSame('',$s['intake_candidates'][$key]['value']); }
        $this->expectException(\RuntimeException::class); Plan::assertConfirmed($s);
    }
    public function testConfirmedEditsBecomeAuthoritativeAndSelectSeriesStage(): void
    {
        $s=$this->draft($this->state(''));
        $values=array_map(static fn($item)=>$item['value'],$s['intake_candidates']);
        $values=array_replace($values,['story_direction'=>'程序员穿越西游，与孙悟空联手修复法术漏洞',
            'episode_count'=>'3集','episode_duration'=>'2分钟','visual_style'=>'国风水墨','aspect_ratio'=>'16：9']);
        $s=Plan::confirm($s,$values);
        Plan::assertConfirmed($s);
        self::assertSame('story',$s['stage_state']['key']);
        self::assertSame(3,Series::count($s)); self::assertSame(120,Policy::duration($s));
        self::assertSame('16:9',$s['creative_settings']['aspect_ratio']);
        self::assertSame($values['story_direction'],$s['slot_values']['story_direction']);
        self::assertSame('国风水墨',$s['slot_values']['visual_style']);
        self::assertSame('confirmed',$s['slot_sources']['visual_style']['source']);
    }
    public function testIncompletePlanCannotAdvance(): void
    {
        $s=$this->draft($this->state());
        $this->expectException(\RuntimeException::class);
        Plan::confirm($s,['episode_count'=>'3集']);
    }
    public function testInvalidRatioCannotAdvance(): void
    {
        $s=$this->draft($this->state());$v=array_map(static fn($item)=>$item['value'],$s['intake_candidates']);$v['aspect_ratio']='7:11';
        $this->expectException(\RuntimeException::class); Plan::confirm($s,$v);
    }
    public function testDelegatingCountOnlyDoesNotAuthorizeWholePlan(): void
    {
        $s=$this->draft($this->state('程序员大战孙悟空，集数你来定'));
        self::assertSame('reviewing_intake',$s['stage_state']['status']);
        $s=Plan::draft($this->state('程序员大战孙悟空，集数你来定'),['candidates'=>[
            'episode_count'=>['value'=>'6集','source'=>'suggestion'],
            'episode_duration'=>['value'=>'90秒','source'=>'suggestion'],
            'ending'=>['value'=>'大团圆','source'=>'suggestion'],
        ],'questions'=>[]]);
        self::assertSame('6集',$s['intake_candidates']['episode_count']['value']);
        self::assertSame('',$s['intake_candidates']['episode_duration']['value']);
        self::assertArrayNotHasKey('ending',$s['intake_candidates']);
        $s=$this->draft($this->state('程序员大战孙悟空，全部你决定，直接生成'));
        self::assertFalse($s['creative_plan_confirmed']);self::assertSame('reviewing_intake',$s['stage_state']['status']);
        $s=$this->draft($this->state('程序员大战孙悟空，全部你决定，但不要直接生成'));
        self::assertFalse($s['creative_plan_confirmed']);
    }
    public function testSuggestionIsReviewedWithoutBecomingAUserFact(): void
    {
        $s=Workflow::withIntakeDraft($this->state(),['candidates'=>[
            ['key'=>'story_direction','value'=>'程序员穿越西游','source'=>'suggestion','evidence'=>'基于灵感的建议','confidence'=>0.9],
            ['key'=>'episode_count','value'=>'3集','source'=>'message','evidence'=>'用户要求三集','confidence'=>1],
        ],'questions'=>[]]);
        self::assertArrayNotHasKey('story_direction',$s['intake_candidates']);
        self::assertSame('程序员大战孙悟空',$s['slot_values']['story_direction']);
        self::assertArrayNotHasKey('episode_count',$s['intake_candidates']);
        self::assertSame('3集',$s['slot_values']['episode_count']);
    }

    public function testOldUnstartedDraftRequiresConfirmationButBoundTasksArePreserved(): void
    {
        $s=$this->state();$s['workflow_snapshot']['creative_policy_version']='2026-10-10.1';
        $s['stage_state']=['key'=>'episode','status'=>'ready'];
        $updated=Plan::upgradePending($s);
        self::assertSame('reviewing_intake',$updated['stage_state']['status']);
        self::assertFalse($updated['creative_plan_confirmed']);
        $s['series']['tasks']['episode_1']=['task_id'=>'saved-task'];
        self::assertSame($s,Plan::upgradePending($s));
    }

    public function testKnownSettingsAreNotAskedAgainAndOnlyRemainingValuesNeedConfirmation(): void
    {
        $s=$this->state();
        $known=['episode_count'=>'3集','episode_duration'=>'120秒','visual_style'=>'国风水墨','aspect_ratio'=>'16:9'];
        $items=[];
        foreach ($known as $key=>$value) $items[]=['key'=>$key,'value'=>$value,'source'=>'message','evidence'=>$value,'confidence'=>1];
        $s=Workflow::withIntakeDraft($s,['candidates'=>$items,'questions'=>[]]);
        self::assertSame([],array_keys($s['intake_candidates']));
        self::assertSame(['story_direction'=>'程序员大战孙悟空']+$known,$s['slot_values']);
        self::assertTrue($s['creative_plan_confirmed']);
        self::assertSame(3,Series::count($s));
        self::assertSame('16:9',$s['creative_settings']['aspect_ratio']);
    }

    public function testAllExplicitSettingsStartWithoutAnotherConfirmationCard(): void
    {
        $values=['story_direction'=>'程序员穿越西游','episode_count'=>'3集','episode_duration'=>'120秒','visual_style'=>'国风水墨','aspect_ratio'=>'16:9'];
        $items=[];
        foreach ($values as $key=>$value) $items[]=['key'=>$key,'value'=>$value,'source'=>'message','evidence'=>$value,'confidence'=>1];
        $s=Workflow::withIntakeDraft($this->state(),['candidates'=>$items,'questions'=>[]]);
        self::assertSame([],$s['intake_candidates']);
        self::assertTrue($s['creative_plan_confirmed']);
        self::assertSame('ready',$s['stage_state']['status']);
        self::assertSame($values,$s['slot_values']);
    }

    public function testConversationCanCorrectASettingWhileKeepingOtherSuggestions(): void
    {
        $s=$this->draft($this->state());
        $s['slot_values']['episode_count']='1集';$s['slot_sources']['episode_count']=['source'=>'message'];
        $s=Workflow::withIntakeDraft($s,['candidates'=>[
            ['key'=>'episode_count','value'=>'3集','source'=>'message','evidence'=>'改成三集','confidence'=>1],
        ],'questions'=>[]]);
        self::assertSame('3集',$s['slot_values']['episode_count']);
        self::assertArrayNotHasKey('episode_count',$s['intake_candidates']);
        self::assertArrayHasKey('visual_style',$s['intake_candidates']);
    }
}
