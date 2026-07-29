<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

/**
 * Persists every execution-relevant turn input on its delivery item. This is
 * deliberately the only bridge from transient chat/canvas state to execution.
 */
final class DeliveryItemContextBinder
{
    public static function bind(int $tenantId, int $userId, int $itemId, array $context = [], array $decision = [], array $changes = []): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) return [];

        $assets = self::assets($changes['reference_assets'] ?? $context['uploaded_references'] ?? $item['reference_assets']);
        $slots = array_merge((array)$item['slots'], (array)($decision['inferred_slots'] ?? []), (array)($changes['slots'] ?? []));
        if ($assets === [] && is_string($slots['product_reference'] ?? null) && trim((string)$slots['product_reference']) !== '') {
            $assets = self::assets([(string)$slots['product_reference']]);
        }
        $creative = array_merge(
            (array)$item['creative_context'],
            (array)($context['creative_context'] ?? []),
            (array)($context['enriched_context'] ?? []),
            (array)($changes['creative_context'] ?? [])
        );
        $selected = array_values(array_filter((array)($context['selected_ids'] ?? []), static fn($id): bool => (string)$id !== ''));
        if ($selected !== []) $creative['selected_canvas_node_ids'] = array_slice(array_map('strval', $selected), 0, 48);
        $snapshot = (array)($decision['skill_contract'] ?? $item['skill_snapshot']);
        $meta = (array)($item['meta'] ?? []);
        if (array_key_exists('execution_options', $changes)) {
            $meta['execution_options'] = self::executionOptions(
                (array)$changes['execution_options'],
                (string)($item['tool_code'] ?? '')
            );
        }

        return DeliveryItemService::transition($tenantId, $userId, $itemId, (string)$item['status'], [
            'reference_assets_json' => $assets,
            'slots_json' => $slots,
            'creative_context_json' => $creative,
            'skill_snapshot_json' => $snapshot,
            'delivery_json' => array_merge((array)$item['delivery'], (array)($changes['delivery'] ?? [])),
            'meta_json' => $meta,
        ]);
    }

    /** Execution reads only this persisted subset, never the request payload. */
    public static function executionContext(array $item): array
    {
        return [
            'uploaded_references' => self::assets((array)($item['reference_assets'] ?? [])),
            'creative_context' => (array)($item['creative_context'] ?? []),
            'selected_ids' => (array)(($item['creative_context']['selected_canvas_node_ids'] ?? [])),
        ];
    }

    private static function assets(array $assets): array
    {
        $result = [];
        foreach ($assets as $asset) {
            if (is_string($asset) && trim($asset) !== '') $asset = ['url' => trim($asset)];
            if (!is_array($asset)) continue;
            $url = trim((string)($asset['url'] ?? $asset['uri'] ?? ''));
            if ($url === '') continue;
            $asset['url'] = $url;
            unset($asset['provider'], $asset['debug'], $asset['raw']);
            $result[] = $asset;
        }
        return array_values($result);
    }

    /** Keep only server-validated media routing candidates on the delivery item. */
    private static function executionOptions(array $options, string $toolCode): array
    {
        if (!in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
            return [];
        }
        return array_intersect_key($options, array_flip([
            'channel', 'model_id', 'market_product_id', 'market_sku_id', 'sku_id',
            'duration', 'quality', 'style', 'seed', 'negative_prompt',
        ]));
    }
}
