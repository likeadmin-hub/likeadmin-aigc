<?php

namespace app\common\service\app\aigc_short_drama\canvas;

use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use think\facade\Db;

/** Persisted, serial Agent orchestration backed by short-drama model resources. */
final class CanvasAgentService
{
    private int $tenantId;
    private int $userId;
    private CanvasWorkspaceService $workspaces;

    public function __construct(int $tenantId, int $userId)
    {
        $this->tenantId = $tenantId; $this->userId = $userId;
        $this->workspaces = new CanvasWorkspaceService($tenantId, $userId);
    }

    public function assertExecutionAvailable(): void
    {
        CanvasExecutionRuntime::assertReady($this->tenantId, $this->userId);
    }

    private function table(string $name) { return Db::name('aigc_short_drama_canvas_' . $name)->where(['tenant_id' => $this->tenantId, 'user_id' => $this->userId]); }
    private function insert(string $name, array $data): int
    {
        return (int)Db::name('aigc_short_drama_canvas_' . $name)->insertGetId($data + ['tenant_id' => $this->tenantId,
            'user_id' => $this->userId, 'create_time' => time(), 'update_time' => time()]);
    }
    private function event(int $workspace, int $run, string $kind, array $payload): void
    {
        $this->insert('event', ['workspace_id' => $workspace, 'run_id' => $run, 'event_key' => bin2hex(random_bytes(16)), 'kind' => $kind, 'payload_json' => CanvasPolicy::encode($payload)]);
    }
    private function lockWorkspace(int $id): void
    {
        if (!$this->table('workspace')->where(['id' => $id, 'delete_time' => 0])->lock(true)->find()) CanvasPolicy::fail('NOT_FOUND', '工作区不存在或无权访问');
    }

