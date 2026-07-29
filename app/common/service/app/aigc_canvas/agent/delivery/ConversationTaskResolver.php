<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;

/**
 * Resolves only explicit or high-confidence continuation actions. New work is
 * created only after AgentTaskDecisionService has made a structured decision.
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
        $explicitItemId = (int)($params['delivery_item_id'] ?? 0);
        if ($explicitItemId > 0) {
            $item = DeliveryItemService::find($tenantId, $userId, $explicitItemId);
            if ($item !== [] && (int)$item['thread_id'] === $threadId) {
                $resolved = self::resolvePending($tenantId, $userId, $item, $params, $content);
                if ($resolved !== []) return $resolved;
                if (self::looksLikeRevision($content)) {
                    $revised = self::reviseExisting($tenantId, $userId, $item, $content);
                    if ($revised !== []) return $revised;
                }
                return self::existing($tenantId, $userId, $item, 'explicit_item');
            }
        }

        $pending = DeliveryPlanService::pendingItems($tenantId, $userId, $threadId);
        if (count($pending) === 1 && self::canNaturallyContinue($content)) {
            $resolved = self::resolvePending($tenantId, $userId, $pending[0], $params, $content);
            if ($resolved !== []) return $resolved;
        }
        if (self::looksLikeRevision($content)) {
            $item = DeliveryPlanService::activeItem($tenantId, $userId, $threadId);
            if ($item === []) $item = DeliveryPlanService::latestItem($tenantId, $userId, $threadId);
            $revised = self::reviseExisting($tenantId, $userId, $item, $content);
            if ($revised !== []) return $revised;
        }
        $decision = (array)($params['task_decision'] ?? []);
        if ((string)($decision['operation'] ?? '') !== 'create') return [];
        $definition = self::definition($content, $context);
        if ($definition === []) return [];

        $plan = DeliveryPlanService::create($tenantId, $userId, $projectId, $threadId, $messageId, $definition);
        $item = (array)($plan['items'][0] ?? []);
        return [
            'operation' => count((array)$plan['items']) > 1 ? 'new_composite_task' : 'new_single_task',
            'plan' => $plan,
            'item' => $item,
            'selected_skill' => self::skill($tenantId, (string)($item['skill_key'] ?? '')),
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

    private static function reviseExisting(int $tenantId, int $userId, array $item, string $content): array
    {
        if ($item === [] || !in_array((string)($item['status'] ?? ''), ['ready', 'awaiting_confirmation', 'failed', 'completed'], true)) return [];
        $delivery = (array)($item['delivery'] ?? []);
        $ratio = self::ratio(mb_strtolower($content, 'UTF-8'));
        if ($ratio !== '') $delivery['ratio'] = $ratio;
        $slots = array_merge((array)($item['slots'] ?? []), ['user_request' => $content]);
        $updated = DeliveryItemService::transition($tenantId, $userId, (int)$item['id'], 'ready', [
            'slots_json' => $slots, 'delivery_json' => $delivery,
            'pending_action_json' => PendingActionProtocol::confirmation(),
        ]);
        return self::existing($tenantId, $userId, $updated, 'high_confidence_revision');
    }

    private static function looksLikeRevision(string $content): bool
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if (preg_match('/\b(?:1:1|3:4|4:3|9:16|16:9)\b/u', $text) === 1) return true;
        return preg_match('/(?:\x{6539}\x{6210}|\x{8C03}\x{6574}|\x{4FEE}\x{6539}|\x{66F4}\x{6362}|revise|change|edit)/iu', $text) === 1;
    }

    private static function resolvePending(int $tenantId, int $userId, array $item, array $params, string $content): array
    {
        try {
            $resolution = PendingActionProtocol::resolve($item, $params, $content);
            if ($resolution === []) return [];
            $updated = DeliveryItemService::transition(
                $tenantId,
                $userId,
                (int)$item['id'],
                (string)$resolution['status'],
                (array)$resolution['patch']
            );
            $updated = DeliveryItemContextBinder::bind($tenantId, $userId, (int)$updated['id'], [], [], self::binderChanges((array)$resolution['patch']));
            $result = self::existing($tenantId, $userId, $updated, 'pending_action_resolved');
            $result['pending_action'] = (array)($resolution['action'] ?? []);
            $result['pending_action_accepted'] = !empty($resolution['accepted']);
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function binderChanges(array $patch): array
    {
        return [
            'slots' => (array)($patch['slots_json'] ?? []),
            'delivery' => (array)($patch['delivery_json'] ?? []),
            'creative_context' => (array)($patch['creative_context_json'] ?? []),
            'reference_assets' => (array)($patch['reference_assets_json'] ?? []),
        ];
    }

    private static function definition(string $content, array $context): array
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if ($text === '') return [];
        $hasReference = !empty($context['uploaded_references']) || !empty($context['selected_elements']) || !empty($context['selection']['elements']);
        $items = [];
        $hasFunctionalSelling = $hasReference && self::hasFunctionalSellingPoint($text);
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
        } elseif ($hasFunctionalSelling) {
            $items[] = self::item('selling_point', '生成商品卖点图', 'ecommerce_selling_point', 'generate_image', true, $content, [
                'type' => 'ecommerce_selling_point', 'purpose' => '商品卖点图', 'ratio' => self::ratio($text), 'quantity' => 1,
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

    private static function hasFunctionalSellingPoint(string $text): bool
    {
        return preg_match('/\x{5356}\x{70B9}|\x{7A81}\x{51FA}|\x{7279}\x{70B9}|\x{4F18}\x{52BF}|\x{7EED}\x{822A}|\x{6750}\x{8D28}|\x{529F}\x{80FD}|\x{964D}\x{566A}/u', $text) === 1;
    }

    /**
     * Generic natural-language fallback. Domain-specific routing deliberately
     * remains in definition(); this only recognizes a conversation continuation.
     */
    private static function canNaturallyContinue(string $content): bool
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if ($text === '') return false;
        return preg_match('/确认|确定|继续|同意|可以|好的|取消|拒绝|不用|重试|修改|调整|补充|改为|yes|confirm|continue|cancel|retry|revise/i', $text) === 1
            ;
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
