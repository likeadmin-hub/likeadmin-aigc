<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaInputContract;
use app\common\service\app\aigc_short_drama\ShortDramaTimedScriptGeneration;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationTextContext;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use app\common\service\app\aigc_short_drama\canvas_agent\MarketTextConversationProvider;
use PHPUnit\Framework\TestCase;

class ShortDramaReliabilityTest extends TestCase
{
    private function workflow(string $version = ConversationWorkflow::VERSION): array
    {
        return ['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>$version],
            'stage_state'=>['key'=>'script'],'creative_brief'=>str_repeat('原始需求',1200).'必须保留的结局',
            'artifact_memory'=>[['stage'=>'script','artifact'=>'story_setting','reference_key'=>'script:story',
                'content'=>str_repeat('正文',3200).'正文结尾不可丢失']]];
    }

    public function testNewWorkflowPreservesBriefArtifactsAndRevisionTails(): void
    {
        $workflow=$this->workflow();
        $workflow['revision_constraints']=['保留人物','保留地点','保留台词','新修改'];
        $workflow['revision_request']=['stage'=>'script','content'=>str_repeat('修改',2200).'保留修改结尾'];
        $messages=ConversationTextContext::messages(['workflow'=>$workflow,'messages'=>[['role'=>'user','content'=>'继续']], 'selected_nodes'=>[]]);
        foreach (['必须保留的结局','正文结尾不可丢失','保留修改结尾','保留人物'] as $text) self::assertStringContainsString($text,$messages[0]['content']);
        $old=ConversationTextContext::messages(['workflow'=>$this->workflow('2026-09-23.11'),'messages'=>[['role'=>'user','content'=>'继续']], 'selected_nodes'=>[]]);
        self::assertStringNotContainsString('必须保留的结局',$old[0]['content']);
        self::assertStringNotContainsString('正文结尾不可丢失',$old[0]['content']);
    }

    public function testArtifactPersistenceDoesNotTruncateNewWorkflow(): void
    {
        $method=new \ReflectionMethod(ConversationWorkflow::class,'rememberArtifacts');$method->setAccessible(true);
        $state=$this->workflow();$text=str_repeat('长剧本',2500).'保留全文';
        $method->invokeArgs(null,[&$state,'script',[['key'=>'story','artifact'=>'story_setting','prompt'=>$text]]]);
        self::assertCount(1,$state['artifact_memory']);
        self::assertSame($text,$state['artifact_memory'][0]['content']);
    }

    public function testFormatRepairCarriesOriginalResponseAndCreativeRules(): void
    {
        $messages=['system_prompt'=>'租户创作规则','content'=>'完整灵感'];
        $repair=ShortDramaInputContract::formatRepair($messages,['content'=>'{"dialogue":"不要改台词"']);
        self::assertSame($messages['system_prompt'],$repair['system_prompt']);
        self::assertStringContainsString('完整灵感',$repair['content']);
        self::assertStringContainsString('不要改台词',$repair['content']);
        self::assertSame('完整灵感',$messages['content']);
    }

    public function testAutomaticTimingPreservesExactDialogueAndVisualCopy(): void
    {
        $shot=['shot_id'=>'s1','dialogue'=>str_repeat('字',48),'visual_description'=>'人物说完完整台词后转身','recommended_duration_seconds'=>5];
        $result=ShortDramaTimedScriptGeneration::fitDialogueTiming(['storyboard'=>[$shot]],['episode_duration_policy'=>['source'=>'default']]);
        self::assertSame(9.0,$result['storyboard'][0]['recommended_duration_seconds']);
        $updated=$result['storyboard'][0];$updated['recommended_duration_seconds']=5;
        self::assertSame($shot,$updated);
    }

    public function testLockedDurationNeverSilentlyShortensDialogue(): void
    {
        $this->expectExceptionCode(409);
        ShortDramaTimedScriptGeneration::fitDialogueTiming(['storyboard'=>[['shot_id'=>'1','dialogue'=>str_repeat('字',48),'recommended_duration_seconds'=>5]]],['episode_duration_policy'=>['source'=>'user']]);
    }

    public function testOverlongDialogueRequiresSplitInsteadOfTruncation(): void
    {
        $this->expectExceptionCode(409);
        ShortDramaTimedScriptGeneration::fitDialogueTiming(['storyboard'=>[['shot_id'=>'1','dialogue'=>str_repeat('字',130),'recommended_duration_seconds'=>5]]],['episode_duration_policy'=>['source'=>'default']]);
    }

    public function testCanvasBudgetUsesActualInputAndDoesNotTrimIt(): void
    {
        $request=['system_prompt'=>'规则','messages'=>[['role'=>'user','content'=>'完整要求']],'max_tokens'=>8192];
        self::assertSame(8192,MarketTextConversationProvider::outputBudget($request,['context_window'=>32768,'max_output_tokens'=>8192]));
        $request['messages'][0]['content']=str_repeat('长',10000);
        $this->expectExceptionCode(413);
        MarketTextConversationProvider::outputBudget($request,['max_input_tokens'=>1000,'context_window'=>32768]);
    }
}
