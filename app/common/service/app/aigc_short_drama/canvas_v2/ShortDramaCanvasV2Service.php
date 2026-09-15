<?php

namespace app\common\service\app\aigc_short_drama\canvas_v2;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use app\common\service\app\aigc_short_drama\canvas\CanvasPromptSafety;
use app\common\service\app\aigc_short_drama\canvas\CanvasTenantConfigService;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketMusicAppRuntimeService;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use think\facade\Db;
use think\facade\Log;

/**
 * The V2 canvas is a short-drama domain service, not an adapter around the
 * standalone canvas.  It keeps graph state in its own tables and delegates
 * only final short-drama operations to the existing short-drama domain API.
 */
final class ShortDramaCanvasV2Service
{
    private const NODE_TYPES = ['text', 'image', 'video', 'music'];
    private const BUSINESS_KINDS = ['', 'script', 'character', 'scene', 'storyboard'];
    private const MEDIA_TYPES = ['image', 'video', 'music'];

    public function __construct(private int $tenantId, private int $userId)
    {
        if ($tenantId <= 0 || $userId <= 0) $this->fail('AUTH_REQUIRED', '请登录后使用短剧画布');
    }

    private function table(string $name)
    {
        return Db::name('aigc_short_drama_canvas_v2_' . $name)->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId]);
    }

    private function fail(string $code, string $message): never { throw new \DomainException($code . ': ' . $message); }
    private function now(): int { return time(); }
    private function json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'; }
    private function decode($value): array { $value = json_decode((string)$value, true); return is_array($value) ? $value : []; }
    private function key($value, string $name = '标识'): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $value)) $this->fail('INVALID_KEY', $name . '格式无效');
        return $value;
    }
    private function text($value, int $max = 20000): string
    {
        $value = trim((string)$value);
        if (mb_strlen($value, 'UTF-8') > $max) $this->fail('TEXT_TOO_LONG', '内容超出长度限制');
        return $value;
    }
    private function schemaReady(): bool
    {
        try { $this->table('workspace')->limit(1)->value('id'); return true; }
        catch (\Throwable) { return false; }
    }

    public function capabilities(): array
    {
        $config = CanvasTenantConfigService::detail($this->tenantId);
        $ready = $this->schemaReady();
        $dependencies = (array)(AigcShortDramaService::dependencies($this->tenantId)['items'] ?? []);
        $textReady = false;
        foreach ($dependencies as $item) if ((string)($item['name'] ?? '') === '剧本策划文本模型') $textReady = !empty($item['ready']);
        $imageGroup = MarketImageModelRuntimeService::modelGroup($this->tenantId);
        $resourceModels = [
            'image' => $this->resourceOptions((array)($imageGroup['options'] ?? [])),
            'video' => $this->resourceOptions(array_merge(MarketVideoModelRuntimeService::options($this->tenantId), MarketVideoAppRuntimeService::options($this->tenantId))),
            'music' => $this->resourceOptions(MarketMusicAppRuntimeService::options($this->tenantId)),
        ];
        return [
            'enabled' => $ready && empty($config['read_only']),
            'read_only' => !$ready || !empty($config['read_only']),
            'schema_ready' => $ready,
            'agent_ready' => $ready && $textReady,
            'message' => !$ready ? '短剧画布 V2 尚未初始化' : (!$textReady ? '短剧租户资源状态中暂无可用的剧本策划文本模型' : '短剧画布使用当前租户资源状态和短剧任务链'),
            'dependencies' => $dependencies,
            // Display-only model identities are read live from the same short
            // drama resource state used again by estimate/submit. No canvas
            // configuration, channel, or price copy is persisted.
            'resource_models' => $resourceModels,
        ];
    }

    private function resourceOptions(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (($row['enabled'] ?? true) === false) || (($row['available'] ?? true) === false) || (int)($row['status'] ?? 1) !== 1) continue;
            $id = trim((string)($row['id'] ?? $row['value'] ?? $row['channel_code'] ?? $row['model_code'] ?? ''));
            if ($id === '') continue;
            $result[] = ['id' => $id, 'name' => (string)($row['name'] ?? $row['label'] ?? $id), 'model_code' => (string)($row['model_code'] ?? ''), 'channel_code' => (string)($row['channel_code'] ?? '')];
        }
        return $result;
    }

    private function writable(): void
    {
        $capabilities = $this->capabilities();
        if (empty($capabilities['enabled'])) $this->fail('CANVAS_READ_ONLY', (string)$capabilities['message']);
    }

    private function workspace(int $id, bool $lock = false): array
    {
        $query = $this->table('workspace')->where(['id' => $id, 'delete_time' => 0]);
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if (!$row) $this->fail('WORKSPACE_NOT_FOUND', '工作区不存在或无权访问');
        if ((int)($row['project_id'] ?? 0) <= 0 || (string)($row['status'] ?? 'ready') !== 'ready') {
            $this->fail('WORKSPACE_NOT_READY', (string)($row['error_message'] ?? '') ?: '工作区正在创建，请稍后刷新');
        }
        return $row;
    }

    private function project(array $workspace): array
    {
        $project = Db::name('aigc_short_drama_project')->where([
            'id' => (int)$workspace['project_id'], 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'delete_time' => 0,
        ])->find();
        if (!$project) $this->fail('PROJECT_NOT_FOUND', '关联短剧项目不存在或无权访问');
        return $project;
    }

    /** Canvas entry creates a normal short-drama planning project immediately. */
    public function create(array $params): array
    {
        $this->writable();
        $requestKey = $this->key($params['request_key'] ?? null, '请求标识');
        $prompt = $this->text($params['prompt'] ?? '');
        if ($prompt === '') $this->fail('EMPTY_PROMPT', '请输入创作想法');
        CanvasPromptSafety::assertAllowed($prompt);
        $skillId = max(0, (int)($params['skill_id'] ?? 0));
        $skillVersion = max(0, (int)($params['skill_version'] ?? 0));
        try { $skill = ShortDramaSkillService::resolveForTask($this->tenantId, ['skill_id' => $skillId, 'skill_version' => $skillVersion]); }
        catch (\Throwable) { $this->fail('SKILL_UNAVAILABLE', '所选短剧 Skill 不可用，请重新选择'); }
        // Reserve the request before starting the normal short-drama planning
        // task.  Repeated clicks can therefore never create a second project.
        $reservedId = Db::transaction(function () use ($requestKey, $prompt, $skill) {
            $existing = $this->table('workspace')->where('request_key', $requestKey)->lock(true)->find();
            if ($existing) {
                if ((string)($existing['status'] ?? '') === 'ready' && (int)($existing['project_id'] ?? 0) > 0) return -(int)$existing['id'];
                if ((string)($existing['status'] ?? '') === 'failed') $this->fail('CREATE_PREVIOUSLY_FAILED', (string)($existing['error_message'] ?? '') ?: '此前创建失败，请使用新的请求重试');
                $this->fail('CREATION_IN_PROGRESS', '该工作区正在创建，请稍后刷新');
            }
            $now = $this->now();
            return (int)Db::name('aigc_short_drama_canvas_v2_workspace')->insertGetId([
                'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'project_id' => null, 'request_key' => $requestKey,
                'title' => mb_substr($prompt, 0, 120, 'UTF-8'), 'status' => 'creating', 'error_message' => '',
                'version' => 1, 'skill_snapshot_json' => $this->json($skill), 'delete_time' => 0,
                'create_time' => $now, 'update_time' => $now,
            ]);
        });
        if ($reservedId < 0) return $this->detail(-$reservedId);
        // This is the existing short-drama project creation flow. It owns script
        // task creation, billing, model resolution and all downstream states.
        try {
            $result = AigcShortDramaService::createScriptPlan($this->tenantId, $this->userId, [
                'prompt' => $prompt, 'skill_id' => $skillId, 'skill_version' => $skillVersion, 'source' => 'short_drama_canvas_v2',
            ]);
        } catch (\Throwable $e) {
            $this->table('workspace')->where('id', $reservedId)->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 255, 'UTF-8'), 'update_time' => $this->now()]);
            throw $e;
        }
        $projectId = (int)($result['project_id'] ?? 0);
        if ($projectId <= 0) {
            $this->table('workspace')->where('id', $reservedId)->update(['status' => 'failed', 'error_message' => '短剧项目创建结果无效', 'update_time' => $this->now()]);
            $this->fail('PROJECT_CREATE_FAILED', '短剧项目创建结果无效');
        }
        try {
            $workspaceId = Db::transaction(function () use ($reservedId, $requestKey, $prompt, $projectId) {
                $reservation = $this->table('workspace')->where('id', $reservedId)->lock(true)->find();
                if (!$reservation) $this->fail('WORKSPACE_NOT_FOUND', '工作区创建记录不存在');
                if ((string)$reservation['status'] === 'ready' && (int)$reservation['project_id'] > 0) return (int)$reservation['id'];
                $now = $this->now();
                $id = (int)$reservation['id'];
                $this->table('workspace')->where('id', $id)->update(['project_id' => $projectId, 'status' => 'ready', 'error_message' => '', 'update_time' => $now]);
                $this->upsertNode($id, ['node_key' => 'brief_' . substr(hash('sha256', $requestKey), 0, 24), 'node_type' => 'text', 'business_kind' => 'script',
                    'title' => '创作想法', 'content' => ['text' => $prompt], 'position' => ['x' => 80, 'y' => 120], 'width' => 360, 'height' => 240, 'z_index' => 1], 0);
                Db::name('aigc_short_drama_canvas_v2_view')->insert([
                    'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $id, 'view_key' => 'global',
                    'viewport_json' => $this->json(['x' => 260, 'y' => 180, 'k' => 1]), 'preferences_json' => '{}', 'version' => 1, 'create_time' => $now, 'update_time' => $now,
                ]);
                $this->message($id, 'initial_' . substr(hash('sha256', $requestKey), 0, 24), 'user', $prompt);
                return $id;
            });
        } catch (\Throwable $e) {
            $this->table('workspace')->where('id', $reservedId)->whereNull('project_id')->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 255, 'UTF-8'), 'update_time' => $this->now()]);
            throw $e;
        }
        return $this->detail($workspaceId);
    }

    public function detail(int $workspaceId): array
    {
        $workspace = $this->workspace($workspaceId);
        $project = $this->project($workspace);
        $nodes = $this->table('node')->where(['workspace_id' => $workspaceId, 'delete_time' => 0])->order('z_index')->order('id')->select()->toArray();
        foreach ($nodes as &$node) {
            $node = $this->formatNode($node);
            $node['task'] = $this->latestAction($workspaceId, (string)$node['node_key']);
        }
        $edges = $this->table('edge')->where(['workspace_id' => $workspaceId, 'delete_time' => 0])->order('id')->select()->toArray();
        foreach ($edges as &$edge) unset($edge['tenant_id'], $edge['user_id'], $edge['delete_time']);
        $view = $this->table('view')->where(['workspace_id' => $workspaceId, 'view_key' => 'global'])->find();
        return ['workspace' => ['id' => (int)$workspace['id'], 'title' => (string)$workspace['title'], 'project_id' => (int)$workspace['project_id'], 'version' => (int)$workspace['version'],
            'skill' => $this->decode($workspace['skill_snapshot_json'])], 'project' => ['id' => (int)$project['id'], 'title' => (string)$project['title'], 'task_id' => (string)($project['last_task_id'] ?? ''), 'status' => (string)($project['status'] ?? '')],
            'graph' => ['nodes' => $nodes, 'edges' => $edges, 'viewport' => $this->decode($view['viewport_json'] ?? '{}'), 'version' => (int)($view['version'] ?? 0)],
            'messages' => $this->messages($workspaceId), 'capabilities' => $this->capabilities()];
    }

    /**
     * Returns the owner's V2 workspace for a project, if it was created from
     * the short-drama canvas.  A missing mapping is deliberately not an error:
     * ordinary short-drama projects must keep using their existing plan route.
     */
    public function projectWorkspace(int $projectId): array
    {
        if ($projectId <= 0) $this->fail('INVALID_PROJECT', '项目标识无效');
        $workspace = $this->table('workspace')->where([
            'project_id' => $projectId,
            'delete_time' => 0,
        ])->find();
        if (!$workspace) return ['workspace_id' => 0, 'status' => ''];
        return [
            'workspace_id' => (int)$workspace['id'],
            'status' => (string)($workspace['status'] ?? ''),
        ];
    }

    public function saveGraph(int $workspaceId, array $params): array
    {
        $this->writable();
        $version = (int)($params['version'] ?? -1);
        $nodes = is_array($params['nodes'] ?? null) ? array_values($params['nodes']) : null;
        $edges = is_array($params['edges'] ?? null) ? array_values($params['edges']) : null;
        if ($nodes === null || $edges === null || count($nodes) > 500 || count($edges) > 1000) $this->fail('INVALID_GRAPH', '画布数据格式无效或超出数量限制');
        $title = $this->text($params['title'] ?? '', 120);
        return Db::transaction(function () use ($workspaceId, $version, $nodes, $edges, $params, $title) {
            $workspace = $this->workspace($workspaceId, true);
            $view = $this->table('view')->where(['workspace_id' => $workspaceId, 'view_key' => 'global'])->lock(true)->find();
            if (!$view || $version !== (int)$view['version']) $this->fail('VERSION_CONFLICT', '画布已在其他页面更新，请重新加载后合并内容');
            $keys = [];
            foreach ($nodes as $node) { $key = $this->upsertNode($workspaceId, (array)$node, (int)($workspace['version'] ?? 0)); $keys[$key] = true; }
            $old = $this->table('node')->where(['workspace_id' => $workspaceId, 'delete_time' => 0])->select()->toArray();
            foreach ($old as $row) if (!isset($keys[(string)$row['node_key']])) $this->table('node')->where('id', $row['id'])->update(['delete_time' => $this->now(), 'update_time' => $this->now()]);
            $edgeKeys = [];
            foreach ($edges as $edge) {
                $edgeKey = $this->key($edge['edge_key'] ?? $edge['id'] ?? null, '连线标识');
                $from = $this->key($edge['from_node_key'] ?? $edge['from'] ?? null, '起点标识'); $to = $this->key($edge['to_node_key'] ?? $edge['to'] ?? null, '终点标识');
                if (!isset($keys[$from], $keys[$to]) || $from === $to) $this->fail('INVALID_EDGE', '连线节点不存在或无效');
                $edgeKeys[$edgeKey] = true; $row = $this->table('edge')->where(['workspace_id' => $workspaceId, 'edge_key' => $edgeKey])->lock(true)->find();
                $data = ['from_node_key' => $from, 'to_node_key' => $to, 'edge_type' => in_array(($edge['edge_type'] ?? 'default'), ['default', 'reference', 'flow'], true) ? $edge['edge_type'] : 'default', 'delete_time' => 0, 'update_time' => $this->now()];
                if ($row) $this->table('edge')->where('id', $row['id'])->update($data + ['version' => (int)$row['version'] + 1]);
                else Db::name('aigc_short_drama_canvas_v2_edge')->insert($data + ['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $workspaceId, 'edge_key' => $edgeKey, 'version' => 1, 'create_time' => $this->now()]);
            }
            foreach ($this->table('edge')->where(['workspace_id' => $workspaceId, 'delete_time' => 0])->select()->toArray() as $row) if (!isset($edgeKeys[(string)$row['edge_key']])) $this->table('edge')->where('id', $row['id'])->update(['delete_time' => $this->now(), 'update_time' => $this->now()]);
            $viewport = $this->viewport($params['viewport'] ?? []);
            $this->table('view')->where('id', $view['id'])->update(['viewport_json' => $this->json($viewport), 'version' => (int)$view['version'] + 1, 'update_time' => $this->now()]);
            $workspaceUpdate = ['version' => (int)$workspace['version'] + 1, 'update_time' => $this->now()];
            if ($title !== '') $workspaceUpdate['title'] = $title;
            $this->table('workspace')->where('id', $workspaceId)->update($workspaceUpdate);
            return ['version' => (int)$view['version'] + 1];
        });
    }

    public function messages(int $workspaceId): array
    {
        $this->workspace($workspaceId);
        return $this->table('message')->where('workspace_id', $workspaceId)->order('id')->limit(100)->field('id,role,content,create_time')->select()->toArray();
    }

    /** A real short-drama text-model turn; model output may only alter V2 graph data. */
    public function agent(int $workspaceId, array $params): array
    {
        $this->writable(); $workspace = $this->workspace($workspaceId); $content = $this->text($params['content'] ?? '');
        if ($content === '') $this->fail('EMPTY_MESSAGE', '请输入 Agent 指令'); CanvasPromptSafety::assertAllowed($content);
        $requestKey = $this->key($params['request_key'] ?? null, '请求标识');
        $existing = $this->table('message')->where(['workspace_id' => $workspaceId, 'request_key' => $requestKey])->find();
        if ($existing) return ['messages' => $this->messages($workspaceId), 'replayed' => true];
        $models = [];
        foreach (MarketTextModelRuntimeService::modelGroups($this->tenantId) as $group) if (($group['key'] ?? '') === 'script_plan') $models = (array)($group['options'] ?? []);
        if (!$models) $this->fail('TEXT_MODEL_UNAVAILABLE', '短剧租户资源状态中暂无可用的剧本策划文本模型');
        // Availability is checked before persisting an idempotency key. A
        // resource-state failure can therefore be retried with the same input
        // once the tenant restores its short-drama text model.
        $this->message($workspaceId, $requestKey, 'user', $content);
        $graph = $this->detail($workspaceId)['graph'];
        $prompt = $this->agentPrompt($content, $graph);
        try {
            $generated = []; $lastError = null;
            // Match short-drama planning: only an explicit stale/unavailable
            // model identifier may fall through to the next tenant-enabled one.
            foreach ($models as $index => $model) {
                try {
                    $generated = MarketTextModelRuntimeService::generate($this->tenantId, $this->userId, [
                        'action_code' => 'short_drama_canvas_v2_agent', 'source_app_code' => 'aigc_short_drama', 'business_table' => 'aigc_short_drama_canvas_v2_workspace', 'business_id' => $workspaceId,
                        'content' => $prompt, 'system_prompt' => '你是短剧画布 Agent。只返回 JSON，不执行外部操作。', 'model_selection' => $model, 'max_tokens' => 3000, 'timeout_seconds' => 120, 'response_format' => ['type' => 'json_object'],
                    ]);
                    break;
                } catch (\Throwable $modelError) {
                    $lastError = $modelError;
                    $canFallback = str_contains(strtolower($modelError->getMessage()), 'model_not_found') && $index < count($models) - 1;
                    if (!$canFallback) throw $modelError;
                }
            }
            if ($generated === [] && $lastError) throw $lastError;
        } catch (\Throwable $e) {
            // Do not persist the request key when no model turn was accepted:
            // the same user action can be retried after tenant resources recover.
            // Keep the diagnostic free of prompt or credential data.
            Log::warning(sprintf('ShortDramaCanvasV2 agent unavailable tenant=%d workspace=%d error=%s', $this->tenantId, $workspaceId, mb_substr($e->getMessage(), 0, 255, 'UTF-8')));
            $this->table('message')->where(['workspace_id' => $workspaceId, 'request_key' => $requestKey])->delete();
            $this->fail('AGENT_UNAVAILABLE', '短剧 Agent 暂时不可用，请检查租户资源状态后重试');
        }
        $plan = $this->decode($generated['content'] ?? '');
        $reply = $this->text($plan['message'] ?? '', 6000) ?: '已整理画布建议，请确认节点内容后再生成媒体。';
        $this->applyAgentNodes($workspaceId, (array)($plan['nodes'] ?? []));
        $this->message($workspaceId, 'reply_' . substr(hash('sha256', $requestKey), 0, 24), 'assistant', $reply);
        return ['messages' => $this->messages($workspaceId), 'graph' => $this->detail($workspaceId)['graph']];
    }

    public function quoteMedia(int $workspaceId, array $params): array
    {
        $this->writable(); $workspace = $this->workspace($workspaceId); $project = $this->project($workspace);
        $node = $this->node($workspaceId, $this->key($params['node_key'] ?? null, '节点标识'));
        $kind = (string)($params['kind'] ?? $node['node_type']);
        if (!in_array($kind, self::MEDIA_TYPES, true)) $this->fail('INVALID_MEDIA_TYPE', '请选择图片、视频或音乐节点');
        $request = $this->mediaRequest($project, $node, $kind, $params);
        $quote = AigcShortDramaService::estimateShotGenerationTask($this->tenantId, $this->userId, $request);
        // Freeze the exact resource selection returned by short-drama's live
        // quote. This prevents an empty/default client value from resolving to
        // a different model during the subsequent confirmed submission.
        if ((string)($request['model_code'] ?? '') === '' && trim((string)($quote['model_id'] ?? '')) !== '') {
            $request['model_code'] = trim((string)$quote['model_id']);
            $quote = AigcShortDramaService::estimateShotGenerationTask($this->tenantId, $this->userId, $request);
        }
        return ['request' => $request, 'quote' => $quote, 'quote_hash' => hash('sha256', $this->json([$request, $quote]))];
    }

    public function submitMedia(int $workspaceId, array $params): array
    {
        $this->writable(); $workspace = $this->workspace($workspaceId); $project = $this->project($workspace);
        $nodeKey = $this->key($params['node_key'] ?? null, '节点标识'); $node = $this->node($workspaceId, $nodeKey);
        $kind = (string)($params['kind'] ?? $node['node_type']); if (!in_array($kind, self::MEDIA_TYPES, true)) $this->fail('INVALID_MEDIA_TYPE', '媒体类型无效');
        $requestKey = $this->key($params['request_key'] ?? null, '请求标识');
        $request = $this->mediaRequest($project, $node, $kind, $params);
        $quote = AigcShortDramaService::estimateShotGenerationTask($this->tenantId, $this->userId, $request);
        $hash = hash('sha256', $this->json([$request, $quote]));
        if (!hash_equals($hash, (string)($params['quote_hash'] ?? ''))) $this->fail('QUOTE_EXPIRED', '生成参数或报价已变化，请重新确认');
        $now = $this->now();
        // Reserve the request before calling the provider. The unique request key
        // is the safety boundary for repeated clicks and concurrent submissions.
        $reserved = false;
        try {
            $reservation = Db::transaction(function () use ($workspaceId, $requestKey, $nodeKey, $kind, $request, $now) {
                $existing = $this->table('action')->where(['workspace_id' => $workspaceId, 'request_key' => $requestKey])->lock(true)->find();
                if ($existing) return ['row' => $existing, 'reserved' => false];
                $actionId = (int)Db::name('aigc_short_drama_canvas_v2_action')->insertGetId(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $workspaceId, 'node_key' => $nodeKey, 'request_key' => $requestKey,
                    'kind' => $kind . '_generation', 'status' => 'submitting', 'version' => 1, 'request_json' => $this->json($request), 'result_json' => '{}', 'create_time' => $now, 'update_time' => $now]);
                return ['row' => $this->table('action')->where('id', $actionId)->find(), 'reserved' => true];
            });
            $action = $reservation['row']; $reserved = (bool)$reservation['reserved'];
        } catch (\Throwable $e) {
            // A concurrent insert can win the unique key between the lookup and
            // insert. Return that reservation rather than creating another task.
            $action = $this->table('action')->where(['workspace_id' => $workspaceId, 'request_key' => $requestKey])->find();
            if (!$action) throw $e;
        }
        if ((string)$action['generation_task_id'] !== '') {
            $this->persistMediaNodeRequest($node, $request);
            return $this->formatAction($action);
        }
        if (!$reserved && (string)$action['status'] === 'submitting') $this->fail('SUBMISSION_IN_PROGRESS', '该媒体任务正在提交，请稍后刷新结果');
        try { $task = AigcShortDramaService::createShotGenerationTask($this->tenantId, $this->userId, $request); }
        catch (\Throwable $e) {
            $this->table('action')->where('id', $action['id'])->update(['status' => 'failed', 'error_code' => 'SUBMIT_FAILED', 'error_message' => mb_substr($e->getMessage(), 0, 255, 'UTF-8'), 'update_time' => $this->now()]); throw $e;
        }
        // The relationship to a formal shot and the resolved model are part of
        // the durable canvas state, not a best-effort browser autosave.
        $this->persistMediaNodeRequest($node, $request);
        $this->table('action')->where('id', $action['id'])->update(['status' => (string)($task['status'] ?? 'pending'), 'generation_task_id' => (string)($task['task_id'] ?? ''), 'provider_task_id' => (string)($task['provider_task_id'] ?? ''), 'result_json' => $this->json($task), 'update_time' => $this->now()]);
        return $this->formatAction($this->table('action')->where('id', $action['id'])->find());
    }

    public function refreshTask(int $workspaceId, int $actionId): array
    {
        $this->workspace($workspaceId); $action = $this->table('action')->where(['workspace_id' => $workspaceId, 'id' => $actionId])->find();
        if (!$action || (string)$action['generation_task_id'] === '') $this->fail('TASK_NOT_FOUND', '媒体任务不存在');
        $task = AigcShortDramaService::generationTaskDetail($this->tenantId, $this->userId, (string)$action['generation_task_id']);
        $this->table('action')->where('id', $action['id'])->update(['status' => (string)($task['status'] ?? $action['status']), 'provider_task_id' => (string)($task['provider_task_id'] ?? $action['provider_task_id']), 'result_json' => $this->json($task), 'update_time' => $this->now()]);
        return $this->formatAction($this->table('action')->where('id', $action['id'])->find());
    }

    /** Applies a text node to the existing short-drama domain after user confirmation. */
    public function applyNode(int $workspaceId, array $params): array
    {
        $this->writable(); $workspace = $this->workspace($workspaceId); $node = $this->node($workspaceId, $this->key($params['node_key'] ?? null, '节点标识'));
        $content = $this->decode($node['content_json']); $kind = (string)$node['business_kind'];
        if (in_array((string)$node['node_type'], self::MEDIA_TYPES, true)) {
            $action = $this->latestAction($workspaceId, (string)$node['node_key']);
            if (!$action || !in_array((string)($action['status'] ?? ''), ['success', 'completed'], true)) $this->fail('MEDIA_NOT_READY', '请等待短剧媒体任务成功后再应用');
            $taskId = (string)($action['generation_task_id'] ?? '');
            $generation = Db::name('aigc_short_drama_generation_task')->where([
                'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'task_id' => $taskId, 'delete_time' => 0,
            ])->find();
            if (!$generation) $this->fail('TASK_NOT_FOUND', '短剧媒体任务不存在或无权访问');
            $assetIds = array_values(array_filter(array_map('intval', array_merge(
                (array)($this->decode($generation['result_json'] ?? '')['asset_ids'] ?? []),
                (array)$this->decode($generation['output_asset_ids'] ?? '')
            ))));
            $assetId = (int)($assetIds[0] ?? 0);
            if ($assetId <= 0) $this->fail('MEDIA_NOT_READY', '短剧媒体资产尚未登记完成');
            if ((string)$node['node_type'] === 'music') {
                // BGM tasks already register an auditable short-drama music asset
                // against the project. The canvas only records that confirmation.
                return ['kind' => 'music', 'asset_id' => $assetId, 'task_id' => $taskId];
            }
            $project = $this->project($workspace);
            $shotId = $this->text($content['shot_id'] ?? '', 40);
            if ($shotId === '') $this->fail('SHOT_REQUIRED', '请先关联真实分镜后再应用媒体');
            $selected = AigcShortDramaService::selectStoryboardAsset($this->tenantId, $this->userId, [
                'project_id' => (int)$project['id'], 'task_id' => (string)$project['last_task_id'], 'shot_id' => $shotId,
                'asset_id' => $assetId, 'asset_type' => (string)$node['node_type'] === 'video' ? 'shot_video' : 'shot_image',
            ]);
            return ['kind' => (string)$node['node_type'], 'asset_id' => $assetId, 'shot_id' => $shotId, 'asset' => $selected['asset'] ?? []];
        }
        if (!in_array($kind, ['character', 'scene', 'storyboard'], true)) $this->fail('NOT_APPLICABLE', '该节点无需应用到短剧');
        if ($kind === 'character' || $kind === 'scene') {
            $subject = AigcShortDramaService::saveSubjectLibrary($this->tenantId, $this->userId, ['name' => (string)$node['title'], 'description' => (string)($content['text'] ?? ''), 'category' => $kind, 'image' => (string)($content['image'] ?? '')]);
            return ['kind' => $kind, 'subject_id' => (int)($subject['id'] ?? 0)];
        }
        $project = $this->project($workspace); $taskId = (string)($project['last_task_id'] ?? '');
        if ($taskId === '') $this->fail('SCRIPT_NOT_READY', '剧本任务尚未就绪，暂不能写入分镜');
        $created = AigcShortDramaService::insertStoryboardShot($this->tenantId, $this->userId, ['project_id' => (int)$project['id'], 'task_id' => $taskId]);
        $shotId = (string)($created['active_shot_id'] ?? $created['shot']['shot_id'] ?? '');
        if ($shotId === '') $this->fail('STORYBOARD_CREATE_FAILED', '分镜创建失败');
        AigcShortDramaService::saveStoryboard($this->tenantId, $this->userId, ['project_id' => (int)$project['id'], 'task_id' => $taskId, 'shot_id' => $shotId, 'title' => (string)$node['title'], 'visual_description' => (string)($content['text'] ?? ''), 'image_prompt' => (string)($content['image_prompt'] ?? ''), 'video_prompt' => (string)($content['video_prompt'] ?? '')]);
        $content['shot_id'] = $shotId; $this->table('node')->where('id', $node['id'])->update(['content_json' => $this->json($content), 'version' => (int)$node['version'] + 1, 'update_time' => $this->now()]);
        return ['kind' => 'storyboard', 'shot_id' => $shotId];
    }

    private function upsertNode(int $workspaceId, array $node, int $workspaceVersion): string
    {
        $key = $this->key($node['node_key'] ?? $node['id'] ?? null, '节点标识');
        $type = (string)($node['node_type'] ?? $node['type'] ?? 'text'); if (!in_array($type, self::NODE_TYPES, true)) $this->fail('INVALID_NODE', '节点类型无效');
        $business = (string)($node['business_kind'] ?? ''); if (!in_array($business, self::BUSINESS_KINDS, true)) $this->fail('INVALID_NODE', '节点业务类型无效');
        if ($type !== 'text') $business = '';
        $title = $this->text($node['title'] ?? '', 120) ?: ['text' => '文本节点', 'image' => '图片节点', 'video' => '视频节点', 'music' => '音乐节点'][$type];
        $content = is_array($node['content'] ?? null) ? $node['content'] : (is_array($node['metadata'] ?? null) ? $node['metadata'] : ['text' => (string)($node['content'] ?? '')]);
        $position = (array)($node['position'] ?? []); $x = (float)($position['x'] ?? $node['position_x'] ?? 0); $y = (float)($position['y'] ?? $node['position_y'] ?? 0);
        if (abs($x) > 1000000 || abs($y) > 1000000) $this->fail('INVALID_POSITION', '节点位置超出范围');
        $width = min(960, max(220, (int)($node['width'] ?? 320))); $height = min(720, max(140, (int)($node['height'] ?? 220)));
        $row = $this->table('node')->where(['workspace_id' => $workspaceId, 'node_key' => $key])->lock(true)->find();
        // Client clocks are not a layout order. Bound z-index to a safe range
        // so a timestamp used by a browser can never overflow MySQL INT.
        $zIndex = max(-1000000, min(1000000, (int)($node['z_index'] ?? 0)));
        $data = ['node_type' => $type, 'business_kind' => $business, 'title' => $title, 'content_json' => $this->json($content), 'position_x' => $x, 'position_y' => $y, 'width' => $width, 'height' => $height, 'z_index' => $zIndex, 'delete_time' => 0, 'update_time' => $this->now()];
        if ($row) $this->table('node')->where('id', $row['id'])->update($data + ['version' => (int)$row['version'] + 1]);
        else Db::name('aigc_short_drama_canvas_v2_node')->insert($data + ['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $workspaceId, 'node_key' => $key, 'version' => 1, 'create_time' => $this->now()]);
        return $key;
    }

    private function node(int $workspaceId, string $key): array
    {
        $node = $this->table('node')->where(['workspace_id' => $workspaceId, 'node_key' => $key, 'delete_time' => 0])->find();
        if (!$node) $this->fail('NODE_NOT_FOUND', '节点不存在或无权访问'); return $node;
    }
    private function formatNode(array $node): array
    {
        $node['id'] = (int)$node['id']; $node['content'] = $this->decode($node['content_json']); $node['position'] = ['x' => (float)$node['position_x'], 'y' => (float)$node['position_y']];
        unset($node['content_json'], $node['tenant_id'], $node['user_id'], $node['delete_time']); return $node;
    }
    private function viewport($value): array
    {
        $value = is_array($value) ? $value : []; $k = (float)($value['k'] ?? 1); return ['x' => (float)($value['x'] ?? 0), 'y' => (float)($value['y'] ?? 0), 'k' => min(4, max(.2, $k))];
    }
    private function message(int $workspaceId, string $key, string $role, string $content): void
    {
        Db::name('aigc_short_drama_canvas_v2_message')->insert(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $workspaceId, 'request_key' => $key, 'role' => $role, 'content' => $content, 'create_time' => $this->now(), 'update_time' => $this->now()]);
    }
    private function latestAction(int $workspaceId, string $nodeKey): ?array
    {
        $row = $this->table('action')->where(['workspace_id' => $workspaceId, 'node_key' => $nodeKey])->order('id', 'desc')->find(); return $row ? $this->formatAction($row) : null;
    }
    private function formatAction(array $action): array
    {
        $action['id'] = (int)$action['id']; $action['request'] = $this->decode($action['request_json']); $action['result'] = $this->decode($action['result_json']); unset($action['request_json'], $action['result_json'], $action['tenant_id'], $action['user_id']); return $action;
    }
    private function mediaRequest(array $project, array $node, string $kind, array $params): array
    {
        $content = $this->decode($node['content_json']); $taskId = (string)($project['last_task_id'] ?? '');
        if ($taskId === '') $this->fail('SCRIPT_NOT_READY', '剧本任务尚未完成可编辑状态，请稍后重试');
        $type = $kind === 'image' ? 'shot_image' : ($kind === 'video' ? 'shot_video' : 'bgm_audio');
        $shotId = $this->text($params['shot_id'] ?? $content['shot_id'] ?? '', 40);
        if ($kind !== 'music' && $shotId === '') $this->fail('SHOT_REQUIRED', '请先将分镜节点应用到短剧后再生成媒体');
        $prompt = $this->text($params['prompt'] ?? $content['prompt'] ?? $content['text'] ?? '', 6000);
        if ($prompt === '') $this->fail('EMPTY_PROMPT', '请补充生成提示词'); CanvasPromptSafety::assertAllowed($prompt);
        $request = ['project_id' => (int)$project['id'], 'task_id' => $taskId, 'shot_id' => $shotId, 'task_type' => $type, 'model_code' => $this->text($params['model_code'] ?? $content['model_code'] ?? '', 120), 'source' => 'short_drama_canvas_v2',
            'ratio' => $this->text($params['ratio'] ?? $content['ratio'] ?? '9:16', 12), 'params' => ['source' => 'short_drama_canvas_v2', 'ratio' => $this->text($params['ratio'] ?? $content['ratio'] ?? '9:16', 12)]];
        if ($kind === 'image') { $request['prompt'] = $prompt; $request['params']['prompt'] = $prompt; }
        elseif ($kind === 'video') { $request['video_prompt'] = $prompt; $request['params']['video_prompt'] = $prompt; $request['params']['duration_seconds'] = max(1, min(30, (int)($params['duration_seconds'] ?? $content['duration_seconds'] ?? 5))); }
        else { $request['music_prompt'] = $prompt; $request['params']['music_prompt'] = $prompt; $request['params']['duration_seconds'] = max(15, min(600, (int)($params['duration_seconds'] ?? $content['duration_seconds'] ?? 60))); }
        return $request;
    }
    /** Persist confirmed generation inputs so task application survives reloads. */
    private function persistMediaNodeRequest(array $node, array $request): void
    {
        $content = $this->decode($node['content_json'] ?? '');
        $prompt = (string)($request['prompt'] ?? $request['video_prompt'] ?? $request['music_prompt'] ?? '');
        $content = array_merge($content, [
            'prompt' => $prompt,
            'shot_id' => (string)($request['shot_id'] ?? ''),
            'model_code' => (string)($request['model_code'] ?? ''),
            'ratio' => (string)($request['ratio'] ?? $content['ratio'] ?? '9:16'),
        ]);
        if (isset($request['params']['duration_seconds'])) $content['duration_seconds'] = (int)$request['params']['duration_seconds'];
        $this->table('node')->where('id', (int)$node['id'])->update([
            'content_json' => $this->json($content), 'version' => (int)$node['version'] + 1, 'update_time' => $this->now(),
        ]);
    }
    private function agentPrompt(string $content, array $graph): string
    {
        $compact = array_map(static fn(array $node): array => ['id' => $node['node_key'], 'type' => $node['node_type'], 'business_kind' => $node['business_kind'], 'title' => $node['title'], 'content' => $node['content']], array_slice((array)$graph['nodes'], -30));
        return $this->json(['instruction' => $content, 'nodes' => $compact, 'contract' => ['message' => '简短中文回复', 'nodes' => [['node_key' => '8-64位英文数字下划线', 'node_type' => 'text|image|video|music', 'business_kind' => 'script|character|scene|storyboard（仅文本）', 'title' => '标题', 'content' => ['text' => '内容或提示词'], 'position' => ['x' => 0, 'y' => 0]]], 'limit' => 8]]);
    }
    private function applyAgentNodes(int $workspaceId, array $nodes): void
    {
        if (count($nodes) > 8) $this->fail('INVALID_AGENT_PLAN', 'Agent 操作数量超出限制');
        Db::transaction(function () use ($workspaceId, $nodes) { foreach ($nodes as $node) if (is_array($node)) $this->upsertNode($workspaceId, $node, 0); });
    }
}
