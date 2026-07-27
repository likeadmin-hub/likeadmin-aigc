<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

final class CanvasDeliveryBatchService
{
    public static function prepare(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $content, array $route): array
    {
        $plan = is_array($route['delivery_plan'] ?? null) ? $route['delivery_plan'] : CanvasDeliveryPlannerService::plan($tenantId, $userId, $content);
        $actions = [];
        foreach ((array)($plan['items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $actions[] = [
                'id' => 'delivery_plan_' . $messageId . '_' . ($index + 1),
                'action_type' => 'insert_workflow',
                'status' => 'pending',
                'tool_call_id' => 0,
                'input' => [
                    'skill' => [
                        'key' => (string)($item['skill_key'] ?? ''),
                        'name' => (string)($item['label'] ?? '交付物'),
                    ],
                    'delivery_item' => $item,
                    'delivery_plan_title' => (string)($plan['title'] ?? ''),
                    'creative_intent' => (string)($item['creative_intent'] ?? $content),
                    'purpose' => (string)($item['purpose'] ?? $item['label'] ?? ''),
                    'asset' => [
                        'type' => 'workflow',
                        'title' => (string)($item['label'] ?? '交付物'),
                    ],
                ],
            ];
        }

        return [
            'reply' => self::reply($plan),
            'tool_calls' => [],
            'workspace_actions' => $actions,
            'assets' => [],
            'next_action' => 'confirm_execution',
            'delivery_plan' => $plan,
            'delivery_groups' => (array)($plan['groups'] ?? []),
            'estimated_tools' => (array)($plan['estimated_tools'] ?? []),
            'total_count' => (int)($plan['total_count'] ?? count($actions)),
            'completed_count' => 0,
            'remaining_count' => (int)($plan['total_count'] ?? count($actions)),
        ];
    }

    private static function reply(array $plan): string
    {
        $lines = ['已拆成交付计划：'];
        foreach ((array)($plan['groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $labels = [];
            foreach ((array)($group['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $labels[] = (string)($item['label'] ?? '');
                }
            }
            $labels = array_values(array_filter($labels));
            if ($labels !== []) {
                $lines[] = (string)($group['label'] ?? '交付物') . '：' . implode('、', $labels);
            }
        }
        return implode("\n", $lines);
    }
}
