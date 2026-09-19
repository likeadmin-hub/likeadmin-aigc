<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStoryDraft;
use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Runs only against the explicitly selected local database, always rolls back fixture data. */
class ShortDramaStoryDraftPersistenceTest extends TestCase
{
    private int $tenant = 2000000719;
    private bool $transaction = false;
    private string $taskId;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to transactional local DB tests');
        (new \think\App())->initialize();
        Db::startTrans(); $this->transaction = true;
        $this->taskId = 'test_story_' . bin2hex(random_bytes(8));
        $projectId = Db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => $this->tenant, 'user_id' => 7, 'multi_episode' => 1, 'episode_count' => 3, 'last_task_id' => $this->taskId]);
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id' => $this->tenant, 'user_id' => 7, 'project_id' => $projectId, 'task_id' => $this->taskId, 'status' => 'success',
            'request_json' => json_encode(['workflow_variant' => ShortDramaStoryWorkflow::VARIANT, 'multi_episode' => true, 'episode_count' => 3, 'multi_episode_stage' => 'story']),
            'result_json' => json_encode(['title' => '原始模型结果'])]);
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    public function testOutlineTitleSaveAfterHttpFiltering(): void
    {
        $scope = ['task_id' => $this->taskId];
        $row = Db::name('aigc_short_drama_script_task')->where($scope)->find();
        $request = json_decode($row['request_json'], true);
        $request['multi_episode_stage'] = 'episodes';
        $base = ['episodes' => array_map(fn($n) => ['episode_number' => $n, 'title' => '原始' . $n], range(1,3))];
        Db::name('aigc_short_drama_script_task')->where($scope)->update(['request_json' => json_encode($request), 'result_json' => json_encode($base)]);
        $edit = $base; $edit['episodes'][0]['title'] = '编辑后的标题';
        $http = new \app\Request();
        $http->withPost(['task_id' => $this->taskId, 'stage' => 'episodes', 'draft_version' => 0, 'result' => $edit]);
        $params = $http->post();
        self::assertSame('1', $params['result']['episodes'][0]['episode_number']);
        ShortDramaStoryDraft::save($this->tenant, 7, $params);
        $row = Db::name('aigc_short_drama_script_task')->where($scope)->find();
        self::assertSame($base, json_decode($row['result_json'], true));
        $saved = ShortDramaStoryDraft::effective(json_decode($row['request_json'], true), $base);
        self::assertSame($edit, $saved);
    }
    public function testSseSubscriptionPushesPreviewChangesWithoutCreatingGeneration(): void
    {
        $scope = ['tenant_id' => $this->tenant, 'task_id' => $this->taskId];
        $before = Db::name('aigc_short_drama_script_task')->count();
        Db::name('aigc_short_drama_script_task')->where($scope)->update(['status' => 'running', 'result_json' => json_encode([
            '__story_preview' => ['unit' => 'story', 'episodes' => [], 'content' => '{"story_outline":"第一段'],
        ])]);
        $previews = [];
        \app\common\service\app\aigc_short_drama\AigcShortDramaService::streamScriptPlan($this->tenant, 7, ['task_id' => $this->taskId],
            function ($event, $data) use (&$previews, $scope) {
                if ($event === 'preview') {
                    $previews[] = $data['story_workspace']['preview']['content'];
                    if (count($previews) === 1) Db::name('aigc_short_drama_script_task')->where($scope)->update(['result_json' => json_encode([
                        '__story_preview' => ['unit' => 'story', 'episodes' => [], 'content' => '{"story_outline":"第一段和第二段'],
                    ])]);
                    else Db::name('aigc_short_drama_script_task')->where($scope)->update(['status' => 'canceled']);
                }
            });
        self::assertSame(['{"story_outline":"第一段', '{"story_outline":"第一段和第二段'], $previews);
        self::assertSame($before, Db::name('aigc_short_drama_script_task')->count());
    }

    public function testCanceledStoryTaskCanContinueWithoutReplayingReceivedOrInFlightUnits(): void
    {
        $scope = ['tenant_id' => $this->tenant, 'user_id' => 7, 'task_id' => $this->taskId];
        $now = time();
        foreach (['received', 'failed', 'waiting', 'running'] as $index => $status) {
            Db::name('aigc_short_drama_planning_unit')->insert($scope + [
                'unit_key' => 'continue_' . $status,
                'status' => $status,
                'attempt' => 1,
                'request_json' => json_encode(['unit' => $status]),
                'result_json' => $status === 'received' ? json_encode(['ok' => true]) : '',
                'error' => $status === 'failed' ? 'fixture failure' : '',
                'create_time' => $now + $index,
                'update_time' => $now + $index,
            ]);
        }

        \app\common\service\app\aigc_short_drama\AigcShortDramaService::cancel($this->tenant, 7, $this->taskId);
        $canceled = Db::name('aigc_short_drama_script_task')->where($scope)->find();
        self::assertSame('canceled', $canceled['status']);
        self::assertSame(1, (int)$canceled['retry_count'], 'cancel fences callbacks from the prior execution epoch');

        try {
            \app\common\service\app\aigc_short_drama\AigcShortDramaService::retry($this->tenant, 7, $this->taskId);
            self::fail('Continue accepted while the stopped provider request was still in flight');
        } catch (\Exception $error) {
            self::assertStringContainsString('正在收尾', $error->getMessage());
        }
        self::assertSame('canceled', Db::name('aigc_short_drama_script_task')->where($scope)->value('status'));
        self::assertSame('running', Db::name('aigc_short_drama_planning_unit')->where($scope)->where('unit_key', 'continue_running')->value('status'));
        // Simulate the durable receipt being finalized after cancellation.
        Db::name('aigc_short_drama_planning_unit')->where($scope)->where('unit_key', 'continue_running')->update(['status' => 'failed', 'error' => 'stopped', 'update_time' => time()]);

        $resumed = \app\common\service\app\aigc_short_drama\AigcShortDramaService::retry($this->tenant, 7, $this->taskId);
        self::assertSame($this->taskId, $resumed['task_id']);
        $resumedTask = Db::name('aigc_short_drama_script_task')->where($scope)->find();
        self::assertSame('pending', $resumedTask['status']);
        self::assertSame(2, (int)$resumedTask['retry_count']);
        self::assertSame('pending', Db::name('aigc_short_drama_planning_unit')->where($scope)->where('unit_key', 'continue_failed')->value('status'));
        self::assertSame('pending', Db::name('aigc_short_drama_planning_unit')->where($scope)->where('unit_key', 'continue_waiting')->value('status'));
        self::assertSame('received', Db::name('aigc_short_drama_planning_unit')->where($scope)->where('unit_key', 'continue_received')->value('status'));
        self::assertSame('pending', Db::name('aigc_short_drama_planning_unit')->where($scope)->where('unit_key', 'continue_running')->value('status'));
    }

    public function testMessageRejectsConfirmedSourceAndWrongStageBeforeGeneration(): void
    {
        $projectId = Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->value('project_id');
        $before = Db::name('aigc_short_drama_script_task')->count();
        foreach (['stage', 'confirmed'] as $case) {
            if ($case === 'confirmed') Db::name('aigc_short_drama_project')->where('id', $projectId)->update(['last_task_id' => 'newer_outline']);
            try {
                \app\common\service\app\aigc_short_drama\AigcShortDramaService::message($this->tenant, 7, [
                    'task_id' => $this->taskId, 'message' => '修改剧情', 'mode' => 'revise_plan', 'draft_version' => 0, 'edit_stage' => 'episodes',
                ]);
                self::fail('Read-only or wrong stage revision accepted');
            } catch (\Exception $error) {
                self::assertStringContainsString($case === 'stage' ? '当前步骤不可修改' : '已确认', $error->getMessage());
            }
        }
        self::assertSame($before, Db::name('aigc_short_drama_script_task')->count());
    }

    public function testSaveReloadConflictIsolationAndNoGeneration(): void
    {
        $tasks = Db::name('aigc_short_drama_script_task')->count();
        $generationTasks = Db::name('aigc_short_drama_generation_task')->count();
        $params = ['task_id' => $this->taskId, 'stage' => 'story', 'draft_version' => 0, 'result' => ['title' => '用户编辑']];
        $saved = ShortDramaStoryDraft::save($this->tenant, 7, $params);
        self::assertSame(1, $saved['draft_version']);
        self::assertFalse($saved['can_confirm']);
        $task = Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->find();
        self::assertSame('原始模型结果', json_decode($task['result_json'], true)['title']);
        self::assertSame('用户编辑', json_decode($task['request_json'], true)['_story_draft']['result']['title']);
        foreach ([[$this->tenant, 7], [$this->tenant + 1, 7], [$this->tenant, 8]] as [$tenant, $user]) {
            try { ShortDramaStoryDraft::save($tenant, $user, $params); self::fail('Stale or foreign write accepted'); }
            catch (\InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertSame($tasks, Db::name('aigc_short_drama_script_task')->count());
        self::assertSame($generationTasks, Db::name('aigc_short_drama_generation_task')->count());
    }

    public function testSaveUsesTheSameSelectedSubjectCanonicalizationAsTheWorkspace(): void
    {
        $scope = ['task_id' => $this->taskId];
        $row = Db::name('aigc_short_drama_script_task')->where($scope)->find();
        $request = json_decode($row['request_json'], true);
        $request['subject_references'] = [[
            'id' => 9, 'name' => '美女', 'category' => 'character', 'gender' => 'female',
            'image' => '/subject.png', 'three_view_image' => '/subject-three-view.png',
        ]];
        $modelResult = [
            'subjects' => [
                ['id' => 'subject_1', 'name' => '林浅', 'category' => 'character', 'role' => '女主角', 'description' => '完整的生成角色'],
                ['id' => 'subject_2', 'name' => '陆远', 'category' => 'character', 'role' => '男主角'],
                ['id' => 'library_9', 'name' => '美女', 'library_subject_id' => '9', 'is_library_reference' => true],
            ],
            'locations' => [],
        ];
        Db::name('aigc_short_drama_script_task')->where($scope)->update([
            'request_json' => json_encode($request),
            'result_json' => json_encode($modelResult),
        ]);

        // This is the value sent by the workspace after it has bound the
        // selected library subject to the generated character and removed the
        // former empty placeholder card.
        $workspaceResult = [
            'subjects' => [
                ['id' => 'subject_1', 'name' => '美女', 'category' => 'character', 'role' => '女主角', 'description' => '完整的生成角色', 'library_subject_id' => '9'],
                ['id' => 'subject_2', 'name' => '陆远', 'category' => 'character', 'role' => '男主角'],
            ],
            'locations' => [],
        ];
        ShortDramaStoryDraft::save($this->tenant, 7, [
            'task_id' => $this->taskId, 'stage' => 'story', 'draft_version' => 0, 'result' => $workspaceResult,
        ]);

        $savedRequest = json_decode(Db::name('aigc_short_drama_script_task')->where($scope)->value('request_json'), true);
        $savedSubjects = $savedRequest['_story_draft']['result']['subjects'];
        self::assertCount(2, $savedSubjects);
        self::assertSame('美女', $savedSubjects[0]['name']);
        self::assertSame('9', (string)$savedSubjects[0]['library_subject_id']);
    }

    public function testEpisodeCountSaveUpdatesSubmissionSources(): void
    {
        ShortDramaStoryDraft::save($this->tenant, 7, ['task_id' => $this->taskId, 'stage' => 'story', 'draft_version' => 0, 'result' => ['episode_count' => 10]]);
        $row = Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->find();
        $request = json_decode($row['request_json'], true);
        self::assertSame(10, $request['episode_count']);
        self::assertSame(10, ShortDramaStoryDraft::effective($request, [])['episode_count']);
        self::assertSame(10, (int)Db::name('aigc_short_drama_project')->where('id', $row['project_id'])->value('episode_count'));
        foreach ([0, 1, 501, 2.5, '', '2.5', '101abc', true, [], '99999999999999999999999999'] as $invalid) {
            try { ShortDramaStoryDraft::merge([], ['episode_count' => $invalid], 'story'); self::fail('Invalid count accepted'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString('整数集数', $error->getMessage()); }
        }
    }

    public function testEpisodeCountAfterHttpRequestFiltering(): void
    {
        $http = new \app\Request();
        $http->withPost(['task_id' => $this->taskId, 'stage' => 'story', 'draft_version' => 0, 'result' => ['episode_count' => 101]]);
        $params = $http->post();
        self::assertSame('101', $params['result']['episode_count']);
        ShortDramaStoryDraft::save($this->tenant, 7, $params);
        $row = Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->find();
        $request = json_decode($row['request_json'], true);
        self::assertSame(101, $request['episode_count']);
        self::assertSame(101, $request['_story_draft']['result']['episode_count']);
        self::assertSame(101, (int)Db::name('aigc_short_drama_project')->where('id', $row['project_id'])->value('episode_count'));
    }

    public function testReceivedUnitIsReusedAfterWorkerRestartAndIsTenantScoped(): void
    {
        $calls = 0;
        $generate = static function () use (&$calls) { $calls++; return ['result' => ['content' => '{"episodes":[]}'], 'usage' => ['total_tokens' => 10]]; };
        $unit = \app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit::class;
        $first = $unit::call($this->tenant, 7, $this->taskId, 'outline_1_10', [], $generate);
        self::assertSame($first, $unit::call($this->tenant, 7, $this->taskId, 'outline_1_10', [], $generate));
        self::assertSame(1, $calls);
        $unit::call($this->tenant + 1, 7, $this->taskId, 'outline_1_10', [], $generate);
        self::assertSame(2, $calls);
    }

    public function testOnlyPreSubmissionConnectionFailuresAreRetryable(): void
    {
        $unit = \app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit::class;
        self::assertTrue($unit::retryableBeforeSubmission('OpenSSL SSL_connect: SSL_ERROR_SYSCALL'));
        self::assertFalse($unit::retryableBeforeSubmission('Operation timed out after response started'));
        self::assertFalse($unit::retryableBeforeSubmission('余额不足'));
        self::assertFalse($unit::retryableBeforeSubmission('内容安全审核失败'));
    }

    public function testConfirmedStoryCannotBeReopenedAndConfirmedQueueLocksEditing(): void
    {
        $row = Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->find();
        $request = json_decode($row['request_json'], true);
        $request['multi_episode_stage'] = 'episodes';
        $request['confirmed_story_snapshot'] = ['title' => '确认时的设定'];
        Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->update(['request_json' => json_encode($request)]);
        try {
            ShortDramaStoryDraft::save($this->tenant, 7, ['task_id' => $this->taskId, 'action' => 'reopen_story', 'draft_version' => 0]);
            self::fail('Confirmed story must remain locked');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('故事设定已确认，不能继续编辑', $error->getMessage());
        }
        $row = Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->find();
        $request = json_decode($row['request_json'], true);
        self::assertArrayNotHasKey('_story_outline_obsolete', $request);
        self::assertSame('episodes', ShortDramaStoryDraft::stage($request));
        self::assertSame('确认时的设定', $request['confirmed_story_snapshot']['title']);
        self::assertSame('原始模型结果', json_decode($row['result_json'], true)['title']);
        Db::name('aigc_short_drama_episode_task')->insert(['tenant_id' => $this->tenant, 'user_id' => 7, 'project_id' => $row['project_id'], 'episode_number' => 1, 'title' => 'fixture']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('前置内容只读');
        ShortDramaStoryDraft::save($this->tenant, 7, ['task_id' => $this->taskId, 'stage' => 'story', 'draft_version' => 1, 'result' => ['title' => '越过确认修改']]);
    }

    public function testConfirmUsesSavedOutlineAndReplayDoesNotCreateMoreEpisodes(): void
    {
        $redis = new \ReflectionProperty(\app\common\service\app\aigc_short_drama\ShortDramaRedisQueue::class, 'redis');
        $redis->setAccessible(true); $previous = $redis->getValue();
        $redis->setValue(null, $this->createMock(\Redis::class));
        try {
            $row = Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->find();
            $request = json_decode($row['request_json'], true);
            $request['multi_episode_stage'] = 'episodes';
            $plan = ['title' => '测试', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '寻找证据',
                'subjects' => [['id' => 's1', 'name' => '记者']], 'locations' => [['id' => 'l1', 'name' => '街道']], 'episodes' => []];
            for ($i = 1; $i <= 3; $i++) $plan['episodes'][] = ['episode_number' => $i, 'title' => '原始标题', 'story_outline' => '线索' . $i, 'conflict_point' => '隐藏证据', 'ending_hook' => '新的证人'];
            Db::name('aigc_short_drama_script_task')->where('task_id', $this->taskId)->update(['request_json' => json_encode($request), 'result_json' => json_encode($plan)]);
            $plan['episodes'][2]['title'] = '草稿的第三集';
            ShortDramaStoryDraft::save($this->tenant, 7, ['task_id' => $this->taskId, 'stage' => 'episodes', 'draft_version' => 0, 'result' => $plan]);
            $params = ['project_id' => $row['project_id'], 'task_id' => $this->taskId, 'draft_version' => 1];
            $service = \app\common\service\app\aigc_short_drama\ShortDramaEpisodeService::class;
            $first = $service::start($this->tenant, 7, $params);
            $again = $service::start($this->tenant, 7, $params);
            self::assertSame(array_column($first['lists'], 'id'), array_column($again['lists'], 'id'));
            self::assertCount(3, $first['lists']);
            self::assertSame('草稿的第三集', $first['lists'][2]['title']);
            $snapshot = Db::name('aigc_short_drama_episode_task')->where(['tenant_id' => $this->tenant, 'project_id' => $row['project_id'], 'episode_number' => 1])->value('series_json');
            self::assertSame(1, json_decode($snapshot, true)['request']['confirmed_outline_version']);
        } finally { $redis->setValue(null, $previous); }
    }
}
