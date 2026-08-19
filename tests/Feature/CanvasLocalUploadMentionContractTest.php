<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasLocalUploadMentionContractTest extends TestCase
{
    public function testLocalReferenceImagesAreIncludedInMentionOptions(): void
    {
        $bundle = $this->canvasBundle();

        self::assertStringContainsString(
            'const o=(v.mentionOptions||[]).filter(a=>a.id&&a.name),a=new Set(o.map(c=>String(c.url||"").trim()).filter(Boolean)),c=(Array.isArray(v.node.metadata.referenceImages)?v.node.metadata.referenceImages:[])',
            $bundle
        );
        self::assertStringContainsString('id:`local-${v.node.id}-image-${b}`', $bundle);
        self::assertStringContainsString('source:f.asset_id?"asset":"local"', $bundle);
        self::assertStringContainsString('local:y("本地上传")', $bundle);
    }

    public function testUploadedImageMentionsReceiveThePermanentUrl(): void
    {
        $bundle = $this->canvasBundle();

        self::assertStringContainsString(
            'map(U=>U&&U.url===o?{...U,url:c}:U)',
            $bundle
        );
        self::assertStringContainsString(
            'metadata:{referenceImages:M,mentions:F,referenceCount:',
            $bundle
        );
        self::assertStringContainsString('reference_assets:r', $bundle);
    }

    private function canvasBundle(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 2) . '/public/_nuxt/_id_.2fb60286.js'
        );
    }
}
