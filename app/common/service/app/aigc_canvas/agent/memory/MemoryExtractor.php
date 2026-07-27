<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

/** Extracts only explicit or confirmed facts that are safe for project reuse. */
final class MemoryExtractor
{
    public static function confirmedDecision(string $request, array $decision, array $context): array
    {
        if (($decision['action_mode'] ?? '') !== 'execute') {
            return [];
        }
        $items = [[
            'type' => 'project_goal',
            'key' => 'confirmed_goal',
            'data' => [
                'summary' => mb_substr(trim($request), 0, 1000, 'UTF-8'),
                'intent' => (string)($decision['intent'] ?? ''),
                'skill_key' => (string)($decision['selected_skill_key'] ?? ''),
            ],
        ]];
        $defaults = (array)($decision['default_slots'] ?? []);
        foreach (['style', 'ratio', 'platform'] as $key) {
            if (!empty($defaults[$key])) {
                $items[] = [
                    'type' => 'creative_decision',
                    'key' => $key,
                    'data' => ['summary' => (string)$defaults[$key], 'value' => $defaults[$key]],
                ];
            }
        }
        return $items;
    }
}
