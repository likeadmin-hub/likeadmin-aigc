<?php
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use app\common\service\app\aigc_short_drama\ShortDramaDialogueSplit;
use app\common\service\app\aigc_short_drama\ShortDramaShotDuration;

/** Plan splits before image binding, never mutate already-approved video nodes. */
final class ConversationShotTiming
{
    private static function enabled(array $workflow): bool
    {
        return ($workflow['workflow_snapshot']['key'] ?? '') === ConversationWorkflow::KEY
            && version_compare((string)($workflow['workflow_snapshot']['version'] ?? '0'), '2026-09-26.2', '>=');
    }

    public static function instruction(array $workflow): string
    {
        if (!self::enabled($workflow) || !in_array($workflow['stage_state']['key'] ?? '', ['script','storyboard','video_plan','video_nodes'], true)) return '';
        return "\n" . ShortDramaDialogueSplit::instruction((array)($workflow['workflow_snapshot']['shot_duration_rule'] ?? []))
            . '剧本规划时主动完成需要的拆镜，后续分镜图片、视频规划及视频节点按拆分后的唯一编号一一对应。'
            . '若已确认阶段才发现超长，不生成超限视频节点，也不复用错误图片引用，应说明需要返回剧本和分镜阶段重新规划。';
    }

    public static function assertProposals(array $workflow, array $proposals): void
    {
        if (!self::enabled($workflow)) return;
        $rule = ShortDramaShotDuration::modelRule((array)($workflow['workflow_snapshot']['shot_duration_rule'] ?? []));
        foreach ($proposals as $proposal) {
            if (($proposal['artifact'] ?? '') !== 'storyboard_video') continue;
            $duration = $proposal['duration_seconds'] ?? null;
            if (!is_numeric($duration) || !is_finite((float)$duration) || $duration <= 0 || $duration > $rule['max_seconds']) {
                throw new ConversationWorkflowValidationException('shot_duration_requires_replanning');
            }
        }
    }
}
