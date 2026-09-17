<?php

namespace app\common\service\app\aigc_short_drama\canvas;

use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use app\common\service\FileService;
use think\facade\Db;

/** Short-drama canvas storage. Formal short-drama writes are handled by a separate bridge service. */
final class CanvasWorkspaceService
{
    private int $tenantId;
    private int $userId;
    private ?bool $schemaReady = null;

    public function __construct(int $tenantId, int $userId)
    {
        if ($tenantId <= 0 || $userId <= 0) CanvasPolicy::fail('AUTH_REQUIRED', '请登录后使用画布');
        $this->tenantId = $tenantId; $this->userId = $userId;
    }

    private function table(string $suffix)
    {
        return Db::name('aigc_short_drama_canvas_' . $suffix)->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId]);
    }

    private function insert(string $suffix, array $data): int
    {
        return (int)Db::name('aigc_short_drama_canvas_' . $suffix)->insertGetId($data + [
            'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'create_time' => time(), 'update_time' => time()]);
    }

    public function capabilities(): array
    {
        $settings = CanvasTenantConfigService::detail($this->tenantId);
        $enabled = (bool)$settings['enabled'] && !(bool)$settings['read_only'];
        if ($this->schemaReady === null) {
            $this->schemaReady = false;
            try {
                foreach (['workspace', 'view', 'message', 'draft', 'run', 'action', 'event'] as $table) $this->table($table)->limit(1)->value('id');
                $this->schemaReady = true;
            } catch (\Throwable $e) { /* No automatic migrations on a user request. */ }
        }
        $schemaReady = $this->schemaReady;
        $agentReady = false;
        $agentMessage = '';
        if ($enabled && $schemaReady) {
            $provider = CanvasExecutionRuntime::provider($this->tenantId, $this->userId);
            $agentReady = (bool)config('short_drama_canvas.execution_ready', false) && $provider->isReady();
            if (!$agentReady) $agentMessage = $provider->unavailableMessage();
        }
        return ['enabled' => $enabled && $schemaReady, 'read_only' => !$enabled || !$schemaReady, 'schema_ready' => $schemaReady,
            'execution_ready' => $agentReady, 'max_nodes' => CanvasPolicy::MAX_NODES,
            'message' => !$schemaReady ? '画布尚未初始化，请联系管理员' : (!$enabled ? '短剧画布暂未开放，已有内容仅供查看' : ($agentReady ? '画布 Agent 将使用短剧租户资源状态中的模型和原有短剧任务链' : $agentMessage))];
    }

    public function assertWritable(): void
    {
        if (!$this->capabilities()['enabled']) CanvasPolicy::fail('CANVAS_DISABLED', '短剧画布暂未开放或尚未初始化');
    }

    private function workspace(int $id, bool $lock = false): array
    {
        $query = $this->table('workspace')->where(['id' => $id, 'delete_time' => 0]);
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if (!$row) CanvasPolicy::fail('NOT_FOUND', '工作区不存在或无权访问');
        // A deleted/reassigned bound project cannot expose its saved context.
        if ((int)$row['project_id'] > 0) $this->project((int)$row['project_id']);
        return $row;
    }

    private function project(int $id): array
    {
        $scope = ['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'id' => $id, 'delete_time' => 0];
        $row = Db::name('aigc_short_drama_project')->where($scope)->find();
        if (!$row) CanvasPolicy::fail('PROJECT_NOT_FOUND', '项目不存在或无权访问');
        if (Db::name('aigc_short_drama_episode_task')->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'production_project_id' => $id, 'delete_time' => 0])->find()) CanvasPolicy::fail('ROOT_PROJECT_REQUIRED', '请绑定主项目，分集制作通过分集视图进入');
        return $row;
    }

    public function create(array $params): array
    {
        $this->assertWritable();
        $key = CanvasPolicy::key($params['request_key'] ?? null);
        $prompt = CanvasPolicy::text($params['prompt'] ?? '');
        $title = CanvasPolicy::text($params['title'] ?? '未命名工作区', 120);
        $projectId = max(0, (int)($params['project_id'] ?? 0));
        $skillId = max(0, (int)($params['skill_id'] ?? 0)); $skillVersion = max(0, (int)($params['skill_version'] ?? 0));
        $attachments = CanvasPolicy::attachments($params['attachments'] ?? []);
        $hash = CanvasPolicy::hash([$prompt, $title, $projectId, $skillId, $skillVersion, $attachments]);
        $existing = $this->table('workspace')->where('request_key', $key)->find();
        if ($existing) return $this->replayWorkspace($existing, $hash);
        if ($projectId) $this->project($projectId);
        try { $snapshot = ShortDramaSkillService::resolveForTask($this->tenantId, ['skill_id' => $skillId, 'skill_version' => $skillVersion]); }
        catch (\Exception $e) { CanvasPolicy::fail('SKILL_UNAVAILABLE', '所选 Skill 未发布、已停用或版本改变，请重新选择'); }
        try {
            $id = Db::transaction(function () use ($key, $hash, $title, $prompt, $projectId, $snapshot, $attachments) {
                $id = $this->insert('workspace', ['request_key' => $key, 'request_hash' => $hash, 'title' => $title ?: '未命名工作区', 'project_id' => $projectId ?: null, 'skill_snapshot_json' => CanvasPolicy::encode($snapshot)]);
                $nodes = $prompt === '' ? [] : [['id' => 'idea_' . bin2hex(random_bytes(8)), 'type' => 'text', 'title' => '创作想法', 'position' => ['x' => 0, 'y' => 0], 'width' => 320, 'height' => 250, 'metadata' => ['content' => $prompt, 'asset_id' => 0]]];
                foreach ($attachments as $index => $attachment) {
                    $nodes[] = ['id' => $attachment['key'], 'type' => $attachment['kind'], 'title' => $attachment['name'],
                        'position' => ['x' => ($index + 1) * 360, 'y' => 0], 'width' => 320, 'height' => 250,
                        'metadata' => ['content' => $attachment['content'] ?? '图片尚未上传，请重新选择原文件导入', 'asset_id' => 0]];
                    if ($attachment['kind'] === 'image') $this->insert('action', ['workspace_id' => $id, 'request_key' => $attachment['key'],
                        'request_hash' => $attachment['checksum'], 'kind' => 'image_upload', 'status' => 'awaiting_upload',
                        'proposal_json' => CanvasPolicy::encode(['node_id' => $attachment['key']]), 'result_json' => '[]']);
                }
                $this->insert('view', ['workspace_id' => $id, 'view_key' => 'global', 'layout_json' => CanvasPolicy::encode(['nodes' => $nodes, 'connections' => [], 'viewport' => ['x' => 200, 'y' => 180, 'k' => 1]])]);
                if ($prompt !== '') $this->insert('message', ['workspace_id' => $id, 'view_key' => 'global', 'request_key' => 'initial_' . $id, 'request_hash' => CanvasPolicy::hash([$prompt, $attachments]), 'role' => 'user', 'content' => $prompt,
                    'attachments_json' => CanvasPolicy::encode(array_map(fn($item) => array_intersect_key($item, array_flip(['key', 'kind', 'name'])), $attachments))]);
                $this->event($id, 'workspace_created', ['workspace_id' => $id]);
                return $id;
            });
        } catch (\Throwable $e) {
            $existing = $this->table('workspace')->where('request_key', $key)->find();
            if ($existing) return $this->replayWorkspace($existing, $hash);
            if ($projectId && $this->table('workspace')->where('project_id', $projectId)->find()) CanvasPolicy::fail('PROJECT_ALREADY_BOUND', '此项目已有画布，请从已有工作区进入');
            throw $e;
        }
        return $this->detail($id);
    }

    private function replayWorkspace(array $row, string $hash): array
    {
        if (!hash_equals($row['request_hash'], $hash)) CanvasPolicy::fail('IDEMPOTENCY_CONFLICT', '相同请求标识不能用于不同内容');
        return $this->detail((int)$row['id']);
    }

    public function lists(array $params): array
    {
        $limit = min(50, max(1, (int)($params['limit'] ?? 20)));
        $rows = $this->table('workspace')->where('delete_time', 0)->order('id', 'desc');
        if ((int)($params['before_id'] ?? 0) > 0) $rows->where('id', '<', (int)$params['before_id']);
        $found = $rows->field('id,title,project_id,version,update_time')->limit($limit)->select()->toArray();
        $projectIds = array_filter(array_column($found, 'project_id'));
        $ownedIds = $projectIds ? Db::name('aigc_short_drama_project')->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'delete_time' => 0])->whereIn('id', $projectIds)->column('id') : [];
        return ['lists' => array_values(array_filter($found, fn($row) => !$row['project_id'] || in_array($row['project_id'], $ownedIds)))];
    }

    public function detail(int $id): array
    {
        $workspace = $this->workspace($id);
        unset($workspace['request_hash'], $workspace['request_key']);
        $workspace['skill'] = CanvasPolicy::decode($workspace['skill_snapshot_json']); unset($workspace['skill_snapshot_json']);
        return ['workspace' => $workspace, 'capabilities' => $this->capabilities(),
            'views' => $this->availableViews($workspace),
            'view' => $this->readView($id, 'global'), 'messages' => $this->messages($id, [])['lists']];
    }

    /** Read-only projection of the short-drama project's episodes. Canvas layouts remain separate. */
    private function availableViews(array $workspace): array
    {
        $existing = $this->table('view')->where('workspace_id', $workspace['id'])
            ->field('id,view_key,episode_id,production_project_id,version')->order('id')->select()->toArray();
        $mapped = [];
        foreach ($existing as $row) $mapped[(string)$row['view_key']] = $row;
        if ((int)$workspace['project_id'] > 0) {
            $episodes = Db::name('aigc_short_drama_episode_task')->where([
                'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'project_id' => $workspace['project_id'], 'delete_time' => 0,
            ])->field('id,production_project_id')->order('id')->select()->toArray();
            foreach ($episodes as $episode) {
                $key = 'episode_' . (int)$episode['id'];
                if (!isset($mapped[$key])) $mapped[$key] = ['id' => 0, 'view_key' => $key, 'episode_id' => (int)$episode['id'],
                    'production_project_id' => (int)$episode['production_project_id'], 'version' => 0];
            }
        }
        $global = $mapped['global'] ?? ['id' => 0, 'view_key' => 'global', 'episode_id' => 0, 'production_project_id' => 0, 'version' => 0];
        unset($mapped['global']);
        return array_merge([$global], array_values($mapped));
    }

    private function viewKey(int $workspaceId, string $key): array
    {
        $workspace = $this->workspace($workspaceId);
        if ($key === 'global') return ['episode_id' => 0, 'production_project_id' => 0];
        if (!preg_match('/^episode_([1-9][0-9]*)$/D', $key, $match) || !$workspace['project_id']) CanvasPolicy::fail('INVALID_VIEW', '分集视图无效');
        $episode = Db::name('aigc_short_drama_episode_task')->where(['id' => (int)$match[1], 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'project_id' => $workspace['project_id'], 'delete_time' => 0])->find();
        if (!$episode) CanvasPolicy::fail('EPISODE_NOT_FOUND', '分集不存在或不属于当前项目');
        return ['episode_id' => (int)$episode['id'], 'production_project_id' => (int)$episode['production_project_id']];
    }

    public function readView(int $workspaceId, string $key): array
    {
        $mapping = $this->viewKey($workspaceId, $key);
        $row = $this->table('view')->where(['workspace_id' => $workspaceId, 'view_key' => $key])->find();
        if (!$row) return ['view_key' => $key, 'version' => 0, 'layout' => ['nodes' => [], 'connections' => [], 'viewport' => ['x' => 200, 'y' => 180, 'k' => 1]]] + $mapping;
        $layout = CanvasPolicy::decode($row['layout_json']);
        $assets = $this->assets($workspaceId, array_column(array_column($layout['nodes'], 'metadata'), 'asset_id'));
        foreach ($layout['nodes'] as &$node) {
            if ((int)($node['metadata']['asset_id'] ?? 0) > 0) {
                $asset = $assets[(int)$node['metadata']['asset_id']] ?? null;
                $node['metadata']['url'] = $asset ? $this->assetUrl($asset) : '';
                $node['metadata']['unavailable'] = !$asset;
            }
        }
        return ['view_key' => $key, 'version' => (int)$row['version'], 'layout' => $layout] + $mapping;
    }

    public function saveView(int $workspaceId, array $params): array
    {
        $this->assertWritable(); $key = (string)($params['view_key'] ?? 'global');
        $mapping = $this->viewKey($workspaceId, $key);
        $version = (int)($params['version'] ?? -1);
        $layout = CanvasPolicy::layout((array)($params['layout'] ?? []));
        $assetIds = array_values(array_unique(array_filter(array_column(array_column($layout['nodes'], 'metadata'), 'asset_id'))));
        if (count($this->assets($workspaceId, $assetIds)) !== count($assetIds)) CanvasPolicy::fail('ASSET_NOT_FOUND', '素材不存在或不属于此工作区');
        return Db::transaction(function () use ($workspaceId, $key, $mapping, $version, $layout) {
            $this->workspace($workspaceId, true);
            $row = $this->table('view')->where(['workspace_id' => $workspaceId, 'view_key' => $key])->find();
            if (($row ? (int)$row['version'] : 0) !== $version) CanvasPolicy::fail('VERSION_CONFLICT', '画布已在其他页面修改，请保留本地内容后重新加载');
            if ($row) $this->table('view')->where('id', $row['id'])->update($mapping + ['layout_json' => CanvasPolicy::encode($layout), 'version' => $version + 1, 'update_time' => time()]);
            else $this->insert('view', $mapping + ['workspace_id' => $workspaceId, 'view_key' => $key, 'layout_json' => CanvasPolicy::encode($layout)]);
            return ['view_key' => $key, 'version' => $version + 1];
        });
    }

    public function bind(int $workspaceId, array $params): array
    {
        $this->assertWritable(); $projectId = (int)($params['project_id'] ?? 0); $this->project($projectId);
        return Db::transaction(function () use ($workspaceId, $projectId, $params) {
            $row = $this->workspace($workspaceId, true);
            if ((int)$row['project_id'] === $projectId) return ['project_id' => $projectId, 'version' => (int)$row['version']];
            if ($row['project_id']) CanvasPolicy::fail('ALREADY_BOUND', '工作区绑定后不能切换到其他项目');
            if ((int)($params['version'] ?? -1) !== (int)$row['version']) CanvasPolicy::fail('VERSION_CONFLICT', '工作区已更新，请重新加载');
            if ($this->table('workspace')->where('project_id', $projectId)->find()) CanvasPolicy::fail('PROJECT_ALREADY_BOUND', '此项目已有工作区');
            $this->table('workspace')->where('id', $workspaceId)->update(['project_id' => $projectId, 'version' => (int)$row['version'] + 1, 'update_time' => time()]);
            $this->event($workspaceId, 'project_bound', ['project_id' => $projectId]);
            // Intentionally no project/episode UPDATE here.
            return ['project_id' => $projectId, 'version' => (int)$row['version'] + 1];
        });
    }

    public function saveIdea(int $workspaceId, array $params): array
    {
        $this->assertWritable(); $this->workspace($workspaceId);
        $key = CanvasPolicy::key($params['request_key'] ?? null); $content = CanvasPolicy::text($params['content'] ?? '');
        if ($content === '') CanvasPolicy::fail('EMPTY_MESSAGE', '请输入内容');
        $view = (string)($params['view_key'] ?? 'global'); $this->viewKey($workspaceId, $view);
        $hash = CanvasPolicy::hash([$content, $view]);
        return Db::transaction(function () use ($workspaceId, $key, $content, $view, $hash) {
            $this->workspace($workspaceId, true);
            $old = $this->table('message')->where(['workspace_id' => $workspaceId, 'request_key' => $key])->find();
            if ($old) {
                if (!hash_equals($old['request_hash'], $hash)) CanvasPolicy::fail('IDEMPOTENCY_CONFLICT', '请求标识已用于其他内容');
                return ['id' => (int)$old['id'], 'status' => 'saved'];
            }
            $id = $this->insert('message', ['workspace_id' => $workspaceId, 'view_key' => $view, 'request_key' => $key, 'request_hash' => $hash, 'content' => $content, 'role' => 'user', 'attachments_json' => '[]']);
            return ['id' => $id, 'status' => 'saved'];
        });
    }

    public function messages(int $workspaceId, array $params): array
    {
        $this->workspace($workspaceId);
        $query = $this->table('message')->where('workspace_id', $workspaceId);
        if ((int)($params['before_id'] ?? 0) > 0) $query->where('id', '<', (int)$params['before_id']);
        $rows = $query->field('id,view_key,role,content,run_id,create_time')->order('id', 'desc')->limit(30)->select()->toArray();
        return ['lists' => array_reverse($rows), 'next_before_id' => count($rows) === 30 ? (int)end($rows)['id'] : null];
    }

    public function events(int $workspaceId, int $afterId): array
    {
        $this->workspace($workspaceId);
        $rows = $this->table('event')->where('workspace_id', $workspaceId)->where('id', '>', max(0, $afterId))->field('id,kind,payload_json,create_time')->order('id')->limit(100)->select()->toArray();
        foreach ($rows as &$row) { $row['payload'] = CanvasPolicy::decode($row['payload_json']); unset($row['payload_json']); }
        return ['lists' => $rows];
    }

    private function event(int $workspaceId, string $kind, array $payload): void
    {
        $this->insert('event', ['workspace_id' => $workspaceId, 'event_key' => bin2hex(random_bytes(16)), 'kind' => $kind, 'payload_json' => CanvasPolicy::encode($payload)]);
    }

    private function asset(int $workspaceId, int $id, bool $required = true): ?array
    {
        $row = $this->assets($workspaceId, [$id])[$id] ?? null;
        if (!$row) {
            if ($required) CanvasPolicy::fail('ASSET_NOT_FOUND', '素材不存在或不属于此工作区');
            return null;
        }
        return $row;
    }

    private function assets(int $workspaceId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $rows = Db::name('aigc_short_drama_asset')->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId,
            'project_id' => 0, 'delete_time' => 0, 'status' => 'ready'])->whereIn('id', $ids)->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $meta = json_decode((string)$row['meta_json'], true) ?: [];
            if ((int)($meta['canvas_workspace_id'] ?? 0) === $workspaceId) $result[(int)$row['id']] = $row;
        }
        return $result;
    }

    private function assetUrl(array $row): string
    {
        return FileService::getFileUrlByStorage($row['uri'], $row['storage_scope'], $row['storage_engine'], $row['storage_domain']);
    }

    public function registerUploadedImage(int $workspaceId, int $fileId): array
    {
        $this->assertWritable(); $this->workspace($workspaceId);
        $file = Db::name('tenant_file')->where(['id' => $fileId, 'tenant_id' => $this->tenantId, 'source_id' => $this->userId,
            'source' => \app\common\enum\FileEnum::SOURCE_USER, 'type' => \app\common\enum\FileEnum::IMAGE_TYPE])
            ->where(function ($query) { $query->whereNull('delete_time')->whereOr('delete_time', 0); })->find();
        if (!$file) CanvasPolicy::fail('FILE_NOT_FOUND', '上传文件不存在或无权使用');
        $taskKey = 'canvas_upload_' . $workspaceId . '_' . $fileId;
        return Db::transaction(function () use ($workspaceId, $file, $taskKey) {
            $this->workspace($workspaceId, true);
            $scope = ['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'task_id' => $taskKey];
            $row = Db::name('aigc_short_drama_asset')->where($scope)->find();
            if (!$row) {
                // Dedicated type is excluded by the original short-drama asset catalogue.
                $id = Db::name('aigc_short_drama_asset')->insertGetId($scope + ['project_id' => 0, 'asset_type' => 'canvas_reference_image', 'title' => mb_substr($file['name'], 0, 120),
                    'uri' => $file['uri'], 'storage_scope' => $file['storage_scope'], 'storage_engine' => $file['storage_engine'], 'storage_domain' => $file['storage_domain'],
                    'meta_json' => CanvasPolicy::encode(['canvas_workspace_id' => $workspaceId, 'file_id' => (int)$file['id'], 'source' => 'short_drama_canvas_upload']),
                    'status' => 'ready', 'create_time' => time(), 'update_time' => time(), 'delete_time' => 0]);
                $row = $this->asset($workspaceId, (int)$id);
            }
            return ['asset_id' => (int)$row['id'], 'url' => $this->assetUrl($row), 'title' => $row['title']];
        });
    }

    /** The storage callback runs outside a DB transaction. Uncertain uploads are never replayed. */
    public function uploadImage(int $workspaceId, string $key, string $checksum, callable $upload): array
    {
        $this->assertWritable(); CanvasPolicy::key($key);
        if (!preg_match('/^[a-f0-9]{64}$/D', $checksum)) CanvasPolicy::fail('INVALID_CHECKSUM', '图片校验信息无效');
        $claim = Db::transaction(function () use ($workspaceId, $key, $checksum) {
            $this->workspace($workspaceId, true);
            $row = $this->table('action')->where(['workspace_id' => $workspaceId, 'request_key' => $key])->find();
            if ($row) {
                if ($row['kind'] !== 'image_upload' || !hash_equals($row['request_hash'], $checksum)) CanvasPolicy::fail('IDEMPOTENCY_CONFLICT', '附件内容已改变，请重新选择');
                if ($row['status'] === 'success') return ['result' => CanvasPolicy::decode($row['result_json'])];
                if ($row['status'] !== 'awaiting_upload') CanvasPolicy::fail('UPLOAD_UNCONFIRMED', '该图片正在上传或结果待核对，请勿重复上传；稍后重试查询或联系管理员');
                $id = (int)$row['id'];
                $this->table('action')->where('id', $id)->update(['status' => 'uploading', 'update_time' => time()]);
            } else {
                $id = $this->insert('action', ['workspace_id' => $workspaceId, 'request_key' => $key, 'request_hash' => $checksum,
                    'kind' => 'image_upload', 'status' => 'uploading', 'proposal_json' => '[]', 'result_json' => '[]']);
            }
            return ['id' => $id];
        });
        if (isset($claim['result'])) {
            $asset = $this->asset($workspaceId, (int)$claim['result']['asset_id']);
            return array_replace($claim['result'], ['url' => $this->assetUrl($asset)]);
        }
        try {
            $fileId = (int)$upload();
            return Db::transaction(function () use ($workspaceId, $claim, $fileId) {
                $this->workspace($workspaceId, true);
                $action = $this->table('action')->where('id', $claim['id'])->find();
                if (!$action || $action['status'] !== 'uploading') CanvasPolicy::fail('UPLOAD_UNCONFIRMED', '上传状态已变化，请重新读取画布');
                $result = $this->registerUploadedImage($workspaceId, $fileId);
                $nodeId = CanvasPolicy::decode($action['proposal_json'])['node_id'] ?? '';
                $result['initial_attachment'] = $nodeId !== '';
                if ($nodeId !== '') {
                    $view = $this->table('view')->where(['workspace_id' => $workspaceId, 'view_key' => 'global'])->find();
                    $layout = CanvasPolicy::decode($view['layout_json']); $changed = false;
                    foreach ($layout['nodes'] as &$node) {
                        if ($node['id'] === $nodeId && !(int)($node['metadata']['asset_id'] ?? 0)) {
                            $node['metadata'] = ['content' => '', 'asset_id' => $result['asset_id']]; $changed = true;
                        }
                    }
                    // Never recreate a deleted placeholder or overwrite other layout changes.
                    if ($changed) $this->table('view')->where('id', $view['id'])->update(['version' => (int)$view['version'] + 1,
                        'layout_json' => CanvasPolicy::encode($layout), 'update_time' => time()]);
                }
                $this->table('action')->where('id', $claim['id'])->update(['status' => 'success', 'result_json' => CanvasPolicy::encode($result), 'update_time' => time()]);
                return $result;
            });
        } catch (\Throwable $e) {
            // The storage provider may have accepted the bytes. Do not automatically call it again.
            $this->table('action')->where(['id' => $claim['id'], 'status' => 'uploading'])->update(['status' => 'upload_unknown', 'update_time' => time()]);
            throw $e;
        }
    }

    public function assertExecutionAvailable(int $workspaceId): void
    {
        $this->assertWritable(); $this->workspace($workspaceId);
        CanvasExecutionRuntime::assertReady($this->tenantId, $this->userId);
    }
}
