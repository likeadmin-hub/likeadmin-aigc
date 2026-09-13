<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic rows only; no provider calls or point consumption. */
class ShortDramaFailedGenerationDeleteTest extends TestCase
{
    private int $tenant = 2000000702;
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_TASK_DB_TESTS') !== '1') self::markTestSkipped('Set SHORT_DRAMA_TASK_DB_TESTS=1 for transactional checks');
        (new \think\App())->initialize();
        Db::startTrans();
        $this->transactionStarted = true;
        self::assertSame(0, Db::name('aigc_short_drama_generation_task')->where('tenant_id', $this->tenant)->count());
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) Db::rollback();
    }

    private function seed(string $status, string $type = 'shot_video'): string
    {
        $id = 'delete_test_' . $status . '_' . $type;
        Db::name('aigc_short_drama_generation_task')->insert([
            'tenant_id' => $this->tenant, 'user_id' => 7, 'task_id' => $id,
            'source_task_id' => $id, 'task_type' => $type, 'status' => $status,
            'request_json' => '{"prompt":"keep audit prompt"}',
            'billing_status' => 'refunded', 'user_charge_points' => 12,
            'output_asset_ids' => '[]', 'create_time' => time(), 'update_time' => time(),
        ]);
        return $id;
    }

    public function testDeletionPersistsIsIdempotentAndPreservesAuditAndMaterials(): void
    {
        foreach (['shot_image', 'shot_video'] as $type) {
            $id = $this->seed('failed', $type);
            $assetId = Db::name('aigc_short_drama_asset')->insertGetId([
                'tenant_id' => $this->tenant, 'user_id' => 7, 'task_id' => $id, 'asset_type' => $type,
            ]);
            $before = Db::name('aigc_short_drama_generation_task')->where('task_id', $id)->find();
            self::assertTrue(Service::deleteFailedGenerationTask($this->tenant, 7, $id)['deleted']);
            self::assertTrue(Service::deleteFailedGenerationTask($this->tenant, 7, $id)['deleted']);
            $after = Db::name('aigc_short_drama_generation_task')->where('task_id', $id)->find();
            self::assertGreaterThan(0, $after['delete_time']);
            foreach ($before as $key => $value) {
                if (!in_array($key, ['delete_time', 'update_time'], true)) self::assertSame($value, $after[$key], $key);
            }
            self::assertSame(0, (int)Db::name('aigc_short_drama_asset')->where('id', $assetId)->value('delete_time'));
            self::assertSame([], Service::generationTaskLists($this->tenant, 7, ['source_task_id' => $id])['lists']);
            try { Service::generationTaskDetail($this->tenant, 7, $id); self::fail('Deleted task still visible'); }
            catch (\Exception $e) { self::assertSame('生成任务不存在', $e->getMessage()); }
        }
    }

    public function testCannotDeleteAcrossTenantOrUser(): void
    {
        $id = $this->seed('failed');
        foreach ([[$this->tenant + 1, 7], [$this->tenant, 8]] as [$tenant, $user]) {
            try { Service::deleteFailedGenerationTask($tenant, $user, $id); self::fail('Wrong owner accepted'); }
            catch (\Exception $e) { self::assertSame('生成任务不存在', $e->getMessage()); }
        }
        self::assertSame(0, (int)Db::name('aigc_short_drama_generation_task')->where('task_id', $id)->value('delete_time'));
    }

    public function testActiveSuccessfulAndCanceledTasksAreNotDeleted(): void
    {
        foreach (['pending', 'queued', 'running', 'success', 'canceled'] as $status) {
            $id = $this->seed($status);
            try { Service::deleteFailedGenerationTask($this->tenant, 7, $id); self::fail('Non-failed task accepted'); }
            catch (\Exception $e) { self::assertSame('仅支持删除生成失败的记录', $e->getMessage()); }
            self::assertSame(0, (int)Db::name('aigc_short_drama_generation_task')->where('task_id', $id)->value('delete_time'));
        }
    }
}
