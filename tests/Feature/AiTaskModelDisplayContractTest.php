<?php

namespace Tests\Feature;

use app\common\service\ai\AiTaskRecordService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AiTaskModelDisplayContractTest extends TestCase
{
    public function testTaskSnapshotNameWinsOverInternalMarketSelector(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'displayModel');
        $method->setAccessible(true);

        self::assertSame('可灵 2.1', $method->invoke(null, [
            'model' => 'market_video_model:171',
            'model_json' => ['model_id' => 'market_video_model:171', 'model_name' => '可灵 2.1'],
        ], 'aigc_video'));
    }

    public function testRegularProviderModelRemainsVisible(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'displayModel');
        $method->setAccessible(true);

        self::assertSame('Nano Banana', $method->invoke(null, [
            'model' => 'nano-banana',
        ], 'aigc_image'));
    }

    public function testReferenceAssetNameIsNeverUsedAsTheModelName(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'displayModel');
        $method->setAccessible(true);

        self::assertSame('AIGC视频', $method->invoke(null, [
            'model' => 'market_video_model:2147483647',
            'model_json' => [
                'model_id' => 'market_video_model:2147483647',
                'reference_assets' => [['name' => '商品主图.jpg']],
            ],
        ], 'aigc_video'));
    }

    public function testTaskQueryIncludesMarketProductForHistoricalNameLookup(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/ai/AiTaskRecordService.php');

        self::assertStringContainsString("'model_json', 'market_product_id', 'provider_request_id'", $source);
        self::assertStringContainsString('TenantPowerMarketService::applyProductDisplays($tenantId, $data);', $source);
        self::assertStringContainsString("preg_match('/^market_[a-z0-9_]+:/i", $source);
    }
}
