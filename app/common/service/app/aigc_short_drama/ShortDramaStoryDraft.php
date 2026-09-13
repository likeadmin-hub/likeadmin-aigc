<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaProject;
use app\common\model\app\aigc_short_drama\AigcShortDramaScriptTask;
use think\facade\Db;
use InvalidArgumentException;

/** Drafts live in the existing task request envelope; result_json remains model evidence. */
final class ShortDramaStoryDraft
{
    public static function effective(array $request, array $original): array
    {
        return ShortDramaStoryWorkflow::enabled($request) && is_array($request['_story_draft']['result'] ?? null)
            ? $request['_story_draft']['result'] : $original;
    }

    public static function version(array $request): int
    {
        return (int)($request['_story_draft']['version'] ?? 0);
    }

    public static function stage(array $request): string
    {
        return (string)($request['_story_draft']['stage'] ?? $request['multi_episode_stage'] ?? 'story');
    }

    public static function assertVersion(array $request, array $params): void
    {
        if (!array_key_exists('draft_version', $params) || !(is_int($params['draft_version']) || (is_string($params['draft_version']) && ctype_digit($params['draft_version'])))
            || (int)$params['draft_version'] !== self::version($request)) {
            throw new InvalidArgumentException('草稿版本已变化，请刷新后重试；当前编辑内容尚未覆盖');
        }
    }

    /** Allow incomplete creative fields, but not runtime metadata or changed stable identifiers. */
    public static function merge(array $base, array $input, string $stage): array
    {
        foreach (['title', 'type_judgement', 'core_theme', 'story_outline'] as $key) {
            if ($stage !== 'story') continue;
            if (!array_key_exists($key, $input)) continue;
            self::text($input[$key]);
            $base[$key] = $input[$key];
        }
        if ($stage === 'story') {
            if (array_key_exists('episode_count', $input)) {
                $count = $input['episode_count'];
                // Request's recursive trim filter converts JSON numbers to strings.
                // Accept decimal integer text, but never coerce fractions or junk.
                if (is_string($count) && preg_match('/^[0-9]+$/D', $count)) {
                    if ((float)$count > 500) throw new InvalidArgumentException('请输入 2～500 之间的整数集数');
                    $count = (int)$count;
                }
                if (!is_int($count) || $count < 2 || $count > 500) throw new InvalidArgumentException('请输入 2～500 之间的整数集数');
                $base['episode_count'] = $count;
            }
            if (isset($input['series_bible'])) {
                if (!is_array($input['series_bible'])) throw new InvalidArgumentException('故事设定格式无效');
                foreach (['audience', 'core_hook', 'logline', 'series_arc'] as $key) {
                    if (!array_key_exists($key, $input['series_bible'])) continue;
                    self::text($input['series_bible'][$key]);
                    $base['series_bible'][$key] = $input['series_bible'][$key];
                }
                foreach (['relationships', 'world_rules'] as $key) {
                    if (!array_key_exists($key, $input['series_bible'])) continue;
                    $items = $input['series_bible'][$key];
                    if (!is_array($items) || count($items) > 500) throw new InvalidArgumentException('故事设定列表格式无效');
                    foreach ($items as $item) self::text($item);
                    $base['series_bible'][$key] = array_values($items);
                }
            }
            foreach (['subjects', 'locations'] as $key) {
                if (!array_key_exists($key, $input)) continue;
                if (!is_array($input[$key]) || count($input[$key]) !== count($base[$key] ?? [])) throw new InvalidArgumentException('不能通过草稿增删素材标识，请使用 AI 修改');
                foreach ($input[$key] as $index => $item) {
                    if (!is_array($item) || ($item['id'] ?? null) !== ($base[$key][$index]['id'] ?? null)) throw new InvalidArgumentException('素材标识已变化，请刷新后重试');
                    foreach (['name', 'description', 'age', 'role', 'background', 'motivation', 'arc'] as $field) {
                        if (!array_key_exists($field, $item)) continue;
                        self::text($item[$field]);
                        $base[$key][$index][$field] = $item[$field];
                    }
                }
            }
            foreach ((array)($base['series_bible']['characters'] ?? []) as $index => $character) {
                foreach ((array)($base['subjects'] ?? []) as $subject) {
                    if (($subject['id'] ?? null) !== ($character['id'] ?? null)) continue;
                    $base['series_bible']['characters'][$index] = array_replace($character, array_intersect_key($subject, array_flip(['name', 'age', 'role', 'background', 'motivation', 'arc'])));
                }
            }
            foreach ((array)($base['series_bible']['locations'] ?? []) as $index => $location) {
                if (!is_array($location)) continue;
                foreach ((array)($base['locations'] ?? []) as $scene) {
                    if (($scene['id'] ?? null) !== ($location['id'] ?? null)) continue;
                    $base['series_bible']['locations'][$index] = array_replace($location, array_intersect_key($scene, array_flip(['name', 'description'])));
                }
            }
        } elseif ($stage === 'episodes') {
            if (isset($input['episodes'])) {
                if (!is_array($input['episodes']) || count($input['episodes']) !== count($base['episodes'] ?? [])) throw new InvalidArgumentException('大纲集数不能通过草稿修改');
                foreach ($input['episodes'] as $index => $episode) {
                    if (!is_array($episode) || self::episodeNumber($episode['episode_number'] ?? null) !== self::episodeNumber($base['episodes'][$index]['episode_number'] ?? null)) throw new InvalidArgumentException('大纲集号不一致');
                    foreach (['title', 'story_outline', 'conflict_point', 'ending_hook'] as $field) {
                        if (!array_key_exists($field, $episode)) continue;
                        self::text($episode[$field]);
                        $base['episodes'][$index][$field] = $episode[$field];
                    }
                }
            }
        } else throw new InvalidArgumentException('无效的草稿阶段');
        return $base;
    }

