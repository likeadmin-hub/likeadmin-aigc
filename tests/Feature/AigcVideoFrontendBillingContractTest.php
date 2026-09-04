<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AigcVideoFrontendBillingContractTest extends TestCase
{
    public function testFrontendEstimateUsesSelectedSkuSecondBillingContract(): void
    {
        $source = $this->sourceFile('/pc/pages/app/aigc_video.vue');

        self::assertStringContainsString('const declaredSpecUnit', $source);
        self::assertStringContainsString('? [declaredSpecUnit]', $source);
        self::assertStringContainsString('usage_unit_size', $source);
        self::assertStringContainsString('Math.max(1, durationValue(form.duration)) / unitSize', $source);
    }

    public function testBackendQuoteUsesRequestedDurationForEverySecondBillingUnit(): void
    {
        $quantity = new ReflectionMethod(MarketVideoRuntimeService::class, 'quantity');
        $quantity->setAccessible(true);
        foreach (['input_second', 'output_second'] as $usageUnit) {
            $market = [
                'product' => [],
                'sku' => [
                    'usage_unit' => $usageUnit,
                    'locked_params' => ['resolution' => '2K'],
                ],
            ];
            self::assertSame(6.0, $quantity->invoke(null, $market, ['duration' => 6]));
            self::assertSame(30.0, $quantity->invoke(null, $market, ['seconds' => 30]));
            self::assertSame(10.0, $quantity->invoke(null, $market, ['video_duration' => 10]));
        }

        $price = new ReflectionMethod(MarketVideoRuntimeService::class, 'priceForQuantity');
        $price->setAccessible(true);
        self::assertSame(480.0, $price->invoke(null, 80.0, 6.0, ['usage_unit_size' => 1]));
        self::assertSame(480.0, $price->invoke(null, 80.0, 30.0, ['usage_unit_size' => 5]));
    }

    public function testUniappEstimateUsesSelectedSkuSecondBillingContract(): void
    {
        $source = $this->sourceFile('/uniapp/src/apps/aigc_video/pages/index/index.vue');

        self::assertStringContainsString('const declaredSpecUnit', $source);
        self::assertStringContainsString('? [declaredSpecUnit]', $source);
        self::assertStringContainsString('usage_unit_size', $source);
        self::assertStringContainsString('Math.max(1, Number(form.duration || 0)) / unitSize', $source);
    }

    private function sourceFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . $path);
        self::assertNotFalse($source, "Unable to read source file: {$path}");
        return (string)$source;
    }
}
