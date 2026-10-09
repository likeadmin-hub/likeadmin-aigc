<?php
namespace Tests\Feature;

use app\common\command\ShortDramaPlanningWorker;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaPlanningWorkerQueueTest extends TestCase
{
    public function testWaitingPageDoesNotHideLaterTasksAndExplicitTaskFilterStillApplies(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        (new \think\App())->initialize(); Db::startTrans();
        try {
            $prefix = 'planning_page_' . bin2hex(random_bytes(8)); $ids = [];
            for ($i = 0; $i < 21; $i++) $ids[] = Db::name('aigc_short_drama_script_task')->insertGetId([
                'tenant_id' => 2000000792, 'user_id' => 7, 'project_id' => 0, 'task_id' => $prefix . $i,
                'status' => 'queued', 'request_json' => json_encode(['_generation_version' => 3, '_pipeline_version' => 1]),
            ]);
            $select = new \ReflectionMethod(ShortDramaPlanningWorker::class, 'candidateTasks'); $select->setAccessible(true);
            $first = $select->invoke(null, '', $ids[0] - 1);
            self::assertCount(20, $first);
            $second = $select->invoke(null, '', (int)end($first)['id']);
            self::assertSame($prefix . '20', $second[0]['task_id']);
            $restricted = $select->invoke(null, $prefix . '20', 0);
            self::assertCount(1, $restricted); self::assertSame($ids[20], (int)$restricted[0]['id']);
            self::assertSame([], $select->invoke(null, $prefix . '20', $ids[20]));
        } finally { Db::rollback(); }
    }
}
