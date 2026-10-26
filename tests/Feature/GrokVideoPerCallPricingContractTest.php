<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use app\common\service\power\PowerMarketService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class GrokVideoPerCallPricingContractTest extends TestCase
{
    public function testRuntimePreservesEachGrokSkuMeter(): void
    {
        $product = [
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => 'grok_video',
        ];
        $sku = [
            'usage_unit' => 'output_second',
            'usage_unit_size' => 5,
            'locked_params' => ['model' => 'grok-imagine-video-1.5-fast'],
        ];

        $billingUnit = new ReflectionMethod(MarketVideoRuntimeService::class, 'billingUnit');
        $billingUnit->setAccessible(true);
        self::assertSame('output_second', $billingUnit->invoke(null, $product, $sku));

        $billingUnitSize = new ReflectionMethod(MarketVideoRuntimeService::class, 'billingUnitSize');
        $billingUnitSize->setAccessible(true);
        self::assertSame(5.0, $billingUnitSize->invoke(null, $product, $sku));


    }

    public function testMarketSyncPreservesSupplierUnit(): void
    {
        $normalize = new ReflectionMethod(PowerMarketService::class, 'normalizedUsageUnit');
        $normalize->setAccessible(true);
        $sku = [
            'usage_unit' => 'output_second',
            'price' => ['unit' => 'output_second'],
        ];

        self::assertSame('output_second', $normalize->invoke(null, $sku, [
            'type' => PowerMarketService::TYPE_APP_API,
            'resource' => ['app_code' => 'grok_video'],
        ]));
        self::assertSame('output_second', $normalize->invoke(null, $sku, [
            'type' => PowerMarketService::TYPE_APP_API,
            'resource' => ['app_code' => 'wan'],
        ]));
    }

    public function testRepairUsesSynchronizedMetadataWithoutChangingPrices(): void
    {
        $root=dirname(__DIR__,2);
        $sql=file_get_contents($root.'/upgrade/20261026_grok_supplier_meter.sql');
        self::assertSame($sql,file_get_contents($root.'/public/upgrade/20261026_grok_supplier_meter.sql'));
        self::assertStringContainsString('JSON_EXTRACT(sku.`source_payload`', $sql);
        self::assertStringNotContainsString('sale_points', $sql);
        self::assertStringNotContainsString('ai_consumption_log', $sql);
    }
}
