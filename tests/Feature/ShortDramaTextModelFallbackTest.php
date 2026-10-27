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
            ['id' => 'selected', 'model_code' => 'selected'],
            ['_input_contract_version' => 8, '_generation_version' => 3], 'story', $events);
    }

    private function prepareCandidates(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_model_fallback.php';
        $this->silenceLog();
        Container::getInstance()->instance('config', new \think\Config());
        \app\common\service\power\MarketTextModelRuntimeService::$returnSuccess = true;
        \app\common\service\power\MarketTextModelRuntimeService::$options = array_map(
            static fn($id) => ['id' => $id, 'model_code' => $id], ['selected', 'second', 'third', 'fourth', 'fifth']);
    }



    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnavailableConfiguredModelNeverSwitchesToAnotherModel(): void
    {
        $this->prepareCandidates();
        \app\common\service\power\MarketTextModelRuntimeService::$allUnavailable = true;
        try {
            $this->invokeCurrentFallback();
            self::fail('Expected all models unavailable');
        } catch (\Exception $error) {
            self::assertStringContainsString('短剧文本模型不可用', $error->getMessage());
            self::assertCount(1, \app\common\service\power\MarketTextModelRuntimeService::$calls);
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

}