    /** Caller must pass the runtime readiness/access gate before enqueueing. No network in this method. */
    public function enqueue(int $workspaceId, array $params): array
    {
        $this->workspaces->assertWritable();
        $detail = $this->workspaces->detail($workspaceId);
        $key = CanvasPolicy::key($params['request_key'] ?? null);
        $content = CanvasPolicy::text($params['content'] ?? '');
        if ($content === '') CanvasPolicy::fail('EMPTY_MESSAGE', '请输入创作要求');
        CanvasPromptSafety::assertAllowed($content);
        $viewKey = (string)($params['view_key'] ?? 'global');
        $view = $this->workspaces->readView($workspaceId, $viewKey);
        $selected = $params['node_ids'] ?? [];
        if (!is_array($selected) || count($selected) > 20) CanvasPolicy::fail('CONTEXT_LIMIT', '每回合最多选择 20 个相关节点');
        foreach ($selected as $id) CanvasPolicy::key($id);
        $selected = array_values(array_unique($selected)); sort($selected);
        $hash = CanvasPolicy::hash([$content, $viewKey, $selected]);
        $existing = $this->table('run')->where(['workspace_id' => $workspaceId, 'request_key' => $key])->find();
        if ($existing) return $this->replay($existing, $hash);
        $snapshot = $detail['workspace']['skill'];
        if ($snapshot) {
            try { $snapshot = ShortDramaSkillService::resolveForTask($this->tenantId, ['skill_id' => $snapshot['id'], 'skill_version' => $snapshot['version']]); }
            catch (\Exception $e) { CanvasPolicy::fail('SKILL_UNAVAILABLE', 'Skill 已停用或版本变化，请重新选择并确认'); }
        }
        $nodeMap = array_column($view['layout']['nodes'], null, 'id');
        foreach ($selected as $id) if (!isset($nodeMap[$id])) CanvasPolicy::fail('NODE_NOT_FOUND', '所选节点已不存在，请重新选择');
        $nodes = [];
        foreach ($selected as $id) { $node = $nodeMap[$id]; $nodes[] = ['id' => $id, 'type' => $node['type'], 'title' => $node['title'], 'content' => mb_substr($node['metadata']['content'] ?? '', 0, 1500)]; }
        $recent = array_slice($detail['messages'], -10);
        $recent = array_map(fn($message) => ['role' => $message['role'], 'content' => mb_substr($message['content'], 0, 1500)], $recent);
        $context = ['view_key' => $viewKey, 'view_version' => $view['version'], 'content' => $content, 'nodes' => $nodes, 'recent' => $recent, 'call_started' => false];
        if (strlen(CanvasPolicy::encode($context)) > 120000) CanvasPolicy::fail('CONTEXT_LIMIT', '本回合上下文过大，请减少选中节点或缩短内容');
        try {
            return Db::transaction(function () use ($workspaceId, $key, $hash, $content, $viewKey, $context, $snapshot) {
                $this->lockWorkspace($workspaceId);
                $existing = $this->table('run')->where(['workspace_id' => $workspaceId, 'request_key' => $key])->find();
                if ($existing) return $this->replay($existing, $hash);
                if ($this->table('run')->where(['workspace_id' => $workspaceId, 'active_slot' => 1])->find()) CanvasPolicy::fail('RUN_BUSY', '当前工作区已有回合在执行或等待核对');
                $id = $this->insert('run', ['workspace_id' => $workspaceId, 'request_key' => $key, 'request_hash' => $hash, 'status' => 'pending', 'active_slot' => 1,
                    'skill_snapshot_json' => CanvasPolicy::encode($snapshot), 'context_json' => CanvasPolicy::encode($context), 'result_json' => '[]']);
                $this->insert('message', ['workspace_id' => $workspaceId, 'run_id' => $id, 'view_key' => $viewKey, 'request_key' => 'run_user_' . $id,
                    'request_hash' => $hash, 'role' => 'user', 'content' => $content, 'attachments_json' => '[]']);
                // The pending row IS the durable outbox. A scanner can recover a lost notification.
                $this->event($workspaceId, $id, 'run_queued', ['run_id' => $id]);
                return ['id' => $id, 'status' => 'pending'];
            });
        } catch (\Throwable $e) {
            $existing = $this->table('run')->where(['workspace_id' => $workspaceId, 'request_key' => $key])->find();
            if ($existing) return $this->replay($existing, $hash);
            throw $e;
        }
    }
    private function replay(array $row, string $hash): array
    {
        if (!hash_equals($row['request_hash'], $hash)) CanvasPolicy::fail('IDEMPOTENCY_CONFLICT', '相同请求标识不能用于不同内容');
        return ['id' => (int)$row['id'], 'status' => $row['status']];
    }
    private function run(int $workspaceId, int $id, bool $lock = false): array
    {
        $query = $this->table('run')->where(['workspace_id' => $workspaceId, 'id' => $id]);
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if (!$row) CanvasPolicy::fail('NOT_FOUND', '回合不存在或无权访问');
        return $row;
    }
    public function status(int $workspaceId, int $id): array
    {
        $this->workspaces->detail($workspaceId); $row = $this->run($workspaceId, $id);
        return array_intersect_key($row, array_flip(['id', 'status', 'tool_count', 'error_code', 'error_message', 'create_time', 'finished_at']));
    }
    public function drafts(int $workspaceId): array
    {
        $this->workspaces->detail($workspaceId);
        $rows = $this->table('draft')->where('workspace_id', $workspaceId)->order('id', 'desc')->limit(100)->select()->toArray();
        foreach ($rows as &$row) { $row['content'] = CanvasPolicy::decode($row['content_json']); unset($row['content_json'], $row['tenant_id'], $row['user_id']); }
        return ['lists' => $rows];
    }
    /** Candidate media stays within this workspace until a separately priced confirmation flow exists. */
    public function proposals(int $workspaceId): array
    {
        $this->workspaces->detail($workspaceId);
        $rows = $this->table('action')->where('workspace_id', $workspaceId)
            ->whereIn('kind', ['image_proposal', 'video_proposal'])->order('id', 'desc')->limit(100)->select()->toArray();
        $lists = [];
        foreach ($rows as $row) {
            $proposal = CanvasPolicy::decode($row['proposal_json']);
            $lists[] = [
                'id' => (int)$row['id'], 'run_id' => (int)$row['run_id'], 'kind' => $row['kind'] === 'video_proposal' ? 'video' : 'image',
                'status' => (string)$row['status'], 'version' => (int)$row['version'], 'proposal' => $proposal,
                'create_time' => (int)$row['create_time'], 'update_time' => (int)$row['update_time'],
            ];
        }
        return ['lists' => $lists];
    }
    public function cancel(int $workspaceId, int $id): array
    {
        $this->workspaces->detail($workspaceId);
        return Db::transaction(function () use ($workspaceId, $id) {
            $row = $this->run($workspaceId, $id, true);
            if (!in_array($row['status'], ['pending', 'running', 'cancel_requested'], true)) {
                return array_intersect_key($row, array_flip(['id', 'status', 'tool_count', 'error_code', 'error_message', 'create_time', 'finished_at']));
            }
            $started = CanvasPolicy::decode($row['context_json'])['call_started'] ?? false;
            $change = $started ? ['status' => 'cancel_requested'] : ['status' => 'canceled', 'active_slot' => null, 'lease_token' => '', 'finished_at' => time()];
            $this->table('run')->where('id', $id)->update($change + ['update_time' => time()]);
            $this->event($workspaceId, $id, 'run_cancel_requested', ['accepted_upstream' => $started]);
            return ['id' => $id, 'status' => $change['status']];
        });
    }

