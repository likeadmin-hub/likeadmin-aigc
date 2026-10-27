<?php
namespace app\common\service\app\aigc_short_drama;

/** Display-only accepted history, never an editable or billable result. */
final class ShortDramaScriptPreview
{
    public static function project(array $plan): array
    {
        return array_intersect_key($plan, array_flip(['title', 'type_judgement', 'core_theme',
            'story_outline', 'script_lines', 'art_style', 'subjects', 'locations', 'scene_screenplays', 'storyboard']));
    }

    /** Read-only compatibility for workers that predate timed checkpoints. */
    public static function fromReceipts(array $rows): array
    {
        $plan = []; $shots = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'received' || !empty($row['error'])) continue;
            if (!preg_match('/^v3_(?:policy2_)?(?:timed_)?(?:skeleton|scene_)/', (string)($row['unit_key'] ?? ''))) continue;
            try {
                $receipt = json_decode((string)$row['result_json'], true, 512, JSON_THROW_ON_ERROR);
                $value = ShortDramaStructuredResponse::decode((array)($receipt['result'] ?? []));
            } catch (\Throwable) { continue; }
            if (!empty($value['locations']) && !empty($value['story_outline'])) {
                $plan = self::project($value); $shots = [];
            }
            if (!$plan) continue;
            foreach (($value['storyboard'] ?? []) as $shot) {
                if (is_array($shot) && !empty($shot['shot_id'])) $shots[(string)$shot['shot_id']] = $shot;
            }
        }
        if ($plan) $plan['storyboard'] = array_values($shots);
        return $plan;
    }
}
