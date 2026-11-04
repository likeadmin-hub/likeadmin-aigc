<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords as R;

/** Tenant-admin history reads against disposable records. All writes roll back. */
final class MicroVideoAdminHistoryTest extends TestCase
{
    private bool $transaction = false;

    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        $db = config('database.connections.mysql');
        $local = $db['database'] === 'z_cn' || ($db['database'] === 'x_cn' && getenv('MICRO_VIDEO_LOCAL_HISTORY_TEST') === '1');
        if ($db['hostname'] !== '127.0.0.1' || !$local) self::markTestSkipped('Local transactional acceptance only');
        Db::startTrans();
        $this->transaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->transaction) Db::rollback();
    }

    public function testAdminVideoHistoryUsesAllProjectRevisionsAndTenantScope(): void
    {
        $project = R::save(1, 1, ['kind' => 'project', 'title' => '项目历史测试', 'body' => []]);
        $ids = [];
        foreach ([1, 2, 3] as $revision) {
            $key = 'admin-history-' . bin2hex(random_bytes(8));
            $task = [
                'tenant_id' => 1, 'user_id' => 1, 'idempotency_key' => $key,
                'status' => 'success', 'params_json' => '{}', 'config_snapshot' => '{}',
                'model_snapshot' => '{}', 'direction_snapshot' => '{}', 'prompt' => '', 'analysis' => '',
                'continuity' => '', 'planning_result' => '{}', 'quote_json' => '{}', 'confirmed_quote' => '{}',
            ];
            $taskId = Db::name('aigc_micro_video_task')->insertGetId($task);
            // A foreign tenant's association must never expose a task in this project.
            R::table('execution')->insert([
                'tenant_id' => $revision === 3 ? 2 : 1, 'user_id' => 1,
                'project_id' => $project['id'], 'project_revision' => $revision,
                'task_id' => $taskId, 'idempotency_key' => $key, 'snapshot_json' => '{}', 'create_time' => time(),
            ]);
            if ($revision !== 3) $ids[] = $taskId;
        }
        $filter = ['project_id' => $project['id'], 'page_size' => 1];
        $result = MicroVideoService::lists(1, 0, $filter, true);
        self::assertEquals(2, $result['count']);
        self::assertEquals($ids[1], $result['lists'][0]['id']);
        $result = MicroVideoService::lists(1, 0, $filter + ['page_no' => 2], true);
        self::assertEquals($ids[0], $result['lists'][0]['id']);
        self::assertEquals(0, MicroVideoService::lists(1, 0, ['project_id' => 0], true)['count']);
    }
}
