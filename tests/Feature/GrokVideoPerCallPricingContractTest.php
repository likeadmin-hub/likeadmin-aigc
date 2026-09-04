<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use app\common\service\power\PowerMarketService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class GrokVideoPerCallPricingContractTest extends TestCase
{
    public function testRuntimeNormalizesGrokVideoToOneCall(): void
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
        self::assertSame('per_call', $billingUnit->invoke(null, $product, $sku));

        $billingUnitSize = new ReflectionMethod(MarketVideoRuntimeService::class, 'billingUnitSize');
        $billingUnitSize->setAccessible(true);
        self::assertSame(1.0, $billingUnitSize->invoke(null, $product, $sku));

        $quantity = new ReflectionMethod(MarketVideoRuntimeService::class, 'quantity');
        $quantity->setAccessible(true);
        self::assertSame(1.0, $quantity->invoke(null, [
            'product' => $product,
            'sku' => $sku,
        ], ['duration' => 30]));
    }

    public function testMarketSyncCannotRestoreGrokSecondBilling(): void
    {
        $normalize = new ReflectionMethod(PowerMarketService::class, 'normalizedUsageUnit');
        $normalize->setAccessible(true);
        $sku = [
            'usage_unit' => 'output_second',
            'price' => ['unit' => 'output_second'],
        ];

        self::assertSame('per_call', $normalize->invoke(null, $sku, [
            'type' => PowerMarketService::TYPE_APP_API,
            'resource' => ['app_code' => 'grok_video'],
        ]));
        self::assertSame('output_second', $normalize->invoke(null, $sku, [
            'type' => PowerMarketService::TYPE_APP_API,
            'resource' => ['app_code' => 'wan'],
        ]));
    }

    public function testUpgradeRepairsExistingGrokSkus(): void
    {
        $root = dirname(__DIR__, 2);
        $upgrade = (string)file_get_contents($root . '/upgrade/20260820_grok_video_per_call_pricing.sql');
        $publicUpgrade = (string)file_get_contents($root . '/public/upgrade/20260820_grok_video_per_call_pricing.sql');

        self::assertSame($upgrade, $publicUpgrade);
        self::assertStringContainsString("product.`upstream_app_code` = 'grok_video'", $upgrade);
        self::assertStringContainsString("sku.`usage_unit` = 'per_call'", $upgrade);
        self::assertStringContainsString('sku.`usage_unit_size` = 1', $upgrade);
    }
}
