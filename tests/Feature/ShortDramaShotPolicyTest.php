<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaShotPolicy as Policy;
use PHPUnit\Framework\TestCase;

class ShortDramaShotPolicyTest extends TestCase
{
    private function call(string $name, ...$args): mixed
    {
        $m = new \ReflectionMethod(Service::class, $name); $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }
    public function testEmptyDisabledAndLegacyRulesNeverRestoreDefaults(): void
    {
        self::assertSame([], $this->call('defaultStoryboardRules'));
        foreach ([[], [['enabled'=>false]], [['code'=>'daily_comedy','min_shots'=>12,'max_shots'=>24,'enabled'=>true]]] as $rules) {
            self::assertSame([], $this->call('normalizeStoryboardRules', $rules));
            self::assertSame([], $this->call('storyboardTargetRule', '日常穿越', ['storyboard_rules'=>$rules]));
        }
    }
    public function testOldNineShotDiagnosticsCannotBlockOrTriggerExpansion(): void
    {
        $plan = ['title'=>'故事','story_outline'=>'完整故事','subjects'=>[['id'=>'p','name'=>'甲','description'=>'人物']],
            'locations'=>[['id'=>'l','name'=>'房间','description'=>'房间']],
            'storyboard_breaking_diagnostics'=>['matched_rule_code'=>'daily_comedy','target_min_shots'=>12,'target_max_shots'=>24],
            'storyboard'=>array_map(static fn($id)=>['shot_id'=>(string)$id,'scene_ref_id'=>'l','subject_ref_ids'=>['p'],
                'visual_description'=>'甲开门','composition'=>'中景','camera_movement'=>'固定',
                'image_prompt'=>'甲开门','video_prompt'=>'甲开门','recommended_duration_seconds'=>5], range(1,9))];
        $report = $this->call('reviewPlanResult', $plan);
        self::assertNotContains('storyboard.shot_count_under_range', array_column($report['issues'],'code'));
        self::assertNotContains('storyboard.shot_count_over_range', array_column($report['issues'],'code'));
        $plan['storyboard'][0]['scene_ref_id']='missing';
        self::assertContains('shot.scene_ref.invalid', array_column($this->call('reviewPlanResult',$plan)['issues'],'code'));
    }
    public function testExplicitDurationGuardIsStillEnforced(): void
    {
        $this->expectExceptionCode(422);
        $this->call('assertStoryboardBudgetSatisfied', ['storyboard'=>[['recommended_duration_seconds'=>5]]],
            ['episode_duration_policy'=>['version'=>1,'source'=>'user','min_seconds'=>30,'max_seconds'=>30,'target_seconds'=>30]], '');
    }
    public function testLegacyRuleMetadataDoesNotEnterCompactInput(): void
    {
        $prompt = $this->call('buildCompactScriptPlanPrompt','按原剧情创作', ['storyboard_rules'=>[['label'=>'不要传给模型','min_shots'=>99]]], '故事', false, true);
        self::assertStringNotContainsString('不要传给模型',$prompt);
        self::assertStringNotContainsString('pacing_references',$prompt);
        self::assertStringNotContainsString('storyboard_rule',$prompt);
        self::assertStringContainsString('完整剧情', Policy::INSTRUCTION);
    }
    public function testFrozenDefaultQuotaAndHistoricalDiagnosticsDoNotLeakIntoPrompts(): void
    {
        $old='There is no fixed storyboard count by text length; never use 8 as the default. When no target duration or timeline exists, judge story complexity and follow the tenant-configured storyboard complexity rules and storyboard breaking intensity from context: light for simple talking-head/advertising/single-scene content, standard for ordinary short films, detailed for complex dream/suspense/reversal films, and cinematic detailed for complex multi-scene plots. Timeline segments without selected duration override storyboard intensity ranges and must not be expanded.';
        $snapshot=['systems'=>['single'=>"保留人物关系\n".$old.'\n用户要求共9镜']];
        $text=\app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog::run($snapshot,
            static fn()=>\app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog::system('single'));
        self::assertStringNotContainsString('tenant-configured storyboard complexity rules',$text);
        self::assertStringContainsString('保留人物关系',$text);
        self::assertStringContainsString('用户要求共9镜',$text);
        $plan=['title'=>'故事','storyboard'=>[['shot_id'=>'1']],
            'storyboard_breaking_diagnostics'=>['target_min_shots'=>12,'target_max_shots'=>24]];
        self::assertSame(['title'=>'故事','storyboard'=>[['shot_id'=>'1']]],$this->call('stripPlanRuntimeFields',$plan));
        self::assertArrayHasKey('storyboard_breaking_diagnostics',$plan);
    }
}
