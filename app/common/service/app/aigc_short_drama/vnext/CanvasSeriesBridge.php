<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService;
use app\common\service\app\aigc_short_drama\ShortDramaStoryDraft;
use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use RuntimeException;
use think\facade\Db;

/** Server-only enrollment; canvas explicitly requests each episode, never the whole series. */
final class CanvasSeriesBridge
{
    public static function create(int $tenant, int $user, int $project, string $outlineTask, int $number, int $draftVersion, int $canvasProject): ?array
    {
        return Db::transaction(static function () use ($tenant, $user, $project, $outlineTask, $number, $draftVersion, $canvasProject): ?array {
            $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'project_id' => $project, 'delete_time' => 0];
            $parent = Db::name('aigc_short_drama_project')->where(['id' => $project, 'tenant_id' => $tenant, 'user_id' => $user, 'delete_time' => 0])->lock(true)->find();
            if (!$parent || !$parent['multi_episode'] || $canvasProject <= 0) throw new RuntimeException('画布系列项目不存在', 409);
            if ($parent['last_task_id'] !== $outlineTask) throw new RuntimeException('大纲已更新，请刷新后确认', 409);
            $first = Db::name('aigc_short_drama_episode_task')->where($scope + ['episode_number' => 1])->lock(true)->find();
            if (!$first) {
                // Never migrate an already-started legacy canvas halfway through its story.
                if ($number !== 1) return null;
                if (!\think\facade\Config::get('short_drama.vnext.canvas_series_enabled', false)) return null;
                $task = Db::name('aigc_short_drama_script_task')->where($scope + ['task_id' => $outlineTask, 'status' => 'success'])->lock(true)->find();
                if (!$task) throw new RuntimeException('请等待大纲生成完成', 409);
                $request = ShortDramaEpisodeService::decode($task['request_json']);
                if (!ShortDramaStoryWorkflow::enabled($request) || ShortDramaStoryDraft::stage($request) !== 'episodes') return null;
                ShortDramaStoryDraft::assertVersion($request, ['draft_version' => $draftVersion]);
                $plan = ShortDramaStoryDraft::effective($request, ShortDramaEpisodeService::decode($task['result_json']));
                $request['confirmed_outline_version'] = ShortDramaStoryDraft::version($request);
                $request['confirmed_outline_snapshot'] = $plan;
                $canonical = SeriesContext::capture($tenant, $user, $project, $outlineTask, $request, $plan,
                    (array)\think\facade\Config::get('short_drama.vnext', []));
                if ($canonical === null) return null;
                $issues = ShortDramaStoryWorkflow::issues($plan, 'episodes', (int)$parent['episode_count'], ShortDramaStoryWorkflow::qualityReviewEnabled($request), true);
                if ($issues) throw new RuntimeException($issues[0]['message'], 422);
                $traceIssues = PlanningTrace::issues($request, $plan, 'episodes', (int)$parent['episode_count']);
                if ($traceIssues) throw new RuntimeException($traceIssues[0]['message'], 422);
                $episodes = ShortDramaEpisodeService::validateOutline($plan, (int)$parent['episode_count'], true);
                $request['_vnext_series'] = $canonical;
                $request['_canvas_series_dispatch'] = ['schema_version' => 1, 'canvas_project_id' => $canvasProject];
                foreach ($episodes as $index => $outline) Db::name('aigc_short_drama_episode_task')->insert([
                    'tenant_id' => $tenant, 'user_id' => $user, 'project_id' => $project, 'episode_number' => $index + 1,
                    'title' => mb_substr($outline['title'], 0, 120), 'outline_task_id' => $outlineTask,
                    'outline_json' => json_encode($outline, JSON_THROW_ON_ERROR),
                    'series_json' => $index === 0 ? json_encode(['plan' => $plan, 'request' => $request], JSON_THROW_ON_ERROR) : null,
                    'status' => 'pending', 'error' => '', 'create_time' => time(), 'update_time' => time(),
                ]);
                Db::name('aigc_short_drama_script_task')->where('id', $task['id'])->update(['request_json' => json_encode($request, JSON_THROW_ON_ERROR), 'update_time' => time()]);
                $first = Db::name('aigc_short_drama_episode_task')->where($scope + ['episode_number' => 1])->find();
            }
            $snapshot = ShortDramaEpisodeService::decode($first['series_json']);
            $dispatch = $snapshot['request']['_canvas_series_dispatch'] ?? null;
            if ($dispatch === null) throw new RuntimeException('此系列已使用普通逐集队列，不能切换画布派发', 409);
            if (($dispatch['schema_version'] ?? 0) !== 1 || ($dispatch['canvas_project_id'] ?? 0) !== $canvasProject
                || $first['outline_task_id'] !== $outlineTask) throw new RuntimeException('画布系列绑定已变化', 409);
            $episode = Db::name('aigc_short_drama_episode_task')->where($scope + ['episode_number' => $number])->lock(true)->find();
            if (!$episode) throw new RuntimeException('本集不属于已确认大纲', 409);
            // Validate current published history even when reusing a previously requested child.
            SeriesContext::prepare($snapshot['request']['_vnext_series'], $episode, $snapshot['plan']);
            if ($episode['task_id'] !== '') return ['task_id' => $episode['task_id'], 'project_id' => (int)$episode['production_project_id']];
            $request = $snapshot['request'];
            unset($request['_submission_key'], $request['_submission_hash'], $request['_story_draft'],
                $request['revision_message'], $request['revision_base_result'], $request['revision_policy'], $request['revision_target']);
            $request['submission_key'] = 'canvas-series:' . $canvasProject . ':' . $project . ':' . $number;
            $created = AigcShortDramaService::createEpisodeProduction($tenant, $user, $episode, $request, $snapshot['plan'], []);
            Db::name('aigc_short_drama_episode_task')->where('id', $episode['id'])->update([
                'task_id' => $created['task_id'], 'production_project_id' => $created['project_id'], 'update_time' => time()]);
            return $created;
        });
    }

    public static function manual(int $tenant, int $user, int $project): bool
    {
        $json = Db::name('aigc_short_drama_episode_task')->where(['tenant_id' => $tenant, 'user_id' => $user,
            'project_id' => $project, 'episode_number' => 1, 'delete_time' => 0])->value('series_json');
        $snapshot = ShortDramaEpisodeService::decode($json); $dispatch = $snapshot['request']['_canvas_series_dispatch'] ?? null;
        if ($dispatch === null) return false;
        if (($dispatch['schema_version'] ?? 0) !== 1 || empty($dispatch['canvas_project_id']) || empty($snapshot['request']['_vnext_series'])) throw new RuntimeException('画布逐集派发策略无效', 409);
        return true;
    }

    /** Called inside the core publication transaction: content and series history become ready together. */
    public static function publishMapping(int $tenant, int $user, int $productionProject, string $task, array $result): void
    {
        $episode = Db::name('aigc_short_drama_episode_task')->where(['tenant_id' => $tenant, 'user_id' => $user,
            'production_project_id' => $productionProject, 'task_id' => $task, 'delete_time' => 0])->lock(true)->find();
        if (!$episode || !self::manual($tenant, $user, (int)$episode['project_id'])) return;
        if (!in_array($episode['status'], ['pending', 'running'], true)) throw new RuntimeException('画布本集已停止或替换，正文候选保留', 409);
        Db::name('aigc_short_drama_episode_task')->where('id', $episode['id'])->update([
            'status' => 'success', 'completed_once' => 1, 'progress' => 100, 'error' => '', 'error_code' => '',
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
            'continuity_json' => json_encode(SeriesContext::metadata($result), JSON_THROW_ON_ERROR),
            'finished_at' => time(), 'update_time' => time(),
        ]);
    }
}
