<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\model\app\aigc_canvas\AigcCanvasDeliveryItem;
use app\common\model\app\aigc_canvas\AigcCanvasDeliveryPlan;
use think\facade\Db;

final class DeliveryPlanService
{
    private static bool $schemaChecked = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        Db::execute("CREATE TABLE IF NOT EXISTS `la_aigc_canvas_delivery_plan` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int unsigned NOT NULL DEFAULT 0,
            `user_id` int unsigned NOT NULL DEFAULT 0,
            `project_id` int unsigned NOT NULL DEFAULT 0,
            `thread_id` int unsigned NOT NULL DEFAULT 0,
            `source_message_id` int unsigned NOT NULL DEFAULT 0,
            `title` varchar(160) NOT NULL DEFAULT '',
            `intent` varchar(80) NOT NULL DEFAULT '',
            `status` varchar(40) NOT NULL DEFAULT 'draft',
            `item_count` int unsigned NOT NULL DEFAULT 0,
            `meta_json` longtext,
            `create_time` int unsigned NOT NULL DEFAULT 0,
            `update_time` int unsigned NOT NULL DEFAULT 0,
            `delete_time` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_delivery_plan_thread` (`tenant_id`,`user_id`,`thread_id`,`status`,`delete_time`),
            KEY `idx_delivery_plan_project` (`tenant_id`,`project_id`,`delete_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas delivery plans'");
        Db::execute("CREATE TABLE IF NOT EXISTS `la_aigc_canvas_delivery_item` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int unsigned NOT NULL DEFAULT 0,
            `user_id` int unsigned NOT NULL DEFAULT 0,
            `project_id` int unsigned NOT NULL DEFAULT 0,
            `thread_id` int unsigned NOT NULL DEFAULT 0,
            `plan_id` int unsigned NOT NULL DEFAULT 0,
            `parent_item_id` int unsigned NOT NULL DEFAULT 0,
            `source_message_id` int unsigned NOT NULL DEFAULT 0,
            `item_key` varchar(96) NOT NULL DEFAULT '',
            `objective` text,
            `skill_key` varchar(120) NOT NULL DEFAULT '',
            `tool_code` varchar(80) NOT NULL DEFAULT '',
            `status` varchar(40) NOT NULL DEFAULT 'draft',
            `sort_order` int unsigned NOT NULL DEFAULT 0,
            `retry_count` int unsigned NOT NULL DEFAULT 0,
            `depends_on_json` longtext,
            `required_slots_json` longtext,
            `soft_slots_json` longtext,
            `slots_json` longtext,
            `delivery_json` longtext,
            `creative_context_json` longtext,
            `reference_assets_json` longtext,
            `pending_action_json` longtext,
            `skill_snapshot_json` longtext,
            `task_snapshot_json` longtext,
            `cost_json` longtext,
            `result_json` longtext,
            `provider_request_id` varchar(160) NOT NULL DEFAULT '',
            `provider_error_code` varchar(120) NOT NULL DEFAULT '',
            `provider_error_message` text,
            `error` text,
            `meta_json` longtext,
            `create_time` int unsigned NOT NULL DEFAULT 0,
            `update_time` int unsigned NOT NULL DEFAULT 0,
            `delete_time` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_delivery_item_key` (`plan_id`,`item_key`,`delete_time`),
            KEY `idx_delivery_item_thread` (`tenant_id`,`user_id`,`thread_id`,`status`,`delete_time`),
            KEY `idx_delivery_item_plan` (`plan_id`,`sort_order`,`delete_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas delivery items'");
        // Existing deployments may have received the initial table before the
        // continuation protocol was added. Keep the runtime repair idempotent.
        $columns = Db::query("SHOW COLUMNS FROM `la_aigc_canvas_delivery_item` LIKE 'pending_action_json'");
        if ($columns === []) {
            Db::execute("ALTER TABLE `la_aigc_canvas_delivery_item` ADD COLUMN `pending_action_json` longtext AFTER `reference_assets_json`");
        }
    }

    public static function create(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, array $definition): array
    {
        self::ensureSchema();
        return Db::transaction(function () use ($tenantId, $userId, $projectId, $threadId, $messageId, $definition): array {
            $items = array_values(array_filter((array)($definition['items'] ?? []), 'is_array'));
            $now = time();
            $plan = AigcCanvasDeliveryPlan::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'source_message_id' => $messageId,
                'title' => mb_substr(trim((string)($definition['title'] ?? '创作交付计划')), 0, 160, 'UTF-8'),
                'intent' => mb_substr(trim((string)($definition['intent'] ?? 'generation')), 0, 80, 'UTF-8'),
                'status' => self::planStatus($items),
                'item_count' => count($items),
                'meta_json' => (array)($definition['meta'] ?? []),
                'create_time' => $now,
                'update_time' => $now,
                'delete_time' => 0,
            ]);
            $itemIds = [];
            foreach ($items as $index => $item) {
                $created = AigcCanvasDeliveryItem::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'thread_id' => $threadId,
                    'plan_id' => (int)$plan['id'],
                    'parent_item_id' => (int)($item['parent_item_id'] ?? 0),
                    'source_message_id' => $messageId,
                    'item_key' => mb_substr((string)($item['item_key'] ?? 'item_' . ($index + 1)), 0, 96, 'UTF-8'),
                    'objective' => mb_substr(trim((string)($item['objective'] ?? '')), 0, 2000, 'UTF-8'),
                    'skill_key' => mb_substr((string)($item['skill_key'] ?? ''), 0, 120, 'UTF-8'),
                    'tool_code' => mb_substr((string)($item['tool_code'] ?? ''), 0, 80, 'UTF-8'),
                    'status' => self::itemStatus($item),
                    'sort_order' => $index + 1,
                    'depends_on_json' => array_values((array)($item['depends_on'] ?? [])),
                    'required_slots_json' => array_values((array)($item['required_slots'] ?? [])),
                    'soft_slots_json' => array_values((array)($item['soft_slots'] ?? [])),
                    'slots_json' => (array)($item['slots'] ?? []),
                    'delivery_json' => (array)($item['delivery'] ?? []),
                    'creative_context_json' => (array)($item['creative_context'] ?? []),
                    'reference_assets_json' => array_values((array)($item['reference_assets'] ?? [])),
                    'pending_action_json' => PendingActionProtocol::normalize((array)($item['pending_action'] ?? [])),
                    'skill_snapshot_json' => (array)($item['skill_snapshot'] ?? []),
                    'task_snapshot_json' => [],
                    'cost_json' => [],
                    'result_json' => [],
                    'provider_request_id' => '',
                    'provider_error_code' => '',
                    'provider_error_message' => '',
                    'error' => '',
                    'meta_json' => (array)($item['meta'] ?? []),
                    'create_time' => $now,
                    'update_time' => $now,
                    'delete_time' => 0,
                ]);
                $itemIds[(string)($item['item_key'] ?? '')] = (int)$created['id'];
            }
            foreach ($items as $item) {
                $key = (string)($item['item_key'] ?? '');
                $itemId = (int)($itemIds[$key] ?? 0);
                if ($itemId <= 0) continue;
                $dependencies = [];
                foreach ((array)($item['depends_on'] ?? []) as $dependency) {
                    $dependencyId = is_numeric($dependency) ? (int)$dependency : (int)($itemIds[(string)$dependency] ?? 0);
                    if ($dependencyId > 0) $dependencies[] = $dependencyId;
                }
                if ($dependencies !== []) {
                    AigcCanvasDeliveryItem::where('id', $itemId)->update(['depends_on_json' => array_values(array_unique($dependencies))]);
                }
            }
            return self::detail($tenantId, $userId, (int)$plan['id']);
        });
    }

    /**
     * Compiles a reusable, linear professional workflow without a separate
     * workflow table. The template only changes entry criteria and wording;
     * execution remains owned by ordinary delivery items.
     */
    public static function compileWorkflow(string $template, string $request, array $context = []): array
    {
        if ($template !== 'brand_planning') return [];
        $brandName = trim((string)($context['brand_name'] ?? $context['project']['brand_name'] ?? ''));
        if ($brandName === '' && preg_match('/(?:品牌|品牌名|品牌名称)\s*(?:叫|为|是)?\s*([\p{Han}A-Za-z0-9_-]{2,40})/u', $request, $match) === 1) {
            $brandName = (string)$match[1];
        }
        $baseSlots = ['user_request' => $request];
        if ($brandName !== '') $baseSlots['brand_name'] = $brandName;
        $stages = [
            ['brief', '项目简报', 'generate_text', [], $brandName === '' ? ['brand_name'] : [], []],
            ['research', '策略研究', 'generate_text', ['brief'], [], []],
            ['strategy', '品牌策略', 'generate_text', ['research'], [], []],
            ['copy', '核心文案', 'generate_text', ['strategy'], [], []],
            ['visual_direction', '视觉方向', 'generate_text', ['copy'], [], []],
            ['visual_assets', '视觉资产', 'generate_image', ['visual_direction'], [], ['approve_visual_generation']],
            ['applications', '应用延展', 'generate_text', ['visual_assets'], [], []],
        ];
        $items = [];
        foreach ($stages as [$key, $objective, $tool, $dependsOn, $required, $actions]) {
            $missing = $key === 'brief' ? $required : [];
            $pending = $missing !== []
                ? PendingActionProtocol::forMissingSlots($missing)
                : ($actions === ['approve_visual_generation'] ? PendingActionProtocol::confirmation('approve_visual_generation') : []);
            $items[] = [
                'item_key' => $key,
                'objective' => $objective,
                'skill_key' => 'script_planning',
                'tool_code' => $tool,
                'status' => $missing !== [] ? 'clarifying' : ($key === 'brief' ? 'ready' : ($pending !== [] ? 'awaiting_confirmation' : 'draft')),
                'depends_on' => $dependsOn,
                'required_slots' => $required,
                'soft_slots' => [],
                'slots' => $baseSlots,
                'delivery' => ['type' => $key, 'workflow_template' => $template],
                'reference_assets' => [],
                'pending_action' => $pending,
                'meta' => ['workflow_template' => $template, 'workflow_stage' => $key],
            ];
        }
        return [
            'title' => '品牌策划工作流',
            'intent' => 'creative_plan',
            'items' => $items,
            'meta' => ['workflow_template' => $template, 'workflow_stages' => array_column($stages, 0), 'source' => 'workflow_compiler'],
        ];
    }

    public static function detail(int $tenantId, int $userId, int $planId): array
    {
        self::ensureSchema();
        $plan = AigcCanvasDeliveryPlan::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $planId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($plan->isEmpty()) return [];
        $items = AigcCanvasDeliveryItem::where(['plan_id' => $planId, 'delete_time' => 0])->order('sort_order', 'asc')->select()->toArray();
        return self::format($plan->toArray(), $items);
    }

    /** Thin query helpers used by turn classification before any Skill routing. */
    public static function item(int $tenantId, int $userId, int $itemId): array
    {
        return DeliveryItemService::find($tenantId, $userId, $itemId);
    }

    /**
     * Returns a stage only when it is the unambiguous next executable item.
     * This avoids silently selecting between parallel plans in one thread.
     */
    public static function nextExecutableItem(int $tenantId, int $userId, int $threadId): array
    {
        self::ensureSchema();
        $rows = AigcCanvasDeliveryItem::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->whereIn('status', ['draft', 'clarifying', 'awaiting_confirmation', 'ready'])
            ->order('update_time', 'desc')->select()->toArray();
        $available = [];
        foreach ($rows as $row) {
            $item = DeliveryItemService::format($row);
            if (!empty($item['pending_action'])) continue;
            $complete = true;
            foreach ((array)($item['depends_on'] ?? []) as $dependencyId) {
                $dependency = DeliveryItemService::find($tenantId, $userId, (int)$dependencyId);
                if ($dependency === [] || (string)($dependency['status'] ?? '') !== 'completed') {
                    $complete = false;
                    break;
                }
            }
            if ($complete) $available[] = $item;
        }
        return count($available) === 1 ? $available[0] : [];
    }

    /** Moves only dependency-satisfied draft stages into executor-claimable ready state. */
    public static function advanceDependencies(int $tenantId, int $userId, int $planId): array
    {
        $plan = self::detail($tenantId, $userId, $planId);
        if ($plan === []) return [];
        foreach ((array)($plan['items'] ?? []) as $item) {
            if ((string)($item['status'] ?? '') !== 'draft' || !self::dependenciesCompleted($tenantId, $userId, (array)$item)) continue;
            DeliveryItemService::transition($tenantId, $userId, (int)$item['id'], 'ready');
        }
        return self::detail($tenantId, $userId, $planId);
    }

    public static function activeItem(int $tenantId, int $userId, int $threadId): array
    {
        self::ensureSchema();
        $row = AigcCanvasDeliveryItem::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->whereIn('status', ['draft', 'clarifying', 'ready', 'awaiting_confirmation', 'queued', 'running'])
            ->order('update_time', 'desc')->findOrEmpty();
        return $row->isEmpty() ? [] : DeliveryItemService::format($row->toArray());
    }

    /** @return array<int, array> */
    public static function pendingItems(int $tenantId, int $userId, int $threadId): array
    {
        self::ensureSchema();
        $rows = AigcCanvasDeliveryItem::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->whereIn('status', ['draft', 'clarifying', 'ready', 'awaiting_confirmation', 'failed'])
            ->order('update_time', 'desc')->select()->toArray();
        return array_values(array_filter(array_map(
            static fn(array $item): array => DeliveryItemService::format($item),
            $rows
        ), static fn(array $item): bool => PendingActionProtocol::isPending((array)($item['pending_action'] ?? []))
            && self::dependenciesCompleted($tenantId, $userId, $item)));
    }

    private static function dependenciesCompleted(int $tenantId, int $userId, array $item): bool
    {
        foreach ((array)($item['depends_on'] ?? []) as $dependencyId) {
            $dependency = DeliveryItemService::find($tenantId, $userId, (int)$dependencyId);
            if ($dependency === [] || (string)($dependency['status'] ?? '') !== 'completed') return false;
        }
        return true;
    }

    /**
     * Detail-page planning remains owned by the existing batch service, while
     * this method exposes every planned section as a first-class item. This
     * keeps historical batches readable and gives new UI/actions item identity.
     */
    public static function syncDetailSections(
        int $tenantId,
        int $userId,
        int $parentItemId,
        array $sections,
        int $batchId = 0,
        array $creativeContext = [],
        array $referenceAssets = []
    ): array {
        self::ensureSchema();
        $parent = AigcCanvasDeliveryItem::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $parentItemId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($parent->isEmpty() || (string)$parent['skill_key'] !== 'ecommerce_detail_page') return [];
        return Db::transaction(function () use ($parent, $sections, $batchId, $creativeContext, $referenceAssets): array {
            $parentId = (int)$parent['id'];
            $existingRows = AigcCanvasDeliveryItem::where([
                'plan_id' => (int)$parent['plan_id'],
                'parent_item_id' => $parentId,
                'delete_time' => 0,
            ])->select();
            $existing = [];
            foreach ($existingRows as $row) $existing[(string)$row['item_key']] = $row;
            $now = time();
            $items = [];
            foreach (array_values(array_filter($sections, 'is_array')) as $index => $section) {
                $sectionKey = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', (string)($section['section_key'] ?? 'section_' . ($index + 1))) ?: 'section_' . ($index + 1);
                $itemKey = (string)$parent['item_key'] . ':section:' . $sectionKey;
                $delivery = array_merge($section, [
                    'type' => 'ecommerce_detail_section',
                    'batch_id' => $batchId,
                    'section_key' => $sectionKey,
                    'section_index' => $index + 1,
                ]);
                $row = $existing[$itemKey] ?? null;
                if ($row) {
                    if (in_array((string)$row['status'], ['draft', 'clarifying', 'ready', 'awaiting_confirmation'], true)) {
                        $row->save([
                            'objective' => (string)($section['title'] ?? $section['purpose'] ?? '详情页区块'),
                            'delivery_json' => $delivery,
                            'creative_context_json' => $creativeContext ?: (array)$row['creative_context_json'],
                            'reference_assets_json' => $referenceAssets ?: (array)$row['reference_assets_json'],
                            'sort_order' => ((int)$parent['sort_order'] * 100) + $index + 1,
                            'update_time' => $now,
                        ]);
                    }
                    $items[] = DeliveryItemService::format($row->toArray());
                    continue;
                }
                $child = AigcCanvasDeliveryItem::create([
                    'tenant_id' => (int)$parent['tenant_id'], 'user_id' => (int)$parent['user_id'],
                    'project_id' => (int)$parent['project_id'], 'thread_id' => (int)$parent['thread_id'],
                    'plan_id' => (int)$parent['plan_id'], 'parent_item_id' => $parentId,
                    'source_message_id' => (int)$parent['source_message_id'], 'item_key' => $itemKey,
                    'objective' => (string)($section['title'] ?? $section['purpose'] ?? '详情页区块'),
                    'skill_key' => (string)$parent['skill_key'], 'tool_code' => (string)($section['tool_code'] ?? 'generate_image'),
                    'status' => 'ready', 'sort_order' => ((int)$parent['sort_order'] * 100) + $index + 1,
                    'retry_count' => 0, 'depends_on_json' => [], 'required_slots_json' => [], 'soft_slots_json' => [],
                    'slots_json' => ['user_request' => (string)($section['user_request'] ?? '')],
                    'delivery_json' => $delivery, 'creative_context_json' => $creativeContext,
                    'reference_assets_json' => $referenceAssets,
                    'pending_action_json' => PendingActionProtocol::confirmation(),
                    'skill_snapshot_json' => (array)$parent['skill_snapshot_json'], 'task_snapshot_json' => [],
                    'cost_json' => [], 'result_json' => [], 'provider_request_id' => '', 'provider_error_code' => '',
                    'provider_error_message' => '', 'error' => '', 'meta_json' => ['generated_from_parent_item_id' => $parentId],
                    'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
                ]);
                $items[] = DeliveryItemService::format($child->toArray());
            }
            $parentDelivery = array_merge((array)$parent['delivery_json'], ['batch_id' => $batchId, 'section_count' => count($items)]);
            $parent->save(['delivery_json' => $parentDelivery, 'update_time' => $now]);
            return $items;
        });
    }

    public static function format(array $plan, array $items = []): array
    {
        $formattedItems = array_map(static fn(array $item): array => DeliveryItemService::format($item), $items);
        return [
            'id' => (int)($plan['id'] ?? 0),
            'thread_id' => (int)($plan['thread_id'] ?? 0),
            'project_id' => (int)($plan['project_id'] ?? 0),
            'source_message_id' => (int)($plan['source_message_id'] ?? 0),
            'title' => (string)($plan['title'] ?? ''),
            'intent' => (string)($plan['intent'] ?? ''),
            'status' => (string)($plan['status'] ?? 'draft'),
            'item_count' => (int)($plan['item_count'] ?? count($formattedItems)),
            'meta' => (array)($plan['meta_json'] ?? []),
            'items' => $formattedItems,
            'created_at' => (int)($plan['create_time'] ?? 0),
            'updated_at' => (int)($plan['update_time'] ?? 0),
        ];
    }

    private static function itemStatus(array $item): string
    {
        $status = (string)($item['status'] ?? '');
        if (in_array($status, DeliveryItemService::STATUSES, true)) return $status;
        return empty($item['missing_slots']) ? 'ready' : 'clarifying';
    }

    private static function planStatus(array $items): string
    {
        foreach ($items as $item) if (!empty($item['missing_slots'])) return 'clarifying';
        return $items === [] ? 'draft' : 'ready';
    }
}
