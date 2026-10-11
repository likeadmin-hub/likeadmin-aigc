<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasTextEditorCaretContractTest extends TestCase
{
    public function testTextNodeEditingDoesNotRewriteInnerHtmlAfterEachSave(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/native/CanvasNode.vue');
        self::assertStringNotContainsString('v-html="textEditorHtml"', $source);
        self::assertStringContainsString('function startTextEditing()', $source);
        self::assertStringContainsString('function persistTextEditor()', $source);
        self::assertStringContainsString('textEditorRef.value', $source);
        self::assertStringContainsString('contenteditable', \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]'));
    }

}
