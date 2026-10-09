<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;
use app\common\service\power\TextRequestNotSubmittedException;
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
        return LocalTransaction::run(function () use ($lane, $input, $deadline, $baseCalls): array {
            $job = $this->query('job')->lock(true)->find();
            if ($job) {
                if ($job['input_hash'] !== ContentDocument::hash($input) || $job['lane_key'] !== $lane) throw new RuntimeException('生成上下文已变化，请建立新修订', 409);
                return $job;
            }
            if ($deadline <= time()) throw new RuntimeException('执行计划截止时间已过', 422);
            if (array_key_exists('resource_limits', $input['execution'] ?? []) && !is_array($input['execution']['resource_limits'])) throw new RuntimeException('任务资源预算配置无效', 422);
            $extra = $baseCalls === 3 ? 5 : min(12, max(5, (int)ceil($baseCalls / 2)));
            $row = $this->scope + ['lane_key' => $lane, 'input_hash' => ContentDocument::hash($input), 'input_json' => self::json($input),
                'budget_json' => self::json(['base_calls' => $baseCalls, 'call_limit' => $baseCalls + $extra, 'calls' => 0,
                    'repair_rounds' => 0, 'reservations' => [], 'input_tokens' => 0, 'output_tokens' => 0,
                    'provider_cost' => 0, 'user_points' => 0, 'elapsed_ms' => 0] +
                    (isset($input['execution']['resource_limits']) ? ['resources' => ResourceBudget::create($input['execution']['resource_limits'])] : [])),
                'deadline_at' => $deadline, 'create_time' => time(), 'update_time' => time()];
            if (isset($input['context']['canonical_input'])) {
                $row['candidate_json'] = self::json(['document' => $input['context']['canonical_input']]);
                $row['document_status'] = 'draft'; $row['event_seq'] = 1;
            }
            try { $this->query('job')->insert($row); }
            catch (\Throwable $error) {
                if (str_contains($error->getMessage(), 'Duplicate entry')) throw new RuntimeException('本集已有生成或修订作业，请先处理当前作业', 409, $error);
                throw $error;
            }
            return $this->snapshot();
        });
    }
    /** Freeze one capacity-derived shot plan. Recovery never increases or resets its budget. */
    public function sceneShotPlan(int $fence, string $key, array $document, ?array $proposal): ?array
    {
        return LocalTransaction::run(function () use ($fence, $key, $document, $proposal): ?array {
            $job = $this->job(); $this->assertFence($job, $fence);
            $input = self::decode($job['input_json']); $budget = self::decode($job['budget_json']);
            if (($input['context']['scene_shot_version'] ?? 0) !== SceneShotExecution::VERSION || $key !== 'shots') throw new RuntimeException('此作业没有分场执行授权', 409);
            $saved = $budget['scene_shot_plan'] ?? null;
            if ($saved !== null) {
                SceneShotExecution::assertPlan($document, $saved);
                if ($proposal !== null && ContentDocument::hash($proposal) !== ContentDocument::hash($saved)) throw new RuntimeException('分场计划已冻结，请建立新修订', 409);
                return $saved;
            }
            if ($proposal === null) return null;
            SceneShotExecution::assertPlan($document, $proposal);
            if (count($proposal['windows']) < 2 || $budget['repair_rounds'] > 0) throw new RuntimeException('分场计划不能在修复期间扩大预算', 409);
            $budget['base_calls'] += count($proposal['windows']) - 1;
            $budget['call_limit'] = max($budget['call_limit'], $budget['base_calls'] + min(12, max(5, (int)ceil($budget['base_calls'] / 2))));
            $budget['scene_shot_plan'] = $proposal;
            // Actual calls, token/point limits, spent amounts and deadline remain untouched.
            $this->query('job')->update(['budget_json' => self::json($budget), 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            return $proposal;
        });
    }
    /** Replace the original one review with N observations plus one global review, once. */
    public function sceneReviewPlan(int $fence, array $document, array $shots, ?array $proposal): ?array
    {
        return LocalTransaction::run(function () use ($fence, $document, $shots, $proposal): ?array {
            $job = $this->job(); $this->assertFence($job, $fence);
            $input = self::decode($job['input_json']); $budget = self::decode($job['budget_json']);
            if (($input['context']['scene_review_version'] ?? 0) !== SceneReviewExecution::VERSION) throw new RuntimeException('此作业没有分窗核验授权', 409);
            $saved = $budget['scene_review_plan'] ?? null;
            if ($saved !== null) {
                SceneReviewExecution::assertPlan($document, $shots, $saved);
                if ($proposal !== null && ContentDocument::hash($proposal) !== ContentDocument::hash($saved)) throw new RuntimeException('核验计划已冻结，请建立新修订', 409);
                return $saved;
            }
            if ($proposal === null) return null;
            SceneReviewExecution::assertPlan($document, $shots, $proposal);
            if ($budget['repair_rounds'] > 0) throw new RuntimeException('不能在修复期间扩大核验预算', 409);
            $budget['base_calls'] += count($proposal['windows']);
            $budget['call_limit'] = max($budget['call_limit'], $budget['base_calls'] + min(12, max(5, (int)ceil($budget['base_calls'] / 2))));
            $budget['scene_review_plan'] = $proposal;
            $this->query('job')->update(['budget_json' => self::json($budget), 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            return $proposal;
        });
    }
    /** Planning plus N scenes replaces one screenplay stage; a truncated attempt remains charged. */
    public function sceneScreenplayPlan(int $fence, array $context, ?array $proposal): ?array
    {
        return LocalTransaction::run(function () use ($fence, $context, $proposal): ?array {
            $job = $this->job(); $this->assertFence($job, $fence);
            $input = self::decode($job['input_json']); $budget = self::decode($job['budget_json']);
            if (($input['context']['scene_screenplay_version'] ?? 0) !== SceneScreenplayExecution::VERSION
                || ContentDocument::hash($context) !== ContentDocument::hash($input['context'])) throw new RuntimeException('此作业没有分场创作授权', 409);
            $saved = $budget['scene_screenplay_plan'] ?? null;
            if ($saved !== null) {
                SceneScreenplayExecution::assertPlan($context, $saved);
                if ($proposal !== null && ContentDocument::hash($proposal) !== ContentDocument::hash($saved)) throw new RuntimeException('创作计划已冻结，请建立新修订', 409);
                return $saved;
            }
            if ($proposal === null) return null;
            SceneScreenplayExecution::assertPlan($context, $proposal);
            if ($budget['repair_rounds'] > 0) throw new RuntimeException('不能在修复期间扩大创作预算', 409);
            $budget['base_calls'] += count($proposal['scenes']);
            $budget['call_limit'] = max($budget['call_limit'], $budget['base_calls'] + min(12, max(5, (int)ceil($budget['base_calls'] / 2))));
            $budget['scene_screenplay_plan'] = $proposal;
            $this->query('job')->update(['budget_json' => self::json($budget), 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            return $proposal;
        });
    }
    public function acquire(string $owner, int $leaseSeconds): int
    {
        if ($owner === '' || strlen($owner) > 64 || $leaseSeconds < 1) throw new RuntimeException('执行租约无效', 422);
        $fence = LocalTransaction::run(function () use ($owner, $leaseSeconds): ?int {
            $job = $this->job();
            if (!in_array($job['execution_status'], ['queued', 'running', 'retry_wait', 'reconciling'], true)) throw new RuntimeException('作业当前不可继续执行', 409);
            if ((int)$job['deadline_at'] <= time()) {
                $this->query('job')->update(['execution_status' => 'needs_attention', 'error_code' => 'deadline_exceeded',
                    'lease_until' => 0, 'fence' => (int)$job['fence'] + 1, 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
                return null;
            }
            if ($job['lease_owner'] !== $owner && (int)$job['lease_until'] > time()) throw new RuntimeException('该集正在由其他执行器处理', 425);
            $fence = (int)$job['fence'] + 1;
            $this->query('job')->update(['fence' => $fence, 'lease_owner' => $owner, 'lease_until' => min((int)$job['deadline_at'], time() + $leaseSeconds),
                'execution_status' => 'running', 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            return $fence;
        });
        if ($fence === null) throw new RuntimeException('作业已达到截止时间，已完成内容保留', 429);
        return $fence;
    }
    public function heartbeat(int $fence, int $leaseSeconds): void
    {
        if ($leaseSeconds < 1) throw new RuntimeException('执行租约无效', 422);
        LocalTransaction::run(function () use ($fence, $leaseSeconds): void {
            $job = $this->job(); $this->assertFence($job, $fence);
            $this->query('job')->update(['lease_until' => min((int)$job['deadline_at'], time() + $leaseSeconds), 'update_time' => time()]);
        });
    }
    public function needsAttention(int $fence, string $code): void
    {
        LocalTransaction::run(function () use ($fence, $code): void {
            $job = $this->job(); $this->assertFence($job, $fence);
            $this->query('job')->update(['execution_status' => 'needs_attention', 'error_code' => $code, 'lease_until' => 0,
                'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
        });
    }
    /** Reserve a complete repair/replan/review round before changing any content. */
    public function reserveRepair(int $fence, string $key, int $calls): void
    {
        LocalTransaction::run(function () use ($fence, $key, $calls): void {
            $job = $this->job(); $this->assertFence($job, $fence); $budget = self::decode($job['budget_json']);
            if (isset($budget['reservations'][$key])) return;
            if ($key === '' || $calls < 2 || $budget['repair_rounds'] >= 2 || $budget['calls'] + array_sum($budget['reservations']) + $calls > $budget['call_limit']) {
                throw new RuntimeException('剩余预算不足以完成修复和复核，候选内容已保留', 429);
            }
            if (isset($budget['resources'])) $budget['resources'] = ResourceBudget::reserveRound($budget['resources'], $key, $calls);
            $budget['repair_rounds']++; $budget['reservations'][$key] = $calls;
            $this->query('job')->update(['budget_json' => self::json($budget)]);
        });
    }
    public function call(int $fence, string $key, array $input, callable $provider, string $reservation = '', ?callable $reconcile = null): array
    {
        if ($key === '' || strlen($key) > 100) throw new RuntimeException('阶段标识无效', 422);
        $scope = $this->scope + ['stage_key' => $key]; $hash = ContentDocument::hash($input);
        $bufferKey = $this->buffer->key($scope, $hash);
        $buffered = $this->buffer->read($bufferKey);
        $claim = LocalTransaction::run(function () use ($fence, $key, $input, $hash, $reservation, $buffered): array {
            $job = $this->job(); $this->assertFence($job, $fence);
            $stage = $this->query('stage')->where('stage_key', $key)->lock(true)->find();
            if ($stage && $stage['input_hash'] !== $hash) throw new RuntimeException('阶段输入与已保存收据不一致', 409);
            if ($stage && $stage['status'] === 'received') return ['receipt' => self::decode($stage['receipt_json'])];
            if ($stage && $buffered !== null) return ['buffered' => $buffered];
            if ($stage && $stage['status'] === 'not_submitted') throw new RuntimeException('生成前预算检查未通过，请建立新修订', 429);
            if ($stage && $stage['status'] !== 'retry_wait') return ['unknown' => true]; // Intent alone cannot prove that submission never happened.
            if ($stage && (int)$stage['retry_after'] > time()) throw new RuntimeException('连接暂时不可用，等待延迟重试', 425);
            if ($stage && (int)$stage['attempt_count'] >= 3) throw new RuntimeException('连接重试次数已用完', 429);
            $budget = self::decode($job['budget_json']);
            if (isset($budget['resources'])) ResourceBudget::assertVerified($budget['resources']);
            // A connection retry is an extra attempt of this stage, not the next
            // planned stage. Keep the remaining review/global slots reserved.
            $attemptReservation = $budget['reopened_reservations'][$key] ?? ($stage ? '' : $reservation);
            unset($budget['reopened_reservations'][$key]);
            if ($attemptReservation !== '') {
                if (($budget['reservations'][$attemptReservation] ?? 0) < 1) throw new RuntimeException('修复轮次预留预算已用完', 429);
                $budget['reservations'][$attemptReservation]--;
            } elseif ($budget['calls'] + array_sum($budget['reservations']) >= $budget['call_limit']) throw new RuntimeException('本集统一调用预算已用完', 429);
            $budget['calls']++;
            $this->query('job')->update(['budget_json' => self::json($budget), 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            $attempt = (int)($stage['attempt_count'] ?? 0) + 1;
            $data = ['input_hash' => $hash, 'input_json' => self::json($input), 'fence' => $fence, 'status' => 'submitted',
                'attempt_count' => $attempt, 'retry_after' => 0, 'update_time' => time()];
            if ($stage) $this->query('stage')->where('stage_key', $key)->update($data);
            else $this->query('stage')->insert($this->scope + ['stage_key' => $key, 'create_time' => time()] + $data);
            return ['submit' => true, 'attempt' => $attempt, 'resources' => isset($budget['resources']), 'reservation' => $attemptReservation];
        });
        if (isset($claim['receipt'])) return $claim['receipt'];
        if (isset($claim['buffered'])) { $this->receive($key, $hash, $claim['buffered']); return $claim['buffered']; }
        if (isset($claim['unknown'])) {
            try {
                $recovered = $this->recoverBuffered($key, $hash, $reconcile);
                if ($recovered !== null) return $recovered;
            } catch (\Throwable $error) {
                $this->query('job')->where('fence', $fence)->where('execution_status', 'running')->update(['execution_status' => 'reconciling', 'error_code' => 'receipt_settlement_pending', 'event_seq' => Db::raw('event_seq + 1')]);
                throw $error;
            }
            $this->query('job')->where('fence', $fence)->where('execution_status', 'running')->update(['execution_status' => 'reconciling', 'error_code' => 'submission_unknown', 'event_seq' => Db::raw('event_seq + 1')]);
            throw new RuntimeException('上游结果尚未确认，已停止重复提交并保留现有内容', 409);
        }
        $reservation = $claim['reservation'];
        $started = microtime(true); $resultReceived = false; $admitted = false; $budgetRejected = false;
        try {
            $persist = function (array $raw) use ($key, $hash, $bufferKey, &$resultReceived): void {
                $resultReceived = true;
                $this->buffer->write(self::rawKey($bufferKey), $raw);
                // Shared database copy precedes business settlement. A second host
                // can recover the exact paid reply without the first host's runtime directory.
                $this->persistRawReceipt($key, $hash, $bufferKey, $raw);
            };
            $admit = !$claim['resources'] ? null : function (array $bound) use ($fence, $key, $reservation, &$admitted, &$budgetRejected): void {
                try {
                    LocalTransaction::run(function () use ($fence, $key, $reservation, $bound): void {
                        $job = $this->job(); $this->assertFence($job, $fence);
                        $budget = self::decode($job['budget_json']);
                        $budget['resources'] = ResourceBudget::admit($budget['resources'], $key, $bound, $reservation);
                        $this->query('job')->update(['budget_json' => self::json($budget)]);
                    });
                    $admitted = true;
                } catch (\Throwable $error) {
                    $budgetRejected = $error->getCode() === 429;
                    throw new TextRequestNotSubmittedException('剩余资源预算不足或作业已停止，已保存内容保留', $error->getCode() === 429 ? 429 : 422, $error);
                }
            };
            $providerInput = $input;
            if (($input['_account_reservation_version'] ?? 0) === 1) $providerInput['_account_reservation_attempt'] = $claim['attempt'];
            $receipt = $provider($providerInput, $bufferKey, $persist, $admit);
            // Detect an adapter contract violation; it cannot authorize any subsequent call.
            if ($claim['resources'] && !$admitted) throw new RuntimeException('模型适配器未完成资源预算准入', 409);
            if (!is_array($receipt)) throw new RuntimeException('模型返回收据无效', 502);
            $receipt['_elapsed_ms'] = $receipt['_elapsed_ms'] ?? (int)round((microtime(true) - $started) * 1000);
            $this->buffer->write($bufferKey, $receipt);
        } catch (\Throwable $error) {
            if (($input['_account_reservation_version'] ?? 0) !== 1 && !$resultReceived && $claim['resources'] && $claim['attempt'] >= 3 && ShortDramaPlanningUnit::retryableBeforeSubmission($error->getMessage())) {
                $error = new TextRequestNotSubmittedException('连接重试预算已用完，已保存内容保留', 429, $error);
            }
            if (!$resultReceived && $error instanceof TextRequestNotSubmittedException) {
                LocalTransaction::run(function () use ($fence, $key, $error, $budgetRejected, $reservation): void {
                    $job = $this->job(); $budget = self::decode($job['budget_json']);
                    if (isset($budget['resources'])) $budget['resources'] = ResourceBudget::releaseUnsubmitted($budget['resources'], $key);
                    if ($budgetRejected && $reservation !== '') $budget['rejected_reservations'][$key] = $reservation;
                    $this->query('stage')->where('stage_key', $key)->where('fence', $fence)->update(['status' => 'not_submitted', 'error_code' => $budgetRejected ? 'resource_budget_exhausted' : 'resource_admission_rejected']);
                    $this->query('job')->update(['budget_json' => self::json($budget)]);
                    $this->query('job')->where('fence', $fence)->where('execution_status', 'running')->update([
                        'execution_status' => 'needs_attention', 'error_code' => $error->getCode() === 429 ? 'budget_exhausted' : 'resource_admission_rejected',
                        'lease_until' => 0, 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
                });
                throw $error;
            }
            if (($input['_account_reservation_version'] ?? 0) !== 1 && !$resultReceived && ShortDramaPlanningUnit::retryableBeforeSubmission($error->getMessage()) && $claim['attempt'] < 3) {
                LocalTransaction::run(function () use ($fence, $key, $claim): void {
                    $job = $this->job(); $this->assertFence($job, $fence);
                    $budget = self::decode($job['budget_json']);
                    if (isset($budget['resources'])) $budget['resources'] = ResourceBudget::releaseUnsubmitted($budget['resources'], $key);
                    $this->query('job')->update(['budget_json' => self::json($budget)]);
                    $this->query('stage')->where('stage_key', $key)->where('fence', $fence)->update(['status' => 'retry_wait',
                        'retry_after' => time() + ($claim['attempt'] === 1 ? 30 : 120) + random_int(0, 10), 'error_code' => 'connection_not_submitted']);
                    $this->query('job')->update(['execution_status' => 'retry_wait', 'error_code' => 'connection_not_submitted', 'event_seq' => (int)$job['event_seq'] + 1]);
                });
                throw new RuntimeException('连接暂时不可用，稍后继续；已保存内容保留', 425, $error);
            }
            // Provider/buffer ambiguity never authorizes another paid submission.
            try { $this->query('job')->where('fence', $fence)->where('execution_status', 'running')->update(['execution_status' => 'reconciling', 'error_code' => $resultReceived ? 'receipt_settlement_pending' : 'submission_unknown', 'update_time' => time(), 'event_seq' => Db::raw('event_seq + 1')]); }
            catch (\Throwable $ignored) { /* Original stage intent remains the durable recovery boundary. */ }
            throw $error;
        }
        $this->receive($key, $hash, $receipt);
        return $receipt;
    }
    public function canAutomaticallyReconcile(): bool
    {
        $job = $this->snapshot(); $budget = $job ? self::decode($job['budget_json']) : [];
        return $job && $job['execution_status'] === 'reconciling' && $job['error_code'] === 'receipt_settlement_pending'
            && (int)$job['deadline_at'] > time() && (int)($budget['reconciliation_attempts'] ?? 0) < 3;
    }
    public function workerReady(): bool
    {
        $job = $this->snapshot();
        if (!$job) return true; // Let the worker report a missing durable job.
        if ($job['execution_status'] === 'running') return (int)$job['lease_until'] <= time();
        if ($job['execution_status'] === 'retry_wait') return !$this->query('stage')->where('status', 'retry_wait')->where('retry_after', '>', time())->count();
        if (!$this->canAutomaticallyReconcile()) return true;
        $attempt = (int)(self::decode($job['budget_json'])['reconciliation_attempts'] ?? 0);
        return time() >= (int)$job['update_time'] + [30, 120, 300][$attempt];
    }
    /** Bounded local settlement recovery. Never revives canceled work or resets paid-call budgets. */
    public function reconcileAutomatically(callable $reconcile): bool
    {
        $claimed = LocalTransaction::run(function (): bool {
            $job = $this->job();
            if (!$this->canAutomaticallyReconcile() || !$this->workerReady()) return false;
            $budget = self::decode($job['budget_json']);
            $budget['reconciliation_attempts'] = (int)($budget['reconciliation_attempts'] ?? 0) + 1;
            $this->query('job')->update(['budget_json' => self::json($budget), 'update_time' => time()]);
            return true;
        });
        if (!$claimed) return false;
        $this->reconcileReceipts($reconcile);
        return $this->resume(true);
    }
    private static function rawKey(string $key): string { return hash('sha256', 'provider-result:' . $key); }
    /** Only a server ledger sealed before its submit marker can reopen a paid attempt. */
    public function reconcileUnsubmitted(callable $release): int
    {
        return LocalTransaction::run(function () use ($release): int {
            $job=$this->job();$input=self::decode($job['input_json']);
            if (($input['context']['account_reservation_version']??0)!==1
                || !in_array($job['execution_status'],['reconciling','canceled'],true)) return 0;
            $budget=self::decode($job['budget_json']);$count=0;
            foreach($this->query('stage')->where('status','submitted')->lock(true)->select()->toArray() as $stage) {
                $params=self::decode($stage['input_json']);
                if (($params['_account_reservation_version']??0)!==1 || (string)$stage['receipt_json']!=='') continue;
                $key=$this->buffer->key($this->scope+['stage_key'=>$stage['stage_key']],$stage['input_hash']);
                if ($this->buffer->read($key)!==null || $this->buffer->read(self::rawKey($key))!==null) continue;
                if ($release($key,(int)$stage['attempt_count'])!==true) continue;
                if(isset($budget['resources'])) $budget['resources']=ResourceBudget::releaseUnsubmitted($budget['resources'],$stage['stage_key']);
                $this->query('stage')->where('id',$stage['id'])->update(['status'=>'retry_wait','retry_after'=>time()+30,
                    'error_code'=>'prepared_request_reconciled','update_time'=>time()]);
                $count++;
            }
            if($count) $this->query('job')->update(['budget_json'=>self::json($budget),'fence'=>(int)$job['fence']+1,
                'lease_until'=>0,'event_seq'=>(int)$job['event_seq']+1,'update_time'=>time()]);
            return $count;
        });
    }
    /** Call outside a surrounding transaction: final receipt publication must follow committed settlement. */
    public function reconcileReceipts(callable $reconcile): void
    {
        foreach ($this->query('stage')->where('status', 'submitted')->select()->toArray() as $stage) {
            $this->recoverBuffered($stage['stage_key'], $stage['input_hash'], $reconcile);
        }
    }
    private function recoverBuffered(string $stage, string $hash, ?callable $reconcile): ?array
    {
        $key = $this->buffer->key($this->scope + ['stage_key' => $stage], $hash);
        $receipt = $this->buffer->read($key);
        if ($receipt === null && $reconcile !== null) {
            $raw = $this->durableRawReceipt($stage, $hash, $key);
            $local = $this->buffer->read(self::rawKey($key));
            if ($raw !== null && $local !== null && ContentDocument::hash($raw) !== ContentDocument::hash($local)) throw new RuntimeException('共享收据与本机收据存在冲突，已停止恢复', 409);
            $raw = $raw ?? $local;
            if ($raw !== null) {
                $receipt = $reconcile($raw);
                if (!is_array($receipt)) throw new RuntimeException('结算恢复收据无效', 409);
                $this->buffer->write($key, $receipt);
            }
        }
        if ($receipt !== null) $this->receive($stage, $hash, $receipt);
        return $receipt;
    }

    private function persistRawReceipt(string $key, string $hash, string $request, array $raw): void
    {
        LocalTransaction::run(function () use ($key, $hash, $request, $raw): void {
            $this->job(); // Same job -> stage lock order as receipt settlement.
            $stage = $this->query('stage')->where('stage_key', $key)->lock(true)->find();
            if (!$stage || $stage['input_hash'] !== $hash) throw new RuntimeException('共享收据与已提交请求不符', 409);
            if ($stage['status'] === 'received') return;
            if ($stage['status'] !== 'submitted') throw new RuntimeException('此阶段没有已提交请求，不能写入共享收据', 409);
            $old = $this->durableRawReceipt($key, $hash, $request);
            if ($old !== null && ContentDocument::hash($old) !== ContentDocument::hash($raw)) throw new RuntimeException('同一请求存在冲突的共享收据', 409);
            $envelope = ['vnext_raw_receipt' => 1, 'request_key' => $request, 'input_hash' => $hash, 'receipt_hash' => ContentDocument::hash($raw), 'receipt' => $raw];
            $json = self::json($envelope);
            if (strlen($json) > 16777215) throw new RuntimeException('完整模型收据超过保存容量，已保留本机缓冲', 413);
            $this->query('stage')->where('id', $stage['id'])->update(['receipt_json' => $json, 'update_time' => time()]);
        });
    }
    private function durableRawReceipt(string $key, string $hash, string $request): ?array
    {
        $stage = $this->query('stage')->where('stage_key', $key)->find();
        if (!$stage || $stage['status'] !== 'submitted' || !$stage['receipt_json']) return null;
        $envelope = self::decode($stage['receipt_json']);
        if (($envelope['vnext_raw_receipt'] ?? 0) !== 1 || ($envelope['request_key'] ?? '') !== $request
            || $stage['input_hash'] !== $hash || ($envelope['input_hash'] ?? '') !== $hash || !is_array($envelope['receipt'] ?? null)
            || ($envelope['receipt_hash'] ?? '') !== ContentDocument::hash($envelope['receipt'])) throw new RuntimeException('共享原始收据校验失败，已停止恢复', 409);
        return $envelope['receipt'];
    }
    /** Receiving a late result is allowed; publication still requires the current fence. */
    private function receive(string $key, string $hash, array $receipt): void
    {
        LocalTransaction::run(function () use ($key, $hash, $receipt): void {
            $job = $this->job(); $stage = $this->query('stage')->where('stage_key', $key)->lock(true)->find();
            if (!$stage || $stage['input_hash'] !== $hash) throw new RuntimeException('收据不属于当前生成阶段', 409);
            if ($stage['status'] === 'received') return;
            $budget = self::decode($job['budget_json']); $usage = $receipt['usage'] ?? $receipt['result']['usage'] ?? [];
            $usage['input_tokens'] = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0;
            $usage['output_tokens'] = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0;
            $usage['user_points'] = $receipt['billing']['user_charge_points'] ?? $usage['user_points'] ?? 0;
            $usage['provider_cost'] = $usage['provider_cost'] ?? $receipt['billing']['provider_cost'] ?? 0;
            foreach (['input_tokens', 'output_tokens', 'provider_cost', 'user_points'] as $field) $budget[$field] += max(0, (float)($usage[$field] ?? 0));
            $budget['elapsed_ms'] += max(0, (int)($receipt['_elapsed_ms'] ?? 0));
            if (isset($budget['resources'])) $budget['resources'] = ResourceBudget::receive($budget['resources'], $key, $receipt);
            $this->query('stage')->where('stage_key', $key)->update(['status' => 'received', 'receipt_json' => self::json($receipt), 'update_time' => time()]);
            $this->query('job')->update(['budget_json' => self::json($budget), 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
        });
    }
    public function checkpoint(int $fence, array $candidate): void
    {
        LocalTransaction::run(function () use ($fence, $candidate): void {
            $job = $this->job(); $this->assertFence($job, $fence);
            if (isset($candidate['screenplay_windows'])) {
                $previous = self::decode($job['candidate_json'] ?: '{}');
                $samePlan = ($previous['screenplay_windows']['plan_hash'] ?? '') === $candidate['screenplay_windows']['plan_hash'];
                foreach ($previous['document']['transforms'] ?? [] as $transform) {
                    if (($transform['code'] ?? '') === 'scene_creation' && ($transform['plan_hash'] ?? '') === $candidate['screenplay_windows']['plan_hash']) $samePlan = true;
                }
                // Replaying paid receipts must not replace a later draft with an earlier prefix.
                if ($samePlan && (count($previous['document']['scenes'] ?? []) > count($candidate['document']['scenes'] ?? [])
                    || isset($previous['shot_plan']) || isset($previous['coverage_report']))) return;
            }
            $this->query('job')->update(['candidate_json' => self::json($candidate), 'document_status' => 'draft',
                'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
        });
    }
    public function publish(int $fence, array $document, array $plan, array $coverage, ?callable $projection = null): void
    {
        ShotCompiler::assertCurrent($document, $plan);
        if (($coverage['status'] ?? '') !== 'ready' || ($coverage['document_hash'] ?? '') !== $document['content_hash'] || ($coverage['shot_hash'] ?? '') !== $plan['content_hash']) throw new RuntimeException('当前修订尚未通过完整验收', 422);
        LocalTransaction::run(function () use ($fence, $document, $plan, $coverage, $projection): void {
            $job = $this->job();
            if ($job['execution_status'] === 'succeeded' && $job['published_revision_id'] === $document['revision_id']) {
                $published = self::decode($job['candidate_json']);
                if (($published['document']['content_hash'] ?? '') !== $document['content_hash'] || ($published['shot_plan']['content_hash'] ?? '') !== $plan['content_hash']) throw new RuntimeException('同一修订标识不能覆盖已发布内容', 409);
                return;
            }
            $this->assertFence($job, $fence);
            $resources = self::decode($job['budget_json'])['resources'] ?? null;
            if ($resources !== null) {
                ResourceBudget::assertVerified($resources);
                if ($resources['holds'] !== []) throw new RuntimeException('模型用量仍待核对，暂不可发布', 429);
            }
            $sequence = (int)$job['event_seq'] + 1;
            $document['status'] = 'ready'; $payload = ['document' => $document, 'shot_plan' => $plan, 'coverage_report' => $coverage];
            // Business task/version pointers and the canonical revision must commit together.
            // This callback performs local persistence only, never provider I/O.
            if ($projection) $projection($payload, $this->receipts());
            $this->query('revision')->insert($this->scope + ['revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'], 'content_json' => self::json($payload), 'create_time' => time()]);
            $this->query('outbox')->insert($this->scope + ['event_seq' => $sequence, 'event_type' => 'revision_published', 'payload_json' => self::json(['revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash']]), 'create_time' => time()]);
            $this->query('job')->update(['execution_status' => 'succeeded', 'document_status' => 'ready', 'active_slot' => null,
                'published_revision_id' => $document['revision_id'], 'event_seq' => $sequence, 'candidate_json' => self::json($payload), 'update_time' => time()]);
        });
    }
    public function cancel(): void
    {
        LocalTransaction::run(function (): void {
            $job = $this->job();
            if (in_array($job['execution_status'], ['succeeded', 'failed', 'canceled'], true)) return;
            $this->query('job')->update(['execution_status' => 'canceled', 'active_slot' => null, 'fence' => (int)$job['fence'] + 1,
                'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
        });
    }

    /** At-least-once notification delivery. The receiver deduplicates event_id; publication is already committed. */
    public function deliverPublicationEvents(callable $deliver): int
    {
        $delivered = 0;
        foreach ($this->query('outbox')->where('delivered_at', 0)->order('event_seq')->limit(10)->select()->toArray() as $event) {
            $payload = self::decode($event['payload_json']);
            $revision = $this->query('revision')->where('revision_id', $payload['revision_id'] ?? '')->find();
            if ($event['event_type'] !== 'revision_published' || !$revision || $revision['document_hash'] !== ($payload['document_hash'] ?? '')) {
                throw new RuntimeException('发布事件与已保存剧本版本不符，已停止投递', 409);
            }
            $envelope = $this->scope + ['event_id' => ContentDocument::hash([$this->scope, (int)$event['event_seq']]),
                'event_seq' => (int)$event['event_seq'], 'event_type' => $event['event_type'], 'payload' => $payload];
            try { $deliver($envelope); }
            catch (\Throwable $ignored) { break; } // Keep ordered events pending; content stays published.
            $delivered += $this->query('outbox')->where(['id' => $event['id'], 'delivered_at' => 0])->update(['delivered_at' => time()]);
        }
        return $delivered;
    }
    /** Explicit user continuation reuses the original receipts, budget and deadline. */
    public function resume(bool $automatic = false): bool
    {
        // A process may have buffered its response before losing database access. Reconcile only exact keys.
        foreach ($this->query('stage')->where('status', 'submitted')->select()->toArray() as $stage) {
            $key = $this->buffer->key($this->scope + ['stage_key' => $stage['stage_key']], $stage['input_hash']);
            $receipt = $this->buffer->read($key);
            if ($receipt !== null) $this->receive($stage['stage_key'], $stage['input_hash'], $receipt);
        }
        return LocalTransaction::run(function () use ($automatic): bool {
            $job = $this->job();
            if ($automatic && $job['execution_status'] !== 'reconciling') return false;
            if (in_array($job['execution_status'], ['queued', 'running', 'retry_wait', 'succeeded'], true)) return false;
            if (!in_array($job['execution_status'], ['canceled', 'reconciling'], true)) throw new RuntimeException('当前问题需要修改内容或执行策略，不能通过重复调用解决', 409);
            if ($this->query('stage')->where('status', 'submitted')->count()) throw new RuntimeException('上游提交尚未确认，不能重复生成；已生成内容保留', 409);
            if ((int)$job['deadline_at'] <= time()) throw new RuntimeException('原任务执行期限已到，不能通过重试重置预算或期限', 429);
            try {
                $this->query('job')->update(['execution_status' => 'queued', 'active_slot' => 1, 'error_code' => '', 'lease_until' => 0,
                    'fence' => (int)$job['fence'] + 1, 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            } catch (\Throwable $error) {
                if (str_contains($error->getMessage(), 'Duplicate entry')) throw new RuntimeException('本集已有新的活动任务，不能同时恢复旧任务', 409, $error);
                throw $error;
            }
            return true;
        });
    }
    public function snapshot(): array { return $this->query('job')->find() ?: []; }
    public function extendBudget(array $extension): bool
    {
        if (array_diff(array_keys($extension), ['request_key', 'expected_event_seq', 'resource_limits', 'call_limit'])
            || !is_string($extension['request_key'] ?? null) || !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $extension['request_key'])
            || !is_int($extension['expected_event_seq'] ?? null) || !is_array($extension['resource_limits'] ?? null)) throw new RuntimeException('请提交明确的预算上限和当前状态版本', 422);
        return LocalTransaction::run(function () use ($extension): bool {
            $job = $this->job(); $budget = self::decode($job['budget_json']); $hash = ContentDocument::hash($extension);
            $prior = $budget['extensions'][$extension['request_key']] ?? null;
            if ($prior !== null) {
                if ($prior['request_hash'] !== $hash) throw new RuntimeException('同一预算授权不能更换内容', 409);
                return false;
            }
            if ((int)$job['event_seq'] !== $extension['expected_event_seq'] || !self::canExtend($job, $budget)
                || $this->query('stage')->where('status', 'submitted')->count()
                || $this->query('stage')->where(['status' => 'not_submitted', 'error_code' => 'resource_admission_rejected'])->count()) throw new RuntimeException('当前任务不能通过增加预算恢复，请核对问题和版本', 409);
            $before = $budget['resources']; $budget['resources'] = ResourceBudget::increase($before, $extension['resource_limits']);
            $calls = $extension['call_limit'] ?? $budget['call_limit'];
            if (!is_int($calls) || $calls < $budget['call_limit'] || $calls > $budget['base_calls'] + 24 || $calls <= $budget['calls'] + array_sum($budget['reservations'])) throw new RuntimeException('调用预算应覆盖未完成阶段，且不能突破单集修复上限', 422);
            $budget['call_limit'] = $calls;
            foreach ($this->query('stage')->where(['status' => 'not_submitted', 'error_code' => 'resource_budget_exhausted'])->lock(true)->select()->toArray() as $stage) {
                $reservation = $budget['rejected_reservations'][$stage['stage_key']] ?? '';
                if ($reservation !== '') {
                    $budget['reservations'][$reservation]++;
                    $budget['reopened_reservations'][$stage['stage_key']] = $reservation;
                    unset($budget['rejected_reservations'][$stage['stage_key']]);
                }
                // Only the verified pre-admission denial is reopened. Connection exhaustion/unknown submits are not.
                $this->query('stage')->where('id', $stage['id'])->update(['status' => 'retry_wait', 'retry_after' => 0,
                    'attempt_count' => max(0, (int)$stage['attempt_count'] - 1), 'error_code' => '', 'update_time' => time()]);
            }
            $budget['extensions'][$extension['request_key']] = ['request_hash' => $hash, 'authorized_by' => $this->scope['user_id'], 'authorized_at' => time(),
                'before' => ResourceBudget::publicState($before), 'after' => ResourceBudget::publicState($budget['resources']), 'call_limit' => $calls];
            $this->query('job')->update(['budget_json' => self::json($budget), 'execution_status' => 'queued', 'active_slot' => 1,
                'fence' => (int)$job['fence'] + 1, 'lease_until' => 0, 'error_code' => '', 'event_seq' => (int)$job['event_seq'] + 1, 'update_time' => time()]);
            return true;
        });
    }
    private static function canExtend(array $job, array $budget): bool
    {
        return $job['execution_status'] === 'needs_attention' && $job['error_code'] === 'budget_exhausted'
            && (int)$job['deadline_at'] > time() && isset($budget['resources']) && $budget['resources']['blocked'] === ''
            && !$budget['resources']['holds'] && count($budget['extensions'] ?? []) < 8;
    }
    public function receipts(): array
    {
        return array_map(static fn($row) => self::decode($row['receipt_json']),
            $this->query('stage')->where('status', 'received')->order('id')->select()->toArray());
    }
    public function publicSnapshot(): array
    {
        $job = $this->snapshot();
        return $job ? self::publicProjection($job + ['pending_receipts' => (int)$this->query('stage')->where('status', 'submitted')->count(),
            'budget_extension_safe' => !$this->query('stage')->where(['status' => 'not_submitted', 'error_code' => 'resource_admission_rejected'])->count()]) : [];
    }
    public static function publicProjection(array $job): array
    {
        if (!$job) return [];
        $candidate = self::decode($job['candidate_json'] ?: '{}'); $actions = []; $budget = self::decode($job['budget_json'] ?? '{}');
        if ($job['document_status'] !== 'empty') $actions[] = 'read_content';
        if (in_array($job['execution_status'], ['queued', 'running', 'retry_wait', 'reconciling', 'needs_attention'], true)) $actions[] = 'cancel';
        // Reconciliation is not a retry permission; budget increments require a new authorization.
        if ($job['execution_status'] === 'reconciling'
            || ($job['execution_status'] === 'canceled' && !empty($job['pending_receipts']))) $actions[] = 'check_receipt';
        if ($job['execution_status'] === 'canceled' && isset($job['pending_receipts']) && !$job['pending_receipts']
            && (int)$job['deadline_at'] > time()) $actions[] = 'retry';
        if (!empty($candidate['document']) && in_array($job['execution_status'], ['succeeded', 'needs_attention'], true)) $actions[] = 'revise_content';
        if (isset($job['pending_receipts']) && !$job['pending_receipts'] && !empty($job['budget_extension_safe']) && self::canExtend($job, $budget)) $actions[] = 'extend_budget';
        return ['pipeline_version' => 1, 'task_id' => $job['task_id'], 'execution_status' => $job['execution_status'],
            'document_status' => $job['document_status'], 'production_status' => $job['production_status'],
            'event_seq' => (int)$job['event_seq'], 'revision_id' => $candidate['document']['revision_id'] ?? '',
            'published_revision_id' => $job['published_revision_id'], 'allowed_actions' => $actions,
            'resource_budget' => in_array('extend_budget', $actions, true) ? ResourceBudget::publicState($budget['resources']) + ['call_limit' => $budget['call_limit'], 'max_call_limit' => $budget['base_calls'] + 24] : null,
            'coverage_report' => $candidate['coverage_report'] ?? null,
            'structural_edit_targets' => !empty($candidate['document']) && in_array('revise_content', $actions, true) ? StructuralRevision::targets($candidate['document'],
                [$candidate['coverage_report']['narrative_ledger'] ?? [], self::decode($job['input_json'] ?? '{}')['context']['previous_narrative'] ?? []]) : null,
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
