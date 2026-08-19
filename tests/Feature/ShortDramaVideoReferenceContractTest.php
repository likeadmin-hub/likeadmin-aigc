<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_video\AigcVideoReferenceAssetService;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaVideoReferenceContractTest extends TestCase
{
    public function testMultiFramePlanKeepsOnlyReferenceRolesAndRecordsTrimmedAssets(): void
    {
        $payload = $this->invoke(
            AigcShortDramaService::class,
            'shortDramaVideoReferenceContractPayload',
            'multi_frame',
            [
                ['asset' => ['id' => 101, 'url' => 'https://example.test/first.png'], 'role' => 'reference_image'],
                ['asset' => ['id' => 102, 'url' => 'https://example.test/main-three-view.png'], 'role' => 'reference_image'],
            ],
            [
                ['asset' => ['id' => 103, 'url' => 'https://example.test/secondary-three-view.png'], 'role' => 'reference_image'],
            ],
            ['generation_modes' => ['omni_reference', 'multi_frame'], 'max_reference_images' => 2, 'max_reference_assets' => 2]
        );

        self::assertSame('multi_frame', $payload['generation_method']);
        self::assertSame([101, 102], $payload['input_asset_ids']);
        self::assertSame(['reference_image', 'reference_image'], array_column($payload['reference_assets'], 'role'));
        self::assertSame([103], $payload['reference_plan']['trimmed_asset_ids']);
    }

    public function testStartEndPlanContainsExactlyTheTwoFrameRoles(): void
    {
        $payload = $this->invoke(
            AigcShortDramaService::class,
            'shortDramaVideoReferenceContractPayload',
            'start_end',
            [
                ['asset' => ['id' => 201, 'url' => 'https://example.test/first.png'], 'role' => 'first_frame_image'],
                ['asset' => ['id' => 202, 'url' => 'https://example.test/last.png'], 'role' => 'last_frame_image'],
            ],
            [],
            ['generation_modes' => ['omni_reference', 'start_end'], 'supports_first_last_frame' => true, 'max_reference_images' => 15, 'max_reference_assets' => 15]
        );

        self::assertSame('start_end', $payload['generation_method']);
        self::assertSame(['first_frame_image', 'last_frame_image'], array_column($payload['reference_assets'], 'role'));
        self::assertSame([201, 202], $payload['input_asset_ids']);
        self::assertSame([], $payload['reference_plan']['trimmed_asset_ids']);
    }

    public function testOmniFallbackPlanSubmitsOnlyTheFirstFrame(): void
    {
        $payload = $this->invoke(
            AigcShortDramaService::class,
            'shortDramaVideoReferenceContractPayload',
            'omni_reference',
            [
                ['asset' => ['id' => 301, 'url' => 'https://example.test/first.png'], 'role' => 'reference_image'],
            ],
            [
                ['asset' => ['id' => 302, 'url' => 'https://example.test/three-view.png'], 'role' => 'reference_image'],
            ],
            ['generation_modes' => ['omni_reference'], 'max_reference_images' => 1, 'max_reference_assets' => 1]
        );

        self::assertSame('omni_reference', $payload['generation_method']);
        self::assertSame([301], $payload['input_asset_ids']);
        self::assertSame([302], $payload['reference_plan']['trimmed_asset_ids']);
    }

    public function testAllMultiImageModelsAdvertiseMultiFrameButOnlyH3AdvertisesStartEnd(): void
    {
        $h3Modes = $this->invoke(
            MarketVideoRuntimeService::class,
            'generationModes',
            ['upstream_model_code' => 'h3-video'],
            []
        );
        $genericModes = $this->invoke(
            MarketVideoRuntimeService::class,
            'generationModes',
            ['upstream_model_code' => 'generic-video'],
            ['generation_modes' => ['omni_reference', 'start_end'], 'max_reference_images' => 2, 'max_reference_assets' => 2]
        );
        $singleImageModes = $this->invoke(
            MarketVideoRuntimeService::class,
            'generationModes',
            ['upstream_model_code' => 'single-image-video'],
            ['generation_modes' => ['omni_reference'], 'max_reference_images' => 1, 'max_reference_assets' => 1]
        );

        self::assertSame(['omni_reference', 'start_end', 'multi_frame'], $h3Modes);
        self::assertSame(['omni_reference', 'multi_frame'], $genericModes);
        self::assertSame(['omni_reference'], $singleImageModes);
    }

    public function testH3PayloadKeepsFirstAndLastFrameRoles(): void
    {
        $content = $this->invoke(
            MarketVideoRuntimeService::class,
            'h3Content',
            [
                'reference_assets' => [
                    ['type' => 'image', 'url' => 'https://example.test/first.png', 'role' => 'first_frame_image'],
                    ['type' => 'image', 'url' => 'https://example.test/last.png', 'role' => 'last_frame_image'],
                ],
            ],
            'animate the transition'
        );

        self::assertSame('first_frame', $content[1]['role']);
        self::assertSame('last_frame', $content[2]['role']);
    }

    public function testH3PayloadKeepsMultipleImagesAsOrdinaryReferences(): void
    {
        $content = $this->invoke(
            MarketVideoRuntimeService::class,
            'h3Content',
            [
                'reference_assets' => [
                    ['type' => 'image', 'url' => 'https://example.test/first.png', 'role' => 'reference_image'],
                    ['type' => 'image', 'url' => 'https://example.test/hero-three-view.png', 'role' => 'reference_image'],
                    ['type' => 'image', 'url' => 'https://example.test/lead-three-view.png', 'role' => 'reference_image'],
                ],
            ],
            'animate the scene'
        );

        self::assertSame(['reference_image', 'reference_image', 'reference_image'], array_column(array_slice($content, 1), 'role'));
    }

    public function testFfmpegCandidatesCoverWindowsLinuxAndMacos(): void
    {
        $windows = $this->invoke(AigcShortDramaService::class, 'ffmpegPlatformCandidates', 'Windows');
        $linux = $this->invoke(AigcShortDramaService::class, 'ffmpegPlatformCandidates', 'Linux');
        $macos = $this->invoke(AigcShortDramaService::class, 'ffmpegPlatformCandidates', 'Darwin');

        self::assertContains('C:\\ffmpeg\\bin\\ffmpeg.exe', $windows);
        self::assertContains('/usr/local/bin/ffmpeg', $linux);
        self::assertContains('/opt/homebrew/bin/ffmpeg', $macos);
        self::assertContains('ffmpeg', $linux);
    }

    public function testRuntimeRejectsIncompleteStartEndContract(): void
    {
        $this->expectExceptionMessage('start/end frame generation requires both first and last frame images');
        $this->invoke(
            MarketVideoRuntimeService::class,
            'assertAssets',
            ['product' => ['upstream_model_code' => 'h3-video']],
            [
                'generation_method' => 'start_end',
                'reference_assets' => [
                    ['type' => 'image', 'url' => 'https://example.test/first.png', 'role' => 'first_frame_image'],
                ],
            ]
        );
    }

    public function testProviderPromptMapsNamesUsingNormalizedReferenceOrder(): void
    {
        $prompt = AigcVideoReferenceAssetService::promptWithReferenceAliases(
            '@林浅看向@顾言，配合@雨声和@片段',
            [
                'reference_assets' => [
                    ['type' => 'image', 'url' => 'https://example.test/lin.png', 'name' => '林浅'],
                    ['type' => 'audio', 'url' => 'https://example.test/rain.mp3', 'name' => '雨声'],
                    ['type' => 'image', 'url' => 'https://example.test/gu.png', 'name' => '顾言'],
                    ['type' => 'video', 'url' => 'https://example.test/clip.mp4', 'name' => '片段'],
                ],
            ]
        );

        self::assertSame('@图片1看向@图片2，配合@音频1和@视频1', $prompt);
    }

    public function testProviderPromptDoesNotMapAmbiguousOrStandardAliases(): void
    {
        $prompt = AigcVideoReferenceAssetService::promptWithReferenceAliases(
            '@图片1与@角色互动',
            [
                'reference_assets' => [
                    ['type' => 'image', 'url' => 'https://example.test/one.png', 'name' => '角色'],
                    ['type' => 'image', 'url' => 'https://example.test/two.png', 'name' => '角色'],
                ],
            ]
        );

        self::assertSame('@图片1与@角色互动', $prompt);
    }

    public function testProviderPromptUsesSubjectNamesFromShortDramaReferenceMetadata(): void
    {
        $prompt = AigcVideoReferenceAssetService::promptWithReferenceAliases(
            '@林浅与@顾言查看@契约合约',
            [
                'reference_assets' => [
                    ['type' => 'image', 'url' => 'https://example.test/first.png', 'title' => '分镜首帧'],
                    ['type' => 'image', 'url' => 'https://example.test/lin.png', 'title' => '三视图', 'meta' => ['subject_name' => '林浅']],
                    ['type' => 'image', 'url' => 'https://example.test/gu.png', 'title' => '三视图', 'meta' => ['subject_name' => '顾言']],
                    ['type' => 'image', 'url' => 'https://example.test/contract.png', 'title' => '三视图', 'meta' => ['item_name' => '契约合约']],
                ],
            ]
        );

        self::assertSame('@图片2与@图片3查看@图片4', $prompt);
    }

    public function testStoryboardImageTaskKeepsOnlyOneProviderResult(): void
    {
        $results = $this->invoke(
            AigcShortDramaService::class,
            'storyboardImageResultsForStorage',
            ['task_type' => 'shot_image'],
            [
                ['image_uri' => 'uploads/aigc_image/first.png'],
                ['image_uri' => 'uploads/aigc_image/second.png'],
            ]
        );

        self::assertSame([['image_uri' => 'uploads/aigc_image/first.png']], $results);
    }

    public function testThreeViewTaskKeepsAllProviderResults(): void
    {
        $results = $this->invoke(
            AigcShortDramaService::class,
            'storyboardImageResultsForStorage',
            ['task_type' => 'three_view'],
            [
                ['image_uri' => 'uploads/aigc_image/first.png'],
                ['image_uri' => 'uploads/aigc_image/second.png'],
            ]
        );

        self::assertCount(2, $results);
    }

    public function testExportPackageFilePlanUsesReadableLocalAssets(): void
    {
        $this->ensureShortDramaPublicPathHelper();
        $dir = dirname(__DIR__, 2) . '/public/uploads/phpunit_short_drama_export';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/shot.mp4';
        file_put_contents($path, 'video-bytes');

        try {
            $files = $this->invoke(
                AigcShortDramaService::class,
                'prepareExportPackageFiles',
                [[
                    'id' => 7001,
                    'uri' => 'uploads/phpunit_short_drama_export/shot.mp4',
                    'asset_type' => 'shot_video',
                    'shot_id' => 'shot-001',
                    'duration' => 3,
                ]],
                sys_get_temp_dir() . DIRECTORY_SEPARATOR
            );

            self::assertSame(str_replace('\\', '/', $path), str_replace('\\', '/', $files[0]['path']));
            self::assertSame('shots/001_shot-001.mp4', $files[0]['zip_name']);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testExportPackageFilePlanReportsMaterialFailureBeforeZipOpen(): void
    {
        try {
            $this->invoke(
                AigcShortDramaService::class,
                'prepareExportPackageFiles',
                [[
                    'id' => 7002,
                    'uri' => '',
                    'asset_type' => 'shot_image',
                    'shot_id' => 'missing-shot',
                ]],
                sys_get_temp_dir() . DIRECTORY_SEPARATOR
            );
            self::fail('Expected export package material validation to fail.');
        } catch (\Exception $e) {
            self::assertStringContainsString('missing-shot', $e->getMessage());
            self::assertStringNotContainsString('Cannot destroy the zip context', $e->getMessage());
        }
    }

    private function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }

    private function ensureShortDramaPublicPathHelper(): void
    {
        if (function_exists('app\\common\\service\\app\\aigc_short_drama\\public_path')) {
            return;
        }
        $publicRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2) . '/public'), '/') . '/';
        eval('namespace app\\common\\service\\app\\aigc_short_drama; function public_path() { return ' . var_export($publicRoot, true) . '; }');
    }
}
