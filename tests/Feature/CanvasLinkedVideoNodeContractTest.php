<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasLinkedVideoNodeContractTest extends TestCase
{
    public function testConnectedVideoNodeChoosesACompatibleVideoModelBeforeCreatingEdges(): void
    {
        $bundle = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/_id_.2fb60286.js');

        self::assertIsString($bundle);
        self::assertStringContainsString('function connectedNodeMetadata(e)', $bundle);
        self::assertStringContainsString('function pickConnectedVideoMetadata(e,t,n,o)', $bundle);
        self::assertStringContainsString('fr(rt.value).map(i=>Un("video",{...r,channel:i.value}))', $bundle);
        self::assertStringContainsString('E.value=E.value.map(n=>{if(n.type!=="video")return n', $bundle);
        self::assertStringContainsString('i&&JSON.stringify(i)!==JSON.stringify(n.metadata)', $bundle);
        self::assertStringContainsString('const t=de.value,n=connectedNodeMetadata(e);if(!t||!canCreateConnectedNode(e,n))return', $bundle);
        self::assertStringContainsString('disabled:!Eo("video")', $bundle);
    }

    public function testCanvasRouteCacheVersionIncludesConnectedVideoFix(): void
    {
        $entry = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/entry.c46691d5.js');

        self::assertIsString($entry);
        self::assertStringContainsString('./_id_.2fb60286.js?v=20260827-connected-video-v1', $entry);
    }
}
