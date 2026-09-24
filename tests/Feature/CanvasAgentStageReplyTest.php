<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStageReply;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowTurn;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowValidationException;
use PHPUnit\Framework\TestCase;

class CanvasAgentStageReplyTest extends TestCase
{
    private function workflow(string $stage): array
    {
        return ['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY], 'stage_state'=>['key'=>$stage]];
    }

    public function testFullStructuredBodyNeverBecomesTheVisibleScriptReply(): void
    {
        $body="## 故事设定\nproject_title: 星海来信\n".str_repeat('第一幕的完整剧本对白和画面描述。',30);
        $nodes=[
            ['type'=>'text','artifact'=>'story_setting','title'=>'《星海来信》故事设定','prompt'=>$body],
            ['type'=>'text','artifact'=>'episode_script','title'=>'第一集剧本','prompt'=>'完整剧本仍在受控节点中'],
        ];
        $visible=ConversationStageReply::present($this->workflow('script'),$body,$nodes,false);
        self::assertStringContainsString('星海来信',$visible);
        self::assertStringContainsString('确认',$visible);
        self::assertStringNotContainsString('project_title',$visible);
        self::assertStringNotContainsString('完整剧本对白',$visible);
        self::assertSame($body,$nodes[0]['prompt']);
    }

    public function testConciseContextualModelSummaryIsKept(): void
    {
        $reply='已整理林岚和周临的角色视觉方向，主场景设为雨夜车站。请确认画风，接下来准备主体图。';
        self::assertSame($reply,ConversationStageReply::present($this->workflow('art'),$reply,[['title'=>'林岚主图','type'=>'text']],false));
    }

    public function testUnwrittenPlanCannotClaimCanvasWasAlreadyChanged(): void
    {
        $visible=ConversationStageReply::present($this->workflow('assets'),'已插入画布，图片已生成，请查看。',[['title'=>'林岚主体图','type'=>'image'],['title'=>'林岚三视图','type'=>'image']],false);
        self::assertStringContainsString('林岚主体图',$visible);
        self::assertStringContainsString('预估积分',$visible);
        self::assertStringNotContainsString('图片已生成',$visible);
    }

    public function testWrittenManualStoryboardReplyDoesNotAskForNonexistentPlanCard(): void
    {
        $visible=ConversationStageReply::present($this->workflow('storyboard'),'已准备图片计划，请核对预计积分，确认后再生成图片。',[
            ['title'=>'办公室场景','type'=>'image'],['title'=>'镜头一','type'=>'image'],
        ],true);
        self::assertStringContainsString('已在画布插入2个',$visible);
        self::assertStringContainsString('逐个确认图片任务',$visible);
        self::assertStringNotContainsString('预计积分',$visible);
    }

    public function testUnconfirmedScriptCannotClaimNodesWereUpdated(): void
    {
        $visible=ConversationStageReply::present($this->workflow('script'),'故事设定节点已更新，单集剧本节点已重写。请确认内容。',[
            ['title'=>'故事设定','type'=>'text'],['title'=>'单集剧本','type'=>'text'],
        ],false);
        self::assertStringNotContainsString('节点已更新',$visible);
        self::assertStringNotContainsString('节点已重写',$visible);
        self::assertStringContainsString('请确认',$visible);
    }

    public function testVideoNodesAlwaysDescribeManualSubmission(): void
    {
        $visible=ConversationStageReply::present($this->workflow('video_nodes'),'已创建画布节点。',[['title'=>'雨夜车站·镜头一','type'=>'video']],true);
        self::assertStringContainsString('雨夜车站',$visible);
        self::assertStringContainsString('逐个',$visible);
    }

    public function testOrdinaryConversationIsUnchanged(): void
    {
        self::assertSame('## 普通聊天',ConversationStageReply::present([], '## 普通聊天',[],false));
    }

    public function testMultiSkillArtPlanAcceptsAllBoundedDistinctArtifacts(): void
    {
        $nodes=[];
        for ($index=1;$index<=40;$index++) $nodes[]=[
            'type'=>'text','artifact'=>'subject_image_prompt','title'=>'角色 '.$index,
            'prompt'=>'角色 '.$index.' 的真实生图提示词','key'=>'subject_'.$index,
        ];
        $output=['reply_markdown'=>'已完成角色画风与主体提示词，接下来请确认规划。','canvas_actions'=>['nodes'=>$nodes]];
        $encoded=json_encode($output,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        self::assertCount(40,ConversationActionPlan::parse($encoded,'art',true)['nodes']);
        self::assertSame(64,ConversationActionPlan::maximumNodesForStage('art',true));
        self::assertSame(16,ConversationActionPlan::maximumNodesForStage('assets',true));
        self::assertSame(24,ConversationActionPlan::maximumNodesForStage('storyboard',true));
        $routing=['version'=>2,'kind'=>'active_workflow','skill_candidates'=>[],
            'workflow_candidate'=>['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>'2026-09-23.10'],
                'stage_state'=>['key'=>'art']]];
        $turn=['intent'=>'continue','confidence'=>0.95,'skill_key'=>'','reply_markdown'=>'','workflow_output'=>$output];
        self::assertCount(40,ConversationWorkflowTurn::parse(json_encode($turn,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing)['nodes']);
    }

    public function testCompletedStageReportsItsActualFrozenSkillsAsOneStageAction(): void
    {
        $workflow=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'stage_skill_versions'=>[
            'art'=>[['name'=>'画风设计'],['name'=>'主体设计'],['name'=>'场景设计'],['name'=>'道具设计']],
        ]],'stage_state'=>['key'=>'art']];
        $items=ConversationWorkflow::timeline($workflow,[],[]);
        self::assertCount(1,$items);
        self::assertSame('本阶段使用技能',$items[0]['label']);
        self::assertSame('画风设计、主体设计、场景设计、道具设计',$items[0]['detail']);
    }

    public function testEveryWorkflowStageRequestsOneValidatedJsonEnvelope(): void
    {
        foreach (['script','art','assets','storyboard','video_plan','video_nodes','audio_plan'] as $stage) {
            self::assertSame(['type'=>'json_object'],ConversationActionPlan::responseFormat($stage));
            $instruction=ConversationActionPlan::instruction('manual',$stage,true,true);
            self::assertStringContainsString('canvas_actions',$instruction);
            self::assertStringNotContainsString('<canvas-actions>',$instruction);
            if (in_array($stage,['assets','storyboard','video_nodes','audio_plan'],true)) self::assertStringContainsString('全批次唯一',$instruction);
        }
        self::assertNull(ConversationActionPlan::responseFormat(''));
    }

    public function testStoryboardInstructionPreservesEachConfirmedScriptShot(): void
    {
        foreach ([true,false] as $compact) {
            $instruction=ConversationActionPlan::instruction('manual','storyboard',$compact,true);
            self::assertStringContainsString('每个剧本镜头恰好对应一个同编号 storyboard',$instruction);
            self::assertStringContainsString('可见动作',$instruction);
            self::assertStringContainsString('结局',$instruction);
            self::assertStringNotContainsString('办公室递交简历',$instruction);
        }
    }

    public function testRejectedMediaPlanReportsOnlySafeStructuralCategory(): void
    {
        $reply=json_encode(['reply_markdown'=>'准备了主体与三视图。','canvas_actions'=>['nodes'=>[
            ['type'=>'image','artifact'=>'three_view','title'=>'三视图','prompt'=>'提示词','key'=>'view','depends_on'=>['subject']],
            ['type'=>'image','artifact'=>'subject','title'=>'主体图','prompt'=>'提示词','key'=>'subject'],
        ]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        self::assertSame('action_node_dependency_order',ConversationActionPlan::failureCategory($reply,'assets',true));
    }

    public function testUniqueConfirmedScenePromptCanFillAnOmittedReference(): void
    {
        $workflow=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>ConversationWorkflow::VERSION],
            'stage_state'=>['key'=>'storyboard'],'artifact_memory'=>[
                ['stage'=>'art','artifact'=>'scene_image_prompt','reference_key'=>'art:scene_shop','content'=>'已确认的耳机店场景生图提示词'],
            ]];
        $nodes=ConversationWorkflow::materializeTextReferences($workflow,[
            ['type'=>'image','artifact'=>'scene','title'=>'耳机店场景','prompt'=>'模型自写提示词','key'=>'scene_shop'],
        ]);
        self::assertSame('已确认的耳机店场景生图提示词',$nodes[0]['prompt']);
        self::assertArrayNotHasKey('reference_keys',$nodes[0]);
    }

    public function testMultipleScenePromptsAreNeverGuessed(): void
    {
        $workflow=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>ConversationWorkflow::VERSION],
            'stage_state'=>['key'=>'storyboard'],'artifact_memory'=>[
                ['stage'=>'art','artifact'=>'scene_image_prompt','reference_key'=>'art:scene_shop','content'=>'耳机店'],
                ['stage'=>'art','artifact'=>'scene_image_prompt','reference_key'=>'art:scene_street','content'=>'街道'],
            ]];
        try {
            ConversationWorkflow::materializeTextReferences($workflow,[
                ['type'=>'image','artifact'=>'scene','title'=>'场景','prompt'=>'模型自写提示词','key'=>'scene'],
            ]);
            self::fail('Ambiguous scene selection must fail');
        } catch (ConversationWorkflowValidationException $error) {
            self::assertSame('INVALID_AGENT_ACTION',$error->getMessage());
            self::assertSame('action_generation_prompt_reference',$error->category());
        }
    }

