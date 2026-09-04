<?php

namespace Tests\Feature;

use app\common\service\ai\ReferenceMentionPromptService;
use PHPUnit\Framework\TestCase;

class ReferenceMentionPromptServiceTest extends TestCase
{
    public function testBareMentionsUseIndependentMediaTypeSequences(): void
    {
        $compiled = ReferenceMentionPromptService::compile([
            'prompt' => '@ @ @ @',
            'reference_assets' => [
                ['type' => 'image', 'url' => 'https://example.test/image-1.png'],
                ['type' => 'audio', 'url' => 'https://example.test/audio-1.mp3'],
                ['type' => 'image', 'url' => 'https://example.test/image-2.png'],
                ['type' => 'video', 'url' => 'https://example.test/video-1.mp4'],
            ],
        ]);

        self::assertSame(
            "@图片1 @音频1 @图片2 @视频1\n参考素材：@图片1 @音频1 @图片2 @视频1",
            $compiled['prompt']
        );
        self::assertSame('@ @ @ @', $compiled['source_prompt']);
    }

    public function testNamedMentionUsesTheMatchedReference(): void
    {
        $compiled = ReferenceMentionPromptService::compile([
            'prompt' => '让@主角挥手',
            'reference_images' => [
                ['id' => 'asset-1', 'url' => 'https://example.test/hero.png'],
            ],
            'selected_mentions' => [
                ['id' => 'asset-1', 'name' => '主角', 'type' => 'image'],
            ],
        ]);

        self::assertStringContainsString('让@图片1挥手', $compiled['prompt']);
    }

    public function testPlainTextDoesNotGainReferenceTokens(): void
    {
        $params = [
            'prompt' => '联系 a@b.com 获取示例',
            'reference_images' => ['https://example.test/image.png'],
        ];

        self::assertSame($params, ReferenceMentionPromptService::compile($params));
    }
}
