<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;

/**
 * Resolves a user turn into a new delivery plan or an update to one item.
 * It is intentionally deterministic for common creation requests so a fast
 * first response does not depend on an additional model call.
 */
final class ConversationTaskResolver
{
    /** Pure planning entry used by routing tests and non-persistent previews. */
    public static function preview(string $content, array $context = []): array
    {
        return self::definition($content, $context);
    }

    public static function resolve(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        string $content,
        array $context,
        array $params = []
    ): array {
        DeliveryPlanService::ensureSchema();
        $taskDecision = (array)($params['task_decision'] ?? []);
        $relation = (string)($taskDecision['turn_relation'] ?? '');
        $confidence = (float)($taskDecision['confidence'] ?? 0);
        $textRetry = (string)($taskDecision['intent'] ?? '') === 'text_generation'
            && !empty($taskDecision['retry_source']);
        $explicitItemId = (int)($taskDecision['target_delivery_item_id'] ?? $params['delivery_item_id'] ?? 0);
        if ($explicitItemId > 0 && in_array($relation, ['continue', 'revise', 'confirm', 'retry', 'cancel'], true)) {
            $item = DeliveryItemService::find($tenantId, $userId, $explicitItemId);
            if ($item !== [] && (int)$item['thread_id'] === $threadId) {
                $resolved = self::resolvePending($tenantId, $userId, $item, $params, $content, $relation);
                if ($resolved !== []) return $resolved;
                $bound = DeliveryItemContextBinder::bindBase($tenantId, $userId, $item, $context + ['content' => $content], $taskDecision);
                return self::existing($tenantId, $userId, $bound, 'explicit_item');
            }
        }

        $pending = DeliveryPlanService::pendingItems($tenantId, $userId, $threadId);
        if (!$textRetry && count($pending) === 1 && $confidence >= 0.8 && in_array($relation, ['continue', 'revise', 'confirm', 'retry', 'cancel'], true)) {
            $resolved = self::resolvePending($tenantId, $userId, $pending[0], $params, $content, $relation);
            if ($resolved !== []) return $resolved;
        }
        $definition = self::definitionFromDecision($taskDecision, $content, $context);
        // Legacy callers that have not adopted the Runtime decision may retain
        // deterministic creation, but may never infer a continuation.
        if ($definition === [] && $taskDecision === []) $definition = self::definition($content, $context);
        if ($definition === []) return [];

        $plan = CreativeDeliveryGraphService::create(
            $tenantId,
            $userId,
            $projectId,
            $threadId,
            $messageId,
            $definition,
            $context + ['content' => $content]
        );
        foreach ((array)($plan['items'] ?? []) as $index => $created) {
            $plan['items'][$index] = DeliveryItemContextBinder::bindBase(
                $tenantId,
                $userId,
                (array)$created,
                $context + ['content' => $content],
                $taskDecision
            );
        }
        $item = (array)($plan['items'][0] ?? []);
        return [
            'operation' => count((array)$plan['items']) > 1 ? 'new_composite_task' : 'new_single_task',
            'plan' => $plan,
            'item' => $item,
            'selected_skill' => self::skill($tenantId, (string)($item['skill_key'] ?? '')),
        ];
    }

    private static function definitionFromDecision(array $decision, string $content = '', array $context = []): array
    {
        $workflow = DeliveryPlanService::compileWorkflow((string)($decision['workflow_template'] ?? ''), $content, $context);
        if ($workflow !== []) return $workflow;
        // A clarification never represents an executable deliverable. This
        // second guard keeps malformed or stale client decisions from creating
        // an item that the panel would render as ready.
        if ((string)($decision['decision_mode'] ?? '') === 'clarify'
            || !empty($decision['missing_hard_slots'])) {
            return [];
        }
        $items = array_values(array_filter((array)($decision['delivery_specs'] ?? []), 'is_array'));
        if ($items === []) return [];
        return [
            'title' => count($items) > 1 ? '组合创作交付计划' : (string)($items[0]['objective'] ?? '创作交付项'),
            'intent' => count($items) > 1 ? 'composite_generation' : (string)($decision['intent'] ?? 'generation'),
            'items' => $items,
            'meta' => [
                'resolver_version' => 'delivery-plan-v2',
                'source' => 'agent_task_decision',
                'turn_relation' => (string)($decision['turn_relation'] ?? ''),
                'confidence' => (float)($decision['confidence'] ?? 0),
            ],
        ];
    }

