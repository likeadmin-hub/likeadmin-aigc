<?php

namespace Tests\Feature;

use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CanvasModelDescriptionContractTest extends TestCase
{
    public function testVideoDescriptionUsesUserFacingMetadataInsteadOfTechnicalIdentifier(): void
    {
        $method = new ReflectionMethod(MarketVideoRuntimeService::class, 'productDescription');
        $method->setAccessible(true);

        self::assertSame('效果好，更真实', $method->invoke(null, [
            'description' => 'market_video_model:171',
        ], [
            'model_description' => '效果好，更真实',
        ]));
        self::assertSame('适合快速出片，画面稳定', $method->invoke(null, [
            'description' => '适合快速出片，画面稳定',
        ], [
            'model_description' => '应被忽略',
        ]));
    }

    public function testImageDescriptionUsesUserFacingMetadataInsteadOfTechnicalIdentifier(): void
    {
        $method = new ReflectionMethod(MarketImageModelRuntimeService::class, 'productDescription');
        $method->setAccessible(true);

        self::assertSame('细节清晰，适合商品海报', $method->invoke(null, [
            'description' => 'market_image_model:205',
        ], [
            'model_description' => '细节清晰，适合商品海报',
        ]));
    }

    public function testCanvasPickerRendersDescriptionsAndNeverUsesMarketIdAsSubtitle(): void
    {
        $bundle = file_get_contents(__DIR__ . '/../../public/_nuxt/_id_.2fb60286.js');

        self::assertIsString($bundle);
        self::assertStringContainsString('a.description,a.model_description,a.app_description,a.summary', $bundle);
        self::assertStringContainsString('!/^market_(?:video|image|audio|text|app)(?:_|:)/i.test(b)', $bundle);
        self::assertStringNotContainsString('a.model_intro,a.description,a.introduction,a.capability_label,a.capability,a.default_quality,a.resolution_label,a.resolution', $bundle);
    }
}
