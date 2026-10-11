<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasLinkedVideoNodeContractTest extends TestCase
{
    public function testConnectedVideoNodeChoosesACompatibleVideoModelBeforeCreatingEdges(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/pages/app/aigc_canvas/project/[id].vue');
        self::assertStringContainsString('connectedNodeMetadata(type)', $source);
        self::assertStringContainsString('pickConnectedVideoMetadata', $source);
        self::assertStringContainsString('compatibleVideoGenerationMethods', $source);
        self::assertStringContainsString('normalizeVideoChannelsForCreateConfig', $source);
        self::assertStringContainsString('canCreateConnectedNode(type)', $source);
        self::assertStringContainsString('reference_video', \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]'));
        self::assertStringContainsString('reference_audio', \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]'));
    }

    public function testCanvasRouteCacheVersionIncludesConnectedVideoFix(): void
    {
        $bundle = \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]');
        self::assertNotEmpty($bundle);
        self::assertStringContainsString('syncNodeMetadataWithOptions', \Tests\Support\CompiledAssets::source('pc/pages/app/aigc_canvas/project/[id].vue'));
    }

}
