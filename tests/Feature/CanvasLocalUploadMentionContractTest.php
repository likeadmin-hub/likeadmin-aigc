<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasLocalUploadMentionContractTest extends TestCase
{
    public function testLocalReferenceImagesAreIncludedInMentionOptions(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/native/CanvasNode.vue');
        self::assertStringContainsString('props.node.metadata.referenceImages', $source);
        self::assertStringContainsString('local-${props.node.id}-image-${index}', $source);
        self::assertStringContainsString('source: item.asset_id ? \'asset\' : \'local\'', $source);
        self::assertStringContainsString('referenceImages', $this->canvasBundle());
    }

    public function testUploadedImageMentionsReceiveThePermanentUrl(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/native/CanvasNode.vue');
        self::assertStringContainsString('mentions:', $source);
        self::assertStringContainsString('referenceImages,', $source);
        self::assertStringContainsString('fromUrl', $source);
        self::assertStringContainsString('toUrl', $source);
        self::assertStringContainsString('reference_assets:', $this->canvasBundle());
    }

    private function canvasBundle(): string
    {
        return (string)\Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]');
    }
}
