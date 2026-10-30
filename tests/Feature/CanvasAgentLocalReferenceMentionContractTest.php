<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\CompiledAssets;

/** The Agent now consumes the current-project reference catalogue through AgentWorkspace. */
class CanvasAgentLocalReferenceMentionContractTest extends TestCase
{
    public function testAgentMentionMenuIncludesNodeLocalReferences(): void
    {
        $panel = CompiledAssets::source('pc/apps/aigc_canvas/components/CanvasConversationPanel.vue');
        $page = CompiledAssets::source('pc/pages/app/aigc_canvas/project/[id].vue');
        $catalogue = CompiledAssets::source('pc/apps/aigc_canvas/components/native/projectReferenceCatalog.ts');
        self::assertStringContainsString('<AgentWorkspace', $panel);
        self::assertStringContainsString('projectAgentReferenceCatalog(nodes.value', $page);
        self::assertStringContainsString('provide(agentProjectReferencePickerKey', $page);
        foreach (['referenceImages', 'referenceVideos', 'referenceAudios', "group: 'upload'"] as $field) {
            self::assertStringContainsString($field, $catalogue);
        }
    }

    public function testSelectedLocalReferenceUsesTheExistingReferenceSubmissionPath(): void
    {
        $page = CompiledAssets::source('pc/pages/app/aigc_canvas/project/[id].vue');
        self::assertStringContainsString('resolveCanvasAgentReference(id: string)', $page);
        self::assertStringContainsString('if (!syncOwner() || !projectId || !item)', $page);
        self::assertStringContainsString("uri: reference.url", $page);
        self::assertStringContainsString("reference: { kind: 'asset', id: String(asset.id) }", $page);
        self::assertStringContainsString('scope !== promptOptimizationScope()', $page);
    }
}
