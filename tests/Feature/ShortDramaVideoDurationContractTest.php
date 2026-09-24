<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaVideoDurationContractTest extends TestCase
{
    public function testDurationNormalizerIsAvailableToBothMarketVideoRuntimeFacades(): void
    {
        self::assertTrue(method_exists(MarketVideoRuntimeService::class, 'normalizeDurationSelection'));
        self::assertTrue(method_exists(MarketVideoAppRuntimeService::class, 'normalizeDurationSelection'));
        self::assertTrue(method_exists(MarketVideoModelRuntimeService::class, 'normalizeDurationSelection'));
    }

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

    public function testNarrativeBeatBelowProviderMinimumUsesTheMinimumRenderableDuration(): void
    {
        $metadata = [
            'params_schema' => [
                'properties' => [
                    'duration' => ['minimum' => 4, 'maximum' => 15],
                ],
            ],
        ];

        self::assertSame(4, $this->invokeConfigurableDuration($metadata, 2));
        self::assertSame(4, $this->invokeConfigurableDuration($metadata, 3));
        self::assertSame(15, $this->invokeConfigurableDuration($metadata, 16));
    }

    public function testUnknownDurationUsesTheFirstSupportedProviderDuration(): void
    {
        $duration = $this->invokeConfigurableDuration([
            'params_schema' => [
                'properties' => [
                    'duration' => ['minimum' => 4, 'maximum' => 15],
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
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/web/pc/components/short-drama/StoryboardCreationWorkbench.vue');

        self::assertStringContainsString('const generationDuration = isVideoMode ? closestVideoDurationForModel(', $source);
        self::assertStringContainsString('duration: generationDuration,', $source);
        self::assertStringContainsString('requested_duration: isVideoMode ? requestedVideoDuration : 0,', $source);
        self::assertStringContainsString('const shotDuration = closestVideoDurationForModel(', $source);
        self::assertStringContainsString('duration: shotDuration,', $source);
    }

    public function testMarketVideoTaskStoresTheSameNegotiatedDurationItSubmitsAndBills(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_video/AigcVideoService.php');
        self::assertStringContainsString('normalizeDurationSelection($tenantId, $selection, (int)($params[\'duration\'] ?? 0))', $source);
        self::assertStringContainsString("\$params['duration'] = (int)\$normalizedDuration['duration'];", $source);
        self::assertStringContainsString("'duration' => max(1, (int)(\$params['duration'] ?? 0))", $source);
    }

    private function invokeConfigurableDuration(array $metadata, int $requestedDuration): int
    {
        $method = new ReflectionMethod(MarketVideoRuntimeService::class, 'configurableDuration');
        $method->setAccessible(true);

        return $method->invoke(null, [], $metadata, $requestedDuration);
    }
}
