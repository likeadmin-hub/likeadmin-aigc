<?php

namespace Tests\Feature;

use app\common\service\app\AiToolDownloadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AiToolDownloadContractTest extends TestCase
{
    public function testToolBundlesUseTheServerZipEndpoint(): void
    {
        $source = $this->frontendSource();

        self::assertStringContainsString(
            '$request.post({url:"/app.ai_tool_download/zip",params:{app_code:M,task_id:re},timeout:120*1e3,retry:0})',
            $source
        );
        self::assertStringContainsString(
            'M&&Ee>0?await serverZipDownload(M,Ee,F):await av(se,F)',
            $source
        );
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
            self::assertStringContainsString('pt("' . $appCode . '")', $setup, $component);
            self::assertArrayHasKey($appCode, $supportedApps, $appCode);
        }
    }

    public function testLegacyFittingEntryAlsoUsesServerSidePackaging(): void
    {
        $source = $this->frontendSource();

        self::assertStringContainsString(
            'ke.value?"aigc_fitting":"aigc_hairstyle"',
            $source
        );
        self::assertStringContainsString(
            't>0?await serverZipDownload(de,t,o)',
            $source
        );
    }

    private function frontendSource(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/_id_.57b448ac.js');
    }
}
