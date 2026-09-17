<?php

namespace app\common\service\app\aigc_short_drama\canvas;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use think\facade\Db;

/**
 * Explicit hand-off from canvas drafts into the existing short-drama domain.
 *
 * Nothing in this service invents a project, model, price, asset type, or
 * generation task. Formal writes happen only after a user confirms them, then
 * delegate to the existing short-drama services.
 */
final class CanvasShortDramaBridgeService
{
    private CanvasWorkspaceService $workspaces;

    public function __construct(private int $tenantId, private int $userId)
    {
        $this->workspaces = new CanvasWorkspaceService($tenantId, $userId);
    }

    private function table(string $name)
    {
        return Db::name('aigc_short_drama_canvas_' . $name)->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId]);
    }

    private function workspace(int $workspaceId): array
    {
        $detail = $this->workspaces->detail($workspaceId);
        return (array)$detail['workspace'];
    }

    /** Creates a normal short-drama planning task; the caller then follows the normal plan route. */
    public function createProject(int $workspaceId, array $params): array
    {
        $this->workspaces->assertWritable();
        $workspace = $this->workspace($workspaceId);
        if ((int)$workspace['project_id'] > 0) CanvasPolicy::fail('PROJECT_ALREADY_BOUND', '当前工作区已经绑定短剧项目');
        $requestKey = CanvasPolicy::key($params['request_key'] ?? null);
        // The canvas UI sends an explicit empty prompt when the user confirms
        // the default hand-off. In that case, use the durable first canvas
        // idea rather than treating the empty client field as authoritative.
        $providedPrompt = CanvasPolicy::text($params['prompt'] ?? '');
        $prompt = $providedPrompt !== '' ? $providedPrompt : $this->firstIdea($workspaceId);
        if ($prompt === '') CanvasPolicy::fail('EMPTY_MESSAGE', '请先输入创作想法');
        CanvasPromptSafety::assertAllowed($prompt);
        $hash = CanvasPolicy::hash([$prompt, $workspace['skill'] ?? []]);
        $claimed = $this->claimFormalAction($workspaceId, $requestKey, $hash, 'create_short_drama_project', 'submitting', ['prompt' => $prompt]);
        if (isset($claimed['replay'])) return $this->actionReplay($claimed['replay'], $hash);
        $actionId = (int)$claimed['id'];
        try {
            $skill = (array)($workspace['skill'] ?? []);
            $result = AigcShortDramaService::createScriptPlan($this->tenantId, $this->userId, [
                'prompt' => $prompt,
                'skill_id' => (int)($skill['id'] ?? 0),
                'skill_version' => (int)($skill['version'] ?? 0),
                'source' => 'short_drama_canvas',
            ]);
            $projectId = (int)($result['project_id'] ?? 0);
            if ($projectId <= 0) throw new \RuntimeException('短剧项目创建结果无效');
            Db::transaction(function () use ($workspaceId, $actionId, $result, $projectId) {
                $bound = $this->table('workspace')->where('id', $workspaceId)->lock(true)->find();
                if (!$bound || (int)$bound['project_id'] > 0) CanvasPolicy::fail('PROJECT_BIND_CONFLICT', '工作区绑定状态已变化，请重新读取');
                $this->table('workspace')->where('id', $workspaceId)->update(['project_id' => $projectId, 'version' => (int)$bound['version'] + 1, 'update_time' => time()]);
                $this->table('action')->where('id', $actionId)->update(['status' => 'success', 'result_json' => CanvasPolicy::encode($result), 'update_time' => time()]);
                $this->event($workspaceId, 'short_drama_project_created', ['project_id' => $projectId, 'task_id' => (string)($result['task_id'] ?? '')]);
            });
            return $result;
        } catch (\Throwable $e) {
            $this->table('action')->where(['id' => $actionId, 'status' => 'submitting'])->update(['status' => 'needs_review', 'update_time' => time()]);
            throw $e;
        }
    }

    /** Read-only projected changes. The browser must call applyDrafts separately. */
    public function previewDrafts(int $workspaceId, array $params = []): array
    {
        $workspace = $this->workspace($workspaceId);
        $ids = $this->draftIds($params['draft_ids'] ?? []);
        $drafts = $this->draftRows($workspaceId, $ids);
        $changes = [];
        foreach ($drafts as $draft) {
            $content = CanvasPolicy::decode($draft['content_json']);
            $changes[] = [
                'draft_id' => (int)$draft['id'],
                'kind' => (string)$draft['kind'],
                'title' => (string)($content['title'] ?? ''),
                'target' => in_array((string)$draft['kind'], ['character', 'scene'], true) ? '主体库' : '当前短剧项目分镜',
                'requires_project' => (string)$draft['kind'] === 'storyboard',
                'available' => (string)$draft['kind'] !== 'storyboard' || (int)$workspace['project_id'] > 0,
            ];
        }
        return ['workspace_id' => $workspaceId, 'project_id' => (int)$workspace['project_id'], 'changes' => $changes,
            'requires_confirmation' => $changes !== []];
    }

    /** Applies approved drafts with short-drama's existing subject/storyboard services. */
    public function applyDrafts(int $workspaceId, array $params): array
    {
        $this->workspaces->assertWritable();
        $workspace = $this->workspace($workspaceId);
        $requestKey = CanvasPolicy::key($params['request_key'] ?? null);
        $ids = $this->draftIds($params['draft_ids'] ?? []);
        $drafts = $this->draftRows($workspaceId, $ids);
        if ($drafts === []) CanvasPolicy::fail('DRAFT_NOT_FOUND', '没有可应用的草稿');
        $hash = CanvasPolicy::hash(array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'version' => (int)$row['version']], $drafts));
        $this->assertDraftsNotApplied($workspaceId, $drafts);
        $claimed = $this->claimFormalAction($workspaceId, $requestKey, $hash, 'apply_short_drama_drafts', 'applying', ['draft_ids' => array_column($drafts, 'id')]);
        if (isset($claimed['replay'])) return $this->actionReplay($claimed['replay'], $hash);
        $actionId = (int)$claimed['id'];
        try {
            $results = [];
            foreach ($drafts as $draft) {
                $content = CanvasPolicy::decode($draft['content_json']);
                $fields = (array)($content['fields'] ?? []);
                if (in_array((string)$draft['kind'], ['character', 'scene'], true)) {
                    $subject = AigcShortDramaService::saveSubjectLibrary($this->tenantId, $this->userId, [
                        'name' => (string)($content['title'] ?? ''), 'description' => (string)($content['description'] ?? ''),
                        'category' => (string)$draft['kind'], 'gender' => (string)($fields['gender'] ?? 'unknown'),
                        'age_stage' => (string)($fields['age_stage'] ?? 'unknown'), 'image' => (string)($fields['image'] ?? ''),
                    ]);
                    $results[] = ['draft_id' => (int)$draft['id'], 'kind' => (string)$draft['kind'], 'subject_id' => (int)($subject['id'] ?? 0)];
                    continue;
                }
                if ((int)$workspace['project_id'] <= 0) CanvasPolicy::fail('PROJECT_REQUIRED', '请先将画布转换为短剧项目，再应用分镜草稿');
                $taskId = $this->currentTaskId((int)$workspace['project_id']);
                $created = AigcShortDramaService::insertStoryboardShot($this->tenantId, $this->userId, [
                    'project_id' => (int)$workspace['project_id'], 'task_id' => $taskId,
                    'after_shot_id' => (string)($fields['after_shot_id'] ?? ''),
                ]);
                $shotId = (string)($created['active_shot_id'] ?? $created['shot']['shot_id'] ?? '');
                if ($shotId === '') throw new \RuntimeException('短剧分镜创建结果无效');
                $shot = AigcShortDramaService::saveStoryboard($this->tenantId, $this->userId, [
                    'project_id' => (int)$workspace['project_id'], 'task_id' => $taskId, 'shot_id' => $shotId,
                    'title' => (string)($content['title'] ?? ''), 'visual_description' => (string)($content['description'] ?? ''),
                    'scene_name' => (string)($fields['scene_name'] ?? ''), 'shot_type' => (string)($fields['shot'] ?? ''),
                    'action' => (string)($fields['action'] ?? ''), 'dialogue' => (string)($fields['dialogue'] ?? ''),
                    'image_prompt' => (string)($fields['image_prompt'] ?? ''), 'video_prompt' => (string)($fields['video_prompt'] ?? ''),
                    'recommended_duration_seconds' => (int)($fields['duration_seconds'] ?? 3),
                ]);
                $results[] = ['draft_id' => (int)$draft['id'], 'kind' => 'storyboard', 'shot_id' => $shotId, 'shot' => (array)($shot['shot'] ?? [])];
            }
            Db::transaction(function () use ($workspaceId, $actionId, $results) {
                $this->table('action')->where('id', $actionId)->update(['status' => 'success', 'result_json' => CanvasPolicy::encode(['results' => $results]), 'update_time' => time()]);
                $this->event($workspaceId, 'short_drama_drafts_applied', ['count' => count($results)]);
            });
            return ['status' => 'success', 'results' => $results];
        } catch (\Throwable $e) {
            $this->table('action')->where(['id' => $actionId, 'status' => 'applying'])->update(['status' => 'needs_review', 'update_time' => time()]);
            throw $e;
        }
    }

    public function quoteProposal(int $workspaceId, int $actionId, array $params): array
    {
        $this->workspaces->assertWritable(); $workspace = $this->workspace($workspaceId);
        $action = $this->proposal($workspaceId, $actionId);
        $request = $this->generationRequest($workspace, $action, $params);
        $quote = AigcShortDramaService::estimateShotGenerationTask($this->tenantId, $this->userId, $request);
        $hash = CanvasPolicy::hash([$action['request_hash'], $request, $quote]);
        $this->table('action')->where('id', $actionId)->update(['status' => 'quoted', 'version' => (int)$action['version'] + 1,
            'result_json' => CanvasPolicy::encode(['request' => $request, 'quote' => $quote, 'quote_hash' => $hash]), 'update_time' => time()]);
        return ['action_id' => $actionId, 'version' => (int)$action['version'] + 1, 'quote_hash' => $hash, 'request' => $request, 'quote' => $quote];
    }

    /** Submits exactly the established short-drama generation task after quote confirmation. */
    public function submitProposal(int $workspaceId, int $actionId, array $params): array
    {
        $this->workspaces->assertWritable(); $this->workspace($workspaceId);
        $requestKey = CanvasPolicy::key($params['request_key'] ?? null);
        $quoteHash = CanvasPolicy::text($params['quote_hash'] ?? '', 128);
        $claim = Db::transaction(function () use ($workspaceId, $actionId, $params, $requestKey, $quoteHash) {
            $action = $this->table('action')->where(['workspace_id' => $workspaceId, 'id' => $actionId])
                ->whereIn('kind', ['image_proposal', 'video_proposal'])->lock(true)->find();
            if (!$action) CanvasPolicy::fail('PROPOSAL_NOT_FOUND', '媒体提案不存在或无权访问');
            $stored = CanvasPolicy::decode($action['result_json']);
            if ((string)$action['status'] !== 'quoted' || !hash_equals((string)($stored['quote_hash'] ?? ''), $quoteHash)) CanvasPolicy::fail('QUOTE_EXPIRED', '生成参数或报价已变化，请重新确认');
            if ((int)($params['version'] ?? -1) !== (int)$action['version']) CanvasPolicy::fail('VERSION_CONFLICT', '媒体提案已变化，请重新确认');
            $confirmHash = CanvasPolicy::hash([$requestKey, $quoteHash]);
            if ((string)$action['confirmed_hash'] !== '' && !hash_equals((string)$action['confirmed_hash'], $confirmHash)) CanvasPolicy::fail('IDEMPOTENCY_CONFLICT', '确认标识不能用于其他生成请求');
            if ((string)$action['generation_task_id'] !== '') return ['replay' => $action];
            $this->table('action')->where('id', $actionId)->update(['status' => 'submitting', 'confirmed_hash' => $confirmHash, 'update_time' => time()]);
            return ['stored' => $stored];
        });
        if (isset($claim['replay'])) return ['status' => (string)$claim['replay']['status'], 'task_id' => (string)$claim['replay']['generation_task_id']];
        $stored = (array)$claim['stored'];
        $request = (array)($stored['request'] ?? []);
        try {
            $result = AigcShortDramaService::createShotGenerationTask($this->tenantId, $this->userId, $request);
            $taskId = CanvasPolicy::text((string)($result['task_id'] ?? ''), 64);
            if ($taskId === '') throw new \RuntimeException('短剧生成任务创建结果无效');
            $this->table('action')->where('id', $actionId)->update(['status' => 'submitted', 'generation_task_id' => $taskId,
                'result_json' => CanvasPolicy::encode(array_replace($stored, ['submission' => $result])), 'update_time' => time()]);
            $this->event($workspaceId, 'short_drama_media_submitted', ['action_id' => $actionId, 'task_id' => $taskId]);
            return ['status' => 'submitted', 'task_id' => $taskId, 'task' => $result];
        } catch (\Throwable $e) {
            $this->table('action')->where(['id' => $actionId, 'status' => 'submitting'])->update(['status' => 'needs_review', 'update_time' => time()]);
            throw $e;
        }
    }

    private function generationRequest(array $workspace, array $action, array $params): array
    {
        if ((int)$workspace['project_id'] <= 0) CanvasPolicy::fail('PROJECT_REQUIRED', '请先将画布转换为短剧项目，再生成图片或视频');
        $proposal = CanvasPolicy::decode($action['proposal_json']);
        $shotId = CanvasPolicy::text($params['shot_id'] ?? $proposal['shot_id'] ?? '', 40);
        if ($shotId === '') CanvasPolicy::fail('SHOT_REQUIRED', '请选择已应用到短剧的分镜');
        $kind = (string)($proposal['kind'] ?? '');
        $request = [
            'project_id' => (int)$workspace['project_id'], 'task_id' => $this->currentTaskId((int)$workspace['project_id']), 'shot_id' => $shotId,
            'task_type' => $kind === 'video' ? 'shot_video' : 'shot_image', 'source' => 'short_drama_canvas',
            'model_code' => CanvasPolicy::text($params['model_code'] ?? $proposal['model'] ?? '', 120),
            'ratio' => CanvasPolicy::text($proposal['aspect_ratio'] ?? '9:16', 12),
            'params' => ['ratio' => CanvasPolicy::text($proposal['aspect_ratio'] ?? '9:16', 12), 'duration_seconds' => (int)($proposal['duration_seconds'] ?? 0)],
        ];
        return $request;
    }

    private function currentTaskId(int $projectId): string
    {
        $taskId = (string)Db::name('aigc_short_drama_project')->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'id' => $projectId, 'delete_time' => 0])->value('last_task_id');
        if ($taskId === '') CanvasPolicy::fail('TASK_REQUIRED', '短剧项目尚未完成可编辑的剧本任务');
        return $taskId;
    }

    private function firstIdea(int $workspaceId): string
    {
        return (string)$this->table('message')->where(['workspace_id' => $workspaceId, 'role' => 'user'])->order('id')->value('content');
    }

    private function draftIds($ids): array
    {
        if (!is_array($ids) || count($ids) > 50) CanvasPolicy::fail('INVALID_DRAFTS', '请选择不超过 50 个草稿');
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function draftRows(int $workspaceId, array $ids): array
    {
        $query = $this->table('draft')->where('workspace_id', $workspaceId)->order('id');
        if ($ids !== []) $query->whereIn('id', $ids);
        $rows = $query->select()->toArray();
        if ($ids !== [] && count($rows) !== count($ids)) CanvasPolicy::fail('DRAFT_NOT_FOUND', '部分草稿不存在或无权访问');
        return $rows;
    }

    private function action(int $workspaceId, string $requestKey): ?array
    {
        return $this->table('action')->where(['workspace_id' => $workspaceId, 'request_key' => $requestKey])->find();
    }

    private function actionReplay(array $action, string $hash): array
    {
        if (!hash_equals((string)$action['request_hash'], $hash)) CanvasPolicy::fail('IDEMPOTENCY_CONFLICT', '请求标识已用于不同操作');
        if ((string)$action['status'] !== 'success') CanvasPolicy::fail('ACTION_NEEDS_REVIEW', '该操作状态待核对，请刷新后重试');
        return CanvasPolicy::decode($action['result_json']);
    }

    private function createAction(int $workspaceId, string $key, string $hash, string $kind, string $status, array $proposal): int
    {
        return (int)Db::name('aigc_short_drama_canvas_action')->insertGetId([
            'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $workspaceId, 'request_key' => $key,
            'request_hash' => $hash, 'kind' => $kind, 'status' => $status, 'version' => 1,
            'proposal_json' => CanvasPolicy::encode($proposal), 'result_json' => '[]', 'confirmed_hash' => '', 'generation_task_id' => null,
            'create_time' => time(), 'update_time' => time(),
        ]);
    }

    /** One formal write can be in progress for a workspace at any time. */
    private function claimFormalAction(int $workspaceId, string $key, string $hash, string $kind, string $status, array $proposal): array
    {
        return Db::transaction(function () use ($workspaceId, $key, $hash, $kind, $status, $proposal) {
            $workspace = $this->table('workspace')->where(['id' => $workspaceId, 'delete_time' => 0])->lock(true)->find();
            if (!$workspace) CanvasPolicy::fail('NOT_FOUND', '工作区不存在或无权访问');
            $same = $this->table('action')->where(['workspace_id' => $workspaceId, 'request_key' => $key])->find();
            if ($same) return ['replay' => $same];
            $busy = $this->table('action')->where('workspace_id', $workspaceId)
                ->whereIn('kind', ['create_short_drama_project', 'apply_short_drama_drafts'])
                ->whereIn('status', ['submitting', 'applying'])->find();
            if ($busy) CanvasPolicy::fail('FORMAL_WRITE_BUSY', '当前工作区正在写入短剧，请等待结果确认');
            return ['id' => $this->createAction($workspaceId, $key, $hash, $kind, $status, $proposal)];
        });
    }

    private function assertDraftsNotApplied(int $workspaceId, array $drafts): void
    {
        $wanted = array_fill_keys(array_map(static fn(array $draft): int => (int)$draft['id'], $drafts), true);
        $actions = $this->table('action')->where(['workspace_id' => $workspaceId, 'kind' => 'apply_short_drama_drafts', 'status' => 'success'])->select()->toArray();
        foreach ($actions as $action) {
            foreach ((array)(CanvasPolicy::decode($action['result_json'])['results'] ?? []) as $result) {
                if (isset($wanted[(int)($result['draft_id'] ?? 0)])) CanvasPolicy::fail('DRAFT_ALREADY_APPLIED', '所选草稿已应用到短剧，不能重复写入');
            }
        }
    }

    private function proposal(int $workspaceId, int $actionId): array
    {
        $row = $this->table('action')->where(['workspace_id' => $workspaceId, 'id' => $actionId])->whereIn('kind', ['image_proposal', 'video_proposal'])->find();
        if (!$row) CanvasPolicy::fail('PROPOSAL_NOT_FOUND', '媒体提案不存在或无权访问');
        return $row;
    }

    private function event(int $workspaceId, string $kind, array $payload): void
    {
        Db::name('aigc_short_drama_canvas_event')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $workspaceId, 'run_id' => 0,
            'event_key' => bin2hex(random_bytes(16)), 'kind' => $kind, 'payload_json' => CanvasPolicy::encode($payload),
            'create_time' => time(), 'update_time' => time(),
        ]);
    }
}
