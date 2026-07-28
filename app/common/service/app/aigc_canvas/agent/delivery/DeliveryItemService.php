<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\model\app\aigc_canvas\AigcCanvasDeliveryItem;
use Exception;

final class DeliveryItemService
{
    public const STATUSES = ['draft', 'clarifying', 'ready', 'awaiting_confirmation', 'queued', 'running', 'completed', 'failed', 'canceled'];

    private const TRANSITIONS = [
        'draft' => ['clarifying', 'ready', 'canceled'],
        'clarifying' => ['ready', 'awaiting_confirmation', 'canceled'],
        'ready' => ['clarifying', 'awaiting_confirmation', 'queued', 'canceled'],
        'awaiting_confirmation' => ['clarifying', 'ready', 'queued', 'canceled'],
        'queued' => ['running', 'completed', 'failed', 'canceled'],
        'running' => ['completed', 'failed', 'canceled'],
        'failed' => ['ready', 'queued', 'canceled'],
        'completed' => [],
        'canceled' => ['ready'],
    ];

    public static function find(int $tenantId, int $userId, int $itemId): array
    {
        DeliveryPlanService::ensureSchema();
        if ($itemId <= 0) return [];
        $row = AigcCanvasDeliveryItem::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $itemId,
            'delete_time' => 0,
        ])->findOrEmpty();
        return $row->isEmpty() ? [] : self::format($row->toArray());
    }

    public static function transition(int $tenantId, int $userId, int $itemId, string $status, array $patch = []): array
    {
        DeliveryPlanService::ensureSchema();
        if (!in_array($status, self::STATUSES, true)) throw new Exception('Invalid delivery item status');
        $row = AigcCanvasDeliveryItem::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $itemId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) throw new Exception('Delivery item not found');
        $current = (string)$row['status'];
        if ($current !== $status && !in_array($status, self::TRANSITIONS[$current] ?? [], true)) {
            throw new Exception('Invalid delivery item status transition');
        }
        $allowed = array_intersect_key($patch, array_flip([
            'objective', 'skill_key', 'tool_code', 'depends_on_json', 'required_slots_json', 'soft_slots_json',
            'slots_json', 'delivery_json', 'creative_context_json', 'reference_assets_json', 'pending_action_json', 'skill_snapshot_json',
            'task_snapshot_json', 'cost_json', 'result_json', 'error', 'provider_request_id', 'provider_error_code',
            'provider_error_message', 'meta_json', 'source_message_id', 'parent_item_id', 'retry_count', 'sort_order',
        ]));
        $row->save(array_merge($allowed, ['status' => $status, 'update_time' => time()]));
        return self::format($row->toArray());
    }

    public static function format(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'plan_id' => (int)($row['plan_id'] ?? 0),
            'parent_item_id' => (int)($row['parent_item_id'] ?? 0),
            'thread_id' => (int)($row['thread_id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'source_message_id' => (int)($row['source_message_id'] ?? 0),
            'item_key' => (string)($row['item_key'] ?? ''),
            'objective' => (string)($row['objective'] ?? ''),
            'skill_key' => (string)($row['skill_key'] ?? ''),
            'tool_code' => (string)($row['tool_code'] ?? ''),
            'status' => (string)($row['status'] ?? 'draft'),
            'depends_on' => array_values((array)($row['depends_on_json'] ?? [])),
            'required_slots' => array_values((array)($row['required_slots_json'] ?? [])),
            'soft_slots' => array_values((array)($row['soft_slots_json'] ?? [])),
            'slots' => (array)($row['slots_json'] ?? []),
            'delivery' => (array)($row['delivery_json'] ?? []),
            'creative_context' => (array)($row['creative_context_json'] ?? []),
            'reference_assets' => array_values((array)($row['reference_assets_json'] ?? [])),
            'pending_action' => PendingActionProtocol::normalize((array)($row['pending_action_json'] ?? [])),
            'skill_snapshot' => (array)($row['skill_snapshot_json'] ?? []),
            'task_snapshot' => (array)($row['task_snapshot_json'] ?? []),
            'cost' => (array)($row['cost_json'] ?? []),
            'result' => (array)($row['result_json'] ?? []),
            'error' => (string)($row['error'] ?? ''),
            'provider_request_id' => (string)($row['provider_request_id'] ?? ''),
            'provider_error_code' => (string)($row['provider_error_code'] ?? ''),
            'provider_error_message' => (string)($row['provider_error_message'] ?? ''),
            'retry_count' => (int)($row['retry_count'] ?? 0),
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'meta' => (array)($row['meta_json'] ?? []),
            'created_at' => (int)($row['create_time'] ?? 0),
            'updated_at' => (int)($row['update_time'] ?? 0),
        ];
    }
}
