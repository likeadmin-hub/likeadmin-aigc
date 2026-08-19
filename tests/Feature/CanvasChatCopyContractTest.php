<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasChatCopyContractTest extends TestCase
{
    public function testSelectedChatTextUsesTheNativeClipboard(): void
    {
        $bundle = (string)file_get_contents(
            dirname(__DIR__, 2) . '/public/_nuxt/_id_.2fb60286.js'
        );

        self::assertStringContainsString(
            'function hasCanvasTextSelection(){if(typeof window>"u")return!1;const e=window.getSelection();return!!(e&&e.rangeCount>0&&!e.isCollapsed&&e.toString())}',
            $bundle
        );
        self::assertStringContainsString(
            'r&&n==="c"?hasCanvasTextSelection()||(e.preventDefault(),pl())',
            $bundle
        );
        self::assertStringContainsString(
            'function Yr(e){var s;const t=e.target;if(t!=null&&t.closest("input,textarea,select,[contenteditable=true]"))return;if(hasCanvasTextSelection())return;',
            $bundle
        );
    }

    public function testCanvasNodesStillUseTheCanvasClipboardWithoutATextSelection(): void
    {
        $bundle = (string)file_get_contents(
            dirname(__DIR__, 2) . '/public/_nuxt/_id_.2fb60286.js'
        );

        self::assertGreaterThanOrEqual(
            2,
            substr_count($bundle, 'sessionStorage.setItem("aigc-canvas-clipboard"')
        );
        self::assertStringContainsString('window.addEventListener("copy",Yr)', $bundle);
    }
}
