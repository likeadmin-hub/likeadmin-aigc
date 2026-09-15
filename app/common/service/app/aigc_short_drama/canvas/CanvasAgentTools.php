<?php

namespace app\common\service\app\aigc_short_drama\canvas;

use think\facade\Db;

/**
 * The only model-controlled write surface for the short-drama canvas.
 *
 * These tools intentionally persist drafts and unsubmitted media proposals only.
 * They cannot write short-drama projects, subjects, storyboards, assets, tasks,
 * wallets, or the standalone canvas application.
 */
final class CanvasAgentTools
{
    private const DRAFT_KINDS = ['character', 'scene', 'storyboard'];
    private const PROPOSAL_KINDS = ['image', 'video'];

    /** @return array{message:string,tools:array<int,array<string,mixed>>} */
    public static function validate($value): array
    {
        if (!is_array($value)) CanvasPolicy::fail('INVALID_AGENT_PLAN', '模型结果格式无效');
        $message = CanvasPolicy::text($value['message'] ?? '', 6000);
        if ($message === '') CanvasPolicy::fail('INVALID_AGENT_PLAN', '模型未返回说明');
        $tools = $value['tools'] ?? [];
        if (!is_array($tools) || count($tools) > CanvasPolicy::MAX_TOOL_CALLS) CanvasPolicy::fail('INVALID_AGENT_PLAN', '模型操作数量超出限制');
        $normalised = [];
        foreach ($tools as $index => $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null) || !is_array($tool['args'] ?? null)) {
                CanvasPolicy::fail('INVALID_AGENT_PLAN', '模型操作格式无效');
            }
            $normalised[] = match ($tool['name']) {
                'upsert_draft' => self::draft($tool['args']),
                'propose_media' => self::proposal($tool['args']),
                default => self::rejectTool(),
            };
        }
        return ['message' => $message, 'tools' => $normalised];
    }

    /** @return array{name:string,args:array<string,mixed>} */
    private static function draft(array $args): array
    {
        $kind = $args['kind'] ?? '';
        if (!in_array($kind, self::DRAFT_KINDS, true)) CanvasPolicy::fail('INVALID_AGENT_PLAN', '草稿类型不支持');
        $fields = $args['fields'] ?? [];
        if (!is_array($fields) || count($fields) > 20) CanvasPolicy::fail('INVALID_AGENT_PLAN', '草稿字段过多');
        $cleanFields = [];
        foreach ($fields as $key => $field) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) || !is_string($field)) {
                CanvasPolicy::fail('INVALID_AGENT_PLAN', '草稿字段格式无效');
            }
            $cleanFields[$key] = CanvasPolicy::text($field, 1000);
        }
        return ['name' => 'upsert_draft', 'args' => [
            'draft_key' => CanvasPolicy::key($args['draft_key'] ?? null),
            'kind' => $kind,
            'title' => CanvasPolicy::text($args['title'] ?? '', 120),
            'description' => CanvasPolicy::text($args['description'] ?? '', 5000),
            'fields' => $cleanFields,
        ]];
    }

    /** @return array{name:string,args:array<string,mixed>} */
    private static function proposal(array $args): array
    {
        $kind = $args['kind'] ?? '';
        if (!in_array($kind, self::PROPOSAL_KINDS, true)) CanvasPolicy::fail('INVALID_AGENT_PLAN', '媒体提案类型不支持');
        $count = self::integer($args['count'] ?? 1, 1, 4);
        $duration = $kind === 'video' ? self::integer($args['duration_seconds'] ?? null, 1, 30) : 0;
        $ratio = $args['aspect_ratio'] ?? '9:16';
        if (!is_string($ratio) || !in_array($ratio, ['1:1', '3:4', '4:3', '9:16', '16:9'], true)) CanvasPolicy::fail('INVALID_AGENT_PLAN', '媒体比例不支持');
        $prompt = CanvasPolicy::text($args['prompt'] ?? '', 6000);
        CanvasPromptSafety::assertAllowed($prompt);
        return ['name' => 'propose_media', 'args' => [
            'proposal_key' => CanvasPolicy::key($args['proposal_key'] ?? null),
            'kind' => $kind,
            'title' => CanvasPolicy::text($args['title'] ?? '', 120),
            // A proposal may target an existing formal short-drama shot. It is
            // only a suggestion until the user confirms generation.
            'shot_id' => CanvasPolicy::text($args['shot_id'] ?? '', 40),
            'prompt' => $prompt,
            'model' => CanvasPolicy::text($args['model'] ?? '', 120),
            'aspect_ratio' => $ratio,
            'count' => $count,
            'duration_seconds' => $duration,
        ]];
    }

    /** @return never */
    private static function rejectTool(): never { CanvasPolicy::fail('INVALID_AGENT_PLAN', '模型请求了未授权操作'); }
    private static function integer($value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) CanvasPolicy::fail('INVALID_AGENT_PLAN', '数值参数不符合要求');
        return $value;
    }

    /**
     * Called inside the run finalisation transaction. Applying a plan is
     * intentionally idempotent by unique draft/proposal keys.
     */
    public static function apply(int $tenantId, int $userId, int $workspaceId, int $runId, string $viewKey, array $tools): void
    {
        $now = time();
        foreach ($tools as $tool) {
            $args = $tool['args'];
            if ($tool['name'] === 'upsert_draft') {
                $where = ['tenant_id' => $tenantId, 'user_id' => $userId, 'workspace_id' => $workspaceId, 'draft_key' => $args['draft_key']];
                $content = CanvasPolicy::encode(['title' => $args['title'], 'description' => $args['description'], 'fields' => $args['fields']]);
                $row = Db::name('aigc_short_drama_canvas_draft')->where($where)->lock(true)->find();
                if ($row) {
                    Db::name('aigc_short_drama_canvas_draft')->where('id', $row['id'])->update([
                        'view_key' => $viewKey, 'kind' => $args['kind'], 'content_json' => $content,
                        'version' => (int)$row['version'] + 1, 'update_time' => $now,
                    ]);
                } else {
                    Db::name('aigc_short_drama_canvas_draft')->insert($where + [
                        'view_key' => $viewKey, 'kind' => $args['kind'], 'version' => 1, 'content_json' => $content,
                        'create_time' => $now, 'update_time' => $now,
                    ]);
                }
                continue;
            }
            if ($tool['name'] === 'propose_media') {
                $where = ['tenant_id' => $tenantId, 'user_id' => $userId, 'workspace_id' => $workspaceId, 'request_key' => $args['proposal_key']];
                $hash = CanvasPolicy::hash($args);
                $row = Db::name('aigc_short_drama_canvas_action')->where($where)->lock(true)->find();
                if ($row && !hash_equals($row['request_hash'], $hash)) CanvasPolicy::fail('IDEMPOTENCY_CONFLICT', '媒体提案标识重复');
                if (!$row) {
                    Db::name('aigc_short_drama_canvas_action')->insert($where + [
                        'run_id' => $runId, 'request_hash' => $hash, 'kind' => $args['kind'] . '_proposal', 'status' => 'proposed',
                        'version' => 1, 'proposal_json' => CanvasPolicy::encode($args), 'result_json' => '[]',
                        'confirmed_hash' => '', 'generation_task_id' => null, 'create_time' => $now, 'update_time' => $now,
                    ]);
                }
            }
        }
    }
}
