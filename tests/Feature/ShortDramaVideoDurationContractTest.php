<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaVideoDurationContractTest extends TestCase
{
    public function testRequestedDurationOverridesConfigurableModelDefault(): void
    {
        $duration = $this->invokeConfigurableDuration([
            'params_schema' => [
                'properties' => [
                    'duration' => ['default' => 4],
                ],
            ],
        ], 9);

        self::assertSame(9, $duration);
    }

    public function testModelDefaultIsOnlyUsedWhenRequestHasNoDuration(): void
    {
        $duration = $this->invokeConfigurableDuration([
            'params_schema' => [
                'properties' => [
                    'duration' => ['default' => 4],
                ],
            ],
        ], 0);

        self::assertSame(4, $duration);
    }

    public function testLockedSkuDurationStillOverridesRequestedDuration(): void
    {
        $method = new ReflectionMethod(MarketVideoRuntimeService::class, 'effectiveDurationFromMarket');
        $method->setAccessible(true);
        $duration = $method->invoke(null, [
            'product' => [],
            'sku' => ['locked_params' => ['duration' => 4]],
        ], 9);

        self::assertSame(4, $duration);
    }

    public function testStoryboardSubmissionsPreferSelectedDurationOverScriptDuration(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/storyboard.f2381e09.js');

        self::assertStringContainsString('wn=Number(F.value||((a==null?void 0:a.duration)||0))', $source);
        self::assertStringContainsString('g=Ya(K.value,l,Number(d||f.duration||0),ne.value)', $source);
        self::assertStringNotContainsString('wn=Number((a==null?void 0:a.duration)||F.value||0)', $source);
        self::assertStringNotContainsString('g=Ya(K.value,l,Number(f.duration||d||0),ne.value)', $source);
    }

    private function invokeConfigurableDuration(array $metadata, int $requestedDuration): int
    {
        $method = new ReflectionMethod(MarketVideoRuntimeService::class, 'configurableDuration');
        $method->setAccessible(true);

        return $method->invoke(null, [], $metadata, $requestedDuration);
    }
}
