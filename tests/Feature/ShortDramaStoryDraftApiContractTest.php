<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaStoryDraftApiContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testAutosaveRouteIsDeclaredAndHandled(): void
    {
        $schema = json_decode(
            (string)file_get_contents($this->root . '/app/apps/aigc_short_drama/api_schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $route = array_values(array_filter(
            (array)($schema['apis'] ?? []),
            static fn(array $api): bool => ($api['api_path'] ?? '') === 'app.aigc_short_drama.script_plan/saveDraft'
        ));

        self::assertCount(1, $route);
        self::assertSame('POST', $route[0]['api_method']);
        self::assertSame('user', $route[0]['scene']);
        self::assertSame(1, $route[0]['need_login']);

        $controller = (string)file_get_contents($this->root . '/app/api/controller/app/aigc_short_drama/ScriptPlanController.php');
        self::assertStringContainsString('public function saveDraft()', $controller);
        self::assertStringContainsString('ShortDramaStoryDraft::save(', $controller);
    }

    public function testAutosaveRouteExistsForFreshAndUpgradedInstallations(): void
    {
        $freshInstallFiles = [
            'app/apps/aigc_short_drama/migrations/install.sql',
            'public/install/db/like.sql',
        ];
        foreach ($freshInstallFiles as $file) {
            $sql = (string)file_get_contents($this->root . '/' . $file);
            self::assertStringContainsString('app.aigc_short_drama.script_plan/saveDraft', $sql, $file);
        }
        self::assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            (string)file_get_contents($this->root . '/app/apps/aigc_short_drama/migrations/install.sql')
        );

        $upgradeFiles = [
            'app/apps/aigc_short_drama/migrations/upgrade_20260915_short_drama_story_draft_autosave.sql',
            'upgrade/20260915_short_drama_story_draft_autosave.sql',
            'public/upgrade/20260915_short_drama_story_draft_autosave.sql',
        ];
        foreach ($upgradeFiles as $file) {
            $sql = (string)file_get_contents($this->root . '/' . $file);
            self::assertStringContainsString('app.aigc_short_drama.script_plan/saveDraft', $sql, $file);
            self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql, $file);
        }
    }
}
