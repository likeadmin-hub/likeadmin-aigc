<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaDefaultCreationModelsContractTest extends TestCase
{
    public function testConfiguredImageAndVideoDefaultsAreAppliedToTheirGroups(): void
    {
        $groups = $this->invoke('applyDefaultCreationModels', $this->groups(), [
            'default_image_model_id' => 'image-b',
            'default_video_model_id' => 'video-b',
        ]);

        self::assertSame('image-b', $groups[0]['default']);
        self::assertSame('video-b', $groups[1]['default']);
        self::assertSame('image-b', $groups[0]['options'][0]['id']);
        self::assertSame('video-b', $groups[1]['options'][0]['id']);
    }

    public function testUnavailableConfiguredDefaultFallsBackToFirstAvailableModel(): void
    {
        $groups = $this->groups();
        $groups[0]['options'][1]['available'] = false;

        $resolved = $this->invoke('applyDefaultCreationModels', $groups, [
            'default_image_model_id' => 'image-b',
        ]);

        self::assertSame('image-a', $resolved[0]['default']);
    }

    public function testUnspecifiedProjectSelectionUsesConfiguredCreationDefaults(): void
    {
        $groups = $this->invoke('applyDefaultCreationModels', $this->groups(), [
            'default_image_model_id' => 'image-b',
            'default_video_model_id' => 'video-b',
        ]);
        $selected = $this->invoke('resolveSelectedModels', 0, [], ['model_groups' => $groups]);

        self::assertSame('image-b', $selected['image']['id']);
        self::assertSame('video-b', $selected['video']['id']);
    }

    public function testTenantAdminConfigContainsBothCreationModelSelectors(): void
    {
        $asset = (string)file_get_contents(dirname(__DIR__, 2) . '/public/admin/assets/config-fge0of94.js');

        self::assertStringContainsString('default_image_model_id', $asset);
        self::assertStringContainsString('default_video_model_id', $asset);
        self::assertStringContainsString('label:"默认图片模型"', $asset);
        self::assertStringContainsString('label:"默认视频模型"', $asset);
    }

    private function groups(): array
    {
        return [
            [
                'key' => 'image',
                'default' => 'image-a',
                'options' => [
                    ['id' => 'image-a', 'name' => 'Image A', 'enabled' => true, 'available' => true, 'status' => 1],
                    ['id' => 'image-b', 'name' => 'Image B', 'enabled' => true, 'available' => true, 'status' => 1],
                ],
            ],
            [
                'key' => 'video',
                'default' => 'video-a',
                'options' => [
                    ['id' => 'video-a', 'name' => 'Video A', 'enabled' => true, 'available' => true, 'status' => 1],
                    ['id' => 'video-b', 'name' => 'Video B', 'enabled' => true, 'available' => true, 'status' => 1],
                ],
            ],
        ];
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
