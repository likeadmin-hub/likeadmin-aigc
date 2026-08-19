<?php

namespace Tests\Feature;

use app\common\service\app\aigc_image\AigcImageChannelService;
use PHPUnit\Framework\TestCase;

class ImageToolDefaultModelDisplayContractTest extends TestCase
{
    public function testLegacySavedModelCodeMatchesTheCurrentNamedOption(): void
    {
        $aligned = AigcImageChannelService::alignConfigSelection([
            'channel' => 'market_image_model98',
            'quality' => '1k',
            'ratio' => '1:1',
        ], $this->options());

        self::assertSame('market_image_model:98', $aligned['channel']);
        self::assertSame('1k', $aligned['quality']);
        self::assertSame('1:1', $aligned['ratio']);
    }

    public function testCurrentModelCodeRemainsBoundToItsNamedOption(): void
    {
        $aligned = AigcImageChannelService::alignConfigSelection([
            'channel' => 'market_image_model:99',
            'quality' => '2k',
            'ratio' => '16:9',
        ], $this->options());

        self::assertSame('market_image_model:99', $aligned['channel']);
        self::assertSame('2k', $aligned['quality']);
        self::assertSame('16:9', $aligned['ratio']);
    }

    public function testUnavailableSavedModelFallsBackToASelectableModel(): void
    {
        $aligned = AigcImageChannelService::alignConfigSelection([
            'channel' => 'market_image_model:404',
            'quality' => 'removed-quality',
            'ratio' => 'removed-ratio',
        ], $this->options());

        self::assertSame('market_image_model:99', $aligned['channel']);
        self::assertSame('2k', $aligned['quality']);
        self::assertSame('16:9', $aligned['ratio']);
    }

    public function testDefaultColumnsAndConfigJsonStayInSync(): void
    {
        $aligned = AigcImageChannelService::alignConfigDefaults([
            'default_channel' => 'market_image_model98',
            'default_quality' => '1k',
            'default_ratio' => '1:1',
            'config_json' => [
                'channel' => 'market_image_model:99',
                'quality' => '2k',
                'ratio' => '16:9',
                'target_language' => 'en',
            ],
        ], $this->options());

        self::assertSame('market_image_model:98', $aligned['default_channel']);
        self::assertSame('1k', $aligned['default_quality']);
        self::assertSame('1:1', $aligned['default_ratio']);
        self::assertSame('market_image_model:98', $aligned['config_json']['channel']);
        self::assertSame('en', $aligned['config_json']['target_language']);
    }

    public function testImageToolConfigServicesUseSharedDefaultModelAlignment(): void
    {
        foreach ($this->alignedServiceSources() as $source) {
            self::assertStringContainsString('AigcImageChannelService::alignConfig', $source);
        }
    }

    public function testAdminModelSelectorsRenderTheModelNameInsteadOfItsCode(): void
    {
        $assets = glob(dirname(__DIR__, 2) . '/public/admin/assets/config-*.js') ?: [];
        $checked = 0;
        foreach ($assets as $asset) {
            $source = (string)file_get_contents($asset);
            if (!str_contains($source, '默认模型')) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/label:([A-Za-z_$][A-Za-z0-9_$]*)\.name,value:\1\.code/',
                $source,
                basename($asset)
            );
            $checked++;
        }
        self::assertGreaterThan(0, $checked);
    }

    private function options(): array
    {
        return [
            'defaults' => [
                'channel' => 'market_image_model:99',
                'quality' => '2k',
                'ratio' => '16:9',
            ],
            'channels' => [
                [
                    'code' => 'market_image_model:98',
                    'name' => '真实人像增强',
                    'qualities' => [[
                        'value' => '1k',
                        'label' => '1K 出图',
                        'ratios' => [[
                            'value' => '1:1',
                            'ratio' => '1:1',
                            'label' => '1:1',
                        ]],
                    ]],
                ],
                [
                    'code' => 'market_image_model:99',
                    'name' => '高清商品图',
                    'qualities' => [[
                        'value' => '2k',
                        'label' => '2K 出图',
                        'ratios' => [[
                            'value' => '16:9',
                            'ratio' => '16:9',
                            'label' => '16:9',
                        ]],
                    ]],
                ],
            ],
        ];
    }

    private function alignedServiceSources(): array
    {
        $root = dirname(__DIR__, 2) . '/app/common/service/app/';
        $files = [
            'aigc_background_removal/AigcBackgroundRemovalService.php',
            'aigc_fashion_lookbook/AigcFashionLookbookService.php',
            'aigc_image_translate/AigcImageTranslateService.php',
            'aigc_one_click_cleanup/AigcOneClickCleanupService.php',
            'aigc_photo_restore/AigcPhotoRestoreService.php',
            'aigc_product_image/AigcProductImageService.php',
            'aigc_product_multi_angle/AigcProductMultiAngleService.php',
            'aigc_product_suite/AigcProductSuiteService.php',
            'aigc_style_transfer/AigcStyleTransferService.php',
        ];
        return array_map(static fn(string $file): string => (string)file_get_contents($root . $file), $files);
    }
}
