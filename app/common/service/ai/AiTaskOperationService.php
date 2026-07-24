<?php

namespace app\common\service\ai;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiTaskDeleteReceipt;
use app\common\model\ai\AiTaskJob;
use app\common\model\ai\AiTaskOperation;
use app\common\model\ai\AiTaskOperationItem;
use app\common\model\ai\AiTaskResultAsset;
use app\common\model\file\TenantFile;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use think\facade\Db;

/**
 * Administrative actions are deliberately routed through the same durable
 * result pipeline used by normal task completion. No action can force a task
 * to success or mutate balances directly.
 */
class AiTaskOperationService
{
    public const ACTION_QUERY = 'query';
    public const ACTION_RETRY_QUERY = 'retry_query';
    public const ACTION_COMPLETE_BY_QUERY = 'complete_by_query';
    public const ACTION_FAIL = 'fail';
    public const ACTION_RECOVER = 'recover';
    public const ACTION_RETRY_TRANSFER = 'retry_transfer';
    public const ACTION_DELETE = 'delete';

    private const ACTIONS = [
        self::ACTION_QUERY, self::ACTION_RETRY_QUERY, self::ACTION_COMPLETE_BY_QUERY,
        self::ACTION_FAIL, self::ACTION_RECOVER, self::ACTION_RETRY_TRANSFER, self::ACTION_DELETE,
    ];

    public static function submit(array $recordKeys, string $action, string $reason, int $tenantId, int $adminId, string $terminal): array
    {
        $action = trim($action);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('不支持的批量操作');
        }
        $recordKeys = array_values(array_unique(array_filter(array_map('strval', $recordKeys))));
        if ($recordKeys === [] || count($recordKeys) > 100) {
            throw new \InvalidArgumentException('请选择 1 至 100 条记录');
        }
        if (mb_strlen(trim($reason)) > 300) {
            throw new \InvalidArgumentException('操作原因不能超过 300 个字符');
        }