    public function claim(int $workspaceId, int $id, int $now): ?string
    {
        $this->workspaces->assertWritable(); $this->workspaces->detail($workspaceId);
        return Db::transaction(function () use ($workspaceId, $id, $now) {
            $row = $this->run($workspaceId, $id, true);
            if ($row['status'] !== 'pending') return null;
            $token = bin2hex(random_bytes(24));
            $this->table('run')->where('id', $id)->update(['status' => 'running', 'lease_token' => $token, 'lease_expires_at' => $now + 180, 'started_at' => $now, 'update_time' => $now]);
            return $token;
        });
    }
    private function leased(int $workspaceId, int $id, string $token, int $now): array
    {
        $row = $this->run($workspaceId, $id, true);
        if (!in_array($row['status'], ['running', 'cancel_requested'], true) || !hash_equals($row['lease_token'], $token) || (int)$row['lease_expires_at'] <= $now) CanvasPolicy::fail('STALE_WORKER', '回合已由其他执行器接管');
        return $row;
    }

    /** A trusted provider adapter must reserve/settle through platform billing and perform at most ONE call. */
    public function execute(int $workspaceId, int $id, string $token, callable $invoke, ?callable $clock = null): void
    {
        $clock = $clock ?? fn() => time();
        $this->workspaces->assertWritable();
        $call = Db::transaction(function () use ($workspaceId, $id, $token, $clock) {
            $row = $this->leased($workspaceId, $id, $token, $clock());
            $result = CanvasPolicy::decode($row['result_json']);
            if (isset($result['provider_result'])) return ['row' => $row, 'result' => $result['provider_result']];
            $context = CanvasPolicy::decode($row['context_json']);
            if ($context['call_started'] ?? false) CanvasPolicy::fail('SUBMISSION_UNKNOWN', '上游提交状态待核对，禁止重新提交');
            if ($row['status'] === 'cancel_requested') CanvasPolicy::fail('CANCELED', '回合已取消');
            $context['call_started'] = true;
            $this->table('run')->where('id', $id)->update(['context_json' => CanvasPolicy::encode($context), 'update_time' => $clock()]);
            return ['row' => $row];
        });
        if (!isset($call['result'])) {
            try {
                // No database transaction/row lock is held here. Stable operation key for billing/provider tracing.
                $result = $invoke(['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'workspace_id' => $workspaceId, 'run_id' => $id,
                    'operation_key' => 'sd_canvas_run_' . $id, 'timeout_seconds' => 120,
                    'context' => CanvasPolicy::decode($call['row']['context_json']), 'skill' => CanvasPolicy::decode($call['row']['skill_snapshot_json'])]);
                if (!is_array($result) || strlen(CanvasPolicy::encode($result)) > 120000) CanvasPolicy::fail('INVALID_RESULT', '模型结果超出限制');
                Db::transaction(function () use ($workspaceId, $id, $token, $clock, $result) {
                    $this->leased($workspaceId, $id, $token, $clock());
                    $this->table('run')->where('id', $id)->update([
                        'result_json' => CanvasPolicy::encode(['provider_result' => $result]),
                        'app_task_id' => max(0, (int)($result['app_task_id'] ?? 0)),
                        'update_time' => $clock(),
                    ]);
                });
            } catch (\DomainException $e) {
                // Policy/provider rejections are known terminal outcomes. The
                // shared market runtime has already settled/refunded its own
                // task when it throws one of these errors, so do not leave the
                // workspace serial slot blocked as an unknown submission.
                [$code, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '');
                $this->finish($workspaceId, $id, 'failed', mb_substr($code ?: 'AGENT_REJECTED', 0, 64), mb_substr($message ?: '短剧 Agent 请求被拒绝', 0, 255));
                return;
            } catch (\Throwable $e) {
                // Unknown calls hold the serial slot. Do not mark failed/refund or retry without reconciliation.
                $this->table('run')->where(['id' => $id, 'lease_token' => $token])->whereIn('status', ['running', 'cancel_requested'])
                    ->update(['status' => 'needs_review', 'error_code' => 'SUBMISSION_UNCONFIRMED', 'error_message' => '模型调用或结算结果待核对，未自动重试', 'lease_token' => '', 'update_time' => $clock()]);
                return;
            }
        } else $result = $call['result'];
        Db::transaction(function () use ($workspaceId, $id, $token, $clock, $result) {
            $row = $this->leased($workspaceId, $id, $token, $clock());
            if (($result['billing_status'] ?? '') !== 'settled' || empty($result['billing_reference'])) {
                $this->table('run')->where('id', $id)->update(['status' => 'needs_review', 'error_code' => 'BILLING_UNCONFIRMED', 'error_message' => '结算待核对，草稿尚未应用', 'lease_token' => '']); return;
            }
            if ($row['status'] === 'cancel_requested') { $this->finish($workspaceId, $id, 'canceled'); return; }
            $context = CanvasPolicy::decode($row['context_json']);
            try { $plan = CanvasAgentTools::validate($result['plan'] ?? null); }
            catch (\DomainException $e) { $this->finish($workspaceId, $id, 'failed', 'INVALID_AGENT_PLAN', '模型返回了不支持的操作，本回合未应用草稿'); return; }
            // All writes and the final assistant message commit together. A replay cannot duplicate tools.
            try {
                CanvasAgentTools::apply($this->tenantId, $this->userId, $workspaceId, $id, $context['view_key'], $plan['tools']);
            } catch (\Throwable $e) {
                $this->finish($workspaceId, $id, 'failed', 'DRAFT_APPLY_FAILED', '草稿未能保存，本回合未写入短剧正式数据');
                return;
            }
            $this->insert('message', ['workspace_id' => $workspaceId, 'run_id' => $id, 'view_key' => $context['view_key'], 'request_key' => 'run_reply_' . $id,
                'request_hash' => CanvasPolicy::hash($plan), 'role' => 'assistant', 'content' => $plan['message'], 'attachments_json' => '[]']);
            $this->table('run')->where('id', $id)->update(['tool_count' => count($plan['tools'])]);
            $this->finish($workspaceId, $id, 'success');
        });
    }
    private function finish(int $workspaceId, int $id, string $status, string $code = '', string $message = ''): void
    {
        $this->table('run')->where('id', $id)->update(['status' => $status, 'active_slot' => null, 'lease_token' => '', 'finished_at' => time(), 'update_time' => time(), 'error_code' => $code, 'error_message' => $message]);
        $this->event($workspaceId, $id, 'run_finished', ['run_id' => $id, 'status' => $status]);
    }
    /** Resume only work known not to have called upstream, or with a durably stored response. */
    public function recoverExpired(int $now): int
    {
        $rows = $this->table('run')->whereIn('status', ['running', 'cancel_requested'])->where('lease_expires_at', '<=', $now)->limit(100)->select()->toArray();
        $count = 0;
        foreach ($rows as $candidate) $count += Db::transaction(function () use ($candidate, $now) {
            $row = $this->run((int)$candidate['workspace_id'], (int)$candidate['id'], true);
            if (!in_array($row['status'], ['running', 'cancel_requested'], true) || (int)$row['lease_expires_at'] > $now) return 0;
            $context = CanvasPolicy::decode($row['context_json']); $result = CanvasPolicy::decode($row['result_json']);
            $safe = empty($context['call_started']) || isset($result['provider_result']);
            // Preserve cancellation rather than resume it as a normal successful run.
            if ($row['status'] === 'cancel_requested') $safe = false;
            $this->table('run')->where('id', $row['id'])->update(['status' => $safe ? 'pending' : 'needs_review', 'lease_token' => '',
                'error_code' => $safe ? '' : 'SUBMISSION_UNCONFIRMED', 'error_message' => $safe ? '' : '执行器中断，上游结果待核对', 'update_time' => $now]);
            return 1;
        });
        return $count;
    }
}
