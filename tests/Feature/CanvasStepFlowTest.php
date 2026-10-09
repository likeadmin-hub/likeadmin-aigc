<?php
declare(strict_types=1);
namespace tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStepFlow as Flow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeriesVisuals as Visuals;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries as Series;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionMethod;

final class CanvasStepFlowTest extends TestCase
{
    private function state(): array { return ['workflow_snapshot'=>['series_core'=>true,'key'=>Workflow::KEY,'version'=>Series::VERSION], 'stage_state'=>['key'=>'video_nodes','status'=>'awaiting_stage_start'], 'artifact_memory'=>[
        ['stage'=>'video_plan','artifact'=>'video_prompt_plan','key'=>'ep1_shot1','title'=>'分镜1','content'=>'第一镜原文'],
        ['stage'=>'video_plan','artifact'=>'video_prompt_plan','key'=>'ep1_shot2','title'=>'分镜2','content'=>'第二镜原文'],
    ]]; }
    private function plan(): array { return ['subjects'=>[['id'=>'a','name'=>'甲','category'=>'character'],['id'=>'p','name'=>'信','category'=>'prop']], 'locations'=>[['id'=>'room','name'=>'房间']], 'storyboard'=>[
        ['scene_ref_id'=>'room','subject_ref_ids'=>['a','p'],'recommended_duration_seconds'=>4],
        ['scene_ref_id'=>'room','subject_ref_ids'=>['a'],'recommended_duration_seconds'=>7],
    ]]; }
    public function testAssetsExcludeTurnaroundsAndTurnaroundsOnlyReferenceExistingCharacters(): void {
        $state=$this->state();$render=static fn($type,$item)=>$item['name']??'镜头';
        $assets=Visuals::proposals($this->plan(),'assets',1,$render,[],$state);
        self::assertSame(['subject','subject','scene'],array_column($assets,'artifact'));
        $state['asset_step']='three_view';
        $views=Visuals::proposals($this->plan(),'assets',1,$render,[],$state);
        self::assertCount(1,$views);self::assertSame(['assets:'.Visuals::key('subject','a')],$views[0]['reference_keys']);
        self::assertArrayNotHasKey('depends_on',$views[0]);
        $validate=new ReflectionMethod(GraphService::class,'validateAgentProposals');$validate->setAccessible(true);
        $validate->invoke(null,$views,'assets',true);
    }
    public function testTextStoryboardIsReviewedBeforeVideoSelection(): void {
        $state=$this->state();
        $next=Flow::advance($state,'assets',['assets']);
        self::assertSame('video_plan',$next['key']);self::assertSame('awaiting_stage_start',$next['status']);
        $state['stage_state']=['key'=>'video_plan'];self::assertTrue(Series::requiresConfirmation($state));
        self::assertSame(['key'=>'storyboard','status'=>'ready','completed'=>['video_plan']],Flow::advance($state,'video_plan',['video_plan']));
        $state['stage_state']=['key'=>'storyboard'];$state['plan_confirmation']=['status'=>'confirmed'];
        self::assertFalse(Workflow::mayAutoSubmit($state));
        self::assertSame('awaiting_stage_start',Flow::advance($state,'storyboard',['storyboard'])['status']);
    }
    public function testSelectedBatchPreservesApprovedTextDurationAndAssetReferences(): void {
        $state=$this->state();Flow::answer($state,'ep1_shot2');
        $nodes=Visuals::proposals($this->plan(),'video_nodes',1,static fn()=>'unused',[],$state);
        self::assertCount(1,$nodes);self::assertSame('ep1_shot2',$nodes[0]['key']);
        self::assertSame('第二镜原文',$nodes[0]['prompt']);self::assertSame(7.0,$nodes[0]['duration_seconds']);
        self::assertSame(['assets:'.Visuals::key('subject','a'),'assets:'.Visuals::key('scene','room')],$nodes[0]['reference_keys']);
        $state['artifact_memory'][]=['artifact'=>'storyboard_video','key'=>'ep1_shot2','node_id'=>'22'];
        self::assertSame(['ep1_shot1'],array_column(Flow::remainingVideos($state),'key'));
        self::assertSame('video_nodes',Flow::advance($state,'video_nodes',['video_nodes'])['key']);
        $state['artifact_memory'][]=['artifact'=>'storyboard_video','key'=>'ep1_shot1','node_id'=>'23'];
        self::assertSame('complete',Flow::advance($state,'video_nodes',['video_nodes'])['key']);
    }
    public function testUnknownDuplicateAndAlreadyCreatedSelectionsAreRejected(): void {
        foreach (['ep1_shot9','ep1_shot1,ep1_shot1','ep1_shot2','continue'] as $answer) {
            $state=$this->state();$state['artifact_memory'][]=['artifact'=>'storyboard_video','key'=>'ep1_shot2','node_id'=>'22'];
            try { Flow::answer($state,$answer); self::fail('Invalid selection accepted'); }
            catch (RuntimeException $error) { self::assertSame('INVALID_WORKFLOW_ANSWER',$error->getMessage()); }
        }
    }
    public function testViewOptionExcludesPropsAndAlreadyCreatedViews(): void {
        $state=$this->state();
        $state['artifact_memory'][]=['artifact'=>'art_bible','content'=>json_encode($this->plan())];
        foreach (['a','p'] as $id) $state['artifact_memory'][]=['stage'=>'assets','artifact'=>'subject','key'=>Visuals::key('subject',$id),'node_id'=>$id];
        self::assertTrue(Flow::canPrepareViews($state));
        $state['artifact_memory'][]=['stage'=>'assets','artifact'=>'three_view','key'=>Visuals::key('view','a'),'node_id'=>'view'];
        self::assertFalse(Flow::canPrepareViews($state));
    }
    public function testStoryboardWritesOneReadableTableAndDoesNotCarryImageReuseIntoVideo(): void {
        $nodes=Flow::storyboardNodes([['prompt'=>'第一镜'],['prompt'=>'第二镜']]);
        self::assertCount(1,$nodes);self::assertSame('第一镜'."\n\n".'第二镜',$nodes[0]['prompt']);
        $state=$this->state();$state['image_plan']=['reuse'=>['old']];$state['plan_hash']='old';
        Flow::advance($state,'assets',['assets']);
        self::assertSame([],$state['image_plan']);self::assertSame('',$state['plan_hash']);
    }
    public function testGraphRejectsPendingTurnaroundSourceAndUnselectedVideo(): void {
        $state=$this->state();$state['video_selection']=['ep1_shot2'];
        $key='assets:'.Visuals::key('subject','a');
        $state['artifact_memory'][]=['reference_key'=>$key,'stage'=>'assets','artifact'=>'subject','node_id'=>'10'];
        $nodes=[['id'=>'10','type'=>'image','metadata'=>['workflow_key'=>$key,'workflow_artifact'=>'subject','status'=>'running','asset_id'=>0]]];
        $resolve=new ReflectionMethod(GraphService::class,'compactWorkflowReferenceSources');$resolve->setAccessible(true);
        try { $resolve->invoke(null,$nodes,[],'assets',['artifact'=>'three_view','reference_keys'=>[$key]],$state,[]);self::fail('Pending image accepted'); }
        catch(RuntimeException $error) { self::assertSame('WORKFLOW_REFERENCE_UNAVAILABLE',$error->getMessage()); }
        $nodes[0]['metadata']['status']='success';$nodes[0]['metadata']['asset_id']=3;
        self::assertEquals(['10'],$resolve->invoke(null,$nodes,[],'assets',['artifact'=>'three_view','reference_keys'=>[$key]],$state,[]));
        try { $resolve->invoke(null,$nodes,[],'video_nodes',['artifact'=>'storyboard_video','key'=>'ep1_shot1','reference_keys'=>[$key]],$state,[]);self::fail('Unselected video accepted'); }
        catch(RuntimeException $error) { self::assertSame('INVALID_AGENT_ACTION',$error->getMessage()); }
    }
    public function testOldPendingStoryboardAdvancesWithoutCreatingImages(): void {
        $state=$this->state();$state['stage_state']['key']='storyboard';Flow::answer($state,'continue');
        self::assertSame(['key'=>'video_plan','status'=>'ready'],$state['stage_state']);
    }
}
