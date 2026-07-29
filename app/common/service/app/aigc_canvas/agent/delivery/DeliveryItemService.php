<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\model\app\aigc_canvas\AigcCanvasDeliveryItem;
use Exception;
use think\facade\Db;

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
        'completed' => ['ready'],
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

    /** Server-only media routing values. Never include these in item responses. */
    public static function executionOptions(int $tenantId, int $userId, int $itemId): array
    {
        DeliveryPlanService::ensureSchema();
        $row = AigcCanvasDeliveryItem::where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $itemId, 'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) return [];
        return (array)(((array)$row['meta_json'])['execution_options'] ?? []);
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
        if (array_intersect(array_keys($allowed), ['slots_json', 'delivery_json', 'creative_context_json', 'reference_assets_json', 'skill_snapshot_json']) !== []) {
            $allowed = array_merge($allowed, [
                'task_snapshot_json' => [], 'result_json' => [], 'provider_request_id' => '',
                'provider_error_code' => '', 'provider_error_message' => '', 'error' => '',
            ]);
        }
        $row->save(array_merge($allowed, ['status' => $status, 'update_time' => time()]));
        return self::format($row->toArray());
    }

    /** Atomically reserve a ready item before any billable provider submission. */
    public static function claimExecution(int $tenantId, int $userId, int $itemId): array
    {
        DeliveryPlanService::ensureSchema();
        $affected = Db::name('aigc_canvas_delivery_item')->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $itemId, 'delete_time' => 0,
        ])->whereIn('status', ['ready', 'failed'])->update(['status' => 'queued', 'update_time' => time()]);
        return ['claimed' => $affected === 1, 'item' => self::find($tenantId, $userId, $itemId)];
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
            'meta' => self::publicMeta((array)($row['meta_json'] ?? [])),
            'created_at' => (int)($row['create_time'] ?? 0),
            'updated_at' => (int)($row['update_time'] ?? 0),
        ];
    }

    /** Removes execution, provider and Skill internals before an item reaches a user response. */
    public static function present(array $item): array
    {
        $result = (array)($item['result'] ?? []);
        $assets = array_values(array_filter((array)($result['assets'] ?? []), 'is_array'));
        if ($assets === [] && !empty($result['url'])) $assets[] = ['url' => (string)$result['url']];
        $assets = array_values(array_filter(array_map([self::class, 'publicAsset'], $assets)));
        return array_filter([
            'id' => (int)($item['id'] ?? 0),
            'plan_id' => (int)($item['plan_id'] ?? 0),
            'parent_item_id' => (int)($item['parent_item_id'] ?? 0),
            'thread_id' => (int)($item['thread_id'] ?? 0),
            'project_id' => (int)($item['project_id'] ?? 0),
            'item_key' => (string)($item['item_key'] ?? ''),
            'objective' => (string)($item['objective'] ?? ''),
            'status' => (string)($item['status'] ?? 'draft'),
            'required_slots' => array_values((array)($item['required_slots'] ?? [])),
            'soft_slots' => array_values((array)($item['soft_slots'] ?? [])),
            'slots' => self::publicSlots((array)($item['slots'] ?? [])),
            'delivery' => self::publicDelivery((array)($item['delivery'] ?? [])),
            'reference_assets' => array_values(array_filter(array_map([self::class, 'publicAsset'], array_values(array_filter((array)($item['reference_assets'] ?? []), 'is_array'))))),
            'pending_action' => PendingActionProtocol::normalize((array)($item['pending_action'] ?? [])),
            'result' => $assets === [] ? [] : ['assets' => $assets],
            'retry_count' => (int)($item['retry_count'] ?? 0),
            'sort_order' => (int)($item['sort_order'] ?? 0),
            'created_at' => (int)($item['created_at'] ?? 0),
            'updated_at' => (int)($item['updated_at'] ?? 0),
        ], static fn($value): bool => $value !== null);
    }

    private static function publicMeta(array $meta): array
    {
        unset($meta['execution_options']);
        return $meta;
    }

    private static function publicSlots(array $slots): array
    {
        $safe = [];
        foreach ($slots as $key => $value) {
            $name = (string)$key;
            if (preg_match('/prompt|provider|skill|channel|model|sku|token|secret|trace|execution|request_id|api[_-]?key/i', $name)) continue;
            if (is_scalar($value) || $value === null) $safe[$name] = $value;
            if (is_array($value) && count($value) <= 24) $safe[$name] = self::publicSlotArray($value);
        }
        return $safe;
    }

    private static function publicSlotArray(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            if (preg_match('/prompt|provider|skill|channel|model|sku|token|secret|trace|execution|request_id|api[_-]?key/i', (string)$key)) continue;
            if (is_scalar($item) || $item === null) $safe[$key] = $item;
            if (is_array($item)) $safe[$key] = self::publicSlotArray($item);
        }
        return $safe;
    }

    private static function publicDelivery(array $delivery): array
    {
        $keys = ['type', 'purpose', 'ratio', 'quantity', 'section_count', 'width', 'height', 'duration', 'style', 'format', 'title'];
        $safe = array_intersect_key($delivery, array_flip($keys));
        if (is_array($delivery['sections'] ?? null)) {
            $safe['sections'] = array_values(array_map(static fn(array $section): array => array_filter([
                'section_key' => (string)($section['section_key'] ?? ''),
                'title' => (string)($section['title'] ?? ''),
                'description' => (string)($section['description'] ?? ''),
            ], static fn($value): bool => $value !== ''), array_values(array_filter($delivery['sections'], 'is_array'))));
        }
        return $safe;
    }

    private static function publicAsset(array $asset): array
    {
        $keys = ['url', 'image_url', 'video_url', 'audio_url', 'poster', 'cover_url', 'cover', 'thumbnail', 'type', 'kind', 'name', 'ratio', 'width', 'height', 'duration', 'mime_type'];
        return array_filter(array_intersect_key($asset, array_flip($keys)), static fn($value): bool => $value !== null && $value !== '');
    }
}
