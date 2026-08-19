<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use Exception;
use think\facade\Db;

/**
 * Creative-graph metadata and scheduling live on delivery plans/items.
 *
 * This is deliberately a facade over the established delivery persistence
 * and executor. It must not become a second queue or source of task state.
 */
final class CreativeDeliveryGraphService
{
    public const SCHEMA_VERSION = 1;

    private const EDITABLE_STATUSES = ['draft', 'clarifying', 'ready', 'awaiting_confirmation'];
    private const ROLES = [
        'creative_director', 'visual_analyst', 'copy_director', 'prompt_director',
        'production_manager', 'quality_reviewer',
    ];

    /** Creates the only durable creative graph: a delivery plan and its items. */
    public static function create(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        array $definition,
        array $context = []
    ): array {
        return DeliveryPlanService::create(
            $tenantId,
            $userId,
            $projectId,
            $threadId,
            $messageId,
            self::compile($definition, $context)
        );
    }

    /**
     * Normalizes a planner result before persistence. JSON metadata is used so
     * established installations need no parallel graph schema or migration.
     */
    public static function compile(array $definition, array $context = [], int $graphVersion = 1): array
    {
        $items = [];
        foreach (array_values(array_filter((array)($definition['items'] ?? []), 'is_array')) as $index => $item) {
            $items[] = self::compileItem($item, $context, $index + 1);
        }
        $meta = (array)($definition['meta'] ?? []);
        $graph = (array)($meta['creative_graph'] ?? []);
        $meta['creative_graph'] = array_merge($graph, [
            'schema_version' => self::SCHEMA_VERSION,
            'graph_version' => max(1, $graphVersion),
            'source' => trim((string)($graph['source'] ?? $meta['source'] ?? 'creative_delivery_planner')),
            'node_count' => count($items),
            'updated_at' => time(),
            'terminal_policy' => 'immutable',
        ]);
        $definition['items'] = $items;
        $definition['meta'] = $meta;
        return $definition;
    }

    /**
     * Returns every dependency-satisfied node that can be claimed now. This
     * deliberately returns a set, not one "next" node, so callers may run
     * independent creative work in parallel.
     *
     * @return array<int, array>
     */
    public static function runnableItems(int $tenantId, int $userId, int $planId, int $limit = 6): array
    {
        $plan = DeliveryPlanService::advanceDependencies($tenantId, $userId, $planId);
        if ($plan === []) return [];
        $result = [];
        foreach ((array)($plan['items'] ?? []) as $item) {
            if ((string)($item['status'] ?? '') !== 'ready' || !empty($item['pending_action'])) continue;
            if (!self::dependenciesCompleted($tenantId, $userId, (array)$item)) continue;
            $result[] = self::describeItem((array)$item);
            if (count($result) >= max(1, min(24, $limit))) break;
        }
        return $result;
    }

    /** Returns graph metadata for a persisted item without changing its state. */
    public static function describeItem(array $item): array
    {
        $meta = (array)($item['meta'] ?? []);
        $graph = (array)($meta['creative_graph'] ?? []);
        if ($graph === []) return $item;
        $item['orchestration'] = [
            'role' => (string)($graph['role'] ?? ''),
            'expected_output' => (array)($graph['expected_output'] ?? []),
            'quality_policy' => (array)($graph['quality_policy'] ?? []),
            'input_snapshot' => (array)($graph['input_snapshot'] ?? []),
            'rework_of_item_id' => max(0, (int)($graph['rework_of_item_id'] ?? 0)),
        ];
        return $item;
    }

    /** Refreshes the immutable-at-creation input record while a node is editable. */
    public static function refreshInputSnapshot(array $item, array $context): array
    {
        $meta = (array)($item['meta'] ?? []);
        $graph = (array)($meta['creative_graph'] ?? []);
        if ($graph === []) return $meta;
        $graph['input_snapshot'] = self::inputSnapshot($item, $context);
        $graph['input_refreshed_at'] = time();
        $meta['creative_graph'] = $graph;
        return $meta;
    }

