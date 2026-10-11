<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasChatCopyContractTest extends TestCase
{
    public function testSelectedChatTextUsesTheNativeClipboard(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/pages/app/aigc_canvas/project/[id].vue');
        self::assertGreaterThanOrEqual(3, substr_count($source, 'hasCanvasTextSelection('));
        self::assertStringContainsString('window.getSelection()', $source);
        self::assertStringContainsString('target?.closest(\'input,textarea,select,[contenteditable=true]\')', $source);
        self::assertStringContainsString('getSelection()', \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]'));
    }

    public function testCanvasNodesStillUseTheCanvasClipboardWithoutATextSelection(): void
    {
        $bundle = \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]');
        self::assertGreaterThanOrEqual(2, substr_count($bundle, 'sessionStorage.setItem("aigc-canvas-clipboard"'));
        self::assertMatchesRegularExpression('/window\.addEventListener\("copy",[A-Za-z_$][\w$]*\)/', $bundle);
    }

}
