<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiTaskJob;
use think\facade\Db;

/** Durable DB queue for post-submit result work. Provider submission is never run here. */
class AiTaskJobService
{
    public const TYPE_QUERY_RESULT = 'query_result';
    public const TYPE_PROCESS_RESULT = 'process_result';
    public const TYPE_TRANSFER_RESULT = 'transfer_result';
    public const TYPE_SETTLE = 'settle';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADMIN_ACTION = 'admin_action';

    public static function enqueueQueryResult(int $consumptionId, int $priority = 0, bool $wake = false): int
    {
        return self::enqueue(self::TYPE_QUERY_RESULT, $consumptionId, 0, [], $priority, $wake);
    }

    /**
     * Explicitly restart a completed query job after its consumption record is
     * recovered from an incorrect local terminal state.
     */
    public static function requeueQueryResult(int $consumptionId, int $priority = 0): int
    {
        return self::requeueCompletedJob(self::TYPE_QUERY_RESULT, $consumptionId, $priority);
    }

    /** Re-run terminal business-result persistence after a task recovery. */
    public static function requeueProcessResult(int $consumptionId, int $priority = 0): int
    {
        return self::requeueCompletedJob(self::TYPE_PROCESS_RESULT, $consumptionId, $priority);
    }

    private static function requeueCompletedJob(string $type, int $consumptionId, int $priority): int
    {
        if ($consumptionId <= 0) {
            return 0;
        }

        $jobId = self::enqueue($type, $consumptionId, 0, [], $priority, true);
        $now = time();
        Db::transaction(function () use ($type, $consumptionId, $priority, $jobId, $now) {
            $job = AiTaskJob::where('id', $jobId)->lock(true)->findOrEmpty();
            if ($job->isEmpty() || (string)$job['status'] !== 'success') {
                return;
            }
            $job->save([
                'status' => 'pending',
                'priority' => max((int)$job['priority'], $priority),
                'attempts' => 0,
                'next_run_time' => $now,
                'lease_token' => '',
                'lease_expire_time' => 0,
                'last_error' => '',
                'finish_time' => 0,
                'update_time' => $now,
            ]);
            AiTaskLifecycleEventService::record($consumptionId, 'job_requeued', 'pending', [
                'job_id' => (int)$job['id'],
                'job_type' => $type,
                'reason' => 'consumption_recovered',
            ]);
        });
        return $jobId;
    }

    public static function enqueueProcessResult(int $consumptionId, int $priority = 0): int
    {
        return self::enqueue(self::TYPE_PROCESS_RESULT, $consumptionId, 0, [], $priority, true);
    }

    public static function enqueueTransfer(int $assetId, bool $forced = false): int
    {
        return self::enqueue(self::TYPE_TRANSFER_RESULT, 0, $assetId, ['forced' => $forced], $forced ? 100 : 0, true);
    }

    public static function enqueueAdminAction(int $operationItemId, string $key): int
    {
        return self::enqueue(self::TYPE_ADMIN_ACTION, 0, 0, ['operation_item_id' => $operationItemId], 200, true, $key);
    }

    public static function enqueue(string $type, int $consumptionId = 0, int $assetId = 0, array $payload = [], int $priority = 0, bool $wake = false, string $customKey = ''): int
    {
        $now = time();
        $key = $customKey !== '' ? $customKey : $type . ':' . ($consumptionId ?: $assetId);
        return Db::transaction(function () use ($type, $consumptionId, $assetId, $payload, $priority, $wake, $key, $now) {
            $job = AiTaskJob::where('idempotency_key', $key)->lock(true)->findOrEmpty();
            if ($job->isEmpty()) {
                $job = AiTaskJob::create([
                    'app_task_id' => $consumptionId > 0 ? (int)(AiConsumptionLog::where('id', $consumptionId)->value('app_task_id') ?: 0) : 0,
                    'consumption_id' => $consumptionId,
                    'result_asset_id' => $assetId,
                    'job_type' => $type,
                    'status' => 'pending',
                    'priority' => $priority,
                    'payload' => $payload,
                    'attempts' => 0,
                    'max_attempts' => 0,
                    'next_run_time' => $now,
                    'lease_token' => '',
                    'lease_expire_time' => 0,
                    'last_error' => '',
                    'idempotency_key' => $key,
                    'create_time' => $now,
                    'update_time' => $now,
                    'finish_time' => 0,
                ]);
                AiTaskLifecycleEventService::record($consumptionId, 'job_enqueued', 'pending', [
                    'job_id' => (int)$job['id'],
                    'job_type' => $type,
                    'priority' => $priority,
                ]);
                return (int)$job['id'];
            }
            if ($wake && !in_array((string)$job['status'], ['success', 'dead'], true)) {
                $job->save([
                    'status' => 'pending',
                    'priority' => max((int)$job['priority'], $priority),
                    'next_run_time' => $now,
                    'lease_token' => '',
                    'lease_expire_time' => 0,
                    'update_time' => $now,
                ]);
                AiTaskLifecycleEventService::record($consumptionId, 'job_woken', 'pending', [
                    'job_id' => (int)$job['id'],
                    'job_type' => $type,
                    'previous_status' => (string)$job['status'],
                ], (int)$job['attempts']);
            }
            return (int)$job['id'];
        });
    }

