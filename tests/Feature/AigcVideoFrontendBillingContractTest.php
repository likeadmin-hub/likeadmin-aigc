<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AigcVideoFrontendBillingContractTest extends TestCase
{
    public function testFrontendEstimateUsesSelectedSkuSecondBillingContract(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/aigc_video.93b37873.js');
        $start = strpos($source, 'et=p(');
        $end = strpos($source, '),tt=p(', $start === false ? 0 : $start);

        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $estimate = substr($source, $start, $end - $start);

        self::assertStringContainsString('usage_unit', $estimate);
        self::assertStringContainsString('usage_unit_size', $estimate);
        self::assertStringContainsString('includes("second")', $estimate);
        self::assertStringContainsString('Q(r.duration)', $estimate);
    }

    public function testBackendQuoteQuantityUsesDurationForOutputSecondSku(): void
    {
        $quantity = new ReflectionMethod(MarketVideoRuntimeService::class, 'quantity');
        $quantity->setAccessible(true);
        $market = [
            'sku' => [
                'usage_unit' => 'output_second',
                'locked_params' => ['resolution' => '2K'],
            ],
        ];

        self::assertSame(10.0, $quantity->invoke(null, $market, ['duration' => 10]));

        $price = new ReflectionMethod(MarketVideoRuntimeService::class, 'priceForQuantity');
        $price->setAccessible(true);
        self::assertSame(800.0, $price->invoke(null, 80.0, 10.0, ['usage_unit_size' => 1]));
    }

    public function testUnifiedCreatePageUsesSelectedSkuSecondBillingContract(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/create.a5c396bf.js');
        $start = strpos($source, 'Kt=f(');
        $end = strpos($source, '),Ke=f(', $start === false ? 0 : $start);

        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $estimate = substr($source, $start, $end - $start);

        self::assertStringContainsString('usage_unit', $estimate);
        self::assertStringContainsString('usage_unit_size', $estimate);
        self::assertStringContainsString('includes("second")', $estimate);
        self::assertStringContainsString('Pe(s.value.duration)', $estimate);
    }
}
