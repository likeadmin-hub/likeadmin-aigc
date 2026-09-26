<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;
use RuntimeException;

/** Retires genre quotas without rewriting historical paid requests or artifacts. */
final class ShortDramaShotPolicy
{
    public const VERSION = 2;
    public const INSTRUCTION = '分镜由完整剧情、实际动作、对白和情绪决定，不使用后台题材档位、关键词匹配或旧模板的镜头数量上下限，不按档位补镜、删镜或凑时长。保留用户明确的镜头要求、时长、时间码、关键事件、人物关系及结局；镜头时长必须能承载画面和对白。';

    /** Remove only the verbatim retired application paragraph from frozen templates. */
    public static function upgradeInstructions(string $text): string
    {
        return str_replace('There is no fixed storyboard count by text length; never use 8 as the default. When no target duration or timeline exists, judge story complexity and follow the tenant-configured storyboard complexity rules and storyboard breaking intensity from context: light for simple talking-head/advertising/single-scene content, standard for ordinary short films, detailed for complex dream/suspense/reversal films, and cinematic detailed for complex multi-scene plots. Timeline segments without selected duration override storyboard intensity ranges and must not be expanded.', self::INSTRUCTION, $text);
    }

    /** Read an original artifact, not a cache hit for a changed provider request.
     * Called only for the same durable task; revisions create a separate task.
     * All normal generation/adaptation/quality/continuity guards still run.
     */
    public static function legacyReceipt(int $tenant, int $user, array $request, string $key = 'v3_script'): ?array
    {
        if (($request['_shot_policy_version'] ?? 0) >= self::VERSION || empty($request['_prompt_task_id'])) return null;
        $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $request['_prompt_task_id']];
        $task = Db::name('aigc_short_drama_script_task')->where($scope)->where('delete_time', 0)->find();
        if (!$task || !in_array($task['status'], ['pending', 'queued', 'running'], true)) return null;
        $saved = json_decode((string)$task['request_json'], true, 512, JSON_THROW_ON_ERROR);
        if (($saved['_shot_policy_version'] ?? 0) >= self::VERSION) return null;
        // Runtime may refresh model display metadata, never creative inputs.
        foreach (['prompt', 'series_context', 'revision_message', 'revision_target', 'revision_base_result',
            'episode_id', 'episode_number', 'episode_duration_policy', 'locked_subject_references'] as $field) {
            if (($saved[$field] ?? null) !== ($request[$field] ?? null)) throw new RuntimeException('生成上下文已变化，请新建版本；原有结果已保留', 409);
        }
        $row = Db::name('aigc_short_drama_planning_unit')->where($scope)->where('unit_key', $key)->find();
        if (!$row) return null;
        // A policy namespace change must never bypass an ambiguous paid call,
        // a failed unit or its retry budget. Preserve the original unit untouched.
        if ($row['status'] !== 'received') {
            throw new RuntimeException('原生成请求尚未获得完整回包，已保留原状态；请核实后新建版本，不能自动重复提交', 409);
        }
        $receipt = json_decode((string)$row['result_json'], true, 512, JSON_THROW_ON_ERROR);
        // Return the original receipt to the owning stage. That stage decodes
        // and validates its own schema (revision patches, skeletons and scene
        // chunks are not full scripts), including its bounded repair path.
        return $receipt;
    }
}
