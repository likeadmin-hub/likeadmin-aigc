<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace as Workspace;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Opt-in integration checks. Every test rolls back all its synthetic tenant rows. */
class ShortDramaPromptPersistenceTest extends TestCase
{
    private int $tenant = 2000000701;
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_PROMPT_DB_TESTS') !== '1') self::markTestSkipped('Set SHORT_DRAMA_PROMPT_DB_TESTS=1 to run transactional DB checks');
        (new \think\App())->initialize();
        Db::startTrans();
        $this->transactionStarted = true;
        self::assertSame(0, Db::name('aigc_short_drama_config')->where('tenant_id', $this->tenant)->count());
        Db::name('aigc_short_drama_config')->insert(['tenant_id' => $this->tenant, 'config_json' => '{}', 'create_time' => time(), 'update_time' => time()]);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) Db::rollback();
    }

    public function testSaveRestoreHistoryConflictAndRollback(): void
    {
        $before = Workspace::capture($this->tenant);
        Workspace::save($this->tenant, 9, ['fingerprint' => $before['fingerprint'], 'overrides' => ['subject.character' => '自定义构图']]);
        $after = Workspace::capture($this->tenant);
        self::assertSame(1, $after['revision']);
        self::assertSame('自定义构图', $after['values']['subject.character']);
        self::assertSame($before, Workspace::forTask($this->tenant, ['_prompt_snapshot' => $before]));
        try { Workspace::save($this->tenant, 10, ['fingerprint' => $before['fingerprint'], 'overrides' => []]); self::fail('Stale write accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('配置已被更新', $e->getMessage()); }
        Workspace::save($this->tenant, 9, ['fingerprint' => $after['fingerprint'], 'overrides' => []]);
        $restored = Workspace::capture($this->tenant);
        self::assertSame($before['values']['subject.character'], $restored['values']['subject.character']);
        Workspace::rollback($this->tenant, 9, ['fingerprint' => $restored['fingerprint'], 'revision' => 1]);
        $rolled = Workspace::capture($this->tenant);
        self::assertSame(3, $rolled['revision']);
        self::assertSame('自定义构图', $rolled['values']['subject.character']);
        self::assertCount(4, Workspace::history($this->tenant));
        self::assertSame([], Workspace::history($this->tenant + 1));
    }

    public function testDocumentSaveRestoreRollbackPreviewAndFrozenTasksUseActualContents(): void
    {
        $before = Workspace::capture($this->tenant);
        $docs = [];
        foreach (\app\common\service\app\aigc_short_drama\ShortDramaPromptDocuments::definition()['documents'] as $id => $spec) $docs[$id] = ['mode' => 'custom', 'body' => '测试完整文档_' . $id];
        Workspace::save($this->tenant, 9, ['format_version' => 3, 'fingerprint' => $before['fingerprint'], 'document_settings' => $docs, 'confirm_migration' => true]);
        $saved = Workspace::capture($this->tenant);
        self::assertSame('documents', $saved['mode']);
        self::assertSame($saved, Workspace::version($this->tenant, 1));
        foreach (['script' => 'script', 'story' => 'script', 'episodes' => 'script', 'production' => 'storyboard', 'revision' => 'script', 'repair' => 'storyboard', 'subject_image' => 'subject_image', 'three_view' => 'subject_views', 'scene_image' => 'scene_image', 'shot_image' => 'shot_image', 'shot_video' => 'shot_video', 'vision_subject' => 'vision_subject', 'vision_scene' => 'vision_scene', 'bgm_audio' => 'music'] as $stage => $id) {
            $preview = AigcShortDramaService::previewPromptWorkspace($this->tenant, ['format_version' => 3, 'fingerprint' => $saved['fingerprint'], 'document_settings' => $docs, 'stage' => $stage]);
            self::assertStringContainsString('测试完整文档_' . $id, json_encode($preview['after']['request'], JSON_UNESCAPED_UNICODE), $stage);
            self::assertSame($preview['before']['request'], $preview['after']['request'], $stage);
        }
        $dry = Workspace::restoreApplication($this->tenant, 9, ['fingerprint' => $saved['fingerprint'], 'target' => 'subject_image']);
        self::assertSame($saved, Workspace::capture($this->tenant));
        self::assertSame('application', $dry['after']['documents']['subject_image']['mode']);
        Workspace::restoreApplication($this->tenant, 9, ['fingerprint' => $saved['fingerprint'], 'target' => 'subject_image', 'confirm' => true]);
        $one = Workspace::capture($this->tenant);
        $preview = AigcShortDramaService::previewPromptWorkspace($this->tenant, ['format_version' => 3, 'fingerprint' => $one['fingerprint'], 'document_settings' => $one['document_settings'], 'stage' => 'subject_image']);
        self::assertStringNotContainsString('测试完整文档_subject_image', $preview['after']['request']['prompt']);
        self::assertSame('custom', $one['documents']['scene_image']['mode']);
        Workspace::restoreApplication($this->tenant, 9, ['fingerprint' => $one['fingerprint'], 'target' => 'all', 'confirm' => true]);
        $all = Workspace::capture($this->tenant);
        foreach ($all['documents'] as $doc) self::assertSame('application', $doc['mode']);
        foreach (['script', 'subject_image', 'three_view', 'scene_image', 'shot_image', 'shot_video', 'vision_subject', 'vision_scene', 'bgm_audio'] as $stage) {
            $request = AigcShortDramaService::previewPromptWorkspace($this->tenant, ['format_version' => 3, 'fingerprint' => $all['fingerprint'], 'document_settings' => $all['document_settings'], 'stage' => $stage]);
            self::assertStringNotContainsString('测试完整文档_', json_encode($request['after']['request'], JSON_UNESCAPED_UNICODE), $stage);
        }
        self::assertSame($saved, Workspace::forTask($this->tenant, ['_prompt_snapshot' => $saved]));
        Workspace::rollback($this->tenant, 9, ['fingerprint' => $all['fingerprint'], 'revision' => 1]);
        $rolled = Workspace::capture($this->tenant);
        self::assertSame($saved['documents'], $rolled['documents']);
        self::assertSame($saved['values'], $rolled['values']);
        self::assertSame(4, $rolled['revision']);
        $request = AigcShortDramaService::previewPromptWorkspace($this->tenant, ['format_version' => 3, 'fingerprint' => $rolled['fingerprint'], 'document_settings' => $rolled['document_settings'], 'stage' => 'subject_image']);
        self::assertStringContainsString('测试完整文档_subject_image', $request['before']['request']['prompt']);
        try { Workspace::save($this->tenant, 9, ['format_version' => 3, 'fingerprint' => $saved['fingerprint'], 'document_settings' => []]); self::fail('Stale save accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('配置已被更新', $e->getMessage()); }
        self::assertSame([], Workspace::history($this->tenant + 1));
    }

    public function testLegacyMigrationRequiresConfirmationAndKeepsBaseline(): void
    {
        $legacy = ['script_system_prompt' => '旧租户规则', 'prompt_config' => ['global_system_prompt' => '旧通用要求']];
        Db::name('aigc_short_drama_config')->where('tenant_id', $this->tenant)->update(['config_json' => json_encode($legacy)]);
        $before = Workspace::capture($this->tenant);
        self::assertSame('legacy', $before['mode']);
        $params = ['fingerprint' => $before['fingerprint'], 'overrides' => ['script.role' => '新租户规则']];
        try { Workspace::save($this->tenant, 9, $params); self::fail('Unconfirmed migration'); } catch (\RuntimeException $e) { self::assertStringContainsString('确认迁移', $e->getMessage()); }
        Workspace::save($this->tenant, 9, $params + ['confirm_migration' => true]);
        self::assertSame($legacy, Workspace::version($this->tenant, 0)['legacy_config']);
        $after = Workspace::capture($this->tenant);
        Workspace::rollback($this->tenant, 9, ['fingerprint' => $after['fingerprint'], 'revision' => 0]);
        self::assertSame('legacy', Workspace::capture($this->tenant)['mode']);
    }

    public function testPreviewIsReadOnlyAndIncludesDraftWithoutSaving(): void
    {
        $before = Workspace::capture($this->tenant);
        $count = Db::name('aigc_short_drama_generation_task')->count();
        foreach (['script', 'story', 'episodes', 'production', 'revision', 'subject_image', 'three_view', 'scene_image', 'shot_image', 'shot_video', 'bgm_audio', 'repair', 'fill', 'vision_subject', 'vision_scene'] as $stage) {
            $preview = AigcShortDramaService::previewPromptWorkspace($this->tenant, ['fingerprint' => $before['fingerprint'], 'overrides' => ['subject.character' => '自定义预览构图'], 'stage' => $stage]);
            self::assertNotEmpty($preview['after']['request'], $stage);
            if ($stage === 'subject_image') self::assertStringContainsString('自定义预览构图', $preview['after']['request']['prompt']);
        }
        self::assertSame($before, Workspace::capture($this->tenant));
        self::assertSame($count, Db::name('aigc_short_drama_generation_task')->count());
        self::assertSame([], Workspace::history($this->tenant));
    }

    public function testFrozenRequestReplayMatchesAssemblerAndIsTenantIsolated(): void
    {
        $snapshot = Workspace::capture($this->tenant);
        foreach (['script', 'story', 'episodes', 'production', 'revision', 'subject_image', 'three_view', 'scene_image', 'shot_image', 'shot_video', 'bgm_audio', 'repair', 'vision_subject', 'vision_scene'] as $stage) {
            $original = AigcShortDramaService::previewPromptWorkspace($this->tenant, [
                'fingerprint' => $snapshot['fingerprint'], 'overrides' => [], 'stage' => $stage,
                'example_prompt' => '明确用户测试输入，保留60秒与末尾3秒镜头。', 'duration' => 60,
                'first_frame' => true, 'last_frame' => true, 'revision_message' => '只修改所选镜头的构图',
            ]);
            Workspace::recordRequest($this->tenant, 8, 'mock_' . $stage, $stage, $original['before']);
            $entry = Workspace::requests($this->tenant)[0];
            $preview = AigcShortDramaService::previewPromptWorkspace($this->tenant, [
                'fingerprint' => $snapshot['fingerprint'], 'overrides' => [], 'example_request_id' => $entry['id'],
                'example_prompt' => '此示例不能污染冻结输入', 'duration' => 5,
            ]);
            self::assertSame($original['before']['request'], $preview['before']['request'], $stage);
            self::assertSame(json_decode(json_encode($original['before']['request']), true), $preview['recorded']['request'], $stage);
        }
        self::assertCount(14, Workspace::requests($this->tenant));
        self::assertSame([], Workspace::requests($this->tenant + 1));
        $this->expectExceptionMessage('生成记录不存在或无权访问');
        Workspace::request($this->tenant + 1, (int)$entry['id']);
    }
}
