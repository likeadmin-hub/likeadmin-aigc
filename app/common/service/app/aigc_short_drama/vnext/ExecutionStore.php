<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit;

/** Owns the only VNext budget and execution lane. Transactions never include provider I/O. */
final class ExecutionStore
{
    private array $scope;
    private ReceiptBuffer $buffer;
    public function __construct(int $tenant, int $user, string $task, ReceiptBuffer $buffer)
    {
        if ($tenant <= 0 || $user <= 0 || $task === '' || strlen($task) > 64) throw new RuntimeException('生成作业身份无效', 422);
        $this->scope = ['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task]; $this->buffer = $buffer;
    }
    public function create(string $lane, array $input, int $deadline, int $baseCalls = 3): array
    {
        if ($baseCalls < 1 || strlen($lane) > 64 || $lane === '') throw new RuntimeException('执行计划无效', 422);
        return Db::transaction(function () use ($lane, $input, $deadline, $baseCalls): array {
            $job = $this->query('job')->lock(true)->find();
            if ($job) {
                if ($job['input_hash'] !== ContentDocument::hash($input) || $job['lane_key'] !== $lane) throw new RuntimeException('生成上下文已变化，请建立新修订', 409);
                return $job;
            }
            if ($deadline <= time()) throw new RuntimeException('执行计划截止时间已过', 422);
            $extra = $baseCalls === 3 ? 5 : min(12, max(5, (int)ceil($baseCalls / 2)));
            $row = $this->scope + ['lane_key' => $lane, 'input_hash' => ContentDocument::hash($input), 'input_json' => self::json($input),
                'budget_json' => self::json(['base_calls' => $baseCalls, 'call_limit' => $baseCalls + $extra, 'calls' => 0,
                    'repair_rounds' => 0, 'reservations' => [], 'input_tokens' => 0, 'output_tokens' => 0,
                    'provider_cost' => 0, 'user_points' => 0, 'elapsed_ms' => 0]),
                'deadline_at' => $deadline, 'create_time' => time(), 'update_time' => time()];
            try { $this->query('job')->insert($row); }
            catch (\Throwable $error) {
                if (str_contains($error->getMessage(), 'Duplicate entry')) throw new RuntimeException('本集已有生成或修订作业，请先处理当前作业', 409, $error);
                throw $error;
            }
            return $this->snapshot();
        });
    }
    public function acquire(string $owner, int $leaseSeconds): int
    {
        if ($owner === '' || strlen($owner) > 64 || $leaseSeconds < 1) throw new RuntimeException('执行租约无效', 422);
        return Db::transaction(function () use ($owner, $leaseSeconds): int {
            $job = $this->job();
            if (!in_array($job['execution_status'], ['queued', 'running', 'retry_wait', 'reconciling'], true)) throw new RuntimeException('作业当前不可继续执行', 409);
            if ($job['lease_owner'] !== $owner && (int)$job['lease_until'] > time()) throw new RuntimeException('该集正在由其他执行器处理', 425);
            if ((int)$job['deadline_at'] <= time()) throw new RuntimeException('作业已达到截止时间，已完成内容保留', 429);
            $fence = (int)$job['fence'] + 1;
            $this->query('job')->update(['fence' => $fence, 'lease_owner' => $owner, 'lease_until' => time() + $leaseSeconds,
                'execution_status' => 'running', 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            return $fence;
        });
    }
    public function heartbeat(int $fence, int $leaseSeconds): void
    {
        if ($leaseSeconds < 1) throw new RuntimeException('执行租约无效', 422);
        Db::transaction(function () use ($fence, $leaseSeconds): void {
            $job = $this->job(); $this->assertFence($job, $fence);
            $this->query('job')->update(['lease_until' => min((int)$job['deadline_at'], time() + $leaseSeconds), 'update_time' => time()]);
        });
    }
    public function needsAttention(int $fence, string $code): void
    {
        Db::transaction(function () use ($fence, $code): void {
            $job = $this->job(); $this->assertFence($job, $fence);
            $this->query('job')->update(['execution_status' => 'needs_attention', 'error_code' => $code, 'lease_until' => 0,
                'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
        });
    }
    /** Reserve a complete repair/replan/review round before changing any content. */
    public function reserveRepair(int $fence, string $key, int $calls): void
    {
        Db::transaction(function () use ($fence, $key, $calls): void {
            $job = $this->job(); $this->assertFence($job, $fence); $budget = self::decode($job['budget_json']);
            if (isset($budget['reservations'][$key])) return;
            if ($key === '' || $calls < 2 || $budget['repair_rounds'] >= 2 || $budget['calls'] + array_sum($budget['reservations']) + $calls > $budget['call_limit']) {
                throw new RuntimeException('剩余预算不足以完成修复和复核，候选内容已保留', 429);
            }
            $budget['repair_rounds']++; $budget['reservations'][$key] = $calls;
            $this->query('job')->update(['budget_json' => self::json($budget)]);
        });
    }
    public function call(int $fence, string $key, array $input, callable $provider, string $reservation = ''): array
    {
        if ($key === '' || strlen($key) > 100) throw new RuntimeException('阶段标识无效', 422);
        $scope = $this->scope + ['stage_key' => $key]; $hash = ContentDocument::hash($input);
        $bufferKey = $this->buffer->key($scope, $hash);
        $buffered = $this->buffer->read($bufferKey);
        $claim = Db::transaction(function () use ($fence, $key, $input, $hash, $reservation, $buffered): array {
            $job = $this->job(); $this->assertFence($job, $fence);
            $stage = $this->query('stage')->where('stage_key', $key)->lock(true)->find();
            if ($stage && $stage['input_hash'] !== $hash) throw new RuntimeException('阶段输入与已保存收据不一致', 409);
            if ($stage && $stage['status'] === 'received') return ['receipt' => self::decode($stage['receipt_json'])];
            if ($stage && $buffered !== null) return ['buffered' => $buffered];
            if ($stage && $stage['status'] !== 'retry_wait') return ['unknown' => true]; // Intent alone cannot prove that submission never happened.
            if ($stage && (int)$stage['retry_after'] > time()) throw new RuntimeException('连接暂时不可用，等待延迟重试', 425);
            if ($stage && (int)$stage['attempt_count'] >= 3) throw new RuntimeException('连接重试次数已用完', 429);
            $budget = self::decode($job['budget_json']);
            if ($reservation !== '') {
                if (($budget['reservations'][$reservation] ?? 0) < 1) throw new RuntimeException('修复轮次预留预算已用完', 429);
                $budget['reservations'][$reservation]--;
            } elseif ($budget['calls'] + array_sum($budget['reservations']) >= $budget['call_limit']) throw new RuntimeException('本集统一调用预算已用完', 429);
            $budget['calls']++;
            $this->query('job')->update(['budget_json' => self::json($budget), 'update_time' => time()]);
            $attempt = (int)($stage['attempt_count'] ?? 0) + 1;
            $data = ['input_hash' => $hash, 'input_json' => self::json($input), 'fence' => $fence, 'status' => 'submitted',
                'attempt_count' => $attempt, 'retry_after' => 0, 'update_time' => time()];
            if ($stage) $this->query('stage')->where('stage_key', $key)->update($data);
            else $this->query('stage')->insert($this->scope + ['stage_key' => $key, 'create_time' => time()] + $data);
            return ['submit' => true, 'attempt' => $attempt];
        });
        if (isset($claim['receipt'])) return $claim['receipt'];
        if (isset($claim['buffered'])) { $this->receive($key, $hash, $claim['buffered']); return $claim['buffered']; }
        if (isset($claim['unknown'])) {
            $this->query('job')->where('fence', $fence)->where('execution_status', 'running')->update(['execution_status' => 'reconciling', 'error_code' => 'submission_unknown', 'event_seq' => Db::raw('event_seq + 1')]);
            throw new RuntimeException('上游结果尚未确认，已停止重复提交并保留现有内容', 409);
        }
        $started = microtime(true);
        try {
            $receipt = $provider($input, $bufferKey);
            if (!is_array($receipt)) throw new RuntimeException('模型返回收据无效', 502);
            $receipt['_elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
            $this->buffer->write($bufferKey, $receipt);
        } catch (\Throwable $error) {
            if (ShortDramaPlanningUnit::retryableBeforeSubmission($error->getMessage()) && $claim['attempt'] < 3) {
                Db::transaction(function () use ($fence, $key, $claim): void {
                    $job = $this->job(); $this->assertFence($job, $fence);
                    $this->query('stage')->where('stage_key', $key)->where('fence', $fence)->update(['status' => 'retry_wait',
                        'retry_after' => time() + ($claim['attempt'] === 1 ? 30 : 120) + random_int(0, 10), 'error_code' => 'connection_not_submitted']);
                    $this->query('job')->update(['execution_status' => 'retry_wait', 'error_code' => 'connection_not_submitted', 'event_seq' => (int)$job['event_seq'] + 1]);
                });
                throw new RuntimeException('连接暂时不可用，稍后继续；已保存内容保留', 425, $error);
            }
            // Provider/buffer ambiguity never authorizes another paid submission.
            try { $this->query('job')->where('fence', $fence)->where('execution_status', 'running')->update(['execution_status' => 'reconciling', 'error_code' => 'submission_unknown', 'event_seq' => Db::raw('event_seq + 1')]); }
            catch (\Throwable $ignored) { /* Original stage intent remains the durable recovery boundary. */ }
            throw $error;
        }
        $this->receive($key, $hash, $receipt);
        return $receipt;
    }
    /** Receiving a late result is allowed; publication still requires the current fence. */
    private function receive(string $key, string $hash, array $receipt): void
    {
        Db::transaction(function () use ($key, $hash, $receipt): void {
            $job = $this->job(); $stage = $this->query('stage')->where('stage_key', $key)->lock(true)->find();
            if (!$stage || $stage['input_hash'] !== $hash) throw new RuntimeException('收据不属于当前生成阶段', 409);
            if ($stage['status'] === 'received') return;
            $budget = self::decode($job['budget_json']); $usage = $receipt['usage'] ?? $receipt['result']['usage'] ?? [];
            $usage['input_tokens'] = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0;
            $usage['output_tokens'] = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0;
            foreach (['input_tokens', 'output_tokens', 'provider_cost', 'user_points'] as $field) $budget[$field] += max(0, (float)($usage[$field] ?? 0));
            $budget['elapsed_ms'] += max(0, (int)($receipt['_elapsed_ms'] ?? 0));
            $this->query('stage')->where('stage_key', $key)->update(['status' => 'received', 'receipt_json' => self::json($receipt), 'update_time' => time()]);
            $this->query('job')->update(['budget_json' => self::json($budget), 'update_time' => time()]);
        });
    }
    public function checkpoint(int $fence, array $candidate): void
    {
        Db::transaction(function () use ($fence, $candidate): void {
            $job = $this->job(); $this->assertFence($job, $fence);
            $this->query('job')->update(['candidate_json' => self::json($candidate), 'document_status' => 'draft',
                'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
        });
    }
    public function publish(int $fence, array $document, array $plan, array $coverage): void
    {
        ShotCompiler::assertCurrent($document, $plan);
        if (($coverage['status'] ?? '') !== 'ready' || ($coverage['document_hash'] ?? '') !== $document['content_hash'] || ($coverage['shot_hash'] ?? '') !== $plan['content_hash']) throw new RuntimeException('当前修订尚未通过完整验收', 422);
        Db::transaction(function () use ($fence, $document, $plan, $coverage): void {
            $job = $this->job();
            if ($job['execution_status'] === 'succeeded' && $job['published_revision_id'] === $document['revision_id']) {
                $published = self::decode($job['candidate_json']);
                if (($published['document']['content_hash'] ?? '') !== $document['content_hash'] || ($published['shot_plan']['content_hash'] ?? '') !== $plan['content_hash']) throw new RuntimeException('同一修订标识不能覆盖已发布内容', 409);
                return;
            }
            $this->assertFence($job, $fence); $sequence = (int)$job['event_seq'] + 1;
            $document['status'] = 'ready'; $payload = ['document' => $document, 'shot_plan' => $plan, 'coverage_report' => $coverage];
            $this->query('revision')->insert($this->scope + ['revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'], 'content_json' => self::json($payload), 'create_time' => time()]);
            $this->query('outbox')->insert($this->scope + ['event_seq' => $sequence, 'event_type' => 'revision_published', 'payload_json' => self::json(['revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash']]), 'create_time' => time()]);
            $this->query('job')->update(['execution_status' => 'succeeded', 'document_status' => 'ready', 'active_slot' => null,
                'published_revision_id' => $document['revision_id'], 'event_seq' => $sequence, 'candidate_json' => self::json($payload), 'update_time' => time()]);
        });
    }
    public function cancel(): void
    {
        Db::transaction(function (): void {
            $job = $this->job();
            if (in_array($job['execution_status'], ['succeeded', 'failed', 'canceled'], true)) return;
            $this->query('job')->update(['execution_status' => 'canceled', 'active_slot' => null, 'fence' => (int)$job['fence'] + 1,
                'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
        });
    }
    public function snapshot(): array { return $this->query('job')->find() ?: []; }
    public function publicSnapshot(): array
    {
        return self::publicProjection($this->snapshot());
    }
    public static function publicProjection(array $job): array
    {
        if (!$job) return [];
        $candidate = self::decode($job['candidate_json'] ?: '{}'); $actions = [];
        if ($job['document_status'] !== 'empty') $actions[] = 'read_content';
        if (in_array($job['execution_status'], ['queued', 'running', 'retry_wait', 'reconciling', 'needs_attention'], true)) $actions[] = 'cancel';
        // Reconciliation is not a retry permission; budget increments require a new authorization.
        if ($job['execution_status'] === 'reconciling') $actions[] = 'check_receipt';
        return ['pipeline_version' => 1, 'task_id' => $job['task_id'], 'execution_status' => $job['execution_status'],
            'document_status' => $job['document_status'], 'production_status' => $job['production_status'],
            'event_seq' => (int)$job['event_seq'], 'revision_id' => $candidate['document']['revision_id'] ?? '',
            'published_revision_id' => $job['published_revision_id'], 'allowed_actions' => $actions,
            'coverage_report' => $candidate['coverage_report'] ?? null,
            'content' => isset($candidate['document']) ? ['document' => $candidate['document'], 'script_lines' => ContentDocument::render($candidate['document'])] : null,
            'error_code' => $job['error_code']];
    }
    private function job(): array
    {
        $job = $this->query('job')->lock(true)->find();
        if (!$job) throw new RuntimeException('生成作业不存在', 404);
        return $job;
    }
    private function assertFence(array $job, int $fence): void
    {
        if ((int)$job['fence'] !== $fence || $job['execution_status'] !== 'running' || (int)$job['lease_until'] <= time()) throw new RuntimeException('执行租约已失效，旧结果不能覆盖当前修订', 409);
        if ((int)$job['deadline_at'] <= time()) throw new RuntimeException('本集执行已达到截止时间', 429);
    }
    private function query(string $kind) { return Db::name('aigc_short_drama_vnext_' . $kind)->where($this->scope); }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    private static function decode(string $value): array { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
}
