<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaProject;
use app\common\model\app\aigc_short_drama\AigcShortDramaScriptTask;
use think\facade\Db;
use Exception;

/** A series owns independent single-film production workspaces through this mapping. */
class ShortDramaEpisodeService
{
    private const TABLE = 'aigc_short_drama_episode_task';

    private static function queueJob(int $tenantId, int $userId, int $projectId, int $episodeId, int $attempt = 0): void
    {
        $now = time();
        $key = $tenantId . ':' . $episodeId . ':episode:' . $attempt;
        $exists = Db::name('aigc_short_drama_episode_job')->where(['tenant_id' => $tenantId, 'episode_id' => $episodeId, 'job_type' => 'episode', 'attempt' => $attempt])->find();
        if ($exists) return;
        $jobId = Db::name('aigc_short_drama_episode_job')->insertGetId(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
            'episode_id' => $episodeId, 'job_type' => 'episode', 'status' => 'pending', 'attempt' => $attempt,
            'idempotency_key' => $key, 'next_run_time' => $now, 'create_time' => $now, 'update_time' => $now]);
        Db::name(self::TABLE)->where('id', $episodeId)->update(['queue_job_id' => $jobId, 'attempt_number' => $attempt, 'update_time' => $now]);
        ShortDramaRedisQueue::enqueue($tenantId, $projectId, $episodeId, $attempt);
    }

    public static function decode($json): array
    {
        return is_array($json) ? $json : (json_decode((string)$json, true) ?: []);
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function productionIds(int $tenantId, int $userId): array
    {
        return Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])
            ->where('production_project_id', '>', 0)->column('production_project_id');
    }

    public static function context(int $tenantId, int $userId, int $productionId): array
    {
        if ($productionId <= 0) return [];
        return Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId,
            'production_project_id' => $productionId, 'delete_time' => 0])->find() ?: [];
    }

    private static function project(int $tenantId, int $userId, int $id): array
    {
        $row = AigcShortDramaProject::where(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->findOrEmpty();
        if ($row->isEmpty()) throw new Exception('项目不存在');
        return $row->toArray();
    }

    private static function episode(int $tenantId, int $userId, int $id): array
    {
        $row = Db::name(self::TABLE)->where(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->find();
        if (!$row) throw new Exception('剧集不存在');
        self::project($tenantId, $userId, (int)$row['project_id']);
        return $row;
    }

    public static function validateOutline(array $plan, int $count): array
    {
        foreach (['title', 'type_judgement', 'story_outline', 'core_theme'] as $field) {
            if (trim((string)($plan[$field] ?? '')) === '') throw new Exception('大纲内容不完整，请补充后确认');
        }
        if (empty($plan['subjects']) || empty($plan['locations'])) throw new Exception('大纲缺少角色或场景，请补充后确认');
        $episodes = (array)($plan['episodes'] ?? []);
        if (count($episodes) !== $count || $count < 2 || $count > 500) throw new Exception('大纲集数不完整，请重新生成');
        foreach (array_values($episodes) as $index => $episode) {
            if ((int)($episode['episode_number'] ?? 0) !== $index + 1) throw new Exception('大纲集号不连续，请重新生成');
            foreach (['title', 'story_outline', 'conflict_point', 'ending_hook'] as $field) {
                if (trim((string)($episode[$field] ?? '')) === '') throw new Exception('第' . ($index + 1) . '集大纲不完整');
            }
        }
        return array_values($episodes);
    }

    public static function start(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        Db::transaction(function () use ($tenantId, $userId, $projectId, $params) {
            $project = AigcShortDramaProject::where(['id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->lock(true)->findOrEmpty();
            if ($project->isEmpty() || !(int)$project['multi_episode']) throw new Exception('多集项目不存在');
            if (Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'project_id' => $projectId, 'delete_time' => 0])->count()) return;
            $taskId = (string)($params['task_id'] ?? $project['last_task_id']);
            if ($taskId !== (string)$project['last_task_id']) throw new Exception('大纲已更新，请刷新后确认最新版本');
            $task = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'task_id' => $taskId, 'status' => 'success', 'delete_time' => 0])->findOrEmpty();
            if ($task->isEmpty()) throw new Exception('请等待大纲生成完成');
            $plan = self::decode($task['result_json']);
            $episodes = self::validateOutline($plan, (int)$project['episode_count']);
            foreach ($episodes as $index => $outline) {
                Db::name(self::TABLE)->insert([
                    'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
                    'episode_number' => $index + 1, 'title' => mb_substr($outline['title'], 0, 120),
                    'outline_task_id' => $taskId, 'outline_json' => self::encode($outline),
                    'series_json' => $index === 0 ? self::encode(['plan' => $plan, 'request' => self::decode($task['request_json'])]) : null,
                    'status' => 'pending', 'error' => '', 'create_time' => time(), 'update_time' => time(),
                ]);
            }
        });
        $rows = Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'delete_time' => 0])->order('episode_number')->select()->toArray();
        if ($rows) self::queueJob($tenantId, $userId, $projectId, (int)$rows[0]['id'], 0);
        return self::lists($tenantId, $userId, $projectId);
    }

    public static function lists(int $tenantId, int $userId, int $projectId): array
    {
        $project = self::project($tenantId, $userId, $projectId);
        self::importLegacy($tenantId, $userId, $project);
        $rows = Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'delete_time' => 0])->order('episode_number')->select()->toArray();
        $storyboardCovers = AigcShortDramaService::episodeStoryboardCovers(
            $tenantId,
            $userId,
            array_column($rows, 'production_project_id')
        );
        $rows = self::withContinuityStatus($tenantId, $userId, $rows);
        $completed = count(array_filter($rows, static fn($r) => (int)$r['completed_once'] === 1));
        $first = self::nextInitialEpisode($rows);
        return ['project_id' => $projectId, 'title' => $project['title'], 'multi_episode' => (bool)$project['multi_episode'],
            'episode_count' => (int)$project['episode_count'], 'outline_task_id' => $project['last_task_id'],
            'completed_count' => $completed, 'started' => count($rows) > 0,
            'paused' => $first && (in_array($first['status'], ['failed', 'canceled'], true) || !empty($first['needs_review'])),
            'lists' => array_map(static function (array $row) use ($storyboardCovers): array {
                $episode = self::summary($row);
                $episode['cover_url'] = (string)($storyboardCovers[(int)($row['production_project_id'] ?? 0)]['url'] ?? '');
                return $episode;
            }, $rows)];
    }

    public static function summary(array $row): array
    {
        if (!$row) return [];
        $ledger = self::decode($row['continuity_json'] ?? '');
        $row['continuity_warnings'] = (array)($ledger['warnings'] ?? []);
        unset($row['outline_json'], $row['result_json'], $row['continuity_json'], $row['series_json'], $row['provider_request_id'], $row['provider_task_id']);
        $row['ready'] = (bool)$row['completed_once'];
        $row['queue_state'] = $row['status'];
        // A running episode can finish after the user pauses the series. Its
        // completed plan is already durable and must remain viewable; only
        // later, not-yet-run episodes are canceled. Keep legacy rows with the
        // old canceled status compatible with that same behavior.
        if ($row['ready'] && $row['status'] === 'canceled') {
            $row['status'] = 'success';
            $row['error'] = '';
        }
        $row['can_retry'] = in_array($row['status'], ['failed', 'canceled'], true) || !empty($row['needs_review']);
        return $row;
    }

    public static function detail(int $tenantId, int $userId, int $id): array
    {
        $row = self::episode($tenantId, $userId, $id);
        $result = self::summary($row);
        $result['outline'] = self::decode($row['outline_json']);
        $result['result'] = self::decode($row['result_json']);
        if ((int)$row['production_project_id'] > 0) {
            $child = self::project($tenantId, $userId, (int)$row['production_project_id']);
            $result['task_id'] = $child['last_task_id'];
            $latest = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId,
                'project_id' => $row['production_project_id'], 'status' => 'success', 'delete_time' => 0])->order('id', 'desc')->findOrEmpty();
            if (!$latest->isEmpty()) {
                $result['result'] = self::decode($latest['result_json']);
                if (in_array($row['status'], ['failed', 'canceled'], true)) $result['task_id'] = $latest['task_id'];
            }
        }
        return $result;
    }

    public static function nextInitialEpisode(array $rows): ?array
    {
        usort($rows, static fn($a, $b) => (int)$a['episode_number'] <=> (int)$b['episode_number']);
        foreach ($rows as $row) if ($row['status'] !== 'success' || !empty($row['needs_review'])) return $row;
        return null;
    }

    /** One batch read; no per-card query and no mutation during polling. */
    private static function withContinuityStatus(int $tenantId, int $userId, array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, 'production_project_id')));
        $versions = $ids ? Db::name('aigc_short_drama_plan_version')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'is_current' => 1, 'delete_time' => 0])
            ->whereIn('project_id', $ids)->field('project_id,continuity_json')->select()->toArray() : [];
        $current = [];
        foreach ($versions as $version) $current[(int)$version['project_id']] = self::decode($version['continuity_json']);
        return ShortDramaContinuity::dependencyStatus($rows, $current);
    }

    public static function retry(int $tenantId, int $userId, int $id): array
    {
        return self::locked($tenantId, $userId, $id, function (array $row) use ($tenantId, $userId) {
            $siblings = self::withContinuityStatus($tenantId, $userId, Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId,
                'project_id' => $row['project_id'], 'delete_time' => 0])->order('episode_number')->select()->toArray());
            $review = false;
            foreach ($siblings as $sibling) {
                if ((int)$sibling['id'] === (int)$row['id']) { $review = !empty($sibling['needs_review']); break; }
                if (!empty($sibling['needs_review'])) throw new Exception('请先复核前面的剧集，避免继续使用旧剧情');
            }
            if (!in_array($row['status'], ['failed', 'canceled'], true) && !($row['status'] === 'success' && $review)) throw new Exception('当前剧集无需重试');
            if ($row['status'] === 'canceled' && (int)$row['completed_once'] && $row['task_id'] !== '') {
                $status = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $row['task_id']])->value('status');
                if ($status === 'success') {
                    Db::name(self::TABLE)->where('id', $row['id'])->update(['status' => 'success', 'cancel_requested' => 0, 'error' => '', 'update_time' => time()]);
                    return ['status' => 'success', 'episode_id' => $row['id'], 'task_id' => $row['task_id'], 'project_id' => $row['production_project_id']];
                }
            }
            $newTask = '';
            if ($row['task_id'] !== '' && (int)$row['production_project_id'] > 0) {
                $old = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $row['task_id'], 'delete_time' => 0])->findOrEmpty();
                if (!$old->isEmpty()) {
                    $request = self::decode($old['request_json']);
                    $originalContext = $request['series_context'] ?? [];
                    if ((int)($request['_generation_version'] ?? 0) >= 3) {
                        $previous = [];
                        foreach ($siblings as $sibling) {
                            if ((int)$sibling['episode_number'] < (int)$row['episode_number'] && (int)$sibling['completed_once']) $previous[] = self::decode($sibling['continuity_json']);
                        }
                        $request['series_context']['continuity'] = ShortDramaContinuity::context($previous);
                        $request['series_context']['previous_episodes'] = ShortDramaContinuity::context($previous)['recent_episodes'];
                        if ($review) {
                            $version = Db::name('aigc_short_drama_plan_version')->where(['tenant_id' => $tenantId, 'user_id' => $userId,
                                'project_id' => $row['production_project_id'], 'is_current' => 1, 'delete_time' => 0])->find();
                            $request['revision_base_result'] = self::decode($version['plan_json'] ?? $row['result_json']);
                            $request['revision_message'] = '复核当前剧本与最新前集的连续性，只修复明确矛盾；保留其他剧情、镜头、台词和所有素材标识。';
                            $request['revision_policy'] = ['mode' => 'local_only', 'preserve_unmentioned' => true];
                            unset($request['revision_target']);
                        }
                    }
                    if (!$review && (int)($request['_generation_version'] ?? 0) >= 3
                        && $originalContext === ($request['series_context'] ?? [])) {
                        Db::name('aigc_short_drama_planning_unit')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $row['task_id']])
                            ->whereIn('status', ['failed', 'running'])->update(['status' => 'pending', 'update_time' => time()]);
                        $old->save(['status' => 'pending', 'error' => '', 'finished_at' => 0, 'update_time' => time()]);
                        $newTask = (string)$old['task_id'];
                    } else {
                        $created = AigcShortDramaService::createScriptPlan($tenantId, $userId, $request, (int)$row['production_project_id'], $request);
                        $newTask = $created['task_id'];
                    }
                }
            }
            Db::name(self::TABLE)->where('id', $row['id'])->update(['status' => 'pending', 'task_id' => $newTask,
                'retry_count' => (int)$row['retry_count'] + 1, 'cancel_requested' => 0, 'started_at' => 0, 'finished_at' => 0, 'error' => '', 'progress' => 0, 'update_time' => time()]);
            self::queueJob($tenantId, $userId, (int)$row['project_id'], (int)$row['id'], (int)$row['retry_count'] + 1);
            return ['status' => 'pending', 'episode_id' => $row['id'], 'project_id' => $row['production_project_id'], 'task_id' => $newTask];
        });
    }

    public static function cancel(int $tenantId, int $userId, int $id): array
    {
        // An in-flight provider call cannot be recalled. Finish it once, retain its result,
        // then pause the series without submitting or billing any subsequent episodes.
        return self::locked($tenantId, $userId, $id, function (array $row) use ($tenantId, $userId) {
            if ($row['status'] === 'canceled') return ['status' => 'canceled'];
            if ($row['status'] === 'running') {
                Db::name(self::TABLE)->where('id', $row['id'])->update(['cancel_requested' => 1, 'update_time' => time()]);
                return ['status' => 'running', 'cancel_requested' => true];
            }
            if ($row['status'] !== 'pending') throw new Exception('当前剧集无需取消');
            if ($row['task_id'] !== '') AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $row['task_id']])->whereIn('status', ['pending', 'queued'])->update(['status' => 'canceled', 'finished_at' => time(), 'update_time' => time()]);
            Db::name(self::TABLE)->where('id', $row['id'])->update(['status' => 'canceled', 'error' => '已取消，后续剧集暂停', 'update_time' => time()]);
            return ['status' => 'canceled'];
        });
    }

    /**
     * Stop every uncompleted episode in one transaction. A running provider
     * request is allowed to finish once so its result and billing state remain
     * consistent; all not-yet-submitted episodes are canceled immediately.
     * Repeating this operation is deliberately a no-op.
     */
    public static function cancelAll(int $tenantId, int $userId, int $projectId): array
    {
        self::project($tenantId, $userId, $projectId);
        return Db::transaction(function () use ($tenantId, $userId, $projectId) {
            $rows = Db::name(self::TABLE)->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])->whereIn('status', ['pending', 'running'])->order('episode_number')->lock(true)->select()->toArray();
            if ($rows === []) {
                return ['project_id' => $projectId, 'canceled_count' => 0, 'finishing_count' => 0];
            }

            $now = time();
            $pendingIds = [];
            $pendingTaskIds = [];
            $runningIds = [];
            foreach ($rows as $row) {
                if ((string)$row['status'] === 'running') {
                    if (!(int)$row['cancel_requested']) {
                        $runningIds[] = (int)$row['id'];
                    }
                    continue;
                }
                $pendingIds[] = (int)$row['id'];
                $taskId = trim((string)($row['task_id'] ?? ''));
                if ($taskId !== '') {
                    $pendingTaskIds[] = $taskId;
                }
            }
            if ($pendingTaskIds !== []) {
                AigcShortDramaScriptTask::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'delete_time' => 0,
                ])->whereIn('task_id', array_values(array_unique($pendingTaskIds)))
                    ->whereIn('status', ['pending', 'queued'])->update([
                        'status' => 'canceled',
                        'finished_at' => $now,
                        'update_time' => $now,
                    ]);
            }
            if ($pendingIds !== []) {
                Db::name('aigc_short_drama_episode_job')->where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                ])->whereIn('episode_id', $pendingIds)->whereIn('status', ['pending', 'queued'])->update([
                    'status' => 'canceled',
                    'update_time' => $now,
                ]);
                Db::name(self::TABLE)->whereIn('id', $pendingIds)->update([
                    'status' => 'canceled',
                    'cancel_requested' => 1,
                    'error' => '已取消自动生成，可按需单独重新生成',
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);
            }
            if ($runningIds !== []) {
                Db::name(self::TABLE)->whereIn('id', $runningIds)->update([
                    'cancel_requested' => 1,
                    'update_time' => $now,
                ]);
            }
            return [
                'project_id' => $projectId,
                'canceled_count' => count($pendingIds),
                'finishing_count' => count($runningIds),
            ];
        });
    }

    private static function locked(int $tenantId, int $userId, int $id, callable $fn): array
    {
        self::episode($tenantId, $userId, $id);
        return Db::transaction(function () use ($tenantId, $userId, $id, $fn) {
            $row = Db::name(self::TABLE)->where(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->lock(true)->find();
            return $fn($row);
        });
    }

    public static function message(int $tenantId, int $userId, array $params): array
    {
        $row = self::episode($tenantId, $userId, (int)($params['episode_id'] ?? 0));
        if (!(int)$row['completed_once'] || $row['status'] !== 'success') throw new Exception('请等待当前剧集完成');
        return AigcShortDramaService::message($tenantId, $userId, ['task_id' => $row['task_id'], 'message' => (string)($params['message'] ?? '')]);
    }

    public static function export(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        self::project($tenantId, $userId, $projectId);
        $rows = Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'delete_time' => 0, 'completed_once' => 1])->order('episode_number')->select()->toArray();
        $id = (int)($params['episode_id'] ?? 0);
        if ($id) $rows = array_values(array_filter($rows, static fn($r) => (int)$r['id'] === $id));
        if (!$rows) throw new Exception('暂无已完成剧集');
        $results = [];
        foreach ($rows as $row) {
            try {
                $type = ($params['type'] ?? '') === 'export_package' ? 'export_package' : 'export_video';
                $task = Db::transaction(function () use ($tenantId, $userId, $row, $type) {
                    Db::name(self::TABLE)->where('id', $row['id'])->lock(true)->find();
                    $active = Db::name('aigc_short_drama_generation_task')->where(['tenant_id' => $tenantId, 'user_id' => $userId,
                        'project_id' => $row['production_project_id'], 'task_type' => $type, 'delete_time' => 0])
                        ->whereIn('status', ['pending', 'running', 'queued'])->order('id', 'desc')->find();
                    if ($active) return ['task_id' => $active['task_id'], 'status' => $active['status'], 'progress' => $active['progress']];
                    return AigcShortDramaService::createShotGenerationTask($tenantId, $userId, [
                    'project_id' => $row['production_project_id'], 'task_id' => $row['task_id'],
                    'task_type' => $type, 'episode_number' => $row['episode_number'],
                    'source' => 'episode_export',
                    ], true);
                });
                $results[] = ['episode_id' => $row['id'], 'episode_number' => $row['episode_number'], 'task' => $task];
            } catch (\Throwable $e) {
                $results[] = ['episode_id' => $row['id'], 'episode_number' => $row['episode_number'], 'error' => $e->getMessage()];
            }
        }
        return ['lists' => $results];
    }

    public static function revisionQueued(int $tenantId, int $userId, int $productionId, string $taskId): void
    {
        Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'production_project_id' => $productionId, 'delete_time' => 0])
            ->update(['task_id' => $taskId, 'status' => 'pending', 'progress' => 0, 'error' => '', 'update_time' => time()]);
    }

    public static function guardContentEdit(int $tenantId, int $userId, int $projectId, string $taskId): void
    {
        $mapped = self::context($tenantId, $userId, $projectId);
        $confirmed = Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'delete_time' => 0])->count();
        if ($mapped || $confirmed) self::guardRevision($tenantId, $userId, $projectId, $taskId);
    }

    public static function guardRevision(int $tenantId, int $userId, int $projectId, string $taskId): void
    {
        $project = AigcShortDramaProject::where(['id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->lock(true)->findOrEmpty();
        if ($project->isEmpty() || $project['last_task_id'] !== $taskId) throw new Exception('剧本已更新，请刷新后重试');
        if (Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'delete_time' => 0])->count()) throw new Exception('大纲已确认，请进入对应剧集修改');
        $row = Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'production_project_id' => $projectId, 'delete_time' => 0])->lock(true)->find();
        if ($row && $row['status'] !== 'success') throw new Exception('本集正在生成或等待重试，请稍后修改');
    }

    /** One provider call per invocation; a DB advisory lock prevents duplicate dispatch after lease expiry. */
    public static function tick(int $tenantId = 0, int $projectId = 0): int
    {
        $exported = $projectId ? 0 : AigcShortDramaService::tickEpisodeExport();
        $query = Db::name(self::TABLE)->where('delete_time', 0)->whereIn('status', ['pending', 'running']);
        $query->where(function ($q) { $q->where('next_retry_at', 0)->whereOr('next_retry_at', '<=', time()); });
        if ($tenantId) $query->where('tenant_id', $tenantId);
        if ($projectId) $query->where('project_id', $projectId);
        $series = $query->group('tenant_id,user_id,project_id')->field('tenant_id,user_id,project_id')->select()->toArray();
        foreach ($series as $owner) {
            $t = (int)$owner['tenant_id']; $u = (int)$owner['user_id']; $p = (int)$owner['project_id'];
            $lock = 'short-drama-series:' . $t . ':' . $p;
            if (!(int)(Db::query('SELECT GET_LOCK(?, 0) AS acquired', [$lock])[0]['acquired'] ?? 0)) continue;
            try {
                self::project($t, $u, $p);
                $rows = Db::name(self::TABLE)->where(['tenant_id' => $t, 'user_id' => $u, 'project_id' => $p, 'delete_time' => 0])->order('episode_number')->select()->toArray();
                $rows = self::withContinuityStatus($t, $u, $rows);
                $first = self::nextInitialEpisode($rows);
                $selected = $first && empty($first['needs_review']) && in_array($first['status'], ['pending', 'running'], true) ? $first : null;
                if (!$selected) continue;
                if ((int)$selected['queue_job_id'] === 0) self::queueJob($t, $u, $p, (int)$selected['id'], (int)$selected['attempt_number']);
                $queueMessage = ShortDramaRedisQueue::claim(gethostname() . '-' . getmypid(), 1);
                if ($queueMessage && (int)($queueMessage['data']['episode_id'] ?? 0) !== (int)$selected['id']) {
                    ShortDramaRedisQueue::ack($queueMessage['id']);
                    $queueMessage = null;
                }
                $leaseToken = bin2hex(random_bytes(16));
                if (ShortDramaRedisQueue::available() && !ShortDramaRedisQueue::lease((int)$selected['id'], $leaseToken)) continue;
                try { self::run($t, $u, $selected, $rows, $leaseToken); }
                finally {
                    if ($queueMessage) ShortDramaRedisQueue::ack($queueMessage['id']);
                    if ($leaseToken !== '') ShortDramaRedisQueue::release((int)$selected['id'], $leaseToken);
                }
                return 1;
            } catch (\Throwable $e) {
                \think\facade\Log::error('Short drama queue: ' . $e->getMessage());
            } finally {
                Db::query('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
        return $exported;
    }

    private static function run(int $tenantId, int $userId, array $row, array $siblings, string $leaseToken = ''): void
    {
        try {
            $sessionId = 'sd_ep_' . $row['id'] . '_' . ($row['attempt_number'] ?? 0) . '_' . bin2hex(random_bytes(8));
            Db::name(self::TABLE)->where('id', $row['id'])->update(['session_id' => $sessionId, 'heartbeat_at' => time(), 'update_time' => time()]);
            if ($row['task_id'] === '') {
                $outlineTask = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $row['outline_task_id'], 'delete_time' => 0])->findOrEmpty();
                if ($outlineTask->isEmpty()) throw new Exception('原始大纲不存在');
                $snapshot = self::decode(Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'project_id' => $row['project_id'], 'episode_number' => 1, 'delete_time' => 0])->value('series_json'));
                $previous = [];
                foreach ($siblings as $sibling) {
                    if ((int)$sibling['episode_number'] < (int)$row['episode_number'] && (int)$sibling['completed_once']) {
                        $previous[] = self::decode($sibling['continuity_json']);
                    }
                }
                Db::transaction(function () use ($tenantId, $userId, &$row, $outlineTask, $previous, $snapshot) {
                    $current = Db::name(self::TABLE)->where('id', $row['id'])->lock(true)->find();
                    if ($current['status'] !== 'pending' || $current['task_id'] !== '') throw new Exception('剧集状态已变化');
                    $created = AigcShortDramaService::createEpisodeProduction($tenantId, $userId, $row,
                        $snapshot['request'] ?? self::decode($outlineTask['request_json']), $snapshot['plan'] ?? self::decode($outlineTask['result_json']), $previous);
                    $row['task_id'] = $created['task_id'];
                    $row['production_project_id'] = $created['project_id'];
                    Db::name(self::TABLE)->where('id', $row['id'])->update(['task_id' => $row['task_id'], 'production_project_id' => $row['production_project_id'], 'update_time' => time()]);
                });
            }
            $claimed = Db::name(self::TABLE)->where(['id' => $row['id'], 'task_id' => $row['task_id'], 'delete_time' => 0])->whereIn('status', ['pending', 'running'])->update(['status' => 'running', 'started_at' => (int)$row['started_at'] ?: time(), 'update_time' => time()]);
            if (!$claimed && (Db::name(self::TABLE)->where('id', $row['id'])->value('status') !== 'running')) return;
            $data = AigcShortDramaService::streamScriptPlan($tenantId, $userId,
                ['project_id' => $row['production_project_id'], 'task_id' => $row['task_id']],
                static function ($event, $data) use ($row) {
                    if (in_array($event, ['stage', 'heartbeat'], true)) Db::name(self::TABLE)->where(['id' => $row['id'], 'task_id' => $row['task_id'], 'delete_time' => 0])->update(['progress' => max(0, min(99, (int)($data['progress'] ?? 0))), 'heartbeat_at' => time(), 'update_time' => time()]);
                }, true);
            $task = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $row['task_id']])->findOrEmpty()->toArray();
            if (!in_array($task['status'] ?? '', ['success', 'failed', 'canceled'], true)) return;
            $plan = self::decode($task['result_json'] ?? '');
            $update = ['status' => $task['status'], 'error' => $task['error'] ?? '', 'progress' => $task['status'] === 'success' ? 100 : 0,
                'provider' => $task['provider'] ?? '', 'provider_request_id' => $task['provider_request_id'] ?? '',
                'provider_task_id' => $task['provider_task_id'] ?? '', 'finished_at' => time(), 'update_time' => time()];
            if ($task['status'] === 'success') {
                if (empty($plan['storyboard']) || empty($plan['subjects']) || empty($plan['locations'])) throw new Exception('本集内容不完整，请重试');
                $update['completed_once'] = 1;
                $update['result_json'] = self::encode($plan);
                if (!empty($plan['_continuity'])) $update['continuity_json'] = self::encode($plan['_continuity']);
                elseif (!(int)$row['completed_once']) $update['continuity_json'] = self::encode(['episode_number' => (int)$row['episode_number'], 'summary' => mb_substr((string)($plan['story_outline'] ?? ''), 0, 1000)]);
            }
            $queueNext = false;
            $pauseFollowing = false;
            Db::transaction(function () use ($row, $update, &$queueNext, &$pauseFollowing) {
                $current = Db::name(self::TABLE)->where(['id' => $row['id'], 'task_id' => $row['task_id'], 'delete_time' => 0])->lock(true)->find();
                if (!$current || !in_array($current['status'], ['pending', 'running'], true)) return;
                $pauseFollowing = $update['status'] === 'success' && !empty($current['cancel_requested']);
                $queueNext = $update['status'] === 'success' && !$pauseFollowing;
                Db::name(self::TABLE)->where('id', $row['id'])->update($update);
            });
            if ($pauseFollowing) {
                self::cancelFollowingEpisodes($tenantId, $userId, (int)$row['project_id'], (int)$row['episode_number']);
            } elseif ($queueNext) {
                $next = Db::name(self::TABLE)->where(['project_id' => $row['project_id'], 'delete_time' => 0])
                    ->where('episode_number', '>', (int)$row['episode_number'])->order('episode_number')->find();
                if ($next) self::queueJob($tenantId, $userId, (int)$row['project_id'], (int)$next['id'], (int)$next['attempt_number']);
            }
        } catch (\Throwable $e) {
            \think\facade\Log::error('Short drama episode ' . $row['id'] . ': ' . $e->getMessage());
            $message = $e->getMessage();
            $temporary = preg_match('/超时|timeout|timed out|连接|network|temporar|限流|rate limit|HTTP 5/i', $message) === 1;
            $retryCount = (int)($row['retry_count'] ?? 0);
            if ($temporary && $retryCount < 2) {
                $delay = $retryCount === 0 ? 30 : 120;
                Db::name(self::TABLE)->where(['id' => $row['id'], 'delete_time' => 0])->whereIn('status', ['pending', 'running'])->update([
                    'status' => 'pending', 'retry_count' => $retryCount + 1, 'next_retry_at' => time() + $delay,
                    'error_code' => 'temporary_provider_error', 'error' => '模型临时异常，已安排自动重试', 'finished_at' => 0, 'update_time' => time(),
                ]);
                self::queueJob($tenantId, $userId, (int)$row['project_id'], (int)$row['id'], $retryCount + 1);
            } else {
                Db::name(self::TABLE)->where(['id' => $row['id'], 'delete_time' => 0])->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error_code' => $temporary ? 'temporary_provider_error' : 'provider_error', 'error' => '本集生成失败，请检查模型配置、可用点数后重试', 'finished_at' => time(), 'update_time' => time()]);
            }
        }
    }

    /** Pause a sequential series after an in-flight episode has completed. */
    private static function cancelFollowingEpisodes(int $tenantId, int $userId, int $projectId, int $episodeNumber): void
    {
        Db::transaction(function () use ($tenantId, $userId, $projectId, $episodeNumber) {
            $rows = Db::name(self::TABLE)->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])->where('episode_number', '>', $episodeNumber)->where('status', 'pending')->lock(true)->select()->toArray();
            if ($rows === []) return;

            $now = time();
            $ids = array_map('intval', array_column($rows, 'id'));
            $taskIds = array_values(array_filter(array_map(static fn(array $item): string => trim((string)($item['task_id'] ?? '')), $rows)));
            if ($taskIds !== []) {
                AigcShortDramaScriptTask::where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'delete_time' => 0,
                ])->whereIn('task_id', array_values(array_unique($taskIds)))->whereIn('status', ['pending', 'queued'])->update([
                    'status' => 'canceled',
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);
            }
            Db::name('aigc_short_drama_episode_job')->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
            ])->whereIn('episode_id', $ids)->whereIn('status', ['pending', 'queued'])->update([
                'status' => 'canceled',
                'update_time' => $now,
            ]);
            Db::name(self::TABLE)->whereIn('id', $ids)->update([
                'status' => 'canceled',
                'cancel_requested' => 1,
                'error' => '已取消自动生成，可按需单独重新生成',
                'finished_at' => $now,
                'update_time' => $now,
            ]);
        });
    }

    private static function importLegacy(int $tenantId, int $userId, array $project): void
    {
        if (empty($project['multi_episode'])) return;
        if (Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'project_id' => $project['id'], 'delete_time' => 0])->count()) return;
        $task = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $project['last_task_id'], 'status' => 'success', 'delete_time' => 0])->findOrEmpty();
        if ($task->isEmpty()) return;
        $plan = self::decode($task['result_json']);
        $hasNestedShots = count(array_filter((array)($plan['episodes'] ?? []), static fn($e) => !empty($e['storyboard']))) > 0;
        if (empty($plan['storyboard']) && !$hasNestedShots) return; // Unconfirmed outline stays editable.
        AigcShortDramaService::importLegacyEpisodes($tenantId, $userId, $project, $task->toArray(), $plan);
    }
}