    private static function existing(int $tenantId, int $userId, array $item, string $operation): array
    {
        return [
            'operation' => $operation,
            'plan' => DeliveryPlanService::detail($tenantId, $userId, (int)($item['plan_id'] ?? 0)),
            'item' => $item,
            'selected_skill' => self::skill($tenantId, (string)($item['skill_key'] ?? '')),
        ];
    }

    private static function resolvePending(int $tenantId, int $userId, array $item, array $params, string $content, string $relation = ''): array
    {
        try {
            if ($relation === 'revise' && trim((string)($params['action'] ?? '')) === '') {
                $revision = self::naturalRevision($content);
                if ($revision !== []) {
                    $revisionAction = PendingActionProtocol::normalize([
                        'action_id' => 'revise_item:natural',
                        'type' => 'revise_item',
                        'required_input_schema' => ['type' => 'object'],
                        'on_success_transition' => 'ready',
                        'on_reject_transition' => 'awaiting_confirmation',
                    ]);
                    $resolution = PendingActionProtocol::resolve(
                        array_merge($item, ['pending_action' => $revisionAction]),
                        ['action' => 'revise_item', 'action_id' => $revisionAction['action_id'], 'structured_value' => $revision]
                    );
                    if ($resolution !== []) {
                        $updated = DeliveryItemService::transition(
                            $tenantId,
                            $userId,
                            (int)$item['id'],
                            (string)$resolution['status'],
                            (array)$resolution['patch']
                        );
                        $result = self::existing($tenantId, $userId, $updated, 'natural_revision_resolved');
                        $result['pending_action'] = $revisionAction;
                        $result['pending_action_accepted'] = true;
                        return $result;
                    }
                }
            }
            $resolution = PendingActionProtocol::resolve($item, $params, $content);
            if ($resolution === []) return [];
            $updated = DeliveryItemService::transition(
                $tenantId,
                $userId,
                (int)$item['id'],
                (string)$resolution['status'],
                (array)$resolution['patch']
            );
            $result = self::existing($tenantId, $userId, $updated, 'pending_action_resolved');
            $result['pending_action'] = (array)($resolution['action'] ?? []);
            $result['pending_action_accepted'] = !empty($resolution['accepted']);
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /** Extract only explicit, safe revision fields from a natural-language turn. */
    private static function naturalRevision(string $content): array
    {
        $delivery = [];
        if (preg_match('/\b(1:1|3:4|4:3|9:16|16:9)\b/u', $content, $match) === 1) {
            $delivery['ratio'] = (string)$match[1];
        }
        if (preg_match('/不(?:要|含)文案|无文案|去除文案|不要文字|无文字/u', $content) === 1) {
            $delivery['copy'] = '';
            $delivery['copy_content'] = '';
        }
        return $delivery === [] ? [] : ['delivery' => $delivery];
    }

    private static function definition(string $content, array $context): array
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if ($text === '') return [];
        $hasReference = !empty($context['uploaded_references']) || !empty($context['selected_elements']) || !empty($context['selection']['elements']);
        $items = [];
        $hasDetail = preg_match('/详情页|详情图|详情长图|多屏|一套|\d+\s*(?:屏|页)/u', $text) === 1;
        $hasSelling = preg_match('/卖点图|功能图|场景图|对比图/u', $text) === 1;
        $hasMain = preg_match('/主图|白底图|首图|商品展示图|单张/u', $text) === 1;
        $hasVideo = preg_match('/视频|短片|动画|图生视频/u', $text) === 1;
        $hasMusic = preg_match('/音乐|配乐|音频|歌曲/u', $text) === 1;
        $hasCanvasEdit = preg_match('/移动|删除|复制|对齐|编组|画布编辑|调整画布/u', $text) === 1;

        if ($hasMain) {
            $items[] = self::item('main_image', '生成单张商品主图', 'ecommerce_main_image', 'generate_image', $hasReference, $content, [
                'type' => 'ecommerce_main_image', 'purpose' => '商品主图', 'ratio' => self::ratio($text),
            ]);
        }
        if ($hasSelling) {
            $items[] = self::item('selling_point', '生成商品卖点图', 'ecommerce_selling_point', 'generate_image', $hasReference, $content, [
                'type' => 'ecommerce_selling_point', 'purpose' => '商品卖点图', 'ratio' => self::ratio($text), 'quantity' => self::imageQuantity($text, 3),
            ]);
        }
        if ($hasDetail) {
            $items[] = self::item('detail_page', '规划商品详情页', 'ecommerce_detail_page', 'generate_image', $hasReference, $content, [
                'type' => 'ecommerce_detail_page', 'purpose' => '商品详情页', 'section_count' => self::sectionCount($text, 5),
            ]);
        }
        if ($hasVideo) {
            $items[] = self::item('video', '生成视频创作', 'video_generation', 'generate_video', true, $content, [
                'type' => 'video', 'purpose' => '视频创作', 'ratio' => self::ratio($text),
            ]);
        }
        if ($hasMusic) {
            $items[] = self::item('music', '生成音乐创作', 'music_generation', 'generate_music', true, $content, [
                'type' => 'music', 'purpose' => '音乐创作',
            ]);
        }
        if ($hasCanvasEdit) {
            $items[] = self::item('canvas_edit', '编辑画布内容', 'canvas_editing', 'canvas_mutation', true, $content, [
                'type' => 'canvas_edit', 'purpose' => '画布编辑',
            ]);
        }
        if ($items === [] && preg_match('/生成|制作|创建|出图|画一张|做一张|海报|logo|包装/u', $text) === 1) {
            $items[] = self::item('image', '生成图片创作', self::generalSkill($text), 'generate_image', true, $content, [
                'type' => 'image', 'purpose' => '图片创作', 'ratio' => self::ratio($text),
            ]);
        }
        if ($items === []) return [];
        foreach ($items as $index => &$item) {
            $item['item_key'] .= '_' . ($index + 1);
            $item['depends_on'] = [];
        }
        unset($item);
        return [
            'title' => count($items) > 1 ? '组合创作交付计划' : (string)$items[0]['objective'],
            'intent' => count($items) > 1 ? 'composite_generation' : 'generation',
            'items' => $items,
            'meta' => ['resolver_version' => 'delivery-plan-v1', 'source' => 'conversation_task_resolver'],
        ];
    }

    private static function item(string $key, string $objective, string $skillKey, string $toolCode, bool $hasReference, string $request, array $delivery): array
    {
        $required = str_starts_with($skillKey, 'ecommerce_') ? ['product_reference'] : [];
        $missing = $required !== [] && !$hasReference ? $required : [];
        return [
            'item_key' => $key,
            'objective' => $objective,
            'skill_key' => $skillKey,
            'tool_code' => $toolCode,
            'required_slots' => $required,
            'soft_slots' => ['selling_points'],
            'missing_slots' => $missing,
            'slots' => ['user_request' => $request],
            'delivery' => $delivery,
            'reference_assets' => [],
            'pending_action' => $missing !== []
                ? PendingActionProtocol::forMissingSlots($missing)
                : PendingActionProtocol::confirmation(),
            'meta' => ['safe_generation_when_soft_slots_missing' => true],
        ];
    }

    private static function skill(int $tenantId, string $skillKey): array
    {
        return $skillKey === '' ? [] : AigcCanvasSkillService::resolveSkill($tenantId, $skillKey);
    }

    private static function looksLikeSupplement(string $content): bool
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if ($text === '' || preg_match('/主图|卖点图|详情页|详情图|海报|logo|包装|视频|音乐|短剧|分镜/u', $text) === 1) return false;
        return preg_match('/确认|继续|没有|无|不确定|不用写|改成|调整|补充|上传|这个|比例|风格|颜色|文案/u', $text) === 1;
    }

    private static function ratio(string $text): string
    {
        return preg_match('/\b(1:1|3:4|4:3|9:16|16:9)\b/u', $text, $matches) === 1 ? (string)$matches[1] : '';
    }

    private static function imageQuantity(string $text, int $fallback): int
    {
        return preg_match('/(\d{1,2})\s*张/u', $text, $matches) === 1
            ? max(1, min(24, (int)$matches[1]))
            : $fallback;
    }

    private static function sectionCount(string $text, int $fallback): int
    {
        return preg_match('/(\d{1,2})\s*(?:屏|页)/u', $text, $matches) === 1
            ? max(1, min(24, (int)$matches[1]))
            : $fallback;
    }

    private static function generalSkill(string $text): string
    {
        if (str_contains($text, '海报')) return 'poster_design';
        if (str_contains($text, 'logo')) return 'logo_design';
        if (str_contains($text, '包装')) return 'packaging_design';
        return 'general_image';
    }
}
