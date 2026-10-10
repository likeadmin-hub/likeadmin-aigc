<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\canvas_agent\NeuronConversationProvider as Bridge;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;

/** Actual Neuron SDK with a deterministic metered adapter, never provider HTTP. */
class CanvasNeuronRuntimeTest extends TestCase
{
    private function transport(?array $result=null, ?\Throwable $error=null): ConversationProviderInterface
    {
        return new class($result,$error) implements ConversationProviderInterface {
            public int $calls=0;
            public int $preflights=0;
            public array $request=[];
            public function __construct(private ?array $result,private ?\Throwable $error) {}
            public function preflight(int $tenant,int $user,array $request):void {$this->preflights++;}
            public function generate(int $tenant,int $user,array $request):array {
                $this->calls++;$this->request=$request;
                if($this->error) throw $this->error;
                ($request['on_event'])('delta',['delta'=>'你好']);
                return $this->result ?? ['content'=>json_encode(CanvasNeuronRuntimeTest::reply()),'safety_checked'=>true,
                    'provider_request_id'=>'frozen-receipt','usage'=>['input_tokens'=>7,'output_tokens'=>13],'tool_calls'=>[]];
            }
        };
    }
    public static function reply():array { return ['reply_markdown'=>'我们可以先聊故事。','action'=>['name'=>'respond','arguments'=>[]],'remember'=>[]]; }
    private function request():array {
        return ['messages'=>[['role'=>'assistant','content'=>'先聊剧情'],['role'=>'user','content'=>'你好']],
            'system_prompt'=>'统一对话决策', 'context'=>['messages'=>[['role'=>'user','content'=>'你好']],
                'attachment_images'=>[['url'=>'https://example.test/owned.png']],
                'intent_routing'=>['version'=>3,'dialogue_engine'=>'neuron_v1','skill_candidates'=>[]]],
            'settings'=>['reasoning_model'=>['id'=>'authorized-model']], 'on_event'=>static function(){}];
    }
    private function bridge(ConversationProviderInterface $transport): Bridge {
        self::assertTrue(class_exists(Bridge::class),'Neuron PHP dialogue bridge must exist');
        return new Bridge($transport,['tenant'=>1,'user'=>2,'canvas'=>3,'thread'=>4]);
    }
    public function testRealSdkForwardsMultimodalFrozenRequestAndReceiptAndSseExactlyOnce():void {
        $transport=$this->transport();$bridge=$this->bridge($transport);$request=$this->request();$events=[];
        $request['on_event']=static function($kind,$data)use(&$events){$events[]=[$kind,$data];};
        $bridge->preflight(1,2,$request);self::assertSame(0,$transport->calls);
        $result=$bridge->generate(1,2,$request);
        self::assertSame(1,$transport->preflights);self::assertSame(1,$transport->calls);
        self::assertSame($request,$transport->request);self::assertSame('frozen-receipt',$result['provider_request_id']);
        self::assertSame(['input_tokens'=>7,'output_tokens'=>13],$result['usage']);
        self::assertTrue($result['safety_checked']);self::assertSame([], $result['tool_calls']);
        self::assertSame(self::reply(),json_decode($result['content'],true));
        self::assertSame([['delta',['delta'=>'你好']]],$events);
        try{$bridge->generate(1,2,$request);self::fail('Repeated metered request');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_ALREADY_CONSUMED',$error->getMessage());}
        self::assertSame(1,$transport->calls);
    }
    public function testChangedModelAfterPreflightCannotReachTransport():void {
        $transport=$this->transport();$bridge=$this->bridge($transport);$request=$this->request();
        $bridge->preflight(1,2,$request);$request['settings']['reasoning_model']['id']='different-model';
        try{$bridge->generate(1,2,$request);self::fail('Mutable accepted request');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_SNAPSHOT_MISMATCH',$error->getMessage());}
        self::assertSame(0,$transport->calls);
    }
    public function testCrossTenantRejectedBeforePreflightOrSubmission():void {
        $transport=$this->transport();$bridge=$this->bridge($transport);
        try{$bridge->preflight(99,2,$this->request());self::fail('Cross tenant');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_SCOPE_MISMATCH',$error->getMessage());}
        self::assertSame(0,$transport->preflights);self::assertSame(0,$transport->calls);
    }
    public function testUncertainTransportOutcomeIsNeverAutomaticallyRetried():void {
        $transport=$this->transport(null,new \RuntimeException('OUTCOME_UNKNOWN'));$bridge=$this->bridge($transport);$request=$this->request();
        $bridge->preflight(1,2,$request);
        try{$bridge->generate(1,2,$request);self::fail('Unknown outcome swallowed');}
        catch(\RuntimeException $error){self::assertSame('OUTCOME_UNKNOWN',$error->getMessage());}
        try{$bridge->generate(1,2,$request);self::fail('Unknown outcome retried');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_ALREADY_CONSUMED',$error->getMessage());}
        self::assertSame(1,$transport->calls);
    }
    public function testMalformedDecisionCannotBeRetriedOrTurnedIntoAReply():void {
        $transport=$this->transport(['content'=>'not json']);$bridge=$this->bridge($transport);$request=$this->request();
        $bridge->preflight(1,2,$request);
        try{$bridge->generate(1,2,$request);self::fail('Malformed decision accepted');}catch(\RuntimeException $error){}
        try{$bridge->generate(1,2,$request);self::fail('Malformed output retried');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_ALREADY_CONSUMED',$error->getMessage());}
        self::assertSame(1,$transport->calls);
    }
    public function testDispatchWithoutPreflightCannotReachTransport():void {
        $transport=$this->transport();$bridge=$this->bridge($transport);
        try{$bridge->generate(1,2,$this->request());self::fail('Missing preflight');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_UNAVAILABLE',$error->getMessage());}
        self::assertSame(0,$transport->calls);
    }
    public function testLegacyQueuedEngineUsesExactlyTheSameDecisionEnvelope():void {
        $transport=$this->transport();$bridge=$this->bridge($transport);$request=$this->request();
        $request['context']['intent_routing']['dialogue_engine']='deepagents_v1';
        $bridge->preflight(1,2,$request);$result=$bridge->generate(1,2,$request);
        self::assertSame(self::reply(),json_decode($result['content'],true));
        self::assertSame('deepagents_v1',$transport->request['context']['intent_routing']['dialogue_engine']);
        self::assertSame(1,$transport->calls);
    }
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMissingSdkFailsBeforeTheMeteredPreflight():void {
        $loader=static function(string $class):void {
            if($class==='NeuronAI\\Workflow\\Workflow') throw new \RuntimeException('SDK dependency missing');
        };
        spl_autoload_register($loader,true,true);
        $transport=$this->transport();$bridge=$this->bridge($transport);
        try {
            $bridge->preflight(1,2,$this->request());self::fail('Missing SDK accepted');
        } catch(\RuntimeException $error) {
            self::assertSame('AGENT_RUNTIME_UNAVAILABLE',$error->getMessage());
            self::assertSame(0,$transport->preflights);self::assertSame(0,$transport->calls);
        } finally {spl_autoload_unregister($loader);}
    }
    public function testRepeatedPreflightDoesNotResetTheSingleSubmissionBudget():void {
        $transport=$this->transport();$bridge=$this->bridge($transport);$request=$this->request();
        $bridge->preflight(1,2,$request);
        try{$bridge->preflight(1,2,$request);self::fail('Repeated preparation');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_ALREADY_PREPARED',$error->getMessage());}
        self::assertSame(1,$transport->preflights);self::assertSame(0,$transport->calls);
        $bridge->generate(1,2,$request);
        try{$bridge->preflight(1,2,$request);self::fail('Consumed graph reset');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_ALREADY_CONSUMED',$error->getMessage());}
        self::assertSame(1,$transport->calls);
    }
    public function testBadScopeIdsFailBeforeAnyMeteredOperation():void {
        $transport=$this->transport();$bridge=new Bridge($transport,['tenant'=>1,'user'=>2,'canvas'=>0,'thread'=>4]);
        try{$bridge->preflight(1,2,$this->request());self::fail('Invalid canvas');}
        catch(\RuntimeException $error){self::assertSame('AGENT_RUNTIME_SCOPE_MISMATCH',$error->getMessage());}
        self::assertSame(0,$transport->preflights);
    }
}
