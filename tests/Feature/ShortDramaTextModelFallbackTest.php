<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\Container;

class ShortDramaTextModelFallbackTest extends TestCase
{
    private function silenceLog(): void
    {
        Container::getInstance()->instance('log', new class {
            public function write(string $message, string $type = 'log'): void
            {
            }
        });
    }





    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSuccessfulTaskUsesTheFrozenModelAndDisablesRuntimeFallback(): void
    {
        $this->prepareCandidates();
        $result = $this->invokeCurrentFallback();
        self::assertSame('selected', $result['model']['id']);
        $calls = \app\common\service\power\MarketTextModelRuntimeService::$calls;
        self::assertCount(1, $calls);
        self::assertSame(701, $calls[0]['tenant_id']);
        self::assertTrue($calls[0]['params']['_disable_model_fallback']);
    }

    private function invokeCurrentFallback(array $params = [], ?callable $events = null): array
    {
        $method = new ReflectionMethod(AigcShortDramaService::class, 'generateScriptPlanLlmWithFallback');
        $method->setAccessible(true);
        return $method->invoke(null, 701, 23, $params + ['content' => '上传剧本原文', 'system_prompt' => '按原文解析'],
            \app\common\service\power\MarketTextModelRuntimeService::$options[0],
            ['_input_contract_version' => 8, '_generation_version' => 3], 'story', $events);
    }

    private function prepareCandidates(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_model_fallback.php';
        $this->silenceLog();
        Container::getInstance()->instance('config', new \think\Config());
        \app\common\service\power\MarketTextModelRuntimeService::$returnSuccess = true;
        \app\common\service\power\MarketTextModelRuntimeService::$options = array_map(
            static fn($id) => self::model($id), ['selected', 'second', 'third', 'fourth', 'fifth']);
    }



    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnavailableModelsTryAtMostTwoCompatibleAlternatives(): void
    {
        $this->prepareCandidates();
        \app\common\service\power\MarketTextModelRuntimeService::$allUnavailable = true;
        try {
            $this->invokeCurrentFallback();
            self::fail('Expected all models unavailable');
        } catch (\Exception $error) {
            self::assertStringContainsString('短剧文本模型不可用', $error->getMessage());
            self::assertCount(3, \app\common\service\power\MarketTextModelRuntimeService::$calls);
        }
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testOtherFailuresDoNotStartAnotherPaidRequest(): void
    {
        $this->prepareCandidates();
        foreach (['provider timeout', '余额不足', '内容审核拒绝', '任务已取消或由新尝试接管', 'invalid parameter'] as $reason) {
            \app\common\service\power\MarketTextModelRuntimeService::$calls = [];
            \app\common\service\power\MarketTextModelRuntimeService::$errors = ['selected' => $reason];
            try {
                $this->invokeCurrentFallback();
                self::fail('Expected original error');
            } catch (\Exception $error) {
                self::assertSame($reason, $error->getMessage());
                self::assertCount(1, \app\common\service\power\MarketTextModelRuntimeService::$calls);
            }
        }
    }







    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProviderMetadataStopsFallbackEvenWithModelNotFoundText(): void
    {
        $this->prepareCandidates();
        \app\common\service\power\MarketTextModelRuntimeService::$errors = ['selected' =>
            new \app\common\service\power\MarketTextModelProviderException('model_not_found', false)];
        try { $this->invokeCurrentFallback(); self::fail('Expected unsafe retry to stop'); }
        catch (\app\common\service\power\MarketTextModelProviderException $e) {
            self::assertFalse($e->allowsModelFallback);
            self::assertCount(1, \app\common\service\power\MarketTextModelRuntimeService::$calls);
        }
    }

    private static function model(string $id): array
    {
        return ['id'=>$id,'model_code'=>$id,'enabled'=>true,'model_type'=>'text','protocol'=>'openai_chat',
            'context_window'=>65536,'max_output_tokens'=>8192,
            'input'=>['usage_unit_size'=>1000,'tenant_price'=>1,'platform_price'=>1],
            'output'=>['usage_unit_size'=>1000,'tenant_price'=>1,'platform_price'=>1]];
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCertifiedConnectionFailureSwitchesWithoutChangingContent(): void
    {
        $this->prepareCandidates();
        $e=new \app\common\service\power\MarketTextModelProviderException('connect timeout',false,null,
            ['errno'=>28,'request_size'=>0,'size_upload'=>0,'size_download'=>0,'pretransfer_time'=>0,'http_code'=>0,'header_size'=>0]);
        \app\common\service\power\MarketTextModelRuntimeService::$errors=['selected'=>$e];
        $r=$this->invokeCurrentFallback();
        self::assertSame('second',$r['model']['id']);
        self::assertSame('second',$r['result']['model_code']);
        self::assertSame('selected',$r['result']['model_fallback']['requested_model_code']);
        $calls=\app\common\service\power\MarketTextModelRuntimeService::$calls;
        self::assertCount(2,$calls);
        self::assertSame($calls[0]['params']['content'],$calls[1]['params']['content']);
        self::assertSame($calls[0]['params']['system_prompt'],$calls[1]['params']['system_prompt']);
        self::assertTrue($calls[1]['params']['_disable_model_fallback']);
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPartialResponseAndPendingSettlementNeverSwitchModels(): void
    {
        $this->prepareCandidates();
        foreach ([new \app\common\service\power\MarketTextModelProviderException('model_not_found',true,['content'=>'received']),
            new \app\common\service\power\TextSettlementPending(3,['content'=>'received'],[],new \RuntimeException('billing'),'model_not_found')] as $error) {
            \app\common\service\power\MarketTextModelRuntimeService::$calls=[];
            \app\common\service\power\MarketTextModelRuntimeService::$errors=['selected'=>$error];
            try {$this->invokeCurrentFallback();self::fail('Paid request replayed');}
            catch(\Exception $e){self::assertSame($error,$e);self::assertCount(1,\app\common\service\power\MarketTextModelRuntimeService::$calls);}
        }
    }

    public function testFallbackCandidatesRespectActualTokenRatesAndCapacity(): void
    {
        $policy=\app\common\service\app\aigc_short_drama\ShortDramaTextFallbackPolicy::class;
        $original=self::model('selected');$good=self::model('safe');$params=['content'=>'原文','max_tokens'=>4096];
        self::assertSame(['selected','safe'],array_column($policy::candidates($original,[$good],$params),'id'));
        foreach ([['enabled'=>false],['protocol'=>'anthropic_messages'],['model_type'=>'image'],['context_window'=>0],['max_output_tokens'=>1024],
            ['context_window'=>2048],['input'=>['usage_unit_size'=>1000,'tenant_price'=>2,'platform_price'=>1]],
            ['output'=>['usage_unit_size'=>1,'tenant_price'=>1,'platform_price'=>1]],['input'=>[]]] as $override) {
            self::assertCount(1,$policy::candidates($original,[array_replace($good,$override)],$params));
        }
        self::assertCount(1,$policy::candidates($original,[$good],$params+['requires_vision'=>true]));
        self::assertCount(1,$policy::candidates($original,[$good],$params+['_agent_tool_history'=>true]));
        $sameRate=$good;foreach(['input','output'] as $side)$sameRate[$side]=['usage_unit_size'=>1000000,'tenant_price'=>1000,'platform_price'=>1000];
        self::assertCount(2,$policy::candidates($original,[$sameRate],$params));
    }

}
