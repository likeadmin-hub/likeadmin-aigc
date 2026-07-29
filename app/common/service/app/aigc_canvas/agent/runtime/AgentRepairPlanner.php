<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

/** Plans at most one non-billing recovery step for a validated tool result. */
final class AgentRepairPlanner
{
    public static function plan(string $toolCode, array $validation, int $attempt): array
    {
        if ($attempt >= 1 || empty($validation['repairable']) || (int)($validation['task_id'] ?? 0) <= 0) {
            return [];
        }
        if (!in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
            return [];
        }
        return [
            'kind' => 'await_existing_task',
            'reason' => 'generation_task_pending_result_sync',
            'attempt' => $attempt + 1,
            'task_id' => (int)$validation['task_id'],
        ];
    }
}
