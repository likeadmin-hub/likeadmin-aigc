<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use app\common\model\app\aigc_short_drama\AigcShortDramaAsset;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Local MySQL integration tests. All fixtures are rolled back and no provider is called. */
class ShortDramaEpisodeQueueTest extends TestCase
{
    private const TENANT = 90000909;
    private const USER = 90000909;

    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') !== '1') $this->markTestSkipped('Set SHORT_DRAMA_DB_TESTS=1 for transactional MySQL tests');
        (new \think\App())->initialize();
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') === '1') Db::rollback();
    }

    public static function counts(): array { return [[2], [3], [10], [500]]; }

    private function outline(int $count): array
    {
        $episodes = [];
        for ($i = 1; $i <= $count; $i++) $episodes[] = ['episode_number' => $i, 'title' => '测试第' . $i . '集',
            'story_outline' => '主角找到第' . $i . '条线索', 'conflict_point' => '证人隐瞒线索', 'ending_hook' => '出现新的证据'];
        return ['title' => '事务测试短剧', 'type_judgement' => '悬疑', 'core_theme' => '寻找真相', 'story_outline' => '主角追查失踪事件',
            'subjects' => [['id' => 'subject_1', 'name' => '调查员', 'description' => '调查失踪案']],
            'locations' => [['id' => 'location_1', 'name' => '街道', 'description' => '夜晚街道']], 'episodes' => $episodes, 'storyboard' => []];
    }

    private function project(int $count): array
    {
        $taskId = 'episode_test_' . bin2hex(random_bytes(8));
        $project = Db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => self::TENANT, 'user_id' => self::USER,
            'title' => 'Queue fixture', 'prompt' => 'test', 'multi_episode' => 1, 'episode_count' => $count,
            'last_task_id' => $taskId, 'create_time' => time(), 'update_time' => time()]);
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'project_id' => $project,
            'task_id' => $taskId, 'status' => 'success', 'progress' => 100, 'result_json' => json_encode($this->outline($count)),
            'request_json' => json_encode(['prompt' => 'test', 'multi_episode' => true, 'episode_count' => $count, 'episode_workflow' => 'outline_queue']),
            'create_time' => time(), 'update_time' => time()]);
        return ['project_id' => $project, 'task_id' => $taskId];
    }

    /** @dataProvider counts */
    public function testConfirmCreatesExactlyNRowsAndIsIdempotent(int $count): void
    {
        $project = $this->project($count);
        $first = Episodes::start(self::TENANT, self::USER, $project);
        $again = Episodes::start(self::TENANT, self::USER, $project);
        self::assertCount($count, $first['lists']);
        self::assertSame(array_column($first['lists'], 'id'), array_column($again['lists'], 'id'));
        self::assertSame(range(1, $count), array_map('intval', array_column($first['lists'], 'episode_number')));
        self::assertSame(['pending'], array_values(array_unique(array_column($first['lists'], 'status'))));
        self::assertSame(1, (int)Episodes::nextInitialEpisode($first['lists'])['episode_number']);
    }

    public function testHistoricalOutlineCanBeConfirmedWithItsOwnEpisodeCount(): void
    {
        // Stub Redis so this transactional fixture never publishes a real job.
        $redisProperty = new \ReflectionProperty(\app\common\service\app\aigc_short_drama\ShortDramaRedisQueue::class, 'redis');
        $redisProperty->setAccessible(true);
        $previousRedis = $redisProperty->getValue();
        $redisProperty->setValue(null, $this->createMock(\Redis::class));
        try {
            $old = $this->project(3);
            $latestTaskId = $old['task_id'] . '_revision';
            Db::name('aigc_short_drama_project')->where('id', $old['project_id'])->update(['last_task_id' => $latestTaskId, 'episode_count' => 4]);
            $task = Db::name('aigc_short_drama_script_task')->where('task_id', $old['task_id'])->find();
            $format = new \ReflectionMethod(AigcShortDramaService::class, 'formatTask');
            $format->setAccessible(true);
            $detail = $format->invoke(null, $task, true);
            self::assertSame(3, $detail['result']['episode_count']);
            self::assertSame('事务测试短剧', $detail['result']['title']);

            $result = Episodes::start(self::TENANT, self::USER, $old);
            self::assertCount(3, $result['lists']);
            self::assertSame($old['task_id'], $result['outline_task_id']);
            self::assertSame([$old['task_id']], array_values(array_unique(array_column($result['lists'], 'outline_task_id'))));
            $again = Episodes::start(self::TENANT, self::USER, $old);
            self::assertSame(array_column($result['lists'], 'id'), array_column($again['lists'], 'id'));

            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('剧集已按其他大纲版本创建');
            Episodes::start(self::TENANT, self::USER, ['project_id' => $old['project_id'], 'task_id' => $latestTaskId]);
        } finally {
            $redisProperty->setValue(null, $previousRedis);
        }
    }

    public function testRevisionClearsOldAttemptStateAndPreservesSavedResult(): void
    {
        $project = $this->project(3);
        $saved = json_encode(['story_outline' => '原剧情']);
        $id = Db::name('aigc_short_drama_episode_task')->insertGetId([
            'tenant_id' => self::TENANT, 'user_id' => self::USER, 'project_id' => $project['project_id'],
            'production_project_id' => $project['project_id'], 'episode_number' => 1, 'title' => '已完成集',
            'outline_task_id' => $project['task_id'], 'outline_json' => '{}', 'task_id' => $project['task_id'],
            'status' => 'success', 'completed_once' => 1, 'result_json' => $saved, 'continuity_json' => $saved,
            'attempt_number' => 4, 'queue_job_id' => 42, 'cancel_requested' => 1, 'retry_count' => 2,
            'next_retry_at' => time() + 120, 'finished_at' => time(), 'create_time' => time(), 'update_time' => time(),
        ]);
        Episodes::revisionQueued(self::TENANT, self::USER, $project['project_id'], 'new_revision');
        $row = Db::name('aigc_short_drama_episode_task')->where('id', $id)->find();
        self::assertSame('pending', $row['status']);
        self::assertSame('new_revision', $row['task_id']);
        self::assertSame(5, (int)$row['attempt_number']);
        foreach (['queue_job_id', 'cancel_requested', 'retry_count', 'next_retry_at', 'finished_at'] as $field) self::assertSame(0, (int)$row[$field]);
        self::assertSame($saved, $row['result_json']);
        self::assertSame($saved, $row['continuity_json']);
        self::assertSame(1, (int)$row['completed_once']);
        Episodes::revisionQueued(self::TENANT + 1, self::USER, $project['project_id'], 'foreign_revision');
        self::assertSame('new_revision', Db::name('aigc_short_drama_episode_task')->where('id', $id)->value('task_id'));
    }

    public function testFailurePausesFollowingEpisodesAndRetryOfPendingAttemptResumesOrder(): void
    {
        $project = $this->project(3);
        $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
        Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->update(['status' => 'success', 'completed_once' => 1]);
        Db::name('aigc_short_drama_episode_task')->where('id', $rows[1]['id'])->update(['status' => 'failed', 'error' => 'fixture']);
        self::assertSame(0, Episodes::tick(self::TENANT, $project['project_id']));
        $list = Episodes::lists(self::TENANT, self::USER, $project['project_id']);
        self::assertTrue($list['paused']);
        self::assertSame('pending', $list['lists'][2]['status']);
        Episodes::retry(self::TENANT, self::USER, $rows[1]['id']);
        $list = Episodes::lists(self::TENANT, self::USER, $project['project_id']);
        self::assertFalse($list['paused']);
        self::assertSame(2, (int)Episodes::nextInitialEpisode($list['lists'])['episode_number']);
        self::assertSame(1, (int)$list['lists'][1]['retry_count']);
        Db::name('aigc_short_drama_episode_task')->where('id', $rows[1]['id'])->update(['status' => 'success', 'completed_once' => 1]);
        self::assertSame(3, (int)Episodes::nextInitialEpisode(Episodes::lists(self::TENANT, self::USER, $project['project_id'])['lists'])['episode_number']);
    }

    public function testCancelIsIdempotentInEffectAndBlocksSubsequentDispatch(): void
    {
        $project = $this->project(3);
        $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
        Episodes::cancel(self::TENANT, self::USER, $rows[0]['id']);
        self::assertSame(0, Episodes::tick(self::TENANT, $project['project_id']));
        $list = Episodes::lists(self::TENANT, self::USER, $project['project_id']);
        self::assertSame(['canceled', 'pending', 'pending'], array_column($list['lists'], 'status'));
        self::assertTrue($list['paused']);
    }

    public function testCancelAllAllowsOnlyExplicitlyRetriedEpisodeOutOfOrder(): void
    {
        $redis = new \ReflectionProperty(\app\common\service\app\aigc_short_drama\ShortDramaRedisQueue::class, 'redis');
        $redis->setAccessible(true);
        $previous = $redis->getValue();
        $redis->setValue(null, $this->createMock(\Redis::class));
        try {
            $project = $this->project(3);
            $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
            $result = Episodes::cancelAll(self::TENANT, self::USER, $project['project_id']);
            self::assertSame(['canceled', 'canceled', 'canceled'], array_column($result['lists'], 'status'));
            self::assertTrue($result['manual_generation']);
            self::assertSame(0, Episodes::tick(self::TENANT, $project['project_id']));
            self::assertSame(array_column($result['lists'], 'status'), array_column(Episodes::cancelAll(self::TENANT, self::USER, $project['project_id'])['lists'], 'status'));
            Episodes::retry(self::TENANT, self::USER, (int)$rows[2]['id']);
            $raw = Db::name('aigc_short_drama_episode_task')->where('project_id', $project['project_id'])->order('episode_number')->select()->toArray();
            self::assertSame(['canceled', 'canceled', 'pending'], array_column($raw, 'status'));
            self::assertSame((int)$rows[2]['id'], (int)Episodes::nextRunnableEpisode($raw)['id']);
            Db::name('aigc_short_drama_episode_task')->where('id', $rows[2]['id'])->update(['status' => 'success', 'completed_once' => 1]);
            $raw = Db::name('aigc_short_drama_episode_task')->where('project_id', $project['project_id'])->select()->toArray();
            self::assertNull(Episodes::nextRunnableEpisode($raw));
        } finally { $redis->setValue(null, $previous); }
    }

    public function testCancelAllPreservesCompletedAndRequestsRunningCancellation(): void
    {
        $redis = new \ReflectionProperty(\app\common\service\app\aigc_short_drama\ShortDramaRedisQueue::class, 'redis');
        $redis->setAccessible(true);
        $previous = $redis->getValue();
        $redis->setValue(null, $this->createMock(\Redis::class));
        try {
            $project = $this->project(3);
            $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
            Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->update(['status' => 'success', 'completed_once' => 1, 'result_json' => '{"kept":true}']);
            Db::name('aigc_short_drama_episode_task')->where('id', $rows[1]['id'])->update(['status' => 'running']);
            $result = Episodes::cancelAll(self::TENANT, self::USER, $project['project_id']);
            self::assertSame(['success', 'running', 'canceled'], array_column($result['lists'], 'status'));
            self::assertSame(2, (int)$result['lists'][1]['cancel_requested']);
            self::assertSame('{"kept":true}', Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->value('result_json'));
            try {
                Episodes::cancelAll(self::TENANT + 1, self::USER, $project['project_id']);
                self::fail('Cross-tenant cancel-all accepted');
            } catch (\Exception $e) { self::assertSame('项目不存在', $e->getMessage()); }
        } finally { $redis->setValue(null, $previous); }
    }

    public function testCancelAllRunningCompletionIsRetainedWithoutDispatchingNextEpisode(): void
    {
        $redis = new \ReflectionProperty(\app\common\service\app\aigc_short_drama\ShortDramaRedisQueue::class, 'redis');
        $redis->setAccessible(true);
        $previous = $redis->getValue();
        $mockRedis = $this->createMock(\Redis::class);
        $mockRedis->method('set')->willReturn(true);
        $redis->setValue(null, $mockRedis);
        try {
            $project = $this->project(3);
            $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
            $child = Db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'title' => 'finished fixture']);
            $taskId = 'cancel_all_done_' . bin2hex(random_bytes(8));
            $plan = $this->outline(3);
            $plan['storyboard'] = [['shot_id' => '1', 'visual_description' => '已生成的画面']];
            Db::name('aigc_short_drama_script_task')->insert(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'project_id' => $child,
                'task_id' => $taskId, 'status' => 'success', 'progress' => 100, 'result_json' => json_encode($plan), 'request_json' => '{}']);
            Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->update(['status' => 'running', 'task_id' => $taskId, 'production_project_id' => $child]);
            Episodes::cancelAll(self::TENANT, self::USER, $project['project_id']);
            self::assertSame(1, Episodes::tick(self::TENANT, $project['project_id']));
            $list = Episodes::lists(self::TENANT, self::USER, $project['project_id']);
            self::assertSame(['success', 'canceled', 'canceled'], array_column($list['lists'], 'status'));
            self::assertTrue($list['lists'][0]['ready']);
            self::assertSame(0, Episodes::tick(self::TENANT, $project['project_id']));
            self::assertSame(1, (int)Db::name('aigc_short_drama_script_task')->where('project_id', $child)->count());
        } finally { $redis->setValue(null, $previous); }
    }

    public function testTenantCannotReadOrMutateAnotherTenantsEpisodes(): void
    {
        $project = $this->project(2);
        $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
        foreach (['detail', 'retry', 'cancel'] as $method) {
            try { Episodes::$method(self::TENANT + 1, self::USER, $rows[0]['id']); self::fail('Cross-tenant operation accepted'); }
            catch (\Exception $e) { self::assertSame('剧集不存在', $e->getMessage()); }
        }
        self::assertSame([], Episodes::context(self::TENANT + 1, self::USER, 0));
        self::assertSame([], Episodes::context(self::TENANT, self::USER, 0));
    }

    public function testConfirmedOutlineCannotBeEditedThroughProductionEndpoints(): void
    {
        $project = $this->project(2);
        Episodes::start(self::TENANT, self::USER, $project);
        foreach (['saveVisualPlan', 'saveStoryboard', 'copyStoryboardShot', 'deleteStoryboardShot'] as $method) {
            try {
                AigcShortDramaService::$method(self::TENANT, self::USER, $project + ['shot_id' => '1', 'shots' => [['shot_id' => '1', 'visual_description' => '不可写入']]]);
                self::fail('Confirmed outline was editable');
            } catch (\Exception $e) { self::assertSame('大纲已确认，请进入对应剧集修改', $e->getMessage()); }
        }
        self::assertSame(0, (int)Db::name('aigc_short_drama_storyboard')->where('project_id', $project['project_id'])->count());
    }

    public function testAssetsReceiveEpisodeOwnershipAndOtherEpisodesRemainUntouched(): void
    {
        $project = $this->project(2);
        $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
        $child = Db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'title' => 'child']);
        Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->update(['production_project_id' => $child]);
        $asset = AigcShortDramaAsset::create(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'project_id' => $child,
            'asset_type' => 'shot_image', 'uri' => 'test/episode.png', 'storage_scope' => 'tenant', 'storage_engine' => 'local', 'storage_domain' => '']);
        self::assertSame((int)$rows[0]['id'], (int)$asset['episode_id']);
        self::assertSame(1, (int)$asset['episode_number']);
        self::assertSame(0, (int)Db::name('aigc_short_drama_asset')->where('episode_id', $rows[1]['id'])->count());
        self::assertSame([$child], array_map('intval', Episodes::productionIds(self::TENANT, self::USER)));
    }

    public function testIncompleteOutlineIsRejectedBeforeAnyQueueRowsAreCreated(): void
    {
        $project = $this->project(3);
        $plan = $this->outline(3); unset($plan['episodes'][1]['conflict_point']);
        Db::name('aigc_short_drama_script_task')->where('task_id', $project['task_id'])->update(['result_json' => json_encode($plan)]);
        try { Episodes::start(self::TENANT, self::USER, $project); self::fail('Incomplete outline accepted'); }
        catch (\Exception $e) { self::assertStringContainsString('大纲不完整', $e->getMessage()); }
        self::assertSame(0, (int)Db::name('aigc_short_drama_episode_task')->where('project_id', $project['project_id'])->count());
    }

    public function testBatchExportReusesActiveTasksInEpisodeOrderAndExcludesIncompleteEpisodes(): void
    {
        $project = $this->project(3);
        $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
        foreach ([1, 0] as $index) {
            $child = Db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'title' => 'export fixture']);
            Db::name('aigc_short_drama_episode_task')->where('id', $rows[$index]['id'])->update(['production_project_id' => $child, 'status' => 'success', 'completed_once' => 1]);
            Db::name('aigc_short_drama_generation_task')->insert(['tenant_id' => self::TENANT, 'user_id' => self::USER,
                'project_id' => $child, 'task_id' => 'episode_export_fixture_' . $child, 'task_type' => 'export_package', 'status' => 'pending']);
        }
        $first = Episodes::export(self::TENANT, self::USER, $project + ['type' => 'export_package']);
        $again = Episodes::export(self::TENANT, self::USER, $project + ['type' => 'export_package']);
        self::assertSame([1, 2], array_map('intval', array_column($first['lists'], 'episode_number')));
        self::assertSame($first, $again);
        self::assertSame(2, (int)Db::name('aigc_short_drama_generation_task')->where('tenant_id', self::TENANT)->count());
        $single = Episodes::export(self::TENANT, self::USER, $project + ['type' => 'export_package', 'episode_id' => $rows[1]['id']]);
        self::assertSame([2], array_map('intval', array_column($single['lists'], 'episode_number')));
    }

    public function testWorkerConsumesPersistedCompletionOnceAndAdvancesWithoutProviderResubmission(): void
    {
        $project = $this->project(3);
        $rows = Episodes::start(self::TENANT, self::USER, $project)['lists'];
        $plan = $this->outline(2);
        $plan['multi_episode'] = false; $plan['episode_count'] = 1; $plan['episodes'] = [];
        $plan['multi_episode_stage'] = 'production';
        $plan['storyboard'] = [['shot_id' => '1', 'episode_number' => 1, 'visual_description' => '主角找到线索']];
        $child = Db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'title' => 'child', 'multi_episode' => 0, 'episode_count' => 1]);
        $taskId = 'episode_done_' . bin2hex(random_bytes(8));
        Db::name('aigc_short_drama_project')->where('id', $child)->update(['last_task_id' => $taskId]);
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'project_id' => $child,
            'task_id' => $taskId, 'status' => 'success', 'progress' => 100, 'result_json' => json_encode($plan),
            'request_json' => json_encode(['multi_episode' => false, 'episode_count' => 1]), 'update_time' => time()]);
        Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->update(['status' => 'running', 'task_id' => $taskId, 'production_project_id' => $child, 'cancel_requested' => 1]);
        self::assertSame(1, Episodes::tick(self::TENANT, $project['project_id']));
        $list = Episodes::lists(self::TENANT, self::USER, $project['project_id']);
        self::assertTrue($list['lists'][0]['ready']);
        self::assertSame('canceled', $list['lists'][0]['status']);
        self::assertSame(0, Episodes::tick(self::TENANT, $project['project_id']));
        $resumed = Episodes::retry(self::TENANT, self::USER, $rows[0]['id']);
        self::assertSame($taskId, $resumed['task_id']);
        self::assertSame('success', $resumed['status']);
        self::assertSame(1, (int)Db::name('aigc_short_drama_script_task')->where('project_id', $child)->count());
        $list = Episodes::lists(self::TENANT, self::USER, $project['project_id']);
        self::assertSame(2, (int)Episodes::nextInitialEpisode($list['lists'])['episode_number']);
    }

    public function testLegacyImportKeepsRealEpisodesAndMarksMissingEpisodeFailed(): void
    {
        $project = $this->project(3);
        $plan = $this->outline(3);
        $plan['multi_episode'] = true; $plan['episode_count'] = 3;
        for ($i = 1; $i <= 4; $i++) $plan['storyboard'][] = ['shot_id' => 'ep1_' . $i, 'episode_number' => 1,
            'title' => '镜头' . $i, 'scene_ref_id' => 'location_1', 'visual_description' => '主角找到线索' . $i,
            'subject_ref_ids' => ['subject_1'], 'recommended_duration_seconds' => 3];
        $plan['storyboard'][] = ['shot_id' => 'fake', 'episode_number' => 2, 'title' => '第2集代表分镜', 'visual_description' => '复制内容'];
        Db::name('aigc_short_drama_script_task')->where('task_id', $project['task_id'])->update(['result_json' => json_encode($plan)]);
        $list = Episodes::lists(self::TENANT, self::USER, $project['project_id']);
        self::assertCount(3, $list['lists']);
        self::assertSame(['success', 'failed', 'failed'], array_column($list['lists'], 'status'));
        $detail = Episodes::detail(self::TENANT, self::USER, $list['lists'][0]['id']);
        self::assertCount(4, $detail['result']['storyboard']);
        self::assertSame(4, (int)Db::name('aigc_short_drama_storyboard')->where('episode_id', $detail['id'])->where('delete_time', 0)->count());
        self::assertCount(3, Episodes::lists(self::TENANT, self::USER, $project['project_id'])['lists']);
    }

    public function testRealTaskCreationRevisionAndRetryKeepTheSameProductionProject(): void
    {
        // The configured local tenant supplies model metadata only; no stream is dispatched.
        $parent = AigcShortDramaService::createScriptPlan(1, 1, ['prompt' => '不调用模型的事务测试', 'multi_episode' => true, 'episode_count' => 2]);
        $outline = $this->outline(2);
        Db::name('aigc_short_drama_script_task')->where('task_id', $parent['task_id'])->update(['status' => 'success', 'result_json' => json_encode($outline)]);
        $list = Episodes::start(1, 1, $parent);
        $row = Db::name('aigc_short_drama_episode_task')->where('id', $list['lists'][0]['id'])->find();
        $parentTask = Db::name('aigc_short_drama_script_task')->where('task_id', $parent['task_id'])->find();
        $created = AigcShortDramaService::createEpisodeProduction(1, 1, $row, Episodes::decode($parentTask['request_json']), $outline, []);
        Db::name('aigc_short_drama_episode_task')->where('id', $row['id'])->update(['production_project_id' => $created['project_id'], 'task_id' => $created['task_id']]);
        $stored = Db::name('aigc_short_drama_script_task')->where('task_id', $created['task_id'])->find();
        $request = Episodes::decode($stored['request_json']);
        self::assertSame($row['id'], $request['episode_id']);
        self::assertFalse($request['multi_episode']);
        self::assertSame(1, $request['episode_count']);
        self::assertSame($outline, $request['series_context']['outline']);
        self::assertSame((int)$row['id'], (int)$stored['episode_id']);
        $observed = AigcShortDramaService::streamScriptPlan(1, 1, $created, static function () {});
        self::assertSame('pending', $observed['status']);
        self::assertSame('', Db::name('aigc_short_drama_script_task')->where('task_id', $created['task_id'])->value('provider_request_id'));
        $result = $outline;
        $result['multi_episode'] = false; $result['episodes'] = []; $result['episode_count'] = 1;
        $result['script_lines'] = ['调查员进入街道'];
        $result['storyboard'] = [['shot_id' => '1', 'visual_description' => '调查员进入街道']];
        Db::name('aigc_short_drama_script_task')->where('task_id', $created['task_id'])->update(['status' => 'success', 'result_json' => json_encode($result)]);
        Db::name('aigc_short_drama_episode_task')->where('id', $row['id'])->update(['status' => 'success', 'completed_once' => 1]);
        $revision = Episodes::message(1, 1, ['episode_id' => $row['id'], 'message' => '把本集开场改为雨天']);
        $revisionTask = Db::name('aigc_short_drama_script_task')->where('task_id', $revision['task_id'])->find();
        self::assertSame((int)$created['project_id'], (int)$revisionTask['project_id']);
        self::assertSame($outline, Episodes::decode($revisionTask['request_json'])['series_context']['outline']);
        Db::name('aigc_short_drama_script_task')->where('task_id', $revision['task_id'])->update(['status' => 'failed']);
        Db::name('aigc_short_drama_episode_task')->where('id', $row['id'])->update(['status' => 'failed']);
        $retry = Episodes::retry(1, 1, $row['id']);
        self::assertSame((int)$created['project_id'], (int)$retry['project_id']);
        self::assertNotSame($revision['task_id'], $retry['task_id']);
        $retryRequest = Episodes::decode(Db::name('aigc_short_drama_script_task')->where('task_id', $retry['task_id'])->value('request_json'));
        self::assertSame('把本集开场改为雨天', $retryRequest['revision_message']);
        self::assertSame('pending', Episodes::detail(1, 1, $list['lists'][1]['id'])['status']);
    }
}
