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
            'creative_brief'=>$brief,'slot_values'=>[],'creative_settings'=>[], 'state_revision'=>1,
            'stage_state'=>['key'=>'intake','status'=>'collecting']];
    }
    private function draft(array $state): array { return Plan::draft($state,['candidates'=>[],'questions'=>[]]); }

    public function testModeConfirmationDoesNotReplaceOriginalBrief(): void
    {
        $brief=Plan::brief([['role'=>'user','content'=>'程序员大战孙悟空'],
            ['role'=>'assistant','content'=>'建议三集，穿越西游'],['role'=>'user','content'=>'b'],['role'=>'user','content'=>'确认']]);
        self::assertSame('程序员大战孙悟空',$brief);
        $s=$this->draft($this->state($brief));
        self::assertSame('reviewing_intake',$s['stage_state']['status']);
        self::assertSame([],$s['slot_values']);
        self::assertFalse($s['creative_plan_confirmed']);
        foreach (Plan::REQUIRED as $key) self::assertArrayHasKey($key,$s['intake_candidates']);
        $this->expectException(\RuntimeException::class); Plan::assertConfirmed($s);
    }
    public function testConfirmedEditsBecomeAuthoritativeAndSelectSeriesStage(): void
    {
        $s=$this->draft($this->state());
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
        $s=$this->draft($this->state('程序员大战孙悟空，全部你决定，直接生成'));
        self::assertTrue($s['creative_plan_confirmed']);self::assertSame('ready',$s['stage_state']['status']);
        $s=$this->draft($this->state('程序员大战孙悟空，全部你决定，但不要直接生成'));
        self::assertFalse($s['creative_plan_confirmed']);
    }
    public function testSuggestionIsReviewedWithoutBecomingAUserFact(): void
    {
        $s=Workflow::withIntakeDraft($this->state(),['candidates'=>[
            ['key'=>'story_direction','value'=>'程序员穿越西游','source'=>'suggestion','evidence'=>'基于灵感的建议','confidence'=>0.9],
            ['key'=>'episode_count','value'=>'3集','source'=>'message','evidence'=>'用户要求三集','confidence'=>1],
        ],'questions'=>[]]);
        self::assertSame('suggestion',$s['intake_candidates']['story_direction']['source']);
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
        self::assertSame(['story_direction'],array_keys($s['intake_candidates']));
        self::assertSame($known,$s['slot_values']);
        $s=Plan::confirm($s,['story_direction'=>'程序员穿越西游，与孙悟空合作']);
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
