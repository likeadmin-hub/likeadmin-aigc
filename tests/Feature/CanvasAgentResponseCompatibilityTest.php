<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationIntakeDraft;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationIntentRouter;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationResponseJson;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowTurn;
use PHPUnit\Framework\TestCase;

class CanvasAgentResponseCompatibilityTest extends TestCase
{
    private function routing(string $stage='intake'): array
    {
        return ['version'=>3,'kind'=>'active_workflow','skill_candidates'=>[],
            'workflow_candidate'=>['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,
                'slot_schema'=>ConversationWorkflow::catalog()['slots']], 'stage_state'=>['key'=>$stage]]];
    }

    private function decision(): array
    {
        return ['intent'=>'continue','confidence'=>0.95,'skill_key'=>'','reply_markdown'=>'',
            'speech_act'=>'answer','deliverable'=>'full_drama','scope'=>'workflow',
            'workflow_output'=>['reply_markdown'=>'收到，先确认画幅。','intake'=>['candidates'=>[['key'=>'bad']],'questions'=>null]]];
    }

    public function testDirectAndActiveIntakeUseTheSameRecovery(): void
    {
        $value=$this->decision();
        $direct=ConversationIntakeDraft::parseConversation(json_encode($value['workflow_output']),ConversationWorkflow::catalog()['slots']);
        $active=ConversationWorkflowTurn::parseConversation(json_encode($value),$this->routing());
        self::assertTrue($active['continue']);
        self::assertSame($direct['reply_markdown'],$active['text']);
        self::assertSame($direct['intake'],$active['intake']);
        self::assertSame([],$active['nodes']);
        $envelope=array_intersect_key($active,$value);
        self::assertSame($active,ConversationWorkflowTurn::parse(json_encode($envelope),$this->routing()));
    }

    public function testActiveOrdinaryReplyIgnoresUnusedStageOutputWithoutExecutingIt(): void
    {
        foreach (['chat','creative_plan','uncertain'] as $intent) {
            $value=array_replace($this->decision(),['intent'=>$intent,'scope'=>'conversation','deliverable'=>'text',
                'reply_markdown'=>'先聊聊人物动机，不改现有画布。','confidence'=>'0.95',
                'workflow_output'=>['canvas_actions'=>['nodes'=>[['type'=>'image']]]]]);
            $result=ConversationWorkflowTurn::parseConversation(json_encode($value),$this->routing('assets'));
            self::assertFalse($result['continue']);
            self::assertNull($result['workflow_output']);
            self::assertSame([],$result['nodes']);
            self::assertSame($value['reply_markdown'],$result['text']);
        }
    }

    public function testCompleteJsonWrappersAndReorderedStageFieldsAreEquivalent(): void
    {
        $node=['type'=>'image','artifact'=>'subject','key'=>'wukong','title'=>'悟空','prompt'=>'悟空的主体参考图'];
        $normal=json_encode(['reply_markdown'=>'主体方案','canvas_actions'=>['nodes'=>[$node]]]);
        $reversed=json_encode(['canvas_actions'=>['nodes'=>[$node]],'reply_markdown'=>'主体方案']);
        $expected=ConversationActionPlan::parse($normal,'assets',true);
        foreach ([$reversed,"```json\n$reversed\n```","\xEF\xBB\xBF$reversed"] as $response) {
            self::assertSame($expected,ConversationActionPlan::parse($response,'assets',true));
        }
        $initial=['intent'=>'chat','confidence'=>1,'skill_key'=>'','reply_markdown'=>'你好',
            'intake'=>['candidates'=>[],'questions'=>[]],'speech_act'=>'chat','deliverable'=>'text','scope'=>'conversation'];
        $json=json_encode($initial);
        self::assertSame($initial['reply_markdown'],ConversationIntentRouter::parseConversation("```json\n$json\n```",$this->routing())['reply_markdown']);
    }

    public function testMalformedExecutableOutputStillFails(): void
    {
        foreach (['assets','video_nodes'] as $stage) {
            $value=$this->decision();
            $value['workflow_output']=['reply_markdown'=>'准备好了','canvas_actions'=>['nodes'=>[['type'=>'image']]]];
            try {
                ConversationWorkflowTurn::parseConversation(json_encode($value),$this->routing($stage));
                self::fail('Malformed action was accepted');
            } catch (\RuntimeException $error) { self::assertSame('INVALID_AGENT_ACTION',$error->getMessage()); }
        }
    }

    public function testFormattingRecoveryNeverRepairsTruncationOrExtractsEmbeddedActions(): void
    {
        foreach (['{"reply_markdown":"unfinished', 'prefix {"a":1}', '{"a":1}{"b":2}', "```json\n{}\n```\ntrailing"] as $response) {
            try { ConversationResponseJson::decode($response);self::fail('Invalid JSON accepted'); }
            catch (\JsonException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }

    public function testIntakeRecoveryDoesNotDiscardExecutableTopLevelFields(): void
    {
        $this->expectExceptionMessage('INVALID_AGENT_INTAKE');
        ConversationIntakeDraft::parseConversation(json_encode(['reply_markdown'=>'开始','intake'=>[],
            'canvas_actions'=>['nodes'=>[]]]),ConversationWorkflow::catalog()['slots']);
    }
}
