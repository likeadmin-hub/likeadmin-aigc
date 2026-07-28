<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\service\app\aigc_canvas\agent\billing\CanvasAgentQueuePolicyService;
use app\common\service\app\aigc_canvas\agent\model\CanvasGenerationPricingService;

final class CanvasDeliveryPlannerService
{
    public static function supports(string $content): bool
    {
        $text = mb_strtolower($content, 'UTF-8');
        $matched = self::matchedDeliverableKeys($text);
        if (in_array('detail_image', $matched, true) && in_array('poster', $matched, true)) {
            return true;
        }
        $packageHit = self::containsAny($text, ['一套', '全套', '整套', '物料', '套件', '发布', 'campaign', 'package']);
        if (!$packageHit) {
            return false;
        }
        return count($matched) >= 2;
    }

    public static function plan(int $tenantId, int $userId, string $content, array $context = []): array
    {
        $items = [];
        $matched = self::matchedDeliverableKeys(mb_strtolower($content, 'UTF-8'));
        $isEcommercePosterCompound = in_array('detail_image', $matched, true) && in_array('poster', $matched, true);
        foreach ($matched as $key) {
            $items[] = self::deliverable($key, count($items) + 1, $content, $isEcommercePosterCompound);
        }
        if ($items === []) {
            $items = [
                self::deliverable('poster', 1, $content),
                self::deliverable('social_image', 2, $content),
                self::deliverable('script', 3, $content),
            ];
        }

        $groups = [];
        $estimatedTools = [];
        foreach ($items as $item) {
            $group = (string)$item['group'];
            $groups[$group] ??= [
                'group_key' => $group,
                'label' => self::groupLabel($group),
                'items' => [],
            ];
            $groups[$group]['items'][] = $item;

            $toolCode = (string)($item['tool_code'] ?? '');
            if (in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
                try {
                    $estimate = CanvasGenerationPricingService::estimate($tenantId, $toolCode, [
                        'quantity' => (int)($item['quantity'] ?? 1),
                        'prompt' => $content,
                    ]);
                } catch (\Throwable) {
                    $estimate = [
                        'available' => false,
                        'quantity' => (int)($item['quantity'] ?? 1),
                        'estimated_points' => 0,
                        'estimated_time' => 0,
                        'message' => 'Pricing will be confirmed before execution.',
                    ];
                }
                $queue = CanvasAgentQueuePolicyService::resolve($estimate);
                $estimatedTools[] = [
                    'tool_code' => $toolCode,
                    'delivery_key' => (string)$item['key'],
                    'available' => !empty($estimate['available']),
                    'quantity' => (float)($estimate['quantity'] ?? $item['quantity'] ?? 1),
                    'estimated_points' => (float)($estimate['estimated_points'] ?? 0),
                    'estimated_time' => (int)($estimate['estimated_time'] ?? 0),
                    'queue' => $queue,
                    'queue_type' => (string)($queue['queue_type'] ?? ''),
                    'queue_label' => (string)($queue['label'] ?? ''),
                    'message' => (string)($estimate['message'] ?? ''),
                ];
            }
        }

        return [
            'type' => 'delivery_plan',
            'title' => self::title($content),
            'brief' => mb_substr(trim($content), 0, 800, 'UTF-8'),
            'items' => $items,
            'groups' => array_values($groups),
            'estimated_tools' => $estimatedTools,
            'total_count' => count($items),
        ];
    }

    private static function matchedDeliverableKeys(string $text): array
    {
        $keys = [];
        foreach (self::definitions() as $key => $definition) {
            if (self::containsAny($text, (array)$definition['keywords'])) {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    private static function deliverable(string $key, int $index, string $content, bool $isEcommercePosterCompound = false): array
    {
        $definition = self::definitions()[$key] ?? self::definitions()['poster'];
        $group = (string)$definition['group'];
        if ($isEcommercePosterCompound) {
            $group = $key === 'detail_image' ? 'ecommerce_detail' : ($key === 'poster' ? 'poster_campaign' : $group);
        }
        return [
            'id' => 'delivery_' . $index . '_' . $key,
            'key' => $key,
            'label' => (string)$definition['label'],
            'group' => $group,
            'asset_type' => (string)$definition['asset_type'],
            'skill_key' => (string)$definition['skill_key'],
            'tool_code' => (string)$definition['tool_code'],
            'quantity' => self::requestedQuantity($content, $key, (int)$definition['quantity']),
            'purpose' => (string)$definition['label'],
            'creative_intent' => self::itemNarrative((string)$definition['label'], $content),
            'status' => 'planned',
        ];
    }

    private static function definitions(): array
    {
        return [
            'poster' => ['label' => '主视觉海报', 'group' => 'image', 'asset_type' => 'image', 'skill_key' => 'creative_plan', 'tool_code' => 'generate_image', 'quantity' => 1, 'keywords' => ['海报', '主视觉', 'kv', 'poster', 'banner']],
            'social_image' => ['label' => '社媒配图', 'group' => 'image', 'asset_type' => 'image', 'skill_key' => 'creative_plan', 'tool_code' => 'generate_image', 'quantity' => 3, 'keywords' => ['社媒', '小红书', '朋友圈', '微博', 'social']],
            'detail_image' => ['label' => '详情图', 'group' => 'image', 'asset_type' => 'image', 'skill_key' => 'ecommerce_detail_page', 'tool_code' => 'generate_image', 'quantity' => 3, 'keywords' => ['详情图', '详情页', '卖点图']],
            'script' => ['label' => '推广脚本', 'group' => 'text', 'asset_type' => 'text', 'skill_key' => 'script_planning', 'tool_code' => 'generate_text', 'quantity' => 1, 'keywords' => ['脚本', '文案', '口播', 'copy', 'script']],
            'video' => ['label' => '短视频', 'group' => 'video', 'asset_type' => 'video', 'skill_key' => 'creative_plan', 'tool_code' => 'generate_video', 'quantity' => 1, 'keywords' => ['视频', '短片', '短视频', 'video']],
            'music' => ['label' => '配乐', 'group' => 'audio', 'asset_type' => 'audio', 'skill_key' => 'creative_plan', 'tool_code' => 'generate_music', 'quantity' => 1, 'keywords' => ['配乐', '音乐', 'bgm', 'music']],
        ];
    }

    private static function title(string $content): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $content) ?? $content), 0, 40, 'UTF-8');
    }

    private static function itemNarrative(string $label, string $content): string
    {
        return "基于以下 brief 生成{$label}：\n" . mb_substr(trim($content), 0, 1200, 'UTF-8');
    }

    private static function groupLabel(string $group): string
    {
        return [
            'image' => '图片交付物',
            'text' => '文本交付物',
            'video' => '视频交付物',
            'audio' => '音频交付物',
            'ecommerce_detail' => '交付组 A：商品详情页',
            'poster_campaign' => '交付组 B：宣传海报',
        ][$group] ?? '交付物';
    }

    private static function requestedQuantity(string $content, string $key, int $fallback): int
    {
        $keywords = implode('|', array_map(static fn(string $item): string => preg_quote($item, '/'), (array)(self::definitions()[$key]['keywords'] ?? [])));
        if ($keywords !== '' && preg_match('/(\d{1,2})\s*(?:张|个|幅)?\s*(?:' . $keywords . ')/ui', $content, $match) === 1) {
            return max(1, min(12, (int)$match[1]));
        }
        return $fallback;
    }

    private static function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, mb_strtolower((string)$needle, 'UTF-8'))) {
                return true;
            }
        }
        return false;
    }
}
