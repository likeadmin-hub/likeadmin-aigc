<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\canvas_agent\DeepAgentConversationProvider as Bridge;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use think\facade\Config;

/** Real Python SDK, fake metered transport. Never makes a provider HTTP call. */
class CanvasDeepAgentRuntimeTest extends TestCase
{
    protected function setUp(): void {
        if(getenv('SHORT_DRAMA_AGENT_TESTS')!=='1') $this->markTestSkipped('Installed Deep Agents runtime required');
        (new \think\App())->initialize();
    }
    private function transport(): ConversationProviderInterface {
        return new class implements ConversationProviderInterface {
            public int $calls=0;
            public array $request=[];
            public function preflight(int $tenant,int $user,array $request): void {}
            public function generate(int $tenant,int $user,array $request): array {
                $this->calls++;$this->request=$request;
                ($request['on_event'])('delta',['delta'=>'你好']);
                return ['content'=>json_encode(CanvasAgentDecisionTest::reply()),'safety_checked'=>true,'provider_request_id'=>'fake-receipt'];
            }
        };
    }
    private function request(): array {
        return ['messages'=>[['role'=>'user','content'=>'你好']], 'system_prompt'=>'统一对话决策',
            'context'=>['messages'=>[['role'=>'user','content'=>'你好']], 'attachment_images'=>[['url'=>'https://example.test/owned.png']],
                'intent_routing'=>['version'=>3,'dialogue_engine'=>Bridge::ENGINE,'skill_candidates'=>[]]],
            'settings'=>['reasoning_model'=>['id'=>'authorized-model']], 'on_event'=>static function(){}];
    }
    public function testRealSdkForwardsFrozenMultimodalRequestAndSseExactlyOnce(): void {
        $transport=$this->transport();$bridge=new Bridge($transport,['tenant'=>1,'user'=>2,'canvas'=>3,'thread'=>4]);
        $request=$this->request();$events=[];
        $request['on_event']=static function($kind,$data)use(&$events){$events[]=[$kind,$data];};
        $bridge->preflight(1,2,$request);
        self::assertSame(0,$transport->calls);
        $result=$bridge->generate(1,2,$request);
        self::assertSame(1,$transport->calls);self::assertSame($request,$transport->request);
        self::assertSame('fake-receipt',$result['provider_request_id']);
        self::assertSame(CanvasAgentDecisionTest::reply(),json_decode($result['content'],true));
        self::assertSame([['delta',['delta'=>'你好']]],$events);
        try {$bridge->generate(1,2,$request);self::fail('repeated paid call');}
        catch(\RuntimeException $e) {self::assertSame(1,$transport->calls);}
    }
    public function testUnavailableRuntimeFailsBeforeMeteredSubmission(): void {
        $original=Config::get('short_drama_agent');Config::set(['python'=>'/nonexistent/python'],'short_drama_agent');
        $transport=$this->transport();
        try {
            $bridge=new Bridge($transport,['tenant'=>1,'user'=>2,'canvas'=>3,'thread'=>4]);
            try {$bridge->preflight(1,2,$this->request());self::fail('unavailable runtime');}
            catch(\RuntimeException $e) {self::assertSame('AGENT_RUNTIME_UNAVAILABLE',$e->getMessage());}
            self::assertSame(0,$transport->calls);
        } finally {Config::set($original,'short_drama_agent');}
    }
    public function testScopeMismatchCannotReachProvider(): void {
        $transport=$this->transport();$bridge=new Bridge($transport,['tenant'=>1,'user'=>2,'canvas'=>3,'thread'=>4]);
        try {$bridge->preflight(99,2,$this->request());self::fail('cross tenant');}
        catch(\RuntimeException $e) {self::assertSame('AGENT_RUNTIME_SCOPE_MISMATCH',$e->getMessage());self::assertSame(0,$transport->calls);}
    }
}
