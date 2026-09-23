<?php

namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;
use think\facade\Db;

/** Read-only source discovery for an explicitly confirmed formal writeback. */
final class ShortDramaCanvasWritebackService
{
    private const TEXT_ARTIFACTS = ['story_setting', 'episode_script', 'episode_outline', 'storyboard_script'];
    private const STORY_FIELDS = ['title', 'type_judgement', 'core_theme', 'story_outline'];

    public static function sources(int $tenantId, int $userId, int $canvasId): array
    {
        // The binding lookup validates ownership even when no formal target is selected.
        $binding = ShortDramaCanvasBindingService::current($tenantId, $userId, $canvasId);
        $document = Db::name('aigc_short_drama_canvas')->where([
            'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('nodes_json,removed_node_ids_json,graph_revision')->find();
        $nodes = json_decode((string)($document['nodes_json'] ?? '[]'), true);
        $removed = json_decode((string)($document['removed_node_ids_json'] ?? '[]'), true);
        $removed = is_array($removed) ? array_fill_keys(array_map('strval', $removed), true) : [];
        $sources = [];
        $seen = [];
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (!is_array($node) || (string)($node['type'] ?? '') !== 'text') continue;
            $id = (string)($node['id'] ?? '');
            if ($id === '' || isset($removed[$id]) || isset($seen[$id])) continue;
            $seen[$id] = true;
            $meta = (array)($node['metadata'] ?? []);
            if ((string)($meta['workflow_source_stage'] ?? '') !== 'script') continue;
            $artifact = (string)($meta['workflow_artifact'] ?? '');
            if (!in_array($artifact, self::TEXT_ARTIFACTS, true)) continue;
            $content = (string)($meta['content'] ?? '');
            if (trim($content) === '') continue;
            $sources[] = [
                'node_id' => $id,
                'artifact' => $artifact,
                'title' => (string)($node['title'] ?? ''),
                'content_revision' => (int)($meta['content_revision'] ?? 0),
                'content_hash' => hash('sha256', $content),
            ];
        }
        return ['binding' => $binding, 'graph_revision' => (int)($document['graph_revision'] ?? 0), 'sources' => $sources];
    }

    /**
     * Preview only: a story-stage field is never changed by this method.
     * The returned hash captures both sides and the binding; a future apply
     * must re-read all of them under locks rather than trust browser values.
     */
    public static function previewStory(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        $nodeId = (string)($params['source_node_id'] ?? '');
        $field = (string)($params['target_field'] ?? '');
        if ($canvasId <= 0 || !ctype_digit($nodeId) || (int)$nodeId <= 0
            || !in_array($field, self::STORY_FIELDS, true)) {
            throw new InvalidArgumentException('写回预览参数无效');
        }
        $binding = ShortDramaCanvasBindingService::current($tenantId, $userId, $canvasId);
        if (!$binding['bound'] || (int)$binding['episode_id'] !== 0) {
            throw new InvalidArgumentException('请先绑定故事项目，不可将故事设定写入剧集制作项目');
        }
        $document = Db::name('aigc_short_drama_canvas')->where([
            'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('nodes_json,removed_node_ids_json,graph_revision')->find();
        $nodes = json_decode((string)($document['nodes_json'] ?? '[]'), true);
        $removed = json_decode((string)($document['removed_node_ids_json'] ?? '[]'), true);
        if (is_array($removed) && in_array($nodeId, array_map('strval', $removed), true)) {
            throw new InvalidArgumentException('来源节点已删除，请重新选择');
        }
        $source = null;
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (!is_array($node) || (string)($node['id'] ?? '') !== $nodeId) continue;
            $meta = (array)($node['metadata'] ?? []);
            if ((string)($node['type'] ?? '') !== 'text'
                || (string)($meta['workflow_source_stage'] ?? '') !== 'script'
                || (string)($meta['workflow_artifact'] ?? '') !== 'story_setting') break;
            $content = (string)($meta['content'] ?? '');
            if (trim($content) === '' || mb_strlen($content, 'UTF-8') > 60000) break;
            $source = ['node_id' => $nodeId, 'content' => $content,
                'content_revision' => (int)($meta['content_revision'] ?? 0),
                'content_hash' => hash('sha256', $content)];
            break;
        }
        if (!$source) throw new InvalidArgumentException('故事设定来源不存在或不适合正式写回');

        $projectId = (int)$binding['project_id'];
        $project = Db::name('aigc_short_drama_project')->where([
            'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('last_task_id,current_version_id')->find();
        $taskId = (string)($project['last_task_id'] ?? '');
        if ($taskId === '') throw new InvalidArgumentException('正式故事项目尚无可编辑的当前剧本');
        $task = Db::name('aigc_short_drama_script_task')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
            'task_id' => $taskId, 'delete_time' => 0,
        ])->field('status,request_json,result_json')->find();
        $request = ShortDramaEpisodeService::decode((string)($task['request_json'] ?? ''));
        if (($task['status'] ?? '') !== 'success' || !ShortDramaStoryWorkflow::enabled($request)
            || ShortDramaStoryDraft::stage($request) !== 'story'
            || Db::name('aigc_short_drama_episode_task')->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'delete_time' => 0,
            ])->count()) {
            throw new InvalidArgumentException('正式故事草稿当前不可编辑');
        }
        $result = ShortDramaStoryDraft::effective($request, ShortDramaEpisodeService::decode((string)$task['result_json']));
        $targetContent = (string)($result[$field] ?? '');
        $target = ['project_id' => $projectId, 'task_id' => $taskId,
            'project_version_id' => (int)($project['current_version_id'] ?? 0),
            'draft_version' => ShortDramaStoryDraft::version($request), 'field' => $field,
            'content' => $targetContent, 'content_hash' => hash('sha256', $targetContent)];
        $fingerprint = [
            $canvasId, $binding['binding_revision'], $source['node_id'], $source['content_revision'],
            $source['content_hash'], $target['project_id'], $target['task_id'], $target['project_version_id'],
            $target['draft_version'], $target['field'], $target['content_hash'],
        ];
        return ['binding' => $binding, 'source' => $source, 'target' => $target,
            'preview_hash' => hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'can_apply' => false];
    }
}