    public function testStoryboardShotCoverageReportsAStableDiagnosticWithoutLeakingScript(): void
    {
        $workflow=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>ConversationWorkflow::VERSION],
            'stage_state'=>['key'=>'storyboard'],'artifact_memory'=>[
                ['stage'=>'script','artifact'=>'episode_script','reference_key'=>'script:episode','content'=>'镜头1：小禾进店。镜头2：店长解释。'],
            ]];
        try {
            ConversationWorkflow::materializeTextReferences($workflow,[
                ['type'=>'image','artifact'=>'storyboard','title'=>'镜头1','prompt'=>'小禾进店','key'=>'shot_one'],
            ]);
            self::fail('Missing confirmed shot must fail');
        } catch (ConversationWorkflowValidationException $error) {
            self::assertSame('action_storyboard_shot_coverage',$error->category());
            self::assertStringNotContainsString('小禾',$error->getMessage());
        }
    }

    public function testExplicitAlternativeShotLabelsRetainConfirmedCoverage(): void
    {
        $workflow=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>ConversationWorkflow::VERSION],
            'stage_state'=>['key'=>'storyboard'],'artifact_memory'=>[
                ['stage'=>'script','artifact'=>'episode_script','reference_key'=>'script:episode','content'=>'镜头01：进店。第2镜：解释。'],
            ]];
        $nodes=ConversationWorkflow::materializeTextReferences($workflow,[
            ['type'=>'image','artifact'=>'storyboard','title'=>'分镜图 1','prompt'=>'进店','key'=>'shot_one'],
            ['type'=>'image','artifact'=>'storyboard','title'=>'镜头：02','prompt'=>'解释','key'=>'shot_two'],
        ]);
        self::assertCount(2,$nodes);
    }

    public function testSingleExactMediaDependencyCanBeCanonicalized(): void
    {
        $reply=json_encode(['reply_markdown'=>'主体资产已准备好。','canvas_actions'=>['nodes'=>[
            ['type'=>'image','artifact'=>'subject','title'=>'主体图','prompt'=>'主体生图提示词','key'=>'subject'],
            ['type'=>'image','artifact'=>'three_view','title'=>'三视图','prompt'=>'三视图生图提示词','key'=>'view','depends_on'=>'subject'],
        ]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $nodes=ConversationActionPlan::parse($reply,'assets',true)['nodes'];
        self::assertSame(['subject'],$nodes[1]['depends_on']);
    }

    public function testDisabledAudioPlanDiscardsProviderExecutionAnnotations(): void
    {
        $reply=json_encode(['reply_markdown'=>'已准备音频规划。','canvas_actions'=>['nodes'=>[
            ['type'=>'audio','artifact'=>'audio_plan','title'=>'背景音乐','prompt'=>'轻喜剧纯器乐配乐，96 BPM，以电钢琴和轻打击乐推进误会与和解，约 30 秒，可无缝循环；不要歌词和人声。','key'=>'audio_plan','provider'=>'not_executed','status'=>'pending'],
        ]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $nodes=ConversationActionPlan::parse($reply,'audio_plan',true)['nodes'];
        self::assertCount(1,$nodes);
        self::assertArrayNotHasKey('provider',$nodes[0]);
        self::assertArrayNotHasKey('status',$nodes[0]);
    }

    public function testAudioAndVideoStageInstructionsKeepSpeechOutOfMusicNode(): void
    {
        $audio=ConversationActionPlan::instruction('manual','audio_plan',true,true);
        $video=ConversationActionPlan::instruction('manual','video_nodes',true,true);
        self::assertStringContainsString('纯背景音乐', $audio);
        self::assertStringContainsString('不得包含逐镜对白', $audio);
        self::assertStringContainsString('说话主体', $video);
        self::assertStringContainsString('对白原文', $video);
        self::assertSame(['music_prompt','music_style','mood_curve','bpm','instruments','duration','looping'], ConversationWorkflow::outputContract('audio_plan'));
    }
}
