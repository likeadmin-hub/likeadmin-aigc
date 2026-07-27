<?php

namespace app\common\service\app\aigc_canvas\agent\drama;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

/** Creates durable short-drama work from Canvas without duplicating its runtime. */
final class CanvasShortDramaWorkflowService
{
    public static function createScriptPlan(int $tenantId, int $userId, array $input): array
    {
        $prompt = trim((string)($input['prompt'] ?? $input['content'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请先提供短剧题材、故事梗概或剧本需求。');
        }

        $result = AigcShortDramaService::createScriptPlan($tenantId, $userId, [
            'prompt' => $prompt,
            'episode_count' => max(1, min(100, (int)($input['episode_count'] ?? 1))),
            'target_duration_seconds' => max(0, min(7200, (int)($input['target_duration_seconds'] ?? 0))),
            'multi_episode' => !empty($input['multi_episode']),
            'ratio' => (string)($input['ratio'] ?? '9:16'),
        ]);

        return [
            'workflow' => 'short_drama_script_plan',
            'status' => (string)($result['status'] ?? 'pending'),
            'task_id' => (string)($result['task_id'] ?? ''),
            'drama_project_id' => (int)($result['project_id'] ?? 0),
            'redirect_url' => (string)($result['redirect_url'] ?? ''),
            'message' => '短剧剧本任务已创建，可在短剧工作台继续生成和分镜。',
        ];
    }
}
