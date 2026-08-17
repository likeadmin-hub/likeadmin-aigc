<?php

namespace Tests\Feature;

use app\common\service\app\aigc_background_removal\AigcBackgroundRemovalService;
use app\common\service\app\aigc_fashion_lookbook\AigcFashionLookbookService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image_translate\AigcImageTranslateService;
use app\common\service\app\aigc_local_redraw\AigcLocalRedrawService;
use app\common\service\app\aigc_one_click_cleanup\AigcOneClickCleanupService;
use app\common\service\app\aigc_outpaint\AigcOutpaintService;
use app\common\service\app\aigc_photo_restore\AigcPhotoRestoreService;
use app\common\service\app\aigc_product_multi_angle\AigcProductMultiAngleService;
use app\common\service\app\aigc_product_suite\AigcProductSuiteService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ImageToolMarketPackageContractTest extends TestCase
{
    public function testMarketModelIdentifiersKeepTheirColon(): void
    {
        foreach ($this->services() as $service) {
            self::assertSame(
                'market_image_model:175',
                $this->invoke($service, 'normalizeCode', 'market_image_model:175'),
                $service
            );
        }
    }

    public function testLegacyMarketModelIdentifiersRecoverTheirColon(): void
    {
        self::assertSame(
            'market_image_model:175',
            AigcImageChannelService::normalizeRuntimeChannelCode('market_image_model175')
        );

        foreach ($this->services() as $service) {
            self::assertSame(
                'market_image_model:175',
                $this->invoke($service, 'normalizeCode', 'market_image_model175'),
                $service
            );
        }
    }

    public function testGeneratedFallbackPackagesRemainSelectable(): void
    {
        $options = [
            'channels' => [[
                'code' => 'market_image_model:175',
                'name' => 'Qwen Image 3.0 Pro',
                'qualities' => [[
                    'value' => '1k',
                    'label' => 'Image 1K',
                    'ratios' => [[
                        'value' => '1:1',
                        'label' => '1:1',
                        'ratio' => '1:1',
                        'platform_unit_cost' => 22,
                    ]],
                ]],
            ]],
        ];

        foreach ($this->packageServices() as $service) {
            [$priceConfig] = $this->invoke($service, 'ensurePricePackages', [], $options);
            $packages = $this->invoke($service, 'buildPricePackages', $options, $priceConfig);

            self::assertNotEmpty($packages, $service);
            self::assertSame('market_image_model:175', $packages[0]['channel'], $service);
            self::assertSame(1, (int)$packages[0]['status'], $service);
        }
    }

    private function services(): array
    {
        return [
            AigcBackgroundRemovalService::class,
            AigcFashionLookbookService::class,
            AigcImageTranslateService::class,
            AigcLocalRedrawService::class,
            AigcOneClickCleanupService::class,
            AigcOutpaintService::class,
            AigcPhotoRestoreService::class,
            AigcProductMultiAngleService::class,
            AigcProductSuiteService::class,
        ];
    }

    private function packageServices(): array
    {
        return [
            AigcBackgroundRemovalService::class,
            AigcImageTranslateService::class,
            AigcOutpaintService::class,
            AigcPhotoRestoreService::class,
        ];
    }

    private function invoke(string $service, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
