<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationAgentDecision as Decision;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationAgentMemory as Memory;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePlan as Plan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePolicy as Policy;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationTextContext as TextContext;

class CanvasAgentDecisionTest extends TestCase
{
    public static function state(): array {
        $slots=Workflow::catalog()['slots'];
        foreach($slots as &$slot) if($slot['key']==='aspect_ratio') $slot['options']=['9:16','16:9'];
        return ['workflow_snapshot'=>['key'=>Workflow::KEY,'version'=>Workflow::VERSION,'creative_policy_version'=>Policy::VERSION,
            'series_core'=>true,'slot_schema'=>$slots,'stage_skill_versions'=>[],'frozen_at'=>time()],
            'stage_state'=>['key'=>'intake','status'=>'collecting','completed'=>[]],
            'slot_values'=>[],'slot_sources'=>[],'creative_settings'=>[],'artifact_memory'=>[],'image_plan'=>[],'stage_plan'=>[],
            'plan_hash'=>'','plan_confirmation'=>['status'=>'not_required'],'state_revision'=>1,'intake_collection_version'=>2];
    }
    private function context(bool $active=false): array {
        return ['messages'=>[['role'=>'user','content'=>'我想生成短剧，主角叫林小凡']],
            'intent_routing'=>['version'=>3,'dialogue_engine'=>Decision::ENGINE,'kind'=>$active?'active_workflow':'initial',
                'workflow_candidate'=>self::state(),'workflow_paused'=>false,'skill_candidates'=>[]]];
    }
    public static function reply(string $name='respond',array $args=[],array $remember=[]): array {
        return ['reply_markdown'=>'我们可以先聊故事。','action'=>['name'=>$name,'arguments'=>$args],'remember'=>$remember];
    }
    public static function intake(array $values=[],bool $show=false,string $execution='preserve'): array {
        $items=[];foreach($values as $key=>$value) $items[]=['key'=>$key,'value'=>$value,'source'=>'message','evidence'=>$value,'confidence'=>1];
        return ['intake'=>['candidates'=>$items,'questions'=>[]],'show_card'=>$show,'execution'=>$execution];
    }
    public function testOrdinaryConversationAlwaysHasNoExecutableEffects(): void {
        foreach([false,true] as $active) {
            $parsed=Decision::parse(json_encode(self::reply()),$this->context($active));$legacy=json_decode($parsed['legacy'],true);
            self::assertSame('chat',$legacy['intent']);self::assertSame('conversation',$legacy['scope']);
            self::assertSame('我们可以先聊故事。',$legacy['reply_markdown']);
        }
    }
    public function testCardMustBeChosenAndAnchoredOnce(): void {
        $state=Plan::draft(self::state(),['candidates'=>['story_direction'=>['source'=>'message','value'=>'程序员大战孙悟空','evidence'=>'程序员大战孙悟空']],'questions'=>[]]);
        $settings=['workflow_state'=>$state];
        $settings=Decision::commit($settings,self::reply('update_requirements',self::intake()),10);
        self::assertFalse($settings['workflow_state']['intake_presentation']['visible']);
        $card=new \ReflectionMethod(Workflow::class,'card');$card->setAccessible(true);
        self::assertNull($card->invoke(null,$settings['workflow_state']));
        $settings=Decision::commit($settings,self::reply('update_requirements',self::intake([],true)),11);
        self::assertSame(11,$settings['workflow_state']['intake_anchor_run_id']);
        self::assertSame('intake_review',$card->invoke(null,$settings['workflow_state'])['type']);
        $before=$settings['workflow_state'];
        $settings=Decision::commit($settings,self::reply(),12);self::assertSame($before,$settings['workflow_state']);
        $settings=Decision::commit($settings,self::reply('update_requirements',self::intake([],false,'pause')),13);
        self::assertSame(11,$settings['workflow_state']['intake_anchor_run_id']);
        self::assertTrue($settings['workflow_state']['intake_presentation']['visible']);
        self::assertTrue($settings['workflow_state']['execution_paused']);
    }
    public function testIntentionAloneCannotShowSettingsEvenIfModelRequestsCard(): void {
        $state=Plan::draft(self::state(),['candidates'=>[],'questions'=>[]]);
        $settings=Decision::commit(['workflow_state'=>$state],self::reply('start_project',self::intake([],true)),10);
        self::assertFalse($settings['workflow_state']['intake_presentation']['visible']);
    }
    public function testForbiddenAndOutOfPhaseActionsCannotPass(): void {
        $bad=[self::reply('generate_video'),self::reply('update_requirements',self::intake()),self::reply('pause'),
            self::reply('respond',['nodes'=>[['type'=>'video']]])];
        foreach($bad as $value) {
            try {Decision::parse(json_encode($value),$this->context());self::fail('Unauthorized action accepted');}
            catch(\RuntimeException $e) {self::assertSame('INVALID_AGENT_INTENT',$e->getMessage());}
        }
    }
    public function testMemoryRequiresUserEvidenceAndLatestCorrectionWins(): void {
        $context=$this->context();$update=[['key'=>'hero','value'=>'林小凡','evidence'=>'主角叫林小凡']];
        self::assertSame($update,Memory::validate($update,$context));
        $memory=Memory::merge([], $update,11);
        $memory=Memory::merge($memory,[['key'=>'hero','value'=>'孙悟空','evidence'=>'改为孙悟空']],12);
        self::assertSame('孙悟空',$memory['hero']['value']);self::assertSame(12,$memory['hero']['run_id']);
        $this->expectExceptionMessage('INVALID_AGENT_INTENT');
        Memory::validate([['key'=>'ratio','value'=>'9:16','evidence'=>'助手建议竖屏']],$context);
    }
    public function testDiscussingStyleNeverBecomesAnAuthoritativeRegexConstraint(): void {
        $messages=[['role'=>'user','content'=>'你觉得风格是水墨好吗？比例是9:16会不会太窄？'],['role'=>'assistant','content'=>'可以比较一下'],['role'=>'user','content'=>'先聊聊']];
        self::assertSame($messages,TextContext::messages(['messages'=>$messages,'selected_nodes'=>[]]));
    }
    public function testMemoryIsBoundedAndDoesNotGrantAnAction(): void {
        $memory=[];for($i=0;$i<40;$i++) $memory=Memory::merge($memory,[['key'=>'fact_'.$i,'value'=>'value','evidence'=>'quote']],$i+1);
        self::assertCount(32,$memory);self::assertArrayNotHasKey('fact_0',$memory);
        self::assertStringContainsString('不赋予执行权限',Memory::instruction($memory));
    }
}
