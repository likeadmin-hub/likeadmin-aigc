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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testModelNotFoundFallsThroughToAnotherTenantEnabledTextModel(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_model_fallback.php';
        $this->silenceLog();
        $snapshot = ShortDramaPromptWorkspace::resolve(701, ['mode' => 'workspace'], []);

        try {
            $method = new ReflectionMethod(AigcShortDramaService::class, 'generateScriptPlanResult');
            $method->setAccessible(true);
            $method->invoke(null, 701, 23, '生成一个短剧计划', [
                'target_duration_seconds' => 60,
                '_prompt_snapshot' => $snapshot,
            ], '测试', [
                'id' => 'retired-model',
                'market_product_id' => 901,
                'model_code' => 'retired-model',
            ]);
            self::fail('The fake provider should capture the fallback request');
        } catch (\ShortDramaFallbackCapturedRequest $captured) {
            self::assertSame('fallback-model', $captured->params['model_selection']['id']);
            self::assertSame('qwen3.6-plus', $captured->params['model_selection']['model_code']);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testOnlyAfterEveryEnabledCandidateIsUnavailableDoesTheTaskFail(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_model_fallback.php';
        $this->silenceLog();
        \app\common\service\power\MarketTextModelRuntimeService::$allUnavailable = true;
        $snapshot = ShortDramaPromptWorkspace::resolve(701, ['mode' => 'workspace'], []);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('当前租户已开启的剧本策划模型均不可用');
        $method = new ReflectionMethod(AigcShortDramaService::class, 'generateScriptPlanResult');
        $method->setAccessible(true);
        $method->invoke(null, 701, 23, '生成一个短剧计划', [
            'target_duration_seconds' => 60,
            '_prompt_snapshot' => $snapshot,
        ], '测试', [
            'id' => 'retired-model',
            'market_product_id' => 901,
            'model_code' => 'retired-model',
        ]);
    }

    private function invokeCurrentFallback(array $params = [], ?callable $events = null): array
    {
        $method = new ReflectionMethod(AigcShortDramaService::class, 'generateScriptPlanLlmWithFallback');
        $method->setAccessible(true);
        return $method->invoke(null, 701, 23, $params + ['content' => '上传剧本原文', 'system_prompt' => '按原文解析'],
            ['id' => 'selected', 'model_code' => 'selected'],
            ['_input_contract_version' => 8, '_generation_version' => 3], 'story', $events);
    }

    private function prepareCandidates(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_model_fallback.php';
        $this->silenceLog();
        \app\common\service\power\MarketTextModelRuntimeService::$returnSuccess = true;
        \app\common\service\power\MarketTextModelRuntimeService::$options = array_map(
            static fn($id) => ['id' => $id, 'model_code' => $id], ['selected', 'second', 'third', 'fourth', 'fifth']);
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCurrentUploadedScriptTriesAllModelsWithoutDuplicatesAndReturnsActualModel(): void
    {
        $this->prepareCandidates();
        \app\common\service\power\MarketTextModelRuntimeService::$errors = [
            'selected' => 'model_not_found', 'second' => 'unsupported_model',
            'third' => '所选文本模型未上架或已不可用', 'fourth' => '当前选择的模型暂不可用，请切换模型后重新提交'];
        $result = $this->invokeCurrentFallback();
        self::assertSame('fifth', $result['model']['id']);
        self::assertSame('fifth', $result['result']['model_code']);
        $calls = \app\common\service\power\MarketTextModelRuntimeService::$calls;
        self::assertSame(['selected', 'second', 'third', 'fourth', 'fifth'], array_column(array_column(array_column($calls, 'params'), 'model_selection'), 'id'));
        foreach ($calls as $call) {
            self::assertSame(701, $call['tenant_id']);
            self::assertSame(23, $call['user_id']);
            self::assertSame('上传剧本原文', $call['params']['content']);
            self::assertTrue($call['params']['_disable_model_fallback']);
            self::assertTrue($call['params']['_require_final_content']);
        }
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCurrentContractReportsFailureOnlyAfterAllCandidatesReject(): void
    {
        $this->prepareCandidates();
        \app\common\service\power\MarketTextModelRuntimeService::$allUnavailable = true;
        try {
            $this->invokeCurrentFallback();
            self::fail('Expected all models unavailable');
        } catch (\Exception $error) {
            self::assertStringContainsString('均不可用', $error->getMessage());
            self::assertCount(5, \app\common\service\power\MarketTextModelRuntimeService::$calls);
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
    public function testCancellationBetweenModelsStopsFallback(): void
    {
        $this->prepareCandidates();
        \app\common\service\power\MarketTextModelRuntimeService::$errors = ['selected' => 'model_not_found'];
        $heartbeats = 0;
        try {
            $this->invokeCurrentFallback([], static function ($event) use (&$heartbeats): void {
                if ($event === 'heartbeat' && ++$heartbeats === 2) throw new \Exception('任务已取消');
            });
            self::fail('Expected cancellation');
        } catch (\Exception $error) {
            self::assertSame('任务已取消', $error->getMessage());
            self::assertCount(1, \app\common\service\power\MarketTextModelRuntimeService::$calls);
        }
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnavailablePreferredModelDoesNotPreventSkippingAnUndersizedCandidate(): void
    {
        $this->prepareCandidates();
        \app\common\service\power\MarketTextModelRuntimeService::$errors = ['selected' => 'model_not_found'];
        \app\common\service\power\MarketTextModelRuntimeService::$options[1]['max_output_tokens'] = 100;
        $result = $this->invokeCurrentFallback(['_planning_count' => 1, '_planning_public' => true, '_planning_stage' => 'story']);
        self::assertSame('third', $result['model']['id']);
        self::assertCount(2, \app\common\service\power\MarketTextModelRuntimeService::$calls);
    }

}
