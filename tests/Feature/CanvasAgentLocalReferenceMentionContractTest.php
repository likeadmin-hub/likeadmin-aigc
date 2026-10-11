<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasAgentLocalReferenceMentionContractTest extends TestCase
{
    public function testAgentMentionMenuIncludesNodeLocalReferences(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/agent/CanvasAgentPanel.vue');
        self::assertStringContainsString('referenceImages', $source);
        self::assertStringContainsString('referenceVideos', $source);
        self::assertStringContainsString('referenceAudios', $source);
        self::assertStringContainsString('source: \'local_upload\'', $source);
        self::assertStringContainsString('local-', $source);
        self::assertStringContainsString('local_upload', $this->agentBundle());
    }

    public function testSelectedLocalReferenceUsesTheExistingReferenceSubmissionPath(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/agent/CanvasAgentPanel.vue');
        self::assertStringContainsString('selectedComposerMentions()', $source);
        self::assertStringContainsString('explicit_references:', $source);
        self::assertStringContainsString('reference_assets:', $source);
        self::assertStringContainsString('explicit_references:', $this->agentBundle());
        self::assertStringContainsString('reference_assets:', $this->agentBundle());
    }

    private function agentBundle(): string
    {
        return (string)\Tests\Support\CompiledAssets::read('pc', 'projects', 'js', ['agent-composer']);
    }
}