    /** @return array<int,array<string,mixed>> */
    public static function claim(string $worker, int $leaseSeconds, int $batch): array
    {
        $jobs = [];
        $batch = max(1, min(100, $batch));
        for ($index = 0; $index < $batch; $index++) {
            $claimed = Db::transaction(function () use ($worker, $leaseSeconds) {
                $now = time();
                $job = AiTaskJob::where(function ($query) use ($now) {
                    $query->where(function ($pending) use ($now) {
                        $pending->whereIn('status', ['pending', 'retrying'])->where('next_run_time', '<=', $now);
                    })->whereOr(function ($expired) use ($now) {
                        $expired->where('status', 'running')->where('lease_expire_time', '<=', $now);
                    });
                })->whereIn('job_type', [self::TYPE_QUERY_RESULT, self::TYPE_PROCESS_RESULT, self::TYPE_TRANSFER_RESULT, self::TYPE_SETTLE, self::TYPE_REFUND, self::TYPE_ADMIN_ACTION])
                    ->order(['priority' => 'desc', 'next_run_time' => 'asc', 'id' => 'asc'])
                    ->lock(true)
                    ->findOrEmpty();
                if ($job->isEmpty()) return null;
                $token = $worker . '-' . bin2hex(random_bytes(8));
                $job->save([
                    'status' => 'running',
                    'attempts' => (int)$job['attempts'] + 1,
                    'lease_token' => $token,
                    'lease_expire_time' => $now + max(10, $leaseSeconds),
                    'update_time' => $now,
                ]);
                $data = $job->toArray();
                $data['lease_token'] = $token;
                AiTaskLifecycleEventService::record((int)$data['consumption_id'], 'worker_claim', 'running', [
                    'job_id' => (int)$data['id'],
                    'job_type' => (string)$data['job_type'],
                    'lease_seconds' => max(10, $leaseSeconds),
                ], (int)$data['attempts']);
                return $data;
            });
            if ($claimed === null) break;
            $jobs[] = $claimed;
        }
        return $jobs;
    }

    /**
     * Bounded worker fallback for scheduled reconciliation. The realtime
     * daemon remains the primary consumer; this prevents completed results
     * from being stranded when that daemon is restarted or temporarily down.
     *
     * @return array{processed:int,succeeded:int,waiting:int,retried:int}
     */
    public static function drain(string $worker, int $leaseSeconds, int $limit): array
    {
        $summary = ['processed' => 0, 'succeeded' => 0, 'waiting' => 0, 'retried' => 0];
        $limit = max(1, min(500, $limit));
        while ($summary['processed'] < $limit) {
            $jobs = self::claim($worker, $leaseSeconds, 1);
            if ($jobs === []) {
                break;
            }
            $job = $jobs[0];
            try {
                if (self::run($job)) {
                    self::succeed($job);
                    $summary['succeeded']++;
                } else {
                    self::reschedule($job, 5);
                    $summary['waiting']++;
                }
            } catch (\Throwable $e) {
                self::retry($job, $e);
                $summary['retried']++;
            }
            $summary['processed']++;
        }
        return $summary;
    }

    public static function run(array $job): bool
    {
        $type = (string)$job['job_type'];
        AiTaskLifecycleEventService::record((int)$job['consumption_id'], 'job_run', 'running', [
            'job_id' => (int)$job['id'],
            'job_type' => $type,
        ], (int)$job['attempts']);
        if ($type === self::TYPE_QUERY_RESULT) {
            return self::queryResult((int)$job['consumption_id'], (int)$job['attempts']);
        }
        if ($type === self::TYPE_PROCESS_RESULT) {
            $consumption = AiConsumptionLog::findOrEmpty((int)$job['consumption_id']);
            if (!$consumption->isEmpty() && in_array((string)$consumption['run_status'], ['success', 'failed', 'canceled', 'cancelled'], true)) {
                return AiTaskBusinessResultService::syncTerminalByConsumptionId((int)$job['consumption_id']);
            }
            AiTaskBusinessResultService::syncByConsumptionId((int)$job['consumption_id']);
            return true;
        }
        if ($type === self::TYPE_TRANSFER_RESULT) {
            AiTaskResultAssetService::transfer((int)$job['result_asset_id']);
            return true;
        }
        if ($type === self::TYPE_ADMIN_ACTION) {
            return AiTaskOperationService::runItem((int)($job['payload']['operation_item_id'] ?? 0));
        }
        // Settlement/refund are intentionally represented as durable jobs. The
        // market runtimes own their idempotent row-locked finalization today.
        self::enqueueProcessResult((int)$job['consumption_id']);
        return true;
    }

