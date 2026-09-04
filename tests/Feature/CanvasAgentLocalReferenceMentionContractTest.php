<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasAgentLocalReferenceMentionContractTest extends TestCase
{
    public function testAgentMentionMenuIncludesNodeLocalReferences(): void
    {
        $bundle = $this->agentBundle();

        self::assertStringContainsString(
            '].flatMap((t,n)=>{var a;const r=String((t==null?void 0:t.id)',
            $bundle
        );
        self::assertStringContainsString('...o("referenceImages","image","图片")', $bundle);
        self::assertStringContainsString('...o("referenceVideos","video","视频")', $bundle);
        self::assertStringContainsString('...o("referenceAudios","audio","音频")', $bundle);
        self::assertStringContainsString('id:`local-${r}-${i}-${g}`', $bundle);
        self::assertStringContainsString('source:"local_upload"', $bundle);
    }

    public function testSelectedLocalReferenceUsesTheExistingReferenceSubmissionPath(): void
    {
        $bundle = $this->agentBundle();

        self::assertStringContainsString('Io(T)', $bundle);
        self::assertStringContainsString('re=[...g,...N]', $bundle);
        self::assertStringContainsString('explicit_references:re', $bundle);
        self::assertStringContainsString('reference_assets:re', $bundle);
    }

    private function agentBundle(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 2) . '/public/_nuxt/projects.b63ba847.js'
        );
    }
}
