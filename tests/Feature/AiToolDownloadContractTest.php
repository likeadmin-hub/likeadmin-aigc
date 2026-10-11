<?php

namespace Tests\Feature;

use app\common\service\app\AiToolDownloadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AiToolDownloadContractTest extends TestCase
{
    public function testToolBundlesUseTheServerZipEndpoint(): void
    {
        $api = \Tests\Support\CompiledAssets::source('pc/apps/ai_tool_download/api.ts');
        $source = \Tests\Support\CompiledAssets::source('pc/apps/ai_tool_download/useAiToolDownload.ts');
        $bundle = $this->frontendSource();
        self::assertStringContainsString('url: \'/app.ai_tool_download/zip\'', $api);
        self::assertStringContainsString('{ app_code: appCode, task_id: taskId }', $source);
        self::assertStringContainsString('{ timeout: 120 * 1000, retry: 0 }', $source);
        self::assertStringContainsString('? await serverZipDownload(appCode, rawTaskId, zipName)', $source);
        self::assertStringContainsString(': await downloadPcAssets(assets, zipName)', $source);
        self::assertStringContainsString('/app.ai_tool_download/zip', $bundle);
    }

    public function testEverySupportedToolPassesItsApplicationCode(): void
    {
        $source = $this->frontendSource();
        $components = [
            'ProductImageCreator' => 'aigc_product_image',
            'StyleTransferCreator' => 'aigc_style_transfer',
            'PhotoRestoreCreator' => 'aigc_photo_restore',
            'ModelWearCreator' => 'aigc_model_wear',
            'BackgroundRemovalCreator' => 'aigc_background_removal',
            'ImageTranslateCreator' => 'aigc_image_translate',
            'OneClickCleanupCreator' => 'aigc_one_click_cleanup',
            'ProductSuiteCreator' => 'aigc_product_suite',
            'ProductMultiAngleCreator' => 'aigc_product_multi_angle',
            'FashionLookbookCreator' => 'aigc_fashion_lookbook',
            'ProductPromoVideoCreator' => 'aigc_product_promo_video',
            'ActionTransferCreator' => 'aigc_action_transfer',
            'PersonReplacementCreator' => 'aigc_person_replacement',
            'OutpaintCreator' => 'aigc_outpaint',
            'LocalRedrawCreator' => 'aigc_local_redraw',
            'HairstyleCreator' => 'aigc_hairstyle',
            'FittingCreator' => 'aigc_fitting',
        ];
        $service = new ReflectionClass(AiToolDownloadService::class);
        $supportedApps = $service->getConstant('APP_NAMES');

        foreach ($components as $component => $appCode) {
            $start = strpos($source, '__name:"' . $component . '"');
            self::assertNotFalse($start, $component);

            $setup = substr($source, $start, 5000);
            self::assertStringContainsString($appCode, $setup, $component);
            self::assertStringContainsString("useAiToolDownload('" . $appCode . "')", \Tests\Support\CompiledAssets::source('pc/apps/' . $appCode . '/' . $component . '.vue'), $component);
            self::assertArrayHasKey($appCode, $supportedApps, $appCode);
        }
    }

    public function testLegacyFittingEntryAlsoUsesServerSidePackaging(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/components/ai-workspace/tool-detail-template.vue');
        self::assertStringContainsString('if (taskId > 0)', $source);
        self::assertStringContainsString('const appCode = isAiFittingTool.value ? \'aigc_fitting\' : \'aigc_hairstyle\'', $source);
        self::assertStringContainsString('createAiToolDownloadZip({ app_code: appCode, task_id: taskId }', $source);
        self::assertStringContainsString('triggerLegacyDownload(downloadUrl', $source);
        self::assertStringContainsString('/app.ai_tool_download/zip', $this->frontendSource());
    }

    private function frontendSource(): string
    {
        return (string)\Tests\Support\CompiledAssets::pcPage('ai/tools/[id]');
    }
}
