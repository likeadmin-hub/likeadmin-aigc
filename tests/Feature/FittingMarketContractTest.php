<?php

namespace Tests\Feature;

use app\common\service\app\aigc_fitting\AigcFittingService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FittingMarketContractTest extends TestCase
{
    public function testStaleDefaultsAreAlignedToMarketModel(): void
    {
        $aligned = $this->invoke(AigcFittingService::class, 'alignMarketConfig', [
            'channel' => 'legacy-image',
            'quality' => 'legacy-quality',
            'ratio' => '16:9',
        ], $this->options());

        self::assertSame('market_image_model:100', $aligned['channel']);
        self::assertSame('1k', $aligned['quality']);
        self::assertSame('1:1', $aligned['ratio']);
    }

    public function testLegacyModelIdentifierKeepsTheSelectedMarketModel(): void
    {
        $aligned = $this->invoke(AigcFittingService::class, 'alignMarketConfig', [
            'channel' => 'market_image_model100',
            'quality' => '1k',
            'ratio' => '3:4',
        ], $this->options());

        self::assertSame('market_image_model:100', $aligned['channel']);
        self::assertSame('1k', $aligned['quality']);
        self::assertSame('3:4', $aligned['ratio']);
    }

    public function testFittingSpecKeepsExactMarketSku(): void
    {
        $spec = $this->invoke(AigcFittingService::class, 'marketSpec', $this->options(), [
            'channel' => 'market_image_model:100',
            'quality' => '1k',
            'ratio' => '3:4',
        ]);

        self::assertSame('market_image_model:100', $spec['model_id']);
        self::assertSame(100, $spec['market_product_id']);
        self::assertSame(294, $spec['market_sku_id']);
        self::assertSame('1k', $spec['quality']);
    }

    public function testSplitTasksKeepOneResultPerModelAndFullReferenceSet(): void
    {
        $tasks = $this->invoke(AigcFittingService::class, 'splitImagePayloads', [
            'garment_images' => ['garment-top', 'garment-bottom'],
            'model_images' => ['model-one', 'model-two'],
            'image_payload' => [
                'quantity' => 2,
                'model_id' => 'market_image_model:100',
                'market_product_id' => 100,
                'market_sku_id' => 294,
                'reference_images' => [],
            ],
        ]);

        self::assertCount(2, $tasks);
        self::assertSame(1, $tasks[0]['quantity']);
        self::assertSame(['garment-top', 'garment-bottom', 'model-one'], $tasks[0]['reference_images']);
        self::assertSame(['garment-top', 'garment-bottom', 'model-two'], $tasks[1]['reference_images']);
        self::assertSame(294, $tasks[1]['market_sku_id']);
    }

    public function testEstimateUsesMarketUnitCostAndApplicationModePrice(): void
    {
        $estimate = $this->invoke(AigcFittingService::class, 'buildFittingEstimate', [
            'mode' => 'custom',
            'unit_price' => 12.0,
            'image_payload' => ['quantity' => 3],
        ], [
            'settlement_mode' => 'reserved',
            'platform_unit_cost' => 30.0,
        ]);

        self::assertSame(90.0, $estimate['tenant_cost_points']);
        self::assertSame(36.0, $estimate['user_charge_points']);
        self::assertSame(36.0, $estimate['display_points']);
    }

    public function testActualUsageSkuIsRejectedBeforeSubmission(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('actual-usage image SKUs');
        $this->invoke(AigcFittingService::class, 'buildFittingEstimate', [
            'mode' => 'single',
            'unit_price' => 10.0,
            'image_payload' => ['quantity' => 1],
        ], ['settlement_mode' => 'actual_usage']);
    }

    public function testFittingNoLongerUsesLegacyImageProviderPath(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_fitting/AigcFittingService.php');
        self::assertStringNotContainsString('AigcImageChannelService', $source);
        self::assertStringNotContainsString('generateWithBillingOverride', $source);
        self::assertStringNotContainsString('IMAGE_APP_CODE', $source);
        self::assertStringContainsString('generateMarketModelWithBillingOverride', $source);
    }

    public function testFittingConfigRestoresModelImageUrlsAfterMarketAlignment(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_fitting/AigcFittingService.php');
        $alignment = '$data[\'config_json\'] = self::alignMarketConfig($data[\'config_json\'], $data[\'option_config\']);';
        $urlAppend = '$data[\'config_json\'][\'model_examples\'] = self::appendExampleImageUrls($data[\'config_json\'][\'model_examples\'] ?? []);';

        $alignmentPosition = strpos($source, $alignment);
        $urlAppendPosition = strpos($source, $urlAppend);

        self::assertNotFalse($alignmentPosition);
        self::assertNotFalse($urlAppendPosition);
        self::assertGreaterThan($alignmentPosition, $urlAppendPosition);
    }

    public function testFittingFrontendNormalizesModelImageUrls(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/_id_.57b448ac.js');

        self::assertStringContainsString('return t.map(j=>hv(String(j||"").trim())).filter', $source);
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

    private function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
