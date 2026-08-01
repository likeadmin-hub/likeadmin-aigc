<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use Exception;

/**
 * Persists the evidence and structured context already gathered for one
 * delivery item. It deliberately does not create tasks or submit providers.
 */
final class DeliveryItemContextBinder
{
    public static function bindBase(int $tenantId, int $userId, array $item, array $requestContext, array $taskDecision = []): array
    {
        if ($item === []) return [];
        $references = self::mergeReferences(
            (array)($item['reference_assets'] ?? []),
            self::referencesFromContext($requestContext),
            !empty($requestContext['replace_reference_assets'])
        );
        $slots = array_merge((array)($item['slots'] ?? []), array_filter([
            'user_request' => trim((string)($requestContext['user_request'] ?? $requestContext['content'] ?? '')),
        ], static fn($value): bool => $value !== ''));
        $creative = self::mergeContext((array)($item['creative_context'] ?? []), [
            'reference_provenance' => self::provenance($references),
            'task_decision' => self::decisionSnapshot($taskDecision),
        ]);
        $skill = array_merge((array)($item['skill_snapshot'] ?? []), array_filter([
            'skill_key' => (string)($taskDecision['selected_skill_key'] ?? $item['skill_key'] ?? ''),
            'turn_relation' => (string)($taskDecision['turn_relation'] ?? ''),
            'confidence' => (float)($taskDecision['confidence'] ?? 0),
        ], static fn($value): bool => $value !== '' && $value !== 0.0));
        $snapshotItem = array_merge($item, [
            'slots' => $slots,
            'reference_assets' => $references,
            'creative_context' => $creative,
        ]);

        return DeliveryItemService::transition($tenantId, $userId, (int)$item['id'], (string)$item['status'], [
            'slots_json' => $slots,
            'reference_assets_json' => $references,
            'creative_context_json' => $creative,
            'skill_snapshot_json' => $skill,
            'delivery_json' => array_merge((array)($item['delivery'] ?? []), [
                'context_bound' => true,
                'reference_policy' => self::requiresReferences($item) ? 'required' : 'optional',
            ]),
            'meta_json' => CreativeDeliveryGraphService::refreshInputSnapshot($snapshotItem, $requestContext),
        ]);
    }

    public static function mergeEnrichment(int $tenantId, int $userId, array $item, array $enrichment): array
    {
        if ($item === []) return [];
        $context = (array)($enrichment['context'] ?? []);
        $creative = self::mergeContext((array)($item['creative_context'] ?? []), (array)($context['creative_context'] ?? []));
        $creative['enrichment'] = [
            'status' => (string)($enrichment['status'] ?? ''),
            'reason' => (string)($enrichment['reason'] ?? ''),
            'asset_insights' => (array)($enrichment['asset_insights'] ?? []),
            'fact_source_distribution' => (array)($enrichment['fact_source_distribution'] ?? []),
        ];
        $slots = array_merge((array)($item['slots'] ?? []), (array)($context['inferred_slot_values'] ?? []));
        return DeliveryItemService::transition($tenantId, $userId, (int)$item['id'], (string)$item['status'], [
            'slots_json' => $slots,
            'creative_context_json' => $creative,
        ]);
    }

    public static function ensureExecutableContext(int $tenantId, int $userId, array $item): array
    {
        if ($item === []) throw new Exception('Delivery item not found');
        if (self::requiresReferences($item) && (array)($item['reference_assets'] ?? []) === []) {
            throw new Exception('Delivery item requires a product or visual reference');
        }
        $delivery = (array)($item['delivery'] ?? []);
        if (empty($delivery['context_bound'])) {
            throw new Exception('Delivery item context has not been bound');
        }
        return $item;
    }

    private static function requiresReferences(array $item): bool
    {
        return in_array('product_reference', (array)($item['required_slots'] ?? []), true)
            || str_starts_with((string)($item['skill_key'] ?? ''), 'ecommerce_');
    }

    private static function referencesFromContext(array $context): array
    {
        $sources = [
            (array)($context['uploaded_references'] ?? $context['attachments'] ?? []),
            (array)($context['referenced_results'] ?? $context['project_references'] ?? []),
            (array)($context['imported_assets'] ?? []),
            (array)($context['selected_elements'] ?? $context['selection']['elements'] ?? []),
        ];
        return array_merge(...$sources);
    }

    private static function mergeReferences(array $existing, array $incoming, bool $replace): array
    {
        $result = [];
        $seen = [];
        foreach (array_merge($replace ? [] : $existing, $incoming) as $asset) {
            $normalized = self::normalizeReference($asset);
            if ($normalized === []) continue;
            $key = (string)($normalized['id'] ?? '') . '|' . (string)($normalized['url'] ?? $normalized['uri'] ?? '');
            if ($key === '|' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $normalized;
        }
        return array_values($result);
    }

    private static function normalizeReference(mixed $asset): array
    {
        if (is_string($asset)) {
            $url = trim($asset);
            return $url === '' ? [] : ['url' => $url, 'source' => 'reference'];
        }
        if (!is_array($asset)) return [];
        $url = trim((string)($asset['url'] ?? $asset['uri'] ?? $asset['image_url'] ?? $asset['cover_uri'] ?? ''));
        $id = (int)($asset['id'] ?? $asset['asset_id'] ?? 0);
        if ($url === '' && $id <= 0) return [];
        return array_filter([
            'id' => $id ?: null,
            'url' => $url,
            'uri' => trim((string)($asset['uri'] ?? '')),
            'type' => (string)($asset['type'] ?? $asset['asset_type'] ?? 'reference_image'),
            'source' => (string)($asset['source'] ?? $asset['source_type'] ?? 'reference'),
            'task_id' => (string)($asset['task_id'] ?? ''),
        ], static fn($value): bool => $value !== '' && $value !== null);
    }

    private static function provenance(array $references): array
    {
        return array_values(array_map(static fn(array $asset): array => [
            'asset_id' => (int)($asset['id'] ?? 0),
            'source' => (string)($asset['source'] ?? 'reference'),
            'task_id' => (string)($asset['task_id'] ?? ''),
        ], $references));
    }

    private static function decisionSnapshot(array $decision): array
    {
        return array_intersect_key($decision, array_flip([
            'turn_relation', 'target_delivery_item_id', 'confidence', 'intent', 'selected_skill_key', 'execution_mode',
        ]));
    }

    private static function mergeContext(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::mergeContext($base[$key], $value);
            } elseif ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