        $now = time();
        return Db::transaction(function () use ($recordKeys, $action, $reason, $tenantId, $adminId, $terminal, $now) {
            $operation = AiTaskOperation::create([
                'operation_no' => 'AO' . date('YmdHis') . random_int(100000, 999999),
                'tenant_id' => $tenantId,
                'admin_id' => $adminId,
                'terminal' => $terminal,
                'action' => $action,
                'reason' => trim($reason),
                'total_count' => count($recordKeys),
                'success_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'status' => 'pending',
                'summary' => [],
                'create_time' => $now,
                'update_time' => $now,
                'finish_time' => 0,
            ]);
            foreach ($recordKeys as $recordKey) {
                $target = self::resolve($recordKey, $tenantId);
                $item = AiTaskOperationItem::create([
                    'operation_id' => (int)$operation['id'],
                    'tenant_id' => (int)($target['tenant_id'] ?? $tenantId),
                    'record_key' => $recordKey,
                    'record_type' => (string)($target['type'] ?? ''),
                    'app_task_id' => (int)($target['app_task_id'] ?? 0),
                    'consumption_id' => (int)($target['consumption_id'] ?? 0),
                    'business_table' => (string)($target['business_table'] ?? ''),
                    'business_id' => (int)($target['business_id'] ?? 0),
                    'upstream_task_id' => (string)($target['upstream_task_id'] ?? ''),
                    'action' => $action,
                    'status' => $target === [] ? 'skipped' : 'pending',
                    'skip_reason' => $target === [] ? '记录不存在或无权操作' : '',
                    'before_status' => (string)($target['run_status'] ?? $target['status'] ?? ''),
                    'after_status' => '',
                    'before_billing_status' => (string)($target['billing_status'] ?? ''),
                    'after_billing_status' => '',
                    'before_snapshot' => self::snapshot($target),
                    'after_snapshot' => [],
                    'result_message' => '',
                    'job_key' => '',
                    'create_time' => $now,
                    'update_time' => $now,
                    'finish_time' => $target === [] ? $now : 0,
                ]);
                if ($target !== []) {
                    $jobKey = 'admin_action:' . (int)$item['id'];
                    $item->save(['job_key' => $jobKey]);
                    AiTaskJobService::enqueueAdminAction((int)$item['id'], $jobKey);
                }
            }
            self::refreshOperation((int)$operation['id']);
            return self::detail((int)$operation['id'], $tenantId);
        });
    }

    public static function runItem(int $itemId): bool
    {
        $item = AiTaskOperationItem::where('id', $itemId)->lock(true)->findOrEmpty();
        if ($item->isEmpty() || in_array((string)$item['status'], ['success', 'failed', 'skipped'], true)) return true;
        $item->save(['status' => 'running', 'update_time' => time()]);
        try {
            $target = self::resolve((string)$item['record_key'], (int)$item['tenant_id']);
            if ($target === []) return self::skip($item, '记录已不存在或不属于当前租户');
            $decision = self::decision($target, (string)$item['action']);
            if (!$decision['allowed']) return self::skip($item, $decision['reason']);
            match ((string)$item['action']) {
                self::ACTION_QUERY, self::ACTION_RETRY_QUERY, self::ACTION_COMPLETE_BY_QUERY => self::queueQuery($target, (string)$item['action']),
                self::ACTION_RECOVER => self::recover($target),
                self::ACTION_RETRY_TRANSFER => self::retryTransfer($target),
                self::ACTION_DELETE => self::deleteTaskDomain($target, $item),
                default => throw new \RuntimeException('当前应用不支持安全的失败/取消操作'),
            };
            $after = self::resolve((string)$item['record_key'], (int)$item['tenant_id']);
            $item->save([
                'status' => 'success', 'after_status' => (string)($after['run_status'] ?? $after['status'] ?? ''),
                'after_billing_status' => (string)($after['billing_status'] ?? ''), 'after_snapshot' => self::snapshot($after),
                'result_message' => self::successMessage((string)$item['action']), 'update_time' => time(), 'finish_time' => time(),
            ]);
        } catch (\Throwable $e) {
            $item->save(['status' => 'failed', 'result_message' => mb_substr($e->getMessage(), 0, 1000), 'update_time' => time(), 'finish_time' => time()]);
        }
        self::refreshOperation((int)$item['operation_id']);
        return true;
    }

    public static function detail(int $operationId, int $tenantId = 0): array
    {
        $query = AiTaskOperation::where('id', $operationId);
        if ($tenantId > 0) $query->where('tenant_id', $tenantId);
        $operation = $query->findOrEmpty();
        if ($operation->isEmpty()) return [];
        $data = $operation->toArray();
        $data['items'] = AiTaskOperationItem::where('operation_id', $operationId)->order('id')->select()->toArray();
        return $data;
    }

    public static function lists(array $params, int $tenantId = 0): array
    {
        $query = AiTaskOperation::alias('o')->leftJoin('tenant t', 't.id=o.tenant_id')
            ->field('o.*,t.name tenant_name,t.sn tenant_sn');
        if ($tenantId > 0) $query->where('o.tenant_id', $tenantId);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        return ['lists' => $query->order('o.id', 'desc')->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray(), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function operationListsForTask(int $appTaskId, int $consumptionId, int $tenantId = 0): array
    {
        $query = AiTaskOperationItem::alias('i')->leftJoin('ai_task_operation o', 'o.id=i.operation_id')
            ->field('i.*,o.operation_no,o.admin_id,o.terminal,o.reason,o.create_time operation_create_time');
        if ($appTaskId > 0) $query->where('i.app_task_id', $appTaskId);
        elseif ($consumptionId > 0) $query->where('i.consumption_id', $consumptionId);
        else return [];
        if ($tenantId > 0) $query->where('i.tenant_id', $tenantId);
        return $query->order('i.id', 'desc')->select()->toArray();
    }

    private static function resolve(string $recordKey, int $tenantId): array
    {
        if (!preg_match('/^(app_task|consumption):([1-9]\d*)$/', $recordKey, $matches)) return [];
        $id = (int)$matches[2];
        $consumption = $matches[1] === 'app_task'
            ? AiConsumptionLog::where('app_task_id', $id)->order('id')->findOrEmpty()
            : AiConsumptionLog::findOrEmpty($id);
        $appTaskId = $matches[1] === 'app_task' ? $id : (int)($consumption['app_task_id'] ?? 0);
        $appTask = $appTaskId > 0 ? AiAppTask::findOrEmpty($appTaskId) : null;
        if (($appTask && !$appTask->isEmpty() && $tenantId > 0 && (int)$appTask['tenant_id'] !== $tenantId)
            || (!$consumption->isEmpty() && $tenantId > 0 && (int)$consumption['tenant_id'] !== $tenantId)) return [];
        if (($appTask === null || $appTask->isEmpty()) && $consumption->isEmpty()) return [];
        $row = $consumption->isEmpty() ? [] : $consumption->toArray();
        $task = $appTask && !$appTask->isEmpty() ? $appTask->toArray() : [];
        return [
            'type' => $matches[1], 'tenant_id' => (int)($row['tenant_id'] ?? $task['tenant_id'] ?? 0),
            'app_task_id' => $appTaskId, 'consumption_id' => (int)($row['id'] ?? 0),
            'business_table' => (string)($task['business_table'] ?? ''), 'business_id' => (int)($task['business_id'] ?? 0),
            'app_code' => (string)($row['app_code'] ?? $task['app_code'] ?? ''), 'status' => (string)($task['status'] ?? ''),
            'run_status' => (string)($row['run_status'] ?? ''), 'billing_status' => (string)($row['billing_status'] ?? ''),
            'upstream_task_id' => (string)($row['upstream_task_id'] ?? $row['upstream_request_id'] ?? ''),
        ];
    }

    private static function decision(array $target, string $action): array
    {
        $run = (string)$target['run_status'];
        $billing = (string)$target['billing_status'];
        $upstream = (string)$target['upstream_task_id'];
        if ($action === self::ACTION_DELETE) {
            return in_array($run, ['success', 'failed', 'canceled', 'cancelled'], true)
                ? ['allowed' => true, 'reason' => ''] : ['allowed' => false, 'reason' => '仅成功、失败或已取消的终态任务可删除'];
        }
        if (in_array($action, [self::ACTION_QUERY, self::ACTION_RETRY_QUERY, self::ACTION_COMPLETE_BY_QUERY], true)) {
            return $upstream !== '' && !in_array($run, ['success', 'failed', 'canceled', 'cancelled'], true)
                ? ['allowed' => true, 'reason' => ''] : ['allowed' => false, 'reason' => '需要存在上游任务号且尚未结束'];
        }
        if ($action === self::ACTION_RECOVER) {
            return $upstream !== '' && $billing === 'refunded' ? ['allowed' => true, 'reason' => ''] : ['allowed' => false, 'reason' => '仅已退款且存在上游任务号的任务可补偿恢复'];
        }
        if ($action === self::ACTION_RETRY_TRANSFER) {
            $count = AiTaskResultAsset::where('consumption_id', (int)$target['consumption_id'])->whereIn('transfer_status', ['retrying', 'failed'])->count();
            return $count > 0 ? ['allowed' => true, 'reason' => ''] : ['allowed' => false, 'reason' => '没有可重试的转存资源'];
        }
        return ['allowed' => false, 'reason' => '当前应用未提供安全的上游取消能力'];
    }

    private static function queueQuery(array $target, string $action): void
    {
        $ids = (int)$target['app_task_id'] > 0
            ? AiConsumptionLog::where('app_task_id', (int)$target['app_task_id'])->column('id')
            : [(int)$target['consumption_id']];
        foreach (array_filter(array_map('intval', $ids)) as $id) {
            if ($action === self::ACTION_RETRY_QUERY) {
                AiTaskJob::where(['consumption_id' => $id, 'job_type' => AiTaskJobService::TYPE_QUERY_RESULT])->whereIn('status', ['retrying', 'dead'])->update(['status' => 'pending', 'next_run_time' => time(), 'lease_token' => '', 'lease_expire_time' => 0, 'update_time' => time()]);
            }
            AiTaskJobService::enqueueQueryResult($id, 100, true);
        }
    }

    private static function recover(array $target): void
    {
        if ((string)$target['app_code'] !== 'aigc_image' || (string)$target['business_table'] !== 'aigc_image_task') {
            self::queueQuery($target, self::ACTION_QUERY);
            return;
        }
        \app\common\service\app\aigc_image\AigcImageService::recoverCompletedTask((int)$target['tenant_id'], (int)$target['business_id']);
    }

    private static function retryTransfer(array $target): void
    {
        $assets = AiTaskResultAsset::where('consumption_id', (int)$target['consumption_id'])->whereIn('transfer_status', ['retrying', 'failed'])->select();
        foreach ($assets as $asset) AiTaskJobService::enqueueTransfer((int)$asset['id'], true);
    }

    private static function deleteTaskDomain(array $target, AiTaskOperationItem $item): void
    {
        $consumptionIds = AiConsumptionLog::where('app_task_id', (int)$target['app_task_id'])->column('id');
        if ($consumptionIds === [] && (int)$target['consumption_id'] > 0) $consumptionIds = [(int)$target['consumption_id']];
        $assets = $consumptionIds === [] ? [] : AiTaskResultAsset::whereIn('consumption_id', $consumptionIds)->select()->toArray();
        $summary = ['app_task_id' => (int)$target['app_task_id'], 'consumption_ids' => array_map('intval', $consumptionIds), 'asset_count' => count($assets), 'files_deleted' => 0, 'files_retained' => 0];
        $filesToRemove = [];
        foreach ($assets as $asset) {
            $uri = trim((string)($asset['local_uri'] ?? ''));
            if ($uri === '') continue;
            $shared = self::hasSharedFileReference($uri, (int)$asset['tenant_id'], (int)$asset['id']);
            if ($shared) { $summary['files_retained']++; continue; }
            self::deleteStoredFile($asset);
            $summary['files_deleted']++;
            $filesToRemove[] = ['tenant_id' => (int)$asset['tenant_id'], 'uri' => $uri];
        }
        $business = self::businessDeletionSpec($target);
        foreach (self::businessResultAssets($target, $business) as $asset) {
            $uri = trim((string)($asset['local_uri'] ?? ''));
            if ($uri === '') continue;
            if (self::hasSharedFileReference($uri, (int)($asset['tenant_id'] ?? 0))) { $summary['files_retained']++; continue; }
            self::deleteStoredFile($asset);
            $summary['files_deleted']++;
            $filesToRemove[] = ['tenant_id' => (int)$asset['tenant_id'], 'uri' => $uri];
        }
        Db::transaction(function () use ($target, $consumptionIds) {
            if ($consumptionIds !== []) {
                AiTaskJob::whereIn('consumption_id', $consumptionIds)->delete();
                AiConsumptionEvent::whereIn('consumption_id', $consumptionIds)->delete();
                AiTaskResultAsset::whereIn('consumption_id', $consumptionIds)->delete();
                AiConsumptionLog::whereIn('id', $consumptionIds)->delete();
            }
            if ((int)$target['app_task_id'] > 0) AiTaskJob::where('app_task_id', (int)$target['app_task_id'])->delete();
            if ((int)$target['app_task_id'] > 0) AiAppTask::where('id', (int)$target['app_task_id'])->delete();
            $business = self::businessDeletionSpec($target);
            if ($business !== []) {
                Db::name($business['result_table'])->where('tenant_id', (int)$target['tenant_id'])->where('task_id', (int)$target['business_id'])->delete();
                Db::name($business['task_table'])->where('tenant_id', (int)$target['tenant_id'])->where('id', (int)$target['business_id'])->delete();
            }
        });
        foreach ($filesToRemove as $file) {
            TenantFile::where('tenant_id', (int)$file['tenant_id'])->where('uri', (string)$file['uri'])->delete();
        }
        AiTaskDeleteReceipt::create([
            'operation_id' => (int)$item['operation_id'], 'operation_item_id' => (int)$item['id'], 'tenant_id' => (int)$target['tenant_id'],
            'admin_id' => (int)(AiTaskOperation::where('id', (int)$item['operation_id'])->value('admin_id') ?: 0), 'record_key' => (string)$item['record_key'], 'target_summary' => ['app_task_id' => (int)$target['app_task_id'], 'consumption_count' => count($consumptionIds), 'app_code' => (string)$target['app_code']],
            'delete_summary' => $summary, 'create_time' => time(),
        ]);
    }

    private static function deleteStoredFile(array $asset): void
    {
        $uri = ltrim((string)($asset['local_uri'] ?? ''), '/');
        $engine = trim((string)($asset['storage_engine'] ?? ''));
        if ($uri === '' || $engine === '') return;
        $config = StorageConfigService::getStoredFileConfig((int)($asset['tenant_id'] ?? 0), (string)($asset['storage_scope'] ?? ''), $engine);
        if (!isset($config['engine'][$engine])) return;
        (new StorageDriver($config, $engine))->delete($uri);
    }

    private static function businessDeletionSpec(array $target): array
    {
        $spec = match ((string)($target['app_code'] ?? '')) {
            'aigc_image' => ['task_table' => 'aigc_image_task', 'result_table' => 'aigc_image_result', 'uris' => ['image_uri']],
            'aigc_video' => ['task_table' => 'aigc_video_task', 'result_table' => 'aigc_video_result', 'uris' => ['video_uri', 'cover_uri']],
            default => [],
        };
        return $spec !== [] && (string)($target['business_table'] ?? '') === $spec['task_table'] && (int)($target['business_id'] ?? 0) > 0 ? $spec : [];
    }

    private static function businessResultAssets(array $target, array $spec): array
    {
        if ($spec === []) return [];
        $fields = array_merge(['id', 'tenant_id', 'storage_scope', 'storage_engine', 'storage_domain'], $spec['uris']);
        try {
            $rows = Db::name($spec['result_table'])->where('tenant_id', (int)$target['tenant_id'])->where('task_id', (int)$target['business_id'])->field(implode(',', $fields))->select()->toArray();
        } catch (\Throwable) { return []; }
        $assets = [];
        foreach ($rows as $row) {
            foreach ($spec['uris'] as $field) {
                if (!empty($row[$field])) $assets[] = ['tenant_id' => (int)$row['tenant_id'], 'local_uri' => (string)$row[$field], 'storage_scope' => (string)$row['storage_scope'], 'storage_engine' => (string)$row['storage_engine'], 'storage_domain' => (string)$row['storage_domain']];
            }
        }
        return $assets;
    }

    private static function hasSharedFileReference(string $uri, int $tenantId, int $excludeAssetId = 0): bool
    {
        $query = AiTaskResultAsset::where('local_uri', $uri);
        if ($excludeAssetId > 0) $query->where('id', '<>', $excludeAssetId);
        if ($query->count() > 0) return true;
        return TenantFile::where('tenant_id', $tenantId)->where('uri', $uri)->count() > 1;
    }

    private static function skip(AiTaskOperationItem $item, string $reason): bool
    {
        $item->save(['status' => 'skipped', 'skip_reason' => $reason, 'result_message' => $reason, 'update_time' => time(), 'finish_time' => time()]);
        self::refreshOperation((int)$item['operation_id']);
        return true;
    }

    private static function refreshOperation(int $operationId): void
    {
        $items = AiTaskOperationItem::where('operation_id', $operationId)->field('status')->select()->toArray();
        $counts = array_count_values(array_column($items, 'status'));
        $pending = (int)($counts['pending'] ?? 0) + (int)($counts['running'] ?? 0);
        AiTaskOperation::where('id', $operationId)->update([
            'success_count' => (int)($counts['success'] ?? 0), 'failed_count' => (int)($counts['failed'] ?? 0), 'skipped_count' => (int)($counts['skipped'] ?? 0),
            'status' => $pending > 0 ? 'running' : 'finished', 'summary' => $counts, 'update_time' => time(), 'finish_time' => $pending > 0 ? 0 : time(),
        ]);
    }

    private static function snapshot(array $target): array
    {
        return array_filter([
            'app_task_id' => (int)($target['app_task_id'] ?? 0), 'consumption_id' => (int)($target['consumption_id'] ?? 0),
            'run_status' => (string)($target['run_status'] ?? ''), 'task_status' => (string)($target['status'] ?? ''),
            'billing_status' => (string)($target['billing_status'] ?? ''), 'upstream_task_id' => (string)($target['upstream_task_id'] ?? ''),
        ], static fn($value) => $value !== '' && $value !== 0);
    }

    private static function successMessage(string $action): string
    {
        return match ($action) {
            self::ACTION_DELETE => '已完成删除与回执记录', self::ACTION_RETRY_TRANSFER => '已唤醒转存重试队列',
            self::ACTION_RECOVER => '已执行补偿恢复查询', default => '已唤醒上游查询队列',
        };
    }
}
