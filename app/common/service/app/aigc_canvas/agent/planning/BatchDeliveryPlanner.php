<?php

namespace app\common\service\app\aigc_canvas\agent\planning;

/**
 * Produces concrete media work items for Skills whose contract promises a
 * batch.  A batch must never be represented by a single cover image.
 */
final class BatchDeliveryPlanner
{
    public static function plan(string $skillKey, string $request, array $slots, array $allowedTools): array
    {
        $quantity = max(1, min(12, (int)($slots['quantity'] ?? 0) ?: self::defaultQuantity($skillKey)));
        $ratio = trim((string)($slots['ratio'] ?? '')) ?: '1:1';
        $toolCodes = self::toolCodes($skillKey, $allowedTools);
        $items = [];
        foreach ($toolCodes as $toolCode) {
            $count = $toolCode === 'generate_image' ? $quantity : 1;
            for ($index = 1; $index <= $count; $index++) {
                $key = $toolCode === 'generate_image'
                    ? 'visual_' . str_pad((string)(count($items) + 1), 2, '0', STR_PAD_LEFT)
                    : str_replace('generate_', '', $toolCode) . '_' . str_pad((string)(count($items) + 1), 2, '0', STR_PAD_LEFT);
                $items[] = [
                    'section_index' => count($items) + 1,
                    'section_key' => $key,
                    'title' => self::title($skillKey, $toolCode, $index),
                    'purpose' => self::title($skillKey, $toolCode, $index),
                    'narrative' => self::narrative($request, $skillKey, $toolCode, $index, $count),
                    'visual_direction' => ['distinct_delivery_item', 'standalone_complete_frame'],
                    'ratio' => $ratio,
                    'tool_code' => $toolCode,
                    'copy_content' => [],
                ];
            }
        }

        return [
            'section_count' => count($items),
            'recommended_section_count' => count($items),
            'count_reason' => 'skill_delivery_contract',
            'detail_sections' => $items,
            'design_analysis' => [],
            'analysis_source' => 'contract_planner',
        ];
    }

    private static function defaultQuantity(string $skillKey): int
    {
        return match ($skillKey) {
            'ecommerce_product_listing' => 5,
            'ad_creative' => 3,
            default => 3,
        };
    }

    private static function toolCodes(string $skillKey, array $allowedTools): array
    {
        $available = array_values(array_intersect($allowedTools, ['generate_image', 'generate_video', 'generate_music']));
        if ($available === []) {
            return ['generate_image'];
        }
        if (in_array($skillKey, ['product_launch_campaign', 'campaign_kit'], true)) {
            return $available;
        }
        return [in_array('generate_image', $available, true) ? 'generate_image' : $available[0]];
    }

    private static function title(string $skillKey, string $toolCode, int $index): string
    {
        $prefix = match ($skillKey) {
            'ecommerce_product_listing' => 'Listing visual',
            'ad_creative' => 'Ad creative',
            'product_launch_campaign' => 'Launch asset',
            'campaign_kit' => 'Campaign asset',
            default => 'Delivery asset',
        };
        return $prefix . ' ' . $index . ' (' . str_replace('generate_', '', $toolCode) . ')';
    }

    private static function narrative(string $request, string $skillKey, string $toolCode, int $index, int $count): string
    {
        return trim($request) . "\n\nDelivery item {$index} of {$count} for {$skillKey}. "
            . 'Keep this item distinct within the promised set and preserve the requested medium: '
            . str_replace('generate_', '', $toolCode) . '.';
    }
}
