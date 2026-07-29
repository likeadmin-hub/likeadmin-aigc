<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService;
use think\facade\Db;

/** Indexed task-to-item association used by polling and provider callbacks. */
final class DeliveryItemTaskSyncService
{
    private static bool $schemaChecked = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        Db::execute("CREATE TABLE IF NOT EXISTS `la_aigc_canvas_delivery_task_binding` (
            `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
            `user_id` int unsigned NOT NULL DEFAULT 0, `delivery_item_id` int unsigned NOT NULL DEFAULT 0,
            `generation_task_id` varchar(160) NOT NULL DEFAULT '', `canvas_run_id` varchar(160) NOT NULL DEFAULT '',
            `provider_task_id` varchar(160) NOT NULL DEFAULT '', `idempotency_key` varchar(160) NOT NULL DEFAULT '',
            `status` varchar(40) NOT NULL DEFAULT 'queued', `create_time` int unsigned NOT NULL DEFAULT 0,
            `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), UNIQUE KEY `uk_delivery_task_attempt` (`delivery_item_id`,`idempotency_key`,`delete_time`),
            KEY `idx_delivery_task_generation` (`tenant_id`,`user_id`,`generation_task_id`,`delete_time`),
            KEY `idx_delivery_task_provider` (`tenant_id`,`provider_task_id`,`delete_time`),
            KEY `idx_delivery_task_status` (`tenant_id`,`status`,`update_time`,`delete_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas delivery task bindings'");
    }

    public static function bind(int $tenantId, int $userId, int $itemId, array $task): void
    {
        self::ensureSchema();
        $key = mb_substr((string)($task['idempotency_key'] ?? ''), 0, 160, 'UTF-8');
        if ($itemId <= 0 || $key === '') return;
        $now = time();
        $data = [
            'generation_task_id' => mb_substr((string)($task['task_id'] ?? ''), 0, 160, 'UTF-8'),
            'canvas_run_id' => mb_substr((string)($task['canvas_run_id'] ?? ''), 0, 160, 'UTF-8'),
            'provider_task_id' => mb_substr((string)($task['provider_task_id'] ?? ''), 0, 160, 'UTF-8'),
            'status' => (string)($task['status'] ?? 'queued'), 'update_time' => $now,
        ];
        $row = Db::name('aigc_canvas_delivery_task_binding')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'delivery_item_id' => $itemId,
            'idempotency_key' => $key, 'delete_time' => 0,
        ])->find();
        if ($row) {
            Db::name('aigc_canvas_delivery_task_binding')->where('id', (int)$row['id'])->update($data);
            return;
        }
        Db::name('aigc_canvas_delivery_task_binding')->insert(array_merge($data, [
            'tenant_id' => $tenantId, 'user_id' => $userId, 'delivery_item_id' => $itemId,
            'idempotency_key' => $key, 'create_time' => $now, 'delete_time' => 0,
        ]));
    }

    public static function latest(int $tenantId, int $userId, int $itemId): array
    {
        self::ensureSchema();
        return (array)(Db::name('aigc_canvas_delivery_task_binding')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'delivery_item_id' => $itemId, 'delete_time' => 0,
        ])->order('id', 'desc')->find() ?: []);
    }

    public static function refresh(int $tenantId, int $userId, int $itemId): array
    {
        $binding = self::latest($tenantId, $userId, $itemId);
        if ($binding === [] || (string)$binding['generation_task_id'] === '') return [];
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) return [];
        $detail = CanvasGenerationTaskCenterService::query($tenantId, $userId, [
            'task_id' => (string)$binding['generation_task_id'],
            'type' => str_replace('generate_', '', (string)$item['tool_code']),
        ]);
        $providerStatus = strtolower((string)($detail['status'] ?? 'running'));
        $status = in_array($providerStatus, ['success', 'completed'], true) ? 'completed'
            : (in_array($providerStatus, ['failed', 'error'], true) ? 'failed'
                : (in_array($providerStatus, ['canceled', 'cancelled'], true) ? 'canceled' : 'running'));
        self::bind($tenantId, $userId, $itemId, [
            'idempotency_key' => (string)$binding['idempotency_key'], 'task_id' => (string)$binding['generation_task_id'],
            'provider_task_id' => (string)($detail['power_task_id'] ?? $binding['provider_task_id']), 'status' => $status,
        ]);
        $patch = [
            'result_json' => ['assets' => (array)($detail['result_assets'] ?? []), 'generation' => $detail],
            'provider_request_id' => (string)($detail['request_id'] ?? ''),
            'provider_error_code' => (string)($detail['error_code'] ?? ''),
            'provider_error_message' => (string)($detail['error_message'] ?? ''), 'error' => (string)($detail['error_message'] ?? ''),
        ];
        if ($status === 'failed') $patch['pending_action_json'] = PendingActionProtocol::confirmation('retry_item');
        return DeliveryItemService::transition($tenantId, $userId, $itemId, $status, $patch);
    }

    /** Polls active owned items when the conversation refreshes. */
    public static function syncActiveForOwner(int $tenantId, int $userId, int $limit = 20): void
    {
        self::ensureSchema();
        $rows = Db::name('aigc_canvas_delivery_task_binding')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->whereIn('status', ['queued', 'running'])->order('update_time', 'asc')->limit(max(1, min(50, $limit)))->select()->toArray();
        foreach ($rows as $row) {
            try {
                self::refresh($tenantId, $userId, (int)($row['delivery_item_id'] ?? 0));
            } catch (\Throwable) {
                // Provider failures are normalized by the explicit item refresh path.
            }
        }
    }
}
