<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\{AigcShortDramaService, ShortDramaStoryDraft, ShortDramaStoryWorkflow};
use PHPUnit\Framework\TestCase;

class ShortDramaConventionalPipelineRegressionTest extends TestCase
{
    private function request(string $source = 'home'): array
    {
        return ['source'=>$source, '_input_contract_version'=>10, 'workflow_variant'=>'story_outline_v2',
            'multi_episode'=>true, 'episode_count'=>5, 'multi_episode_stage'=>'story', 'quality_review_enabled'=>false];
    }
    private function legacy(): array
    {
        return ['title'=>'西游', 'story_outline'=>'程序员修复西游世界', 'type_judgement'=>'', 'core_theme'=>'',
            'series_bible'=>['genre'=>'奇幻喜剧', 'themes'=>['团队协作','技术思维']],
            'subjects'=>[['id'=>'a','name'=>'陈默']], 'locations'=>[['id'=>'b','name'=>'五庄观']],
            'episodes'=>[['episode'=>1,'title'=>'初遇','outline'=>'初遇大圣']], 'storyboard'=>[['shot_id'=>'s1']],
            'generation_settings'=>['creative_control'=>'upstream'], 'raw_content'=>'原始结果'];
    }
    public function testLegacyStoryCanConfirmWithoutRegenerationForBothEntrypoints(): void
    {
        foreach (['home','canvas'] as $source) {
            $raw=$this->legacy(); $result=ShortDramaStoryDraft::effective($this->request($source),$raw);
            self::assertSame('奇幻喜剧',$result['type_judgement']);
            self::assertSame('团队协作、技术思维',$result['core_theme']);
            self::assertSame([],ShortDramaStoryWorkflow::issues($result,'story',5,false));
            self::assertSame([],$result['episodes']); self::assertSame([],$result['storyboard']);
            self::assertSame('原始结果',$result['raw_content']); self::assertCount(1,$raw['episodes']);
        }
    }
    public function testSavedDraftDeletionIsNeverRefilledFromModelAliases(): void
    {
        $request=$this->request(); $request['_story_draft']=['version'=>1,'stage'=>'story','result'=>$this->legacy()];
        $result=ShortDramaStoryDraft::effective($request,$this->legacy());
        self::assertSame('',$result['core_theme']); self::assertSame('',$result['type_judgement']);
        self::assertNotEmpty(ShortDramaStoryWorkflow::issues($result,'story',5,false));
    }
    public function testExistingOutlineIsReadableWithoutChangingIdsOrProse(): void
    {
        $request=$this->request(); $request['multi_episode_stage']='episodes';
        $result=ShortDramaStoryDraft::effective($request,$this->legacy());
        self::assertSame(1,$result['episodes'][0]['episode_number']);
        self::assertSame('初遇大圣',$result['episodes'][0]['story_outline']);
        self::assertSame('a',$result['subjects'][0]['id']);
    }
    public function testSharedEntryCannotShortCircuitConventionalStageServices(): void
    {
        $method=new \ReflectionMethod(AigcShortDramaService::class,'generateScriptPlanResultScoped');
        $lines=file($method->getFileName());
        $body=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
        self::assertStringNotContainsString('ShortDramaDirectGeneration::',$body);
        self::assertStringContainsString('ShortDramaStoryGeneration::generate',$body);
        self::assertStringContainsString('ShortDramaScriptGeneration::generate',$body);
        self::assertStringContainsString('assembleScriptPromptRequest',$body);
    }
}
