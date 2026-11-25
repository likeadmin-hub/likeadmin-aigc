<?php
declare(strict_types=1);

use app\common\service\app\StudioTemplateModel;
use app\common\service\app\aigc_story_commerce\StoryCommerceCatalog;
use PHPUnit\Framework\TestCase;

final class StudioTemplateModelTest extends TestCase
{
    public function testOriginalMotionModelDoesNotEnumerateAlternatives(): void
    {
        $model = ['id' => 'original'];
        self::assertSame([$model, 'original'], StudioTemplateModel::pick(
            'original',
            'default',
            static fn(string $id) => $id === 'original' ? $model : null,
            static fn() => throw new LogicException('The original is already available'),
            static fn() => false
        ));
    }

    public function testVideoFallbackMatchesBothSpecificationAndImageCount(): void
    {
        [$draft, $notice, $confirmed] = $this->resolve(['small', 'wrong-spec', 'compatible', 'default']);
        self::assertSame('compatible', $draft['video_selection']['model_id']);
        self::assertSame(15, $draft['video_selection']['duration']);
        self::assertCount(2, $draft['images']);
        self::assertStringContainsString('兼容模型', $notice);
        self::assertTrue($confirmed);
    }

    public function testDefaultCanChangeSpecificationsWithoutDiscardingScriptOrImages(): void
    {
        [$draft, $notice, $confirmed] = $this->resolve(['small', 'default']);
        self::assertSame(['model_id' => 'default', 'quality' => '720p', 'ratio' => '9:16', 'duration' => 10], $draft['video_selection']);
        self::assertSame('两张商品图，按 15 秒构思。', $draft['script']);
        self::assertCount(2, $draft['images']);
        self::assertStringContainsString('清晰度、时长', $notice);
        self::assertFalse($confirmed);
    }

    public function testUnavailableDefaultLeavesTheModelBlankAndPreservesTheOriginalSpecifications(): void
    {
        [$draft, $notice, $confirmed] = $this->resolve(['wrong-spec']);
        self::assertSame(['model_id' => '', 'quality' => '480p', 'ratio' => '9:16', 'duration' => 15], $draft['video_selection']);
        self::assertSame('两张商品图，按 15 秒构思。', $draft['script']);
        self::assertStringContainsString('请选择视频模型', $notice);
        self::assertFalse($confirmed);
    }

    private function resolve(array $available): array
    {
        // The catalog only returns models available to this tenant.
        StudioTemplateModelCatalogFixture::$available = $available;
        return StudioTemplateModel::video(2, [
            'images' => [['uri' => 'one.png'], ['uri' => 'two.png']],
            'audio' => null,
            'script' => '两张商品图，按 15 秒构思。',
            'video_selection' => ['model_id' => 'old', 'quality' => '480p', 'ratio' => '9:16', 'duration' => 15],
        ], 'default', StudioTemplateModelCatalogFixture::class, [StoryCommerceCatalog::class, 'selectionForModel']);
    }
}

final class StudioTemplateModelCatalogFixture
{
    public static array $available = [];

    public static function models(int $tenant, string $id = ''): array
    {
        if ($tenant !== 2) {
            throw new LogicException('Expected the receiving tenant');
        }
        $models = [];
        foreach (self::$available as $key) {
            if ($id !== '' && $id !== $key) {
                continue;
            }
            $match = in_array($key, ['small', 'compatible'], true);
            $models[] = [
                'id' => $key,
                'max_reference_images' => $key === 'small' ? 1 : 4,
                'specs' => [[
                    'quality' => $match ? '480p' : '720p',
                    'ratio' => '9:16',
                    'duration' => $match ? 15 : 10,
                    'generation_method' => 'image_reference',
                ]],
            ];
        }
        return $models;
    }
}