    private static function episodeNumber($value): int
    {
        // HTTP filters stringify numeric JSON values. Validate before converting;
        // never accept floats, booleans or numeric prefixes as episode identities.
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,2}$/D', (string)$value) || (int)$value > 500) {
            throw new InvalidArgumentException('大纲集号不一致');
        }
        return (int)$value;
    }

    private static function text($value): void
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > 60000) throw new InvalidArgumentException('文本格式无效或超过 60000 字');
    }

    public static function save(int $tenantId, int $userId, array $params): array
    {
        $taskId = (string)($params['task_id'] ?? '');
        $scope = ['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId, 'delete_time' => 0];
        $task = AigcShortDramaScriptTask::where($scope)->findOrEmpty();
        if ($task->isEmpty()) throw new InvalidArgumentException('任务不存在');
        return Db::transaction(function () use ($tenantId, $userId, $params, $scope, $task) {
            // Match episode/start and message's project-before-task locking order.
            $project = AigcShortDramaProject::where(['id' => $task['project_id'], 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->lock(true)->findOrEmpty();
            if ($project->isEmpty() || $project['last_task_id'] !== $task['task_id']) throw new InvalidArgumentException('当前版本已过期，请打开最新任务');
            $locked = AigcShortDramaScriptTask::where($scope)->lock(true)->findOrEmpty();
            $request = ShortDramaEpisodeService::decode($locked['request_json']);
            if (!ShortDramaStoryWorkflow::enabled($request) || $locked['status'] !== 'success') throw new InvalidArgumentException('当前任务不支持草稿编辑');
            if (Db::name('aigc_short_drama_episode_task')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $task['project_id'], 'delete_time' => 0])->count()) throw new InvalidArgumentException('已确认大纲，前置内容只读');
            self::assertVersion($request, $params);
            $stage = self::stage($request);
            if (($params['action'] ?? '') === 'reopen_story') {
                throw new InvalidArgumentException('故事设定已确认，不能继续编辑');
            } else {
                if (($params['stage'] ?? '') !== $stage || !is_array($params['result'] ?? null)) throw new InvalidArgumentException('草稿阶段或内容无效');
                $result = self::merge(self::effective($request, ShortDramaEpisodeService::decode($locked['result_json'])), $params['result'], $stage);
            }
            if ($stage === 'story' && isset($result['episode_count'])) {
                $request['episode_count'] = $result['episode_count'];
                $project->save(['episode_count' => $result['episode_count'], 'update_time' => time()]);
            }
            $request['_story_draft'] = ['version' => self::version($request) + 1, 'stage' => $stage, 'result' => $result, 'saved_at' => time()];
            $locked->save(['request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'update_time' => time()]);
            $issues = ShortDramaStoryWorkflow::issues($result, $stage, (int)$request['episode_count']);
            return ['draft_version' => self::version($request), 'saved_at' => time(), 'issues' => $issues, 'can_confirm' => !$issues];
        });
    }
}
