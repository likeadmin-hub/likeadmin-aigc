<?php

namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan;
use think\facade\Db;

/** Scoped, version-checked source discovery and confirmed formal writeback. */
final class ShortDramaCanvasWritebackService
{
    private const TEXT_ARTIFACTS = ['story_setting', 'episode_script', 'episode_outline', 'storyboard_script'];
    private const STORY_FIELDS = ['title', 'type_judgement', 'core_theme', 'story_outline'];
    private const EPISODE_FIELDS = ['title', 'story_outline'];

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
            if (!is_array($node) || !in_array((string)($node['type'] ?? ''), ['text', 'image'], true)) continue;
            $id = (string)($node['id'] ?? '');
            if ($id === '' || isset($removed[$id]) || isset($seen[$id])) continue;
            $seen[$id] = true;
            $meta = (array)($node['metadata'] ?? []);
            $artifact = (string)($meta['workflow_artifact'] ?? '');
            $isShot = (string)$node['type'] === 'image'
                && (string)($meta['workflow_source_stage'] ?? '') === 'storyboard'
                && $artifact === 'storyboard';
            if (!$isShot && ((string)$node['type'] !== 'text'
                || (string)($meta['workflow_source_stage'] ?? '') !== 'script'
                || !in_array($artifact, self::TEXT_ARTIFACTS, true))) continue;
            $content = (string)($meta[$isShot ? 'prompt' : 'content'] ?? '');
            if ($isShot) $content = trim($content);
            if ($content === '' || ($isShot && mb_strlen($content, 'UTF-8') > 2000)) continue;
            $formalFields = $isShot ? ['visual_description' => $content] : self::formalFields($artifact, $meta, $content);
            $sources[] = [
                'node_id' => $id,
                'artifact' => $artifact,
                'title' => (string)($node['title'] ?? ''),
                'content_revision' => (int)($meta['content_revision'] ?? 0),
                'content_hash' => hash('sha256', $content),
                'writeback_fields' => array_keys($formalFields),
            ];
        }
        $shotTargets = [];
        if ($binding['bound'] && (int)$binding['episode_id'] > 0) {
            $project = Db::name('aigc_short_drama_project')->where([
                'id' => (int)$binding['production_project_id'], 'tenant_id' => $tenantId,
                'user_id' => $userId, 'delete_time' => 0,
            ])->field('last_task_id')->find();
            if ($project && (string)$project['last_task_id'] !== '') {
                $rows = Db::name('aigc_short_drama_storyboard')->where([
                    'project_id' => (int)$binding['production_project_id'], 'tenant_id' => $tenantId,
                    'user_id' => $userId, 'task_id' => (string)$project['last_task_id'], 'delete_time' => 0,
                ])->field('shot_id,title,sort')->order('sort,id')->limit(500)->select()->toArray();
                foreach ($rows as $row) $shotTargets[] = [
                    'shot_id' => (string)$row['shot_id'], 'title' => (string)$row['title'], 'sort' => (int)$row['sort'],
                ];
            }
        }
        return ['binding' => $binding, 'graph_revision' => (int)($document['graph_revision'] ?? 0),
            'sources' => $sources, 'shot_targets' => $shotTargets];
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
            $formalFields = self::formalFields('story_setting', $meta, $content);
            if (!isset($formalFields[$field])) break;
            $source = ['node_id' => $nodeId, 'content' => $formalFields[$field],
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
            $source['content_hash'], hash('sha256', $source['content']), $target['project_id'], $target['task_id'], $target['project_version_id'],
            $target['draft_version'], $target['field'], $target['content_hash'],
        ];
        return ['binding' => $binding, 'source' => $source, 'target' => $target,
            'preview_hash' => hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'can_apply' => true];
    }

    /** Apply exactly the reviewed field. Replays return the original receipt. */
    public static function applyStory(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        $previewHash = (string)($params['preview_hash'] ?? '');
        if ($canvasId <= 0 || ($params['confirm'] ?? null) !== '1'
            || !preg_match('/^[a-f0-9]{64}$/D', $previewHash)) {
            throw new InvalidArgumentException('请先预览差异并逐项确认写回');
        }
        return Db::transaction(function () use ($tenantId, $userId, $params, $canvasId, $previewHash): array {
            $canvas = Db::name('aigc_short_drama_canvas')->where([
                'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$canvas) throw new InvalidArgumentException('画布项目不存在或无权访问');
            $bindingRow = Db::name('aigc_short_drama_canvas_binding')->where([
                'canvas_id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId,
            ])->lock(true)->find();
            if (!$bindingRow || (int)$bindingRow['episode_id'] !== 0) {
                throw new InvalidArgumentException('请先绑定故事项目');
            }
            $projectId = (int)$bindingRow['project_id'];
            $project = Db::name('aigc_short_drama_project')->where([
                'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$project) throw new InvalidArgumentException('正式项目不存在或无权访问');
            $taskId = (string)($project['last_task_id'] ?? '');
            $task = Db::name('aigc_short_drama_script_task')->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
                'task_id' => $taskId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$task) throw new InvalidArgumentException('正式故事任务不存在');
            $request = ShortDramaEpisodeService::decode((string)$task['request_json']);
            foreach ((array)($request['_canvas_writeback_receipts'] ?? []) as $receipt) {
                if (($receipt['preview_hash'] ?? '') === $previewHash) return $receipt;
            }
            $preview = self::previewStory($tenantId, $userId, $params);
            if (!hash_equals($preview['preview_hash'], $previewHash)) {
                throw new InvalidArgumentException('VERSION_CONFLICT: 来源或正式项目已变化，请重新预览');
            }
            $save = ShortDramaStoryDraft::save($tenantId, $userId, [
                'task_id' => $taskId, 'draft_version' => $preview['target']['draft_version'],
                'stage' => 'story', 'result' => [$preview['target']['field'] => $preview['source']['content']],
            ]);
            $updated = Db::name('aigc_short_drama_script_task')->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
                'task_id' => $taskId, 'delete_time' => 0,
            ])->lock(true)->find();
            $updatedRequest = ShortDramaEpisodeService::decode((string)$updated['request_json']);
            $receipt = ['applied' => true, 'preview_hash' => $previewHash,
                'project_id' => $projectId, 'task_id' => $taskId,
                'target_field' => $preview['target']['field'],
                'draft_version' => (int)$save['draft_version']];
            $receipts = array_values((array)($updatedRequest['_canvas_writeback_receipts'] ?? []));
            $receipts[] = $receipt;
            $updatedRequest['_canvas_writeback_receipts'] = array_slice($receipts, -20);
            Db::name('aigc_short_drama_script_task')->where('id', $updated['id'])->update([
                'request_json' => json_encode($updatedRequest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'update_time' => time(),
            ]);
            return $receipt;
        });
    }

    /** A single owned episode-outline row, never the free-form episode script. */
    public static function previewEpisode(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        $nodeId = (string)($params['source_node_id'] ?? '');
        $field = (string)($params['target_field'] ?? '');
        if ($canvasId <= 0 || !ctype_digit($nodeId) || (int)$nodeId <= 0
            || !in_array($field, self::EPISODE_FIELDS, true)) throw new InvalidArgumentException('分集写回参数无效');
        $binding = ShortDramaCanvasBindingService::current($tenantId, $userId, $canvasId);
        if (!$binding['bound'] || (int)$binding['episode_id'] !== 0) throw new InvalidArgumentException('请先绑定故事项目');
        $document = Db::name('aigc_short_drama_canvas')->where([
            'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('nodes_json,removed_node_ids_json')->find();
        $nodes = json_decode((string)($document['nodes_json'] ?? '[]'), true);
        $removed = json_decode((string)($document['removed_node_ids_json'] ?? '[]'), true);
        if (is_array($removed) && in_array($nodeId, array_map('strval', $removed), true)) throw new InvalidArgumentException('来源节点已删除');
        $source = null;
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if ((string)($node['id'] ?? '') !== $nodeId) continue;
            $meta = (array)($node['metadata'] ?? []);
            if (($node['type'] ?? '') !== 'text' || ($meta['workflow_source_stage'] ?? '') !== 'script'
                || ($meta['workflow_artifact'] ?? '') !== 'episode_script') break;
            $content = (string)($meta['content'] ?? '');
            $fields = self::formalFields('episode_script', $meta, $content);
            if (!isset($fields[$field])) break;
            $source = ['node_id' => $nodeId, 'episode_number' => $fields['episode_number'],
                'content' => $fields[$field], 'content_revision' => (int)($meta['content_revision'] ?? 0),
                'content_hash' => hash('sha256', $content)];
            break;
        }
        if (!$source) throw new InvalidArgumentException('分集来源不存在或不适合正式写回');
        $projectId = (int)$binding['project_id'];
        $project = Db::name('aigc_short_drama_project')->where([
            'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('last_task_id,current_version_id')->find();
        $taskId = (string)($project['last_task_id'] ?? '');
        $task = Db::name('aigc_short_drama_script_task')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
            'task_id' => $taskId, 'delete_time' => 0,
        ])->field('status,request_json,result_json')->find();
        $request = ShortDramaEpisodeService::decode((string)($task['request_json'] ?? ''));
        if (($task['status'] ?? '') !== 'success' || !ShortDramaStoryWorkflow::enabled($request)
            || ShortDramaStoryDraft::stage($request) !== 'episodes'
            || Db::name('aigc_short_drama_episode_task')->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'delete_time' => 0,
            ])->count()) throw new InvalidArgumentException('正式分集大纲当前不可编辑');
        $result = ShortDramaStoryDraft::effective($request, ShortDramaEpisodeService::decode((string)$task['result_json']));
        $targetContent = null;
        foreach ((array)($result['episodes'] ?? []) as $episode) {
            if ((int)($episode['episode_number'] ?? 0) !== $source['episode_number']) continue;
            $targetContent = (string)($episode[$field] ?? '');
            break;
        }
        if ($targetContent === null) throw new InvalidArgumentException('正式大纲中不存在对应集号');
        $target = ['project_id' => $projectId, 'task_id' => $taskId,
            'episode_number' => $source['episode_number'], 'field' => $field,
            'project_version_id' => (int)($project['current_version_id'] ?? 0),
            'draft_version' => ShortDramaStoryDraft::version($request),
            'content' => $targetContent, 'content_hash' => hash('sha256', $targetContent)];
        $fingerprint = [$canvasId, $binding['binding_revision'], $source['node_id'],
            $source['content_revision'], $source['content_hash'], hash('sha256', $source['content']),
            $target['project_id'], $target['task_id'], $target['episode_number'],
            $target['project_version_id'], $target['draft_version'], $target['field'], $target['content_hash']];
        return ['binding' => $binding, 'source' => $source, 'target' => $target,
            'preview_hash' => hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'can_apply' => true];
    }

    public static function applyEpisode(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        $previewHash = (string)($params['preview_hash'] ?? '');
        if ($canvasId <= 0 || ($params['confirm'] ?? null) !== '1'
            || !preg_match('/^[a-f0-9]{64}$/D', $previewHash)) {
            throw new InvalidArgumentException('请先预览差异并逐项确认写回');
        }
        return Db::transaction(function () use ($tenantId, $userId, $params, $canvasId, $previewHash): array {
            $canvas = Db::name('aigc_short_drama_canvas')->where([
                'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$canvas) throw new InvalidArgumentException('画布项目不存在或无权访问');
            $binding = Db::name('aigc_short_drama_canvas_binding')->where([
                'canvas_id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId,
            ])->lock(true)->find();
            if (!$binding || (int)$binding['episode_id'] !== 0) throw new InvalidArgumentException('请先绑定故事项目');
            $projectId = (int)$binding['project_id'];
            $project = Db::name('aigc_short_drama_project')->where([
                'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$project) throw new InvalidArgumentException('正式项目不存在或无权访问');
            $taskId = (string)($project['last_task_id'] ?? '');
            $scope = ['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
                'task_id' => $taskId, 'delete_time' => 0];
            $task = Db::name('aigc_short_drama_script_task')->where($scope)->lock(true)->find();
            if (!$task) throw new InvalidArgumentException('正式分集任务不存在');
            $request = ShortDramaEpisodeService::decode((string)$task['request_json']);
            foreach ((array)($request['_canvas_writeback_receipts'] ?? []) as $receipt) {
                if (($receipt['preview_hash'] ?? '') === $previewHash) return $receipt;
            }
            $preview = self::previewEpisode($tenantId, $userId, $params);
            if (!hash_equals($preview['preview_hash'], $previewHash)) {
                throw new InvalidArgumentException('VERSION_CONFLICT: 来源或正式大纲已变化，请重新预览');
            }
            $result = ShortDramaStoryDraft::effective($request, ShortDramaEpisodeService::decode((string)$task['result_json']));
            $episodes = (array)($result['episodes'] ?? []);
            $found = false;
            foreach ($episodes as &$episode) {
                if ((int)($episode['episode_number'] ?? 0) !== $preview['target']['episode_number']) continue;
                $episode[$preview['target']['field']] = $preview['source']['content'];
                $found = true;
                break;
            }
            unset($episode);
            if (!$found) throw new InvalidArgumentException('正式大纲中不存在对应集号');
            $save = ShortDramaStoryDraft::save($tenantId, $userId, [
                'task_id' => $taskId, 'draft_version' => $preview['target']['draft_version'],
                'stage' => 'episodes', 'result' => ['episodes' => $episodes],
            ]);
            $updated = Db::name('aigc_short_drama_script_task')->where($scope)->lock(true)->find();
            $updatedRequest = ShortDramaEpisodeService::decode((string)$updated['request_json']);
            $receipt = ['applied' => true, 'preview_hash' => $previewHash,
                'project_id' => $projectId, 'task_id' => $taskId,
                'episode_number' => $preview['target']['episode_number'],
                'target_field' => $preview['target']['field'], 'draft_version' => (int)$save['draft_version']];
            $receipts = array_values((array)($updatedRequest['_canvas_writeback_receipts'] ?? []));
            $receipts[] = $receipt;
            $updatedRequest['_canvas_writeback_receipts'] = array_slice($receipts, -20);
            Db::name('aigc_short_drama_script_task')->where('id', $updated['id'])->update([
                'request_json' => json_encode($updatedRequest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'update_time' => time(),
            ]);
            return $receipt;
        });
    }

    /** An Agent storyboard image prompt becomes one formal shot's visual description. */
    public static function previewShot(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        $nodeId = (string)($params['source_node_id'] ?? '');
        $shotId = trim((string)($params['target_shot_id'] ?? ''));
        if ($canvasId <= 0 || !ctype_digit($nodeId) || (int)$nodeId <= 0
            || $shotId === '' || mb_strlen($shotId, 'UTF-8') > 40) {
            throw new InvalidArgumentException('分镜写回参数无效');
        }
        $binding = ShortDramaCanvasBindingService::current($tenantId, $userId, $canvasId);
        if (!$binding['bound'] || (int)$binding['episode_id'] <= 0 || (int)$binding['production_project_id'] <= 0) {
            throw new InvalidArgumentException('请先绑定已创建制作项目的剧集');
        }
        $episode = Db::name('aigc_short_drama_episode_task')->where([
            'id' => (int)$binding['episode_id'], 'tenant_id' => $tenantId, 'user_id' => $userId,
            'project_id' => (int)$binding['project_id'], 'delete_time' => 0,
        ])->field('production_project_id,status')->find();
        if (!$episode || (int)$episode['production_project_id'] !== (int)$binding['production_project_id']
            || (string)$episode['status'] !== 'success') {
            throw new InvalidArgumentException('绑定剧集已变化或尚不可编辑');
        }
        $document = Db::name('aigc_short_drama_canvas')->where([
            'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('nodes_json,removed_node_ids_json')->find();
        $nodes = json_decode((string)($document['nodes_json'] ?? '[]'), true);
        $removed = json_decode((string)($document['removed_node_ids_json'] ?? '[]'), true);
        if (is_array($removed) && in_array($nodeId, array_map('strval', $removed), true)) {
            throw new InvalidArgumentException('来源节点已删除');
        }
        $source = null;
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (!is_array($node) || (string)($node['id'] ?? '') !== $nodeId) continue;
            $meta = (array)($node['metadata'] ?? []);
            if ((string)($node['type'] ?? '') !== 'image'
                || (string)($meta['workflow_source_stage'] ?? '') !== 'storyboard'
                || (string)($meta['workflow_artifact'] ?? '') !== 'storyboard') break;
            $content = trim((string)($meta['prompt'] ?? ''));
            if ($content === '' || mb_strlen($content, 'UTF-8') > 2000) break;
            $source = ['node_id' => $nodeId, 'content' => $content,
                'content_revision' => (int)($meta['content_revision'] ?? 0),
                'content_hash' => hash('sha256', $content)];
            break;
        }
        if (!$source) throw new InvalidArgumentException('分镜来源不存在、超长或不适合正式写回');
        $projectId = (int)$binding['production_project_id'];
        $project = Db::name('aigc_short_drama_project')->where([
            'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('last_task_id,current_version_id')->find();
        $taskId = (string)($project['last_task_id'] ?? '');
        $task = Db::name('aigc_short_drama_script_task')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
            'task_id' => $taskId, 'delete_time' => 0,
        ])->field('status')->find();
        if ($taskId === '' || ($task['status'] ?? '') !== 'success') {
            throw new InvalidArgumentException('正式剧集尚无可编辑的当前分镜');
        }
        $shot = Db::name('aigc_short_drama_storyboard')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId,
            'task_id' => $taskId, 'shot_id' => $shotId, 'delete_time' => 0,
        ])->find();
        if (!$shot) throw new InvalidArgumentException('正式镜头不存在或无权访问');
        $targetContent = (string)($shot['visual_description'] ?? '');
        $target = ['project_id' => $projectId, 'task_id' => $taskId,
            'shot_id' => $shotId, 'field' => 'visual_description',
            'project_version_id' => (int)($project['current_version_id'] ?? 0),
            'content' => $targetContent, 'content_hash' => hash('sha256', $targetContent),
            'shot_hash' => hash('sha256', json_encode($shot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
        $fingerprint = [$canvasId, $binding['binding_revision'], $source['node_id'],
            $source['content_revision'], $source['content_hash'], $binding['episode_id'],
            $target['project_id'], $target['task_id'], $target['shot_id'],
            $target['project_version_id'], $target['shot_hash']];
        return ['binding' => $binding, 'source' => $source, 'target' => $target,
            'preview_hash' => hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'can_apply' => true, 'prompt_policy' => 'rebuild_from_shot_fields'];
    }

    public static function applyShot(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        $previewHash = (string)($params['preview_hash'] ?? '');
        if ($canvasId <= 0 || ($params['confirm'] ?? null) !== '1'
            || !preg_match('/^[a-f0-9]{64}$/D', $previewHash)) {
            throw new InvalidArgumentException('请先预览差异并逐镜确认写回');
        }
        return Db::transaction(function () use ($tenantId, $userId, $params, $canvasId, $previewHash): array {
            $canvas = Db::name('aigc_short_drama_canvas')->where([
                'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$canvas) throw new InvalidArgumentException('画布项目不存在或无权访问');
            $binding = Db::name('aigc_short_drama_canvas_binding')->where([
                'canvas_id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId,
            ])->lock(true)->find();
            if (!$binding || (int)$binding['episode_id'] <= 0) throw new InvalidArgumentException('请先绑定剧集');
            $projectId = (int)$binding['production_project_id'];
            $project = Db::name('aigc_short_drama_project')->where([
                'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$project) throw new InvalidArgumentException('正式制作项目不存在或无权访问');
            $taskId = (string)$project['last_task_id'];
            $scope = ['tenant_id' => $tenantId, 'user_id' => $userId,
                'project_id' => $projectId, 'task_id' => $taskId, 'delete_time' => 0];
            $task = Db::name('aigc_short_drama_script_task')->where($scope)->lock(true)->find();
            if (!$task) throw new InvalidArgumentException('正式分镜任务不存在');
            $request = ShortDramaEpisodeService::decode((string)$task['request_json']);
            foreach ((array)($request['_canvas_writeback_receipts'] ?? []) as $receipt) {
                if (($receipt['preview_hash'] ?? '') === $previewHash) return $receipt;
            }
            $shotId = trim((string)($params['target_shot_id'] ?? ''));
            $shot = Db::name('aigc_short_drama_storyboard')->where($scope + ['shot_id' => $shotId])->lock(true)->find();
            if (!$shot) throw new InvalidArgumentException('正式镜头不存在或无权访问');
            $preview = self::previewShot($tenantId, $userId, $params);
            if (!hash_equals($preview['preview_hash'], $previewHash)) {
                throw new InvalidArgumentException('VERSION_CONFLICT: 来源或正式镜头已变化，请重新预览');
            }
            $payload = $shot;
            $payload['subject_ref_ids'] = ShortDramaEpisodeService::decode((string)($shot['subject_ref_ids'] ?? ''));
            $payload['visual_description'] = $preview['source']['content'];
            AigcShortDramaService::saveStoryboard($tenantId, $userId, $payload + [
                'project_id' => $projectId, 'task_id' => $taskId, 'shot_id' => $shotId,
            ]);
            $updated = Db::name('aigc_short_drama_script_task')->where($scope)->lock(true)->find();
            $updatedRequest = ShortDramaEpisodeService::decode((string)$updated['request_json']);
            $receipt = ['applied' => true, 'preview_hash' => $previewHash,
                'project_id' => $projectId, 'task_id' => $taskId, 'shot_id' => $shotId,
                'target_field' => 'visual_description',
                'project_version_id' => (int)Db::name('aigc_short_drama_project')->where('id', $projectId)->value('current_version_id')];
            $receipts = array_values((array)($updatedRequest['_canvas_writeback_receipts'] ?? []));
            $receipts[] = $receipt;
            $updatedRequest['_canvas_writeback_receipts'] = array_slice($receipts, -20);
            Db::name('aigc_short_drama_script_task')->where('id', $updated['id'])->update([
                'request_json' => json_encode($updatedRequest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'update_time' => time(),
            ]);
            return $receipt;
        });
    }

    /** Legacy prose and manually edited prose are deliberately not guessed into formal fields. */
    private static function formalFields(string $artifact, array $meta, string $content): array
    {
        if ((string)($meta['workflow_formal_content_hash'] ?? '') !== hash('sha256', $content)) return [];
        try {
            return ConversationActionPlan::formalFields($artifact, $meta['workflow_formal_fields'] ?? null);
        } catch (\RuntimeException) {
            return [];
        }
    }
}
