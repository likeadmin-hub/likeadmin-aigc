<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaScriptTask;
use think\facade\Db;
use Exception;

/**
 * Converts a published short-drama Skill snapshot into bounded, stage-specific
 * instructions. Product Skills may only narrow creative output: system safety,
 * billing, provider availability and tenant ownership remain platform owned.
 */
final class ShortDramaSkillRuntime
{
    public const STAGES = [
        'workflow' => '流程规划',
        'asset_analysis' => '素材分析',
        'storyboard' => '故事板设计',
        'media_generation' => '媒体生成',
        'prompt_writing' => '提示词写法',
    ];

    public static function normalizeDefinition(array $definition): array
    {
        $stages = [];
        foreach (self::STAGES as $key => $label) {
            $value = $definition['stages'][$key] ?? $definition[$key] ?? '';
            $stages[$key] = mb_substr(trim(is_string($value) ? $value : ''), 0, 12000, 'UTF-8');
        }
        return [
            'stages' => $stages,
            'positive_examples' => self::strings($definition['positive_examples'] ?? []),
            'negative_examples' => self::strings($definition['negative_examples'] ?? []),
            'keywords' => self::strings($definition['keywords'] ?? []),
            'required_slots' => self::slots($definition['required_slots'] ?? []),
            'model_policy' => is_array($definition['model_policy'] ?? null) ? $definition['model_policy'] : [],
            'execution_policy' => is_array($definition['execution_policy'] ?? null) ? $definition['execution_policy'] : [],
            'output_policy' => is_array($definition['output_policy'] ?? null) ? $definition['output_policy'] : [],
        ];
    }

    public static function instruction(array $snapshot, string $stage): string
    {
        $definition = self::normalizeDefinition((array)($snapshot['definition'] ?? $snapshot['definition_json'] ?? []));
        $parts = [];
        if ($stage === 'script_plan') {
            $parts = [$definition['stages']['workflow'], $definition['stages']['asset_analysis'], $definition['stages']['storyboard'], $definition['stages']['prompt_writing']];
        } elseif ($stage === 'asset_analysis') {
            $parts = [$definition['stages']['asset_analysis'], $definition['stages']['prompt_writing']];
        } elseif (in_array($stage, ['subject_image', 'three_view', 'scene_image', 'shot_image', 'shot_video', 'bgm_audio'], true)) {
            $parts = [$definition['stages']['media_generation'], $definition['stages']['prompt_writing']];
        } elseif (isset(self::STAGES[$stage])) {
            $parts = [$definition['stages'][$stage]];
        }
        $parts = array_values(array_filter(array_map('trim', $parts)));
        if ($stage === 'script_plan' && !empty($definition['output_policy']['required'])) $parts[] = '要求交付：' . implode('、', self::strings($definition['output_policy']['required']));
        return $parts === [] ? '' : "\n\n【已选择短剧 Skill 创作约束】\n" . implode("\n\n", $parts)
            . "\n【边界】以上仅约束创作内容，不得改变安全、计费、模型权限、任务状态或租户数据规则。";
    }

    public static function missingSlots(array $snapshot, array $request): array
    {
        $definition = self::normalizeDefinition((array)($snapshot['definition'] ?? []));
        $missing = [];
        foreach ($definition['required_slots'] as $slot) {
            $key = (string)($slot['key'] ?? '');
            if ($key !== '' && empty($request[$key]) && trim((string)($request['skill_inputs'][$key] ?? '')) === '') $missing[] = $slot;
        }
        return $missing;
    }

    public static function validateMedia(array $snapshot, string $type, array $params): void
    {
        if (!$snapshot || in_array($type, ['export_video', 'export_package'], true)) return;
        $policy = (array)($snapshot['model_policy'] ?? []);
        $params = array_replace((array)($params['params'] ?? []), $params);
        $model = (string)($params['model_code'] ?? $params['model_id'] ?? '');
        $group = $type === 'script_plan' ? 'text' : ($type === 'bgm_audio' ? 'audio' : ($type === 'shot_video' ? 'video' : 'image'));
        $allowed = array_map('strval', (array)($policy[$group . '_models'] ?? $policy['allowed_models'] ?? []));
        if ($allowed && ($model === '' || !in_array($model, $allowed, true))) throw new Exception('所选模型不在此 Skill 允许的模型范围内');
        $ratio = (string)($params['ratio'] ?? $params['aspect_ratio'] ?? '');
        if (in_array($group, ['image', 'video'], true) && !empty($policy['allowed_ratios']) && !in_array($ratio, (array)$policy['allowed_ratios'], true)) throw new Exception('请选择此 Skill 允许的画面比例');
        $duration = (float)($params['duration'] ?? $params['duration_seconds'] ?? 0);
        if ($duration > 0 && ((float)($policy['min_duration'] ?? 0) > $duration || ((float)($policy['max_duration'] ?? 0) > 0 && $duration > (float)$policy['max_duration']))) throw new Exception('生成时长超出此 Skill 允许的范围');
        if (($policy['allow_audio'] ?? true) === false && ($type === 'bgm_audio' || !empty($params['generate_audio']))) throw new Exception('此 Skill 不允许生成音频');
    }