    public static function succeed(array $job): void
    {
        AiTaskJob::where(['id' => (int)$job['id'], 'lease_token' => (string)$job['lease_token']])->update([
            'status' => 'success', 'lease_token' => '', 'lease_expire_time' => 0,
            'last_error' => '', 'finish_time' => time(), 'update_time' => time(),
        ]);
        AiTaskLifecycleEventService::record((int)$job['consumption_id'], 'job_success', 'success', [
            'job_id' => (int)$job['id'],
            'job_type' => (string)$job['job_type'],
        ], (int)$job['attempts']);
    }

    public static function retry(array $job, \Throwable $error): void
    {
        $attempts = max(1, (int)$job['attempts']);
        $delay = min(300, max(2, 2 ** min(8, $attempts)));
        AiTaskJob::where(['id' => (int)$job['id'], 'lease_token' => (string)$job['lease_token']])->update([
            'status' => 'retrying', 'lease_token' => '', 'lease_expire_time' => 0,
            'next_run_time' => time() + $delay,
            'last_error' => mb_substr($error->getMessage(), 0, 1000),
            'update_time' => time(),
        ]);
        AiTaskLifecycleEventService::record((int)$job['consumption_id'], 'job_retry', 'retrying', [
            'job_id' => (int)$job['id'],
            'job_type' => (string)$job['job_type'],
            'delay_seconds' => $delay,
            'error_class' => get_class($error),
            'error' => mb_substr($error->getMessage(), 0, 300),
        ], $attempts);
    }

    public static function reschedule(array $job, int $delay = 5): int
    {
        $delay = self::rescheduleDelay($job, $delay);
        AiTaskJob::where(['id' => (int)$job['id'], 'lease_token' => (string)$job['lease_token']])->update([
            'status' => 'pending', 'lease_token' => '', 'lease_expire_time' => 0,
            'next_run_time' => time() + max(1, $delay), 'update_time' => time(),
        ]);
        AiTaskLifecycleEventService::record((int)$job['consumption_id'], 'job_waiting', 'pending', [
            'job_id' => (int)$job['id'],
            'job_type' => (string)$job['job_type'],
            'delay_seconds' => $delay,
        ], (int)$job['attempts']);
        return $delay;
    }

    private static function queryResult(int $consumptionId, int $attempt = 0): bool
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty()) return true;
        AiTaskLifecycleEventService::record($consumptionId, 'query_start', 'running', [
            'provider' => (string)$consumption['provider'],
            'protocol' => (string)$consumption['protocol'],
            'upstream_task_id' => (string)$consumption['upstream_task_id'],
        ], $attempt);
        AiMarketTaskRuntimeService::refresh($consumptionId);
        $latest = AiConsumptionLog::findOrEmpty($consumptionId);
        if (!$latest->isEmpty()) {
            AiTaskLifecycleEventService::record($consumptionId, 'query_finish', self::readyForBusinessProcessing($latest->toArray()) ? 'terminal' : 'running', [
                'run_status' => (string)$latest['run_status'],
                'billing_status' => (string)$latest['billing_status'],
                'error_code' => (string)$latest['error_code'],
            ], $attempt);
        }
        if (!$latest->isEmpty() && self::readyForBusinessProcessing($latest->toArray())) {
            // A recovered task may have a completed process job from its
            // earlier incorrect terminal state, so explicitly wake it again.
            self::requeueProcessResult($consumptionId);
            return true;
        }
        return false;
    }

    private static function readyForBusinessProcessing(array $consumption): bool
    {
        return in_array((string)($consumption['run_status'] ?? ''), ['success', 'failed', 'canceled', 'cancelled'], true)
            || in_array((string)($consumption['billing_status'] ?? ''), ['settled', 'refunded'], true);
    }

    private static function rescheduleDelay(array $job, int $delay): int
    {
        if ((string)($job['job_type'] ?? '') !== self::TYPE_QUERY_RESULT) {
            return max(1, $delay);
        }

        // Keep early result polls responsive, then reduce pressure from tasks
        // that remain pending while the provider or its query endpoint is slow.
        $attempts = max(1, (int)($job['attempts'] ?? 1));
        return min(60, max(5, $delay, 5 * (int)ceil($attempts / 10)));
    }
}
