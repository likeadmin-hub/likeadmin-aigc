<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationIntentRouter;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowTurn;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CanvasAgentIntentRoutingTest extends TestCase
{
    private function routing(bool $signal=false): array
    {
        return ['version'=>3,'workflow_signal'=>$signal,'skill_candidates'=>[],
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

    public function testCompleteProductionRequiresMoreThanOneKeyword(): void
    {
        foreach (['什么是短剧？','你能帮我生成一个视频脚本吗？','写一集短剧剧本','给我做一张人物图'] as $message) {
            self::assertFalse(ConversationIntentRouter::fullWorkflowSignal($message),$message);
        }
        foreach (['帮我制作一部短剧','我要做10集短剧','做一部完整短剧','从故事到角色分镜视频做成片'] as $message) {
            self::assertTrue(ConversationIntentRouter::fullWorkflowSignal($message),$message);
        }
    }

    public function testOnlyExplicitCompleteCreationCanActivate(): void
    {
        $routing=$this->routing(true);
        self::assertTrue(ConversationIntentRouter::shouldActivateWorkflow($this->decision(),$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($this->decision(),$this->routing(false)));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($this->decision(['speech_act'=>'question']),$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($this->decision(['deliverable'=>'text','scope'=>'standalone']),$routing));
        self::assertFalse(ConversationIntentRouter::shouldActivateWorkflow($this->decision(['confidence'=>0.79]),$routing));
        self::assertSame('你想启动完整短剧制作流程，还是先只完成一份剧本或素材？',
            ConversationIntentRouter::reply($this->decision(),$this->routing(false)));
    }

    public function testStandaloneTextAndAbilityQuestionStayInConversation(): void
    {
        $routing=$this->routing(false);
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

    public function testNewRoutingRejectsMissingOrInventedAxes(): void
    {
        $routing=$this->routing(true);
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
        $routing=$this->routing(false);
        $value=$this->decision(['intent'=>'uncertain','speech_act'=>'request','deliverable'=>'none',
            'scope'=>'uncertain','reply_markdown'=>'你要的是产品宣传脚本，还是实际生成一段产品视频？']);
        $parsed=ConversationIntentRouter::parse(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertSame('你要的是产品宣传脚本，还是实际生成一段产品视频？',ConversationIntentRouter::reply($parsed,$routing));
    }

    public function testActiveWorkflowDoesNotConsumeUnrelatedCreation(): void
    {
        $routing=$this->routing(true)+['kind'=>'active_workflow','workflow_paused'=>false];
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
    }

    public function testFrozenLegacyRoutingShapeRemainsReadable(): void
    {
        $routing=['version'=>2,'workflow_candidate'=>['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY]]];
        $legacy=['intent'=>'short_drama','confidence'=>0.8,'skill_key'=>'','reply_markdown'=>'开始采集'];
        $parsed=ConversationIntentRouter::parse(json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
        self::assertTrue(ConversationIntentRouter::shouldActivateWorkflow($parsed,$routing));
    }
}
