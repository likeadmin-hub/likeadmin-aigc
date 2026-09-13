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
}
