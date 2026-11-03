<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\CompiledAssets;

/** Source-only wiring guards; behavior is exercised by the PC reference tests. */
class CanvasLocalUploadMentionContractTest extends TestCase
{
    public function testLocalReferenceImagesAreIncludedInMentionOptions(): void
    {
        $page = CompiledAssets::source('pc/pages/app/aigc_canvas/project/[id].vue');
        $catalogue = CompiledAssets::source('pc/apps/aigc_canvas/components/native/projectReferenceCatalog.ts');
        foreach (['referenceImages', 'referenceVideos', 'referenceAudios', 'project-upload-'] as $field) {
            self::assertStringContainsString($field, $catalogue);
        }
        self::assertStringContainsString('projectUploadReferences(nodes.value).forEach', $page);
        self::assertStringContainsString("source: 'local'", $page);
        self::assertStringContainsString(':mention-options="nodePresentations.get(node).mentions"', $page);
    }

    public function testUploadedImageMentionsReceiveThePermanentUrl(): void
    {
        $source = CompiledAssets::source('pc/apps/aigc_canvas/components/native/CanvasNode.vue');
        $references = CompiledAssets::source('pc/apps/aigc_canvas/components/native/inputReferences.ts');
        $catalogue = CompiledAssets::source('pc/apps/aigc_canvas/components/native/projectReferenceCatalog.ts');
        self::assertStringContainsString('replaceCanvasReference(item,toUrl)', $source);
        self::assertStringContainsString('!requestedRole || item.role === requestedRole', $source);
        self::assertStringContainsString('uri:url', $references);
        self::assertStringContainsString('[reference?.uri,reference?.url]', $catalogue);
        self::assertStringContainsString('isPersistentMediaUrl(value)', $catalogue);
    }
}
