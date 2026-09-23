<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationIntentRouter;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowTurn;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CanvasAgentIntentRoutingTest extends TestCase
{
    private function routing(): array
    {
        return ['version'=>3,'skill_candidates'=>[],
            'workflow_candidate'=>['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY],
                'stage_state'=>['key'=>'intake']]];
    }

    private function decision(array $changes=[]): array
    {
        return array_replace(['intent'=>'short_drama','confidence'=>0.96,'skill_key'=>'',
            'reply_markdown'=>'可以，我来帮你规划。','intake'=>['candidates'=>[],'questions'=>[]],
            'speech_act'=>'request','deliverable'=>'full_drama','scope'=>'workflow'],$changes);
    }

    public function testKeywordsAndQuestionsDoNotDirectlyStartAWorkflow(): void
    {
        $method=new ReflectionMethod(ConversationWorkflow::class,'route');
        $method->setAccessible(true);
        self::assertNull($method->invoke(null,[],'什么是短剧？'));
        self::assertNull($method->invoke(null,[],'帮我写一集短剧剧本'));
        self::assertNull($method->invoke(null,[],'你能帮我生成一个视频脚本吗？'));
        self::assertSame('manual',$method->invoke(null,[],'/short-drama 开始创作'));
    }

    public function testOnlySemanticallyClassifiedCompleteCreationCanActivate(): void
    {
        $routing=$this->routing();
        self::assertTrue(ConversationIntentRouter::shouldActivateWorkflow($this->decision(),$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($this->decision(['speech_act'=>'question']),$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($this->decision(['deliverable'=>'text','scope'=>'standalone']),$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($this->decision(['confidence'=>0.79]),$routing));
        self::assertSame('我还不确定你希望得到什么结果。你是想咨询问题、单独创作一份内容，还是启动完整短剧制作？',
            ConversationIntentRouter::reply($this->decision(['confidence'=>0.79]),$routing));
    }

    public function testStandaloneTextAndAbilityQuestionStayInConversation(): void
    {
        $routing=$this->routing();
        $script=$this->decision(['intent'=>'creative_plan','deliverable'=>'text','scope'=>'standalone',
            'reply_markdown'=>'产品视频脚本：镜头一展示产品。']);
        $parsed=ConversationIntentRouter::parse(json_encode($script,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertSame('产品视频脚本：镜头一展示产品。',ConversationIntentRouter::reply($parsed,$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($parsed,$routing));
        $question=$this->decision(['intent'=>'chat','speech_act'=>'question','deliverable'=>'text',
            'scope'=>'conversation','reply_markdown'=>'可以。你希望脚本讲什么主题？']);
        $parsed=ConversationIntentRouter::parse(json_encode($question,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertSame('可以。你希望脚本讲什么主题？',ConversationIntentRouter::reply($parsed,$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($parsed,$routing));
    }

    public function testOrdinaryProductScriptCanRecoverAnIncompleteAdvisoryEnvelope(): void
    {
        $routing=$this->routing();
        // A real product-script reply needs neither a workflow intake draft
        // nor executable media fields. The model may omit advisory axes or
        // format confidence as a JSON string.
        $response=json_encode(['intent'=>'creative_plan','confidence'=>'0.93',
            'reply_markdown'=>'耳机宣传脚本：降噪场景切换到环绕立体音场景。',
            'reasoning'=>'not an executable action'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $parsed=ConversationIntentRouter::parseConversation($response,$routing);
        self::assertSame('耳机宣传脚本：降噪场景切换到环绕立体音场景。',ConversationIntentRouter::reply($parsed,$routing));
        self::assertSame('standalone',$parsed['scope']);
        self::assertSame('text',$parsed['deliverable']);
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($parsed,$routing));
        self::assertSame(['candidates'=>[],'questions'=>[]],$parsed['intake']);
        self::assertSame('intent_shape',ConversationIntentRouter::failureCategory($response,$routing));
        $canonical=json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        self::assertSame($parsed,ConversationIntentRouter::parse($canonical,$routing));
        $emptyIntake=json_encode(['intent'=>'chat','confidence'=>0.9,'skill_key'=>'',
            'reply_markdown'=>'可以继续完善耳机脚本。','intake'=>[],'speech_act'=>'answer',
            'deliverable'=>'text','scope'=>'conversation'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        self::assertSame('可以继续完善耳机脚本。',
            ConversationIntentRouter::parseConversation($emptyIntake,$routing)['reply_markdown']);
    }

    public function testRecoveryCannotActivateWorkflowOrAcceptUnownedActions(): void
    {
        $routing=$this->routing();
        foreach ([
            ['intent'=>'short_drama','confidence'=>0.96,'reply_markdown'=>'开始完整短剧制作'],
            ['intent'=>'creative_plan','confidence'=>0.96,'reply_markdown'=>'已完成','scope'=>'workflow'],
            ['intent'=>'creative_plan','confidence'=>0.96,'reply_markdown'=>'已完成','deliverable'=>'full_drama'],
            ['intent'=>'creative_plan','confidence'=>0.96,'reply_markdown'=>'已完成','skill_key'=>'unowned_skill'],
            ['intent'=>'creative_plan','confidence'=>0.96,'reply_markdown'=>'已完成','intake'=>['candidates'=>[['key'=>'genre']],'questions'=>[]]],
            ['intent'=>'creative_plan','confidence'=>0.96,'reply_markdown'=>'<canvas-actions>{}</canvas-actions>'],
            ['intent'=>'creative_plan','confidence'=>0.96,'reply_markdown'=>'已完成','canvas_actions'=>['nodes'=>[]]],
            ['intent'=>'image','confidence'=>0.96,'reply_markdown'=>'图片已经生成'],
        ] as $value) {
            try {
                ConversationIntentRouter::parseConversation(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
                self::fail('An incomplete workflow, media claim, or unowned action was accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('INVALID_AGENT_INTENT',$error->getMessage());
            }
        }
        self::assertSame('intent_not_json',ConversationIntentRouter::failureCategory('not-json',$routing));
    }

    public function testNewRoutingRejectsMissingOrInventedAxes(): void
    {
        $routing=$this->routing();
        $missing=$this->decision();
        unset($missing['scope']);
        try {
            ConversationIntentRouter::parse(json_encode($missing,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
            self::fail('Missing scope was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('INVALID_AGENT_INTENT',$error->getMessage());
        }
        $invented=$this->decision(['scope'=>'all_projects']);
        try {
            ConversationIntentRouter::parse(json_encode($invented,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
            self::fail('Invented scope was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('INVALID_AGENT_INTENT',$error->getMessage());
        }
    }

    public function testClarificationUsesTheCurrentMessageInsteadOfAStockQuestion(): void
    {
        $routing=$this->routing();
        $value=$this->decision(['intent'=>'uncertain','speech_act'=>'request','deliverable'=>'none',
            'scope'=>'uncertain','reply_markdown'=>'你要的是产品宣传脚本，还是实际生成一段产品视频？']);
        $parsed=ConversationIntentRouter::parse(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertSame('你要的是产品宣传脚本，还是实际生成一段产品视频？',ConversationIntentRouter::reply($parsed,$routing));
    }

    public function testActiveWorkflowDoesNotConsumeUnrelatedCreation(): void
    {
        $routing=$this->routing()+['kind'=>'active_workflow','workflow_paused'=>false];
        $value=['intent'=>'video','confidence'=>0.95,'skill_key'=>'',
            'reply_markdown'=>'这是一项独立视频创作，当前短剧进度保留。','workflow_output'=>null,
            'speech_act'=>'request','deliverable'=>'video','scope'=>'standalone'];
        $parsed=ConversationWorkflowTurn::parse(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertFalse($parsed['continue']);
        self::assertSame([],$parsed['nodes']);
        $value['intent']='continue';
        $parsed=ConversationWorkflowTurn::parse(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertFalse($parsed['continue']);
        self::assertSame([],$parsed['nodes']);
        $value=['intent'=>'uncertain','confidence'=>0.5,'skill_key'=>'',
            'reply_markdown'=>'你说的“换个主题”是修改这部短剧，还是开始一项独立创作？','workflow_output'=>null,
            'speech_act'=>'request','deliverable'=>'none','scope'=>'uncertain'];
        $parsed=ConversationWorkflowTurn::parse(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertFalse($parsed['continue']);
        self::assertSame('你说的“换个主题”是修改这部短剧，还是开始一项独立创作？',$parsed['text']);
    }

    public function testPausedArtPlanCanSemanticallyReopenScriptWithoutWritingMedia(): void
    {
        $state=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>'2026-09-23.10','stage_skill_versions'=>[]],
            'stage_state'=>['key'=>'art','status'=>'awaiting_stage_confirmation','completed'=>['intake','script']],
            'slot_values'=>['ending'=>'悲剧'],'creative_settings'=>[],
            'artifact_memory'=>[
                ['stage'=>'script','artifact'=>'story_setting','node_id'=>'123','content'=>'旧的悲剧故事设定'],
                ['stage'=>'script','artifact'=>'episode_script','node_id'=>'124','content'=>'旧的悲剧单集剧本'],
                ['stage'=>'art','artifact'=>'art_bible','content'=>'旧的悲剧美术规划'],
            ],
            'image_plan'=>[],'stage_plan'=>['stage'=>'art','nodes'=>[['type'=>'text']]],
            'plan_hash'=>'old-hash','plan_confirmation'=>['status'=>'required'],'state_revision'=>7];
        self::assertSame(['script','art'],ConversationWorkflow::revisableStages($state));
        $routing=['version'=>3,'kind'=>'active_workflow','workflow_paused'=>true,
            'workflow_candidate'=>$state,'revision_allowed_stages'=>['script','art'],'skill_candidates'=>[]];
        $value=['intent'=>'revise','confidence'=>0.95,'skill_key'=>'',
            'reply_markdown'=>'我会从剧本重新生成，修订后请你确认。',
            'workflow_output'=>['revision_stage'=>'script'],'speech_act'=>'request',
            'deliverable'=>'full_drama','scope'=>'workflow'];
        $parsed=ConversationWorkflowTurn::parse(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertSame('script',$parsed['revision_stage']);
        self::assertFalse($parsed['continue']);
        self::assertSame([], $parsed['nodes']);
        $reopened=ConversationWorkflow::reopenStage($state,$parsed['revision_stage'],'不要悲伤美学，改为喜剧无厘头');
        self::assertSame(['key'=>'script','status'=>'ready','completed'=>['intake']],$reopened['stage_state']);
        self::assertSame(8,$reopened['state_revision']);
        self::assertSame([],$reopened['stage_plan']);
        self::assertSame([],$reopened['image_plan']);
        self::assertSame('',$reopened['plan_hash']);
        self::assertCount(2,$reopened['artifact_memory']);
        self::assertSame('不要悲伤美学，改为喜剧无厘头',$reopened['revision_request']['content']);
        self::assertSame('replace',$reopened['revision_request']['mode']);
        self::assertSame(['不要悲伤美学，改为喜剧无厘头'],$reopened['revision_constraints']);
        self::assertSame('悲剧',$reopened['slot_values']['ending']);
        $value['workflow_output']=['revision_stage'=>'video_plan'];
        $this->expectExceptionMessage('INVALID_AGENT_INTENT');
        ConversationWorkflowTurn::parse(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
    }

    public function testRevisionAfterMediaForksWithoutDiscardingPaidGraphNodes(): void
    {
        $state=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>'2026-09-23.10','stage_skill_versions'=>[]],
            'stage_state'=>['key'=>'complete','status'=>'ready','completed'=>['intake','script','art','assets','storyboard','video_plan','video_nodes','audio_plan']],
            'slot_values'=>[],'creative_settings'=>[],
            'artifact_memory'=>[
                ['stage'=>'script','artifact'=>'story_setting','node_id'=>'1','content'=>'旧设定'],
                ['stage'=>'script','artifact'=>'episode_script','node_id'=>'2','content'=>'旧剧本'],
                ['stage'=>'assets','artifact'=>'subject','node_id'=>'3','content'=>'旧主体图'],
            ],
            'image_plan'=>[],'stage_plan'=>[],'plan_hash'=>'',
            'plan_confirmation'=>['status'=>'not_required'],'state_revision'=>21,
            'revision_constraints'=>['旧结局必须悲剧']];
        self::assertSame(['script','art','video_plan'],ConversationWorkflow::revisableStages($state));
        $instruction=ConversationWorkflowTurn::instruction(['version'=>3,'workflow_candidate'=>$state,
            'revision_allowed_stages'=>ConversationWorkflow::revisableStages($state)],'manual');
        self::assertStringContainsString('不要反问是否开始',$instruction);
        self::assertStringContainsString('已有图片与视频节点及已付费结果会保留',$instruction);
        $reopened=ConversationWorkflow::reopenStage($state,'script','改成无厘头喜剧结局');
        self::assertSame('fork',$reopened['revision_request']['mode']);
        self::assertSame(['key'=>'script','status'=>'ready','completed'=>['intake']],$reopened['stage_state']);
        self::assertSame(['改成无厘头喜剧结局'],$reopened['revision_constraints']);
        self::assertSame(['1','2'],array_column($reopened['artifact_memory'],'node_id'));
        self::assertSame('旧主体图',$state['artifact_memory'][2]['content']);
    }

    public function testOnlyExactReadyStageProtocolCanBypassSemanticClassification(): void
    {
        $state=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY],
            'stage_state'=>['key'=>'script','status'=>'ready'],'state_revision'=>18];
        $prompt=ConversationWorkflow::autoStagePrompt('script');
        self::assertNotSame('',$prompt);
        self::assertTrue(ConversationWorkflow::validAutoStageRequest($state,213,538,'as:213:538:18:script',$prompt));
        self::assertFalse(ConversationWorkflow::validAutoStageRequest($state,213,538,'message:arbitrary',$prompt));
        self::assertFalse(ConversationWorkflow::validAutoStageRequest($state,213,538,'as:213:538:17:script',$prompt));
        self::assertFalse(ConversationWorkflow::validAutoStageRequest($state,213,538,'as:213:538:18:script','请改变剧本结局'));
        $state['stage_state']['status']='awaiting_stage_confirmation';
        self::assertFalse(ConversationWorkflow::validAutoStageRequest($state,213,538,'as:213:538:18:script',$prompt));
    }

    public function testTerminalStageFailureBecomesExplicitlyRetryableWithoutLosingRevision(): void
    {
        $workflow=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY],
            'stage_state'=>['key'=>'script','status'=>'running','completed'=>['intake']],
            'state_revision'=>18,'revision_request'=>['stage'=>'script','content'=>'改为喜剧']];
        $settings=['workflow_state'=>$workflow];
        $recovered=ConversationWorkflow::recoverTerminalStageFailure($settings,['workflow'=>$workflow]);
        self::assertSame('ready',$recovered['workflow_state']['stage_state']['status']);
        self::assertSame(19,$recovered['workflow_state']['state_revision']);
        self::assertSame('改为喜剧',$recovered['workflow_state']['revision_request']['content']);
        self::assertNull(ConversationWorkflow::recoverTerminalStageFailure($recovered,['workflow'=>$workflow]));
        $stale=$workflow;$stale['state_revision']=17;
        self::assertNull(ConversationWorkflow::recoverTerminalStageFailure($settings,['workflow'=>$stale]));
    }

    public function testStageFailureDiagnosticsNeverRetainModelContent(): void
    {
        $reply=json_encode(['reply_markdown'=>'秘密内容','canvas_actions'=>['nodes'=>[
            ['type'=>'text','artifact'=>'story_setting','title'=>'故事','prompt'=>'私密剧本'],
        ]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        self::assertSame('action_script_artifacts',ConversationActionPlan::failureCategory($reply,'script',true));
        self::assertSame('action_not_json',ConversationActionPlan::failureCategory('私密剧本','script',true));
    }

    public function testFrozenLegacyRoutingShapeRemainsReadable(): void
    {
        $routing=['version'=>2,'workflow_candidate'=>['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY]]];
        $legacy=['intent'=>'short_drama','confidence'=>0.8,'skill_key'=>'','reply_markdown'=>'开始采集'];
        $parsed=ConversationIntentRouter::parse(json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertTrue(ConversationIntentRouter::shouldActivateWorkflow($parsed,$routing));
    }
}
