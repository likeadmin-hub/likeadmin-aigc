<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiTaskJob;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_video\AigcVideoService;
use think\facade\Db;

/** Durable DB queue for post-submit result work. Provider submission is never run here. */
class AiTaskJobService
{
    public const TYPE_QUERY_RESULT = 'query_result';
    public const TYPE_PROCESS_RESULT = 'process_result';
    public const TYPE_TRANSFER_RESULT = 'transfer_result';
    public const TYPE_SETTLE = 'settle';
    public const TYPE_REFUND = 'refund';

    public static function enqueueQueryResult(int $consumptionId, int $priority = 0, bool $wake = false): int
    {
        return self::enqueue(self::TYPE_QUERY_RESULT, $consumptionId, 0, [], $priority, $wake);
    }

    public static function enqueueProcessResult(int $consumptionId, int $priority = 0): int
    {
        return self::enqueue(self::TYPE_PROCESS_RESULT, $consumptionId, 0, [], $priority, true);
    }

    public static function enqueueTransfer(int $assetId, bool $forced = false): int
    {
        return self::enqueue(self::TYPE_TRANSFER_RESULT, 0, $assetId, ['forced' => $forced], $forced ? 100 : 0, true);
    }

    public static function enqueue(string $type, int $consumptionId = 0, int $assetId = 0, array $payload = [], int $priority = 0, bool $wake = false): int
    {
        $now = time();
        $key = $type . ':' . ($consumptionId ?: $assetId);
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
                })->whereIn('job_type', [self::TYPE_QUERY_RESULT, self::TYPE_PROCESS_RESULT, self::TYPE_TRANSFER_RESULT, self::TYPE_SETTLE, self::TYPE_REFUND])
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
                return $data;
            });
            if ($claimed === null) break;
            $jobs[] = $claimed;
        }
        return $jobs;
    }

    public static function run(array $job): bool
    {
        $type = (string)$job['job_type'];
        if ($type === self::TYPE_QUERY_RESULT) {
            return self::queryResult((int)$job['consumption_id']);
        }
        if ($type === self::TYPE_PROCESS_RESULT) {
            AiTaskResultAssetService::recordConsumptionAssets((int)$job['consumption_id'], self::mustTransfer((int)$job['consumption_id']));
            return true;
        }
        if ($type === self::TYPE_TRANSFER_RESULT) {
            AiTaskResultAssetService::transfer((int)$job['result_asset_id']);
            return true;
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
    }

    public static function reschedule(array $job, int $delay = 5): void
    {
        AiTaskJob::where(['id' => (int)$job['id'], 'lease_token' => (string)$job['lease_token']])->update([
            'status' => 'pending', 'lease_token' => '', 'lease_expire_time' => 0,
            'next_run_time' => time() + max(1, $delay), 'update_time' => time(),
        ]);
    }

    private static function queryResult(int $consumptionId): bool
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty()) return true;
        $appTask = (int)$consumption['app_task_id'];
        $businessTable = (string)(Db::name('ai_app_task')->where('id', $appTask)->value('business_table') ?: '');
        $businessId = (int)(Db::name('ai_app_task')->where('id', $appTask)->value('business_id') ?: 0);
        if ($businessTable === 'aigc_image_task' && $businessId > 0) {
            AigcImageService::refreshMarketTask((int)$consumption['tenant_id'], $businessId, (int)$consumption['user_id']);
        } elseif ($businessTable === 'aigc_video_task' && $businessId > 0) {
            AigcVideoService::refreshMarketTask((int)$consumption['tenant_id'], $businessId, (int)$consumption['user_id']);
        } elseif ($businessTable === 'aigc_short_drama_generation_task' && $businessId > 0) {
            AigcShortDramaService::refreshMarketGenerationTask($businessId);
        } else {
            if (in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true)) {
                self::enqueueProcessResult($consumptionId);
                return true;
            }
            // A runtime with no business result adapter must not be marked failed
            // merely because it is not yet migrated. It remains retryable.
            throw new \RuntimeException('未注册的市场结果处理器: ' . $businessTable);
        }
        $latest = AiConsumptionLog::findOrEmpty($consumptionId);
        if (!$latest->isEmpty() && in_array((string)$latest['billing_status'], ['settled', 'refunded'], true)) {
            self::enqueueProcessResult($consumptionId);
            return true;
        }
        return false;
    }

    private static function mustTransfer(int $consumptionId): bool
    {
        $task = Db::name('ai_app_task')->alias('t')
            ->join('ai_consumption_log c', 'c.app_task_id=t.id')
            ->where('c.id', $consumptionId)->field('t.app_code,t.business_table')->find();
        return (string)($task['app_code'] ?? '') === 'aigc_short_drama'
            || (string)($task['business_table'] ?? '') === 'aigc_short_drama_generation_task';
    }
}
