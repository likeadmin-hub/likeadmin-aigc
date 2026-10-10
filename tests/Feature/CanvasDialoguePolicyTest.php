<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationDialoguePolicy as Policy;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowTurn as Turn;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;

class CanvasDialoguePolicyTest extends TestCase
{
    private function routing(): array { return ['version'=>3,'kind'=>'active_workflow','workflow_paused'=>true,'skill_candidates'=>[],
        'workflow_candidate'=>['workflow_snapshot'=>['key'=>Workflow::KEY],'stage_state'=>['key'=>'intake']]]; }
    private function decision(array $changes=[]): array { return array_replace(['intent'=>'chat','confidence'=>0.95,'skill_key'=>'',
        'reply_markdown'=>'你想沿用刚才的故事，还是重新构思？','workflow_output'=>null,
        'speech_act'=>'chat','deliverable'=>'text','scope'=>'conversation'],$changes); }
    public function testPendingCardNeverReplacesUsefulConversationOrClarification(): void {
        foreach (['chat','uncertain','continue'] as $intent) {
            $value=$this->decision(['intent'=>$intent]);
            $decision=Turn::parse(json_encode($value),$this->routing());
            self::assertSame($value['reply_markdown'],$decision['text']);
            self::assertFalse($decision['continue']);self::assertSame([],$decision['nodes']);
        }
    }
    public function testPendingProductionStillCannotExecuteModelProposals(): void {
        $value=$this->decision(['intent'=>'continue','scope'=>'workflow','speech_act'=>'request',
            'workflow_output'=>['canvas_actions'=>['nodes'=>[['type'=>'video']]]]]);
        $this->expectExceptionMessage('INVALID_AGENT_INTENT');Turn::parse(json_encode($value),$this->routing());
    }
    public function testPauseResumeAreExplicitControlDecisionsWithoutMediaActions(): void {
        foreach (['pause'=>true,'resume'=>false] as $intent=>$paused) {
            $value=$this->decision(['intent'=>$intent,'scope'=>'workflow','speech_act'=>'request']);
            $decision=Turn::parse(json_encode($value),$this->routing());
            self::assertSame($paused,$decision['execution_paused']);
            self::assertSame([],$decision['nodes']);self::assertFalse($decision['continue']);
        }
    }
    public function testQuestionAboutPausingCannotPause(): void {
        $value=$this->decision(['intent'=>'pause','scope'=>'workflow','speech_act'=>'question']);
        $this->expectExceptionMessage('INVALID_AGENT_INTENT');Turn::parse(json_encode($value),$this->routing());
    }
    public function testPausedStageRejectsAutomaticExecutionEvenWithMatchingRequestKey(): void {
        $state=['workflow_snapshot'=>['key'=>Workflow::KEY],'stage_state'=>['key'=>'script','status'=>'ready'],
            'state_revision'=>7,'execution_paused'=>true];
        self::assertFalse(Workflow::validAutoStageRequest($state,1,2,'as:1:2:7:script',Workflow::autoStagePrompt('script')));
        $state['execution_paused']=false;
        self::assertTrue(Workflow::validAutoStageRequest($state,1,2,'as:1:2:7:script',Workflow::autoStagePrompt('script')));
    }
    public function testActivityComesFromFrozenRunNotDormantWorkflow(): void {
        $workflow=['stage_state'=>['key'=>'art','completed'=>['script']],
            'workflow_snapshot'=>['stages'=>[['key'=>'script','label'=>'剧本'],['key'=>'art','label'=>'美术']]]];
        self::assertSame(['kind'=>'conversation'],Policy::activity(['intent_routing'=>['workflow_candidate'=>$workflow]]));
        self::assertSame(['kind'=>'stage','stage_key'=>'art','stage_label'=>'美术','completed'=>1,'total'=>2],Policy::activity(['workflow'=>$workflow]));
        $workflow['stage_state']['key']='intake';self::assertSame(['kind'=>'conversation'],Policy::activity(['workflow'=>$workflow]));
    }
    public function testConversationModelSelectionDoesNotChangeFrozenProductionModels(): void {
        $state=['workflow_snapshot'=>['model_preferences'=>['reasoning_model'=>['id'=>'original-text'],
            'image_model'=>['id'=>'original-image'],'generation_mode'=>'manual']]];
        $preferences=['reasoning_model'=>'chosen-text','image_model'=>'new-image','generation_mode'=>'auto'];
        self::assertSame('chosen-text',Workflow::frozenPreferences($state,$preferences,false)['reasoning_model']);
        self::assertSame('original-image',Workflow::frozenPreferences($state,$preferences,false)['image_model']);
        self::assertSame('manual',Workflow::frozenPreferences($state,$preferences,false)['generation_mode']);
        self::assertSame('original-text',Workflow::frozenPreferences($state,$preferences,true)['reasoning_model']);
        self::assertSame('original-text',Workflow::frozenPreferences($state,[],false)['reasoning_model']);
    }

    public function testBoundedMemoryRetainsRolesAndMarksTruncation(): void {
        $messages=[];for($i=0;$i<15;$i++) $messages[]=['role'=>$i%2?'assistant':'user','content'=>(string)$i];
        $messages[14]['content']=str_repeat('中',2500);
        $recent=Policy::recentDialogue($messages);
        self::assertCount(12,$recent);self::assertSame('3',$recent[0]['content']);
        self::assertSame('assistant',$recent[0]['role']);self::assertFalse($recent[0]['truncated']);
        self::assertSame(2000,mb_strlen($recent[11]['content']));self::assertTrue($recent[11]['truncated']);
        self::assertSame(2500,mb_strlen($messages[14]['content']));
    }
}
