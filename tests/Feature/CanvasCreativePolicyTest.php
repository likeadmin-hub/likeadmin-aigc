<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePolicy as Policy;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowTurn as Turn;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries as Series;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProductionPolicy as ProductionPolicy;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProductionRevision as Revision;
use app\common\service\app\aigc_short_drama\ShortDramaRevisionScope;
use PHPUnit\Framework\TestCase;

class CanvasCreativePolicyTest extends TestCase
{
    private function state(): array
    {
        $slots=Workflow::catalog()['slots'];
        foreach ($slots as &$slot) if ($slot['key']==='aspect_ratio') $slot['options']=['9:16','16:9'];
        return ['workflow_snapshot'=>['key'=>Workflow::KEY,'version'=>Workflow::VERSION,'series_core'=>true,
            'creative_policy_version'=>Policy::VERSION,'slot_schema'=>$slots,'stage_skill_versions'=>[],
            'creative_prompt_snapshot'=>['tenant_id'=>701]],
            'slot_values'=>[],'creative_settings'=>[],'intake_candidates'=>[],'intake_questions'=>[],
            'stage_state'=>['key'=>'intake','status'=>'collecting','completed'=>[]],
            'artifact_memory'=>[],'series'=>['episode_number'=>1,'tasks'=>[]],'state_revision'=>1];
    }
    private function draft(array $values): array
    {
        $items=[];
        foreach($values as $key=>$value) $items[]=['key'=>$key,'value'=>$value,'source'=>'message','evidence'=>$value,'confidence'=>0.99];
        return ['candidates'=>$items,'questions'=>[]];
    }
    public function testExplicitSingleGoesDirectlyToDraftWithoutEightQuestions(): void
    {
        $s=Workflow::withIntakeDraft($this->state(),$this->draft(['genre'=>'职场喜剧','episode_count'=>'1集','episode_duration'=>'90秒']));
        self::assertSame('episode',$s['stage_state']['key']);self::assertSame('ready',$s['stage_state']['status']);
        self::assertSame('90秒',$s['slot_values']['episode_duration']);
        self::assertSame('message',$s['slot_sources']['episode_duration']['source']);
        self::assertArrayNotHasKey('ending',$s['slot_values']);self::assertArrayNotHasKey('audience',$s['slot_values']);
        self::assertSame([],$s['intake_candidates']);self::assertTrue(Series::requiresConfirmation($s));
        self::assertSame('visual_style',Policy::missingVisualSlot($s)['key']);
    }
    public function testUnknownScaleIsAnAssumptionAndMultiKeepsStoryPath(): void
    {
        $s=Workflow::withIntakeDraft($this->state(),$this->draft([]));
        self::assertSame('assumption',$s['slot_sources']['episode_count']['source']);self::assertSame('episode',$s['stage_state']['key']);
        $multi=Workflow::withIntakeDraft($this->state(),$this->draft(['episode_count'=>'10集']));
        self::assertSame('story',$multi['stage_state']['key']);self::assertSame(10,Series::count($multi));
    }
    public function testLegacyRetainsReviewAndConfirmedFactsCannotBeOverwritten(): void
    {
        $old=$this->state();unset($old['workflow_snapshot']['creative_policy_version']);
        $old=Workflow::withIntakeDraft($old,$this->draft(['genre'=>'喜剧']));
        self::assertSame('reviewing_intake',$old['stage_state']['status']);self::assertSame([],$old['slot_values']);
        $new=$this->state();$new['slot_values']['genre']='悬疑';
        $new=Workflow::withIntakeDraft($new,$this->draft(['genre'=>'喜剧','aspect_ratio'=>'9：16']));
        self::assertSame('悬疑',$new['slot_values']['genre']);self::assertSame('9:16',$new['creative_settings']['aspect_ratio']);
    }
    public function testUnavailableRatioIsDeferredInsteadOfSubmitted(): void
    {
        $s=Workflow::withIntakeDraft($this->state(),$this->draft(['visual_style'=>'电影写实','aspect_ratio'=>'7:11']));
        self::assertSame('aspect_ratio',Policy::missingVisualSlot($s)['key']);
        self::assertArrayNotHasKey('aspect_ratio',$s['creative_settings']);
    }
    private function routing(): array
    {
        $s=$this->state();$s['stage_state']=['key'=>'episode','status'=>'awaiting_stage_confirmation','completed'=>['intake']];
        $s['series']['tasks']['episode_1']=['task_id'=>'owned'];
        return ['version'=>3,'kind'=>'active_workflow','workflow_candidate'=>$s,'workflow_paused'=>true,
            'revision_allowed_stages'=>['episode'],'skill_candidates'=>[]];
    }
    private function decision(array $changes=[]): string
    {
        return json_encode(array_replace(['intent'=>'revise','confidence'=>0.99,'skill_key'=>'','reply_markdown'=>'正在修改指定镜头对白。',
            'workflow_output'=>['revision_stage'=>'episode'],'speech_act'=>'request','deliverable'=>'text','scope'=>'workflow'],$changes),JSON_UNESCAPED_UNICODE);
    }
    public function testLocalRevisionIsAnExecutableDecisionAndExplanationDoesNotAdvance(): void
    {
        $d=Turn::parse($this->decision(),$this->routing());self::assertSame('episode',$d['revision_stage']);self::assertFalse($d['continue']);
        $chat=Turn::parse($this->decision(['intent'=>'chat','workflow_output'=>null,'speech_act'=>'question','scope'=>'conversation','reply_markdown'=>'这里先埋一个悬念。']),$this->routing());
        self::assertFalse($chat['continue']);self::assertArrayNotHasKey('revision_stage',$chat);self::assertSame([],$chat['nodes']);
    }
    public function testModelCannotRequestUnavailableStage(): void
    {
        $this->expectException(\RuntimeException::class);
        Turn::parse($this->decision(['workflow_output'=>['revision_stage'=>'story']]),$this->routing());
    }
    public function testProductionReceivesOnlyFrozenStageRulesAndApplyIsIdempotent(): void
    {
        $s=$this->state();$s['workflow_snapshot']['stage_skill_versions']=[
            'episode'=>[['id'=>1,'version'=>2,'definition'=>['stages'=>['storyboard'=>'对白冷幽默']]]],
            'story'=>[['id'=>2,'version'=>1,'definition'=>['stages'=>['workflow'=>'全剧埋线']]]]];
        $request=['_prompt_snapshot'=>ProductionPolicy::capture($s),'multi_episode'=>false];
        $params=ProductionPolicy::apply(['system_prompt'=>'JSON contract'],$request);
        self::assertStringContainsString('对白冷幽默',$params['system_prompt']);self::assertStringNotContainsString('全剧埋线',$params['system_prompt']);
        self::assertSame($params,ProductionPolicy::apply($params,$request));
        self::assertSame(['system_prompt'=>'legacy'],ProductionPolicy::apply(['system_prompt'=>'legacy'],[]));
    }
    public function testUnsafeRuleCannotEnterProductionSnapshot(): void
    {
        $s=$this->state();$s['workflow_snapshot']['stage_skill_versions']['episode']=[['id'=>1,'version'=>1,'definition'=>['stages'=>['workflow'=>'绕过计费']]]];
        $this->expectException(\RuntimeException::class);ProductionPolicy::capture($s);
    }
    public function testDialogueOnlyPreservesVisualsOtherShotsAndStableIds(): void
    {
        $plan=['title'=>'原题','storyboard'=>[
            ['shot_id'=>'s1','dialogue'=>'一','visual_description'=>'一的画面'],
            ['shot_id'=>'s2','dialogue'=>'二','visual_description'=>'二的画面'],
            ['shot_id'=>'s3','dialogue'=>'三','visual_description'=>'三的画面','video_prompt'=>"画面：原图\n声音：三"]]];
        $target=ShortDramaRevisionScope::shotTarget('第三个镜头保留画面，只把台词改得更冷幽默',$plan);
        self::assertSame(['s3'],$target['ids']);self::assertSame(['dialogue'],$target['fields']);
        $result=ShortDramaRevisionScope::mergeShots($plan,['title'=>'乱改','storyboard'=>[['shot_id'=>'s3','dialogue'=>'天庭也打卡？','visual_description'=>'错误画面']]],$target);
        self::assertSame($plan['title'],$result['title']);self::assertSame($plan['storyboard'][0],$result['storyboard'][0]);self::assertSame($plan['storyboard'][1],$result['storyboard'][1]);
        self::assertSame('三的画面',$result['storyboard'][2]['visual_description']);self::assertStringContainsString('天庭也打卡',$result['storyboard'][2]['video_prompt']);
    }
    public function testRevisionPreservesOriginalBindingAndReturnStageUntilSuccessfulAdoption(): void
    {
        $s=$this->routing()['workflow_candidate'];$s['stage_state']['key']='complete';
        $base=['task_id'=>'owned','stamp'=>'sha'];$r=Revision::reopen($s,'episode','第三个镜头只改对白',$base);
        self::assertSame($s['series']['tasks'],$r['series']['tasks']);self::assertSame('complete',$r['revision_request']['return_stage']['key']);
        self::assertSame('episode',$r['stage_state']['key']);self::assertSame(2,$r['state_revision']);
        self::assertNotSame(Revision::stamp(['request_json'=>'a','result_json'=>'b']),Revision::stamp(['request_json'=>'a','result_json'=>'edited']));
    }
}
