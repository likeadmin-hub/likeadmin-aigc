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
        $source = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/native/CanvasNode.vue');
        self::assertStringContainsString('value.description', $source);
        self::assertStringContainsString('value.model_description', $source);
        self::assertStringContainsString('value.app_description', $source);
        self::assertStringContainsString('value.summary', $source);
        self::assertStringContainsString('market_(?:video|image|audio|text|app)', $source);
        self::assertStringContainsString('model_description', \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]'));
    }

    public function testCanvasAgentModelRowsShowDescriptionAndHideTechnicalValue(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/canvas-model-label-fix.css');
        self::assertIsString($stylesheet);
        self::assertStringContainsString('.agent-model-row > span:nth-child(2) > em', $stylesheet);
        self::assertStringContainsString('display: none !important', $stylesheet);
        self::assertStringContainsString('em.canvas-model-description', $stylesheet);

        $script = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/canvas-model-label-fix.js');
        self::assertIsString($script);
        self::assertStringContainsString('/api/app.aigc_canvas.config/detail', $script);
        self::assertStringContainsString('option.description', $script);
        self::assertStringContainsString('var technicalIdentifier = /^market_/i', $script);
        self::assertStringContainsString('!technicalIdentifier.test(value)', $script);
        self::assertStringContainsString("clean(valueNode.textContent) !== description", $script);

        foreach ([
            __DIR__ . '/../../public/pc/index.html',
            __DIR__ . '/../../public/pc/app/aigc_canvas/index.html',
            __DIR__ . '/../../public/pc/app/aigc_canvas/replay/index.html',
        ] as $entryPoint) {
            $html = file_get_contents($entryPoint);
            self::assertIsString($html);
            self::assertStringContainsString('/_nuxt/canvas-model-label-fix.css?v=20260824-model-label-v2', $html);
            self::assertStringContainsString('/_nuxt/canvas-model-label-fix.js?v=20260824-model-label-v2', $html);
            self::assertLessThan(
                strpos($html, '</head>'),
                strpos($html, '/_nuxt/canvas-model-label-fix.css?v=20260824-model-label-v2')
            );
            self::assertLessThan(
                strpos($html, '</body>'),
                strpos($html, '/_nuxt/canvas-model-label-fix.js?v=20260824-model-label-v2')
            );
        }
    }
}
