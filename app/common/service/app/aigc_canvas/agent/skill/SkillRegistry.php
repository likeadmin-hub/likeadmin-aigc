<?php

namespace app\common\service\app\aigc_canvas\agent\skill;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;

final class SkillRegistry
{
    public static function usable(int $tenantId): array
    {
        return AigcCanvasSkillService::usable($tenantId, ['limit' => 200]);
    }

    public static function toolPolicies(int $tenantId): array
    {
        $items = self::usable($tenantId);
        $policies = [];
        foreach ($items as $item) {
            $key = (string)($item['skill_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $policies[$key] = [
                'skill_key' => $key,
                'skill_type' => (string)($item['skill_type'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'trigger_description' => (string)($item['trigger_description'] ?? ''),
            ];
        }
        return $policies;
    }
}