    /**
     * Applies a replan only to editable nodes. Running and terminal nodes are
     * never rewritten; this prevents a late plan update from changing a task
     * already submitted to a provider or resurrecting a terminal node.
     */
    public static function updateEditableNodes(
        int $tenantId,
        int $userId,
        int $planId,
        array $definition,
        array $context = []
    ): array {
        $current = DeliveryPlanService::detail($tenantId, $userId, $planId);
        if ($current === []) throw new Exception('Delivery plan not found');
        $currentMeta = (array)($current['meta'] ?? []);
        $currentGraph = (array)($currentMeta['creative_graph'] ?? []);
        $compiled = self::compile($definition, $context, (int)($currentGraph['graph_version'] ?? 0) + 1);
        $incoming = [];
        $itemIds = [];
        foreach ((array)($current['items'] ?? []) as $existing) {
            $itemIds[(string)($existing['item_key'] ?? '')] = (int)($existing['id'] ?? 0);
        }
        foreach ((array)$compiled['items'] as $item) {
            $key = (string)($item['item_key'] ?? '');
            if ($key === '') continue;
            if (!array_key_exists($key, $itemIds)) {
                throw new Exception('A graph update cannot append a node; create a new delivery plan instead');
            }
            $item['depends_on'] = array_values(array_filter(array_map(
                static fn($dependency): int => is_numeric($dependency)
                    ? (int)$dependency
                    : (int)($itemIds[(string)$dependency] ?? 0),
                (array)($item['depends_on'] ?? [])
            ), static fn(int $dependency): bool => $dependency > 0));
            $incoming[$key] = $item;
        }

        Db::transaction(function () use ($tenantId, $userId, $planId, $current, $incoming, $compiled): void {
            foreach ((array)($current['items'] ?? []) as $existing) {
                $key = (string)($existing['item_key'] ?? '');
                $replacement = (array)($incoming[$key] ?? []);
                if ($replacement === [] || !in_array((string)($existing['status'] ?? ''), self::EDITABLE_STATUSES, true)) continue;
                DeliveryItemService::transition($tenantId, $userId, (int)$existing['id'], (string)$existing['status'], self::itemPatch($replacement));
            }
            $updated = Db::name('aigc_canvas_delivery_plan')->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $planId,
                'delete_time' => 0,
                'update_time' => (int)($current['updated_at'] ?? 0),
            ])->update([
                'title' => mb_substr(trim((string)($compiled['title'] ?? $current['title'] ?? '')), 0, 160, 'UTF-8'),
                'intent' => mb_substr(trim((string)($compiled['intent'] ?? $current['intent'] ?? '')), 0, 80, 'UTF-8'),
                'meta_json' => json_encode((array)($compiled['meta'] ?? []), JSON_UNESCAPED_UNICODE),
                'update_time' => time(),
            ]);
            if ($updated !== 1) throw new Exception('Delivery plan changed while it was being replanned');
        });
        return DeliveryPlanService::detail($tenantId, $userId, $planId);
    }

    private static function compileItem(array $item, array $context, int $index): array
    {
        $meta = (array)($item['meta'] ?? []);
        $graph = (array)($meta['creative_graph'] ?? []);
        $role = self::role((string)($item['role'] ?? $graph['role'] ?? ''), $item);
        $meta['creative_graph'] = array_merge($graph, [
            'role' => $role,
            'expected_output' => self::expectedOutput($item, $graph),
            'quality_policy' => self::qualityPolicy($item, $graph),
            'input_snapshot' => self::inputSnapshot($item, $context),
            'rework_of_item_id' => max(0, (int)($item['rework_of_item_id'] ?? $graph['rework_of_item_id'] ?? 0)),
            'node_order' => $index,
        ]);
        unset($item['role'], $item['expected_output'], $item['quality_policy'], $item['input_snapshot'], $item['rework_of_item_id']);
        $item['meta'] = $meta;
        return $item;
    }

    private static function role(string $requested, array $item): string
    {
        if (in_array($requested, self::ROLES, true)) return $requested;
        $tool = (string)($item['tool_code'] ?? '');
        if ($tool === 'asset_analyze') return 'visual_analyst';
        if (in_array($tool, ['generate_image', 'generate_video', 'generate_music', 'canvas_mutation', 'selection_action'], true)) return 'production_manager';
        $type = (string)($item['delivery']['type'] ?? '');
        if (str_contains($type, 'prompt')) return 'prompt_director';
        if (str_contains($type, 'copy')) return 'copy_director';
        if (str_contains($type, 'quality')) return 'quality_reviewer';
        return 'creative_director';
    }

    private static function expectedOutput(array $item, array $graph): array
    {
        $provided = (array)($item['expected_output'] ?? $graph['expected_output'] ?? []);
        $tool = (string)($item['tool_code'] ?? '');
        $delivery = (array)($item['delivery'] ?? []);
        return array_merge([
            'kind' => match ($tool) {
                'generate_image', 'generate_video', 'generate_music' => 'media_asset',
                'asset_analyze' => 'visual_analysis',
                default => 'creative_text',
            },
            'count' => max(1, (int)($delivery['quantity'] ?? $delivery['section_count'] ?? 1)),
            'delivery_type' => (string)($delivery['type'] ?? $tool),
        ], $provided);
    }

    private static function qualityPolicy(array $item, array $graph): array
    {
        $provided = (array)($item['quality_policy'] ?? $graph['quality_policy'] ?? []);
        $tool = (string)($item['tool_code'] ?? '');
        $checks = in_array($tool, ['generate_image', 'generate_video', 'generate_music'], true)
            ? ['provider_result', 'asset_count', 'creative_constraints']
            : ['output_present', 'creative_constraints'];
        return array_merge([
            'mode' => 'structural_then_creative',
            'checks' => $checks,
            'failure_action' => 'create_local_rework',
        ], $provided);
    }

    private static function inputSnapshot(array $item, array $context): array
    {
        $slots = (array)($item['slots'] ?? []);
        $request = trim((string)($slots['user_request'] ?? $context['content'] ?? $context['user_request'] ?? ''));
        return [
            'user_request' => mb_substr($request, 0, 8000, 'UTF-8'),
            'delivery' => (array)($item['delivery'] ?? []),
            'creative_context' => (array)($item['creative_context'] ?? []),
            'reference_assets' => array_values((array)($item['reference_assets'] ?? [])),
        ];
    }

    private static function itemPatch(array $item): array
    {
        return [
            'objective' => mb_substr(trim((string)($item['objective'] ?? '')), 0, 2000, 'UTF-8'),
            'skill_key' => mb_substr((string)($item['skill_key'] ?? ''), 0, 120, 'UTF-8'),
            'tool_code' => mb_substr((string)($item['tool_code'] ?? ''), 0, 80, 'UTF-8'),
            'depends_on_json' => array_values((array)($item['depends_on'] ?? [])),
            'required_slots_json' => array_values((array)($item['required_slots'] ?? [])),
            'soft_slots_json' => array_values((array)($item['soft_slots'] ?? [])),
            'slots_json' => (array)($item['slots'] ?? []),
            'delivery_json' => (array)($item['delivery'] ?? []),
            'creative_context_json' => (array)($item['creative_context'] ?? []),
            'reference_assets_json' => array_values((array)($item['reference_assets'] ?? [])),
            'pending_action_json' => PendingActionProtocol::normalize((array)($item['pending_action'] ?? [])),
            'meta_json' => (array)($item['meta'] ?? []),
        ];
    }

    private static function dependenciesCompleted(int $tenantId, int $userId, array $item): bool
    {
        foreach ((array)($item['depends_on'] ?? []) as $dependencyId) {
            $dependency = DeliveryItemService::find($tenantId, $userId, (int)$dependencyId);
            if ($dependency === [] || (string)($dependency['status'] ?? '') !== 'completed') return false;
        }
        return true;
    }
}