    public static function confirmations(array $snapshot, array $request): array
    {
        $labels = ['script' => '剧本', 'assets' => '素材绑定', 'storyboard' => '分镜'];
        $required = (array)($snapshot['execution_policy']['require_confirmation'] ?? []);
        $result = [];
        foreach ($labels as $key => $label) if (in_array($key, $required, true)) {
            $result[] = ['key' => $key, 'label' => $label, 'confirmed' => !empty($request['_skill_confirmations'][$key]), 'confirmed_at' => (int)($request['_skill_confirmations'][$key] ?? 0)];
        }
        return $result;
    }

    public static function executionSteps(array $task): array
    {
        if (!(int)($task['skill_id'] ?? 0)) return [];
        $scope = ['tenant_id' => (int)$task['tenant_id'], 'user_id' => (int)$task['user_id'], 'project_id' => (int)$task['project_id'], 'delete_time' => 0];
        $runId = Db::name('aigc_short_drama_agent_run')->where($scope)->where('task_id', (string)$task['task_id'])->value('agent_run_id');
        $logs = $runId ? Db::name('aigc_short_drama_agent_step_log')->where($scope)->where('agent_run_id', $runId)->whereLike('step_key', 'skill_%')->column('*', 'step_key') : [];
        $media = Db::name('aigc_short_drama_generation_task')->where($scope)->where('source_task_id', (string)$task['task_id'])->whereIn('task_type', ['subject_image','scene_image','three_view','shot_image','shot_video','bgm_audio'])
            ->field('task_id,task_type,status,progress,error_msg,started_at,finished_at')->order('id', 'asc')->select()->toArray();
        $steps = [];
        foreach (self::STAGES as $key => $label) {
            $log = $logs['skill_' . $key] ?? [];
            $entry = ['key' => $key, 'label' => $label, 'status' => (string)($log['status'] ?? ($key === 'workflow' ? $task['status'] : 'pending')), 'error' => (string)($log['error_msg'] ?? ''), 'started_at' => (int)($log['started_at'] ?? 0), 'finished_at' => (int)($log['finished_at'] ?? 0)];
            if ($key === 'media_generation') {
                $statuses = array_column($media, 'status');
                $entry['status'] = !$media ? 'pending' : (array_diff($statuses, ['success','failed','canceled']) ? 'running' : (in_array('failed', $statuses, true) ? 'failed' : (in_array('canceled', $statuses, true) ? 'canceled' : 'success')));
                $entry['tasks'] = $media;
            }
            $steps[] = $entry;
        }
        return $steps;
    }

    public static function confirm(int $tenantId, int $userId, string $taskId, string $node): array
    {
        return Db::transaction(static function () use ($tenantId, $userId, $taskId, $node): array {
            $task = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId, 'delete_time' => 0])->lock(true)->find();
            if (!$task || $task['status'] !== 'success') throw new Exception('请等待剧本任务完成后再确认');
            $snapshot = json_decode((string)$task['skill_snapshot_json'], true) ?: [];
            $request = json_decode((string)$task['request_json'], true) ?: [];
            $found = false;
            foreach (self::confirmations($snapshot, $request) as $item) {
                if ($item['key'] === $node) { $found = true; break; }
                if (!$item['confirmed']) throw new Exception('请先确认' . $item['label']);
            }
            if (!$found) throw new Exception('此 Skill 不需要该确认节点');
            $request['_skill_confirmations'][$node] = $request['_skill_confirmations'][$node] ?? time();
            $task->save(['request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time()]);
            return self::confirmations($snapshot, $request);
        });
    }

    public static function assertConfirmed(int $tenantId, int $userId, int $projectId, string $taskId): void
    {
        $task = AigcShortDramaScriptTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'task_id' => $taskId, 'delete_time' => 0])->find();
        if (!$task || !(int)$task['skill_id']) return;
        $snapshot = json_decode((string)$task['skill_snapshot_json'], true) ?: [];
        $request = json_decode((string)$task['request_json'], true) ?: [];
        foreach (self::confirmations($snapshot, $request) as $item) if (!$item['confirmed']) throw new Exception('请先在剧本详情确认' . $item['label'] . '，再生成媒体');
    }

    private static function strings(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\n,，]/u', (string)$value);
        return array_values(array_unique(array_filter(array_map(static fn($item) => mb_substr(trim((string)$item), 0, 240, 'UTF-8'), $items ?: []))));
    }

    private static function slots(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_filter(array_map(static function ($slot): array {
            if (is_string($slot)) {
                $slot = trim($slot);
                return ['key' => mb_substr($slot, 0, 64, 'UTF-8'), 'label' => mb_substr($slot, 0, 120, 'UTF-8'), 'ask' => '请补充' . $slot];
            }
            return is_array($slot) ? [
                'key' => mb_substr(trim((string)($slot['key'] ?? '')), 0, 64, 'UTF-8'),
                'label' => mb_substr(trim((string)($slot['label'] ?? $slot['key'] ?? '')), 0, 120, 'UTF-8'),
                'ask' => mb_substr(trim((string)($slot['ask'] ?? '')), 0, 240, 'UTF-8'),
            ] : [];
        }, $value), static fn($slot) => !empty($slot['key'])));
    }
}
