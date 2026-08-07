<?php

namespace Tests\Feature;

use app\common\service\app\aigc_model_wear\AigcModelWearService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ModelWearMarketContractTest extends TestCase
{
    public function testStaleDefaultsAreAlignedToMarketModel(): void
    {
        $aligned = $this->invoke('alignMarketConfig', [
            'channel' => 'legacy-image',
            'quality' => 'legacy-quality',
            'ratio' => '16:9',
        ], $this->options());

        self::assertSame('market_image_model:100', $aligned['channel']);
        self::assertSame('1k', $aligned['quality']);
        self::assertSame('1:1', $aligned['ratio']);
    }

    public function testMarketSpecKeepsExactSku(): void
    {
        $spec = $this->invoke('marketSpec', $this->options(), [
            'channel' => 'market_image_model:100',
            'quality' => '1k',
            'ratio' => '3:4',
        ]);

        self::assertSame('market_image_model:100', $spec['model_id']);
        self::assertSame(100, $spec['market_product_id']);
        self::assertSame(294, $spec['market_sku_id']);
        self::assertSame('1k', $spec['quality']);
        self::assertSame('3:4', $spec['ratio']);
    }

    public function testMarketModelIdentifierKeepsItsColon(): void
    {
        self::assertSame('market_image_model:100', $this->invoke('normalizeCode', 'market_image_model:100'));
    }

    public function testPricePackageKeepsApplicationUserPrice(): void
    {
        $estimate = $this->invoke('buildEstimate', [
            'unit_price' => 12.0,
            'width' => 0,
            'height' => 0,
            'size_key' => '3:4',
            'price_package' => ['code' => 'standard', 'name' => 'Standard'],
        ], [
            'settlement_mode' => 'reserved',
            'platform_unit_cost' => 30.0,
        ]);

        self::assertSame(30.0, $estimate['tenant_cost_points']);
        self::assertSame(12.0, $estimate['user_charge_points']);
        self::assertSame(12.0, $estimate['display_points']);
    }

    public function testActualUsageSkuIsRejectedBeforeSubmission(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('fixed-price market image SKUs');
        $this->invoke('buildEstimate', [
            'unit_price' => 12.0,
            'width' => 0,
            'height' => 0,
            'size_key' => '3:4',
            'price_package' => [],
        ], ['settlement_mode' => 'actual_usage']);
    }

    public function testModelWearNoLongerUsesLegacyImageProviderPath(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_model_wear/AigcModelWearService.php');
        self::assertStringNotContainsString('AigcImageChannelService', $source);
        self::assertStringNotContainsString('generateWithBillingOverride', $source);
        self::assertStringNotContainsString('IMAGE_APP_CODE', $source);
        self::assertStringContainsString('generateMarketModelWithBillingOverride', $source);
    }

    private function options(): array
    {
        return [
            'channels' => [[
                'code' => 'market_image_model:100',
                'qualities' => [[
                    'value' => '1k',
                    'ratios' => [
                        ['value' => '1:1', 'ratio' => '1:1', 'model_id' => 'market_image_model:100', 'market_product_id' => 100, 'market_sku_id' => 294, 'quality' => '1k'],
                        ['value' => '3:4', 'ratio' => '3:4', 'model_id' => 'market_image_model:100', 'market_product_id' => 100, 'market_sku_id' => 294, 'quality' => '1k'],
                    ],
                ]],
            ]],
        ];
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcModelWearService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
