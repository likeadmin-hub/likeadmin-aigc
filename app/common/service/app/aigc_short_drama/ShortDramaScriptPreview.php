<?php
namespace app\common\service\app\aigc_short_drama;

/** Display-only accepted history, never an editable or billable result. */
final class ShortDramaScriptPreview
{
    /** User-facing hints describe only saved content and a coarse recent stage. */
    public static function recoveryInfo(array $state, string $status): array
    {
        if (!in_array($status, ['failed','canceled'], true)) return [];
        $draft = self::project((array)($state['__script_checkpoint'] ?? []));
        $shots = is_array($draft['storyboard'] ?? null) ? count($draft['storyboard']) : 0;
        $hasDraft = $shots > 0 || trim((string)($draft['story_outline'] ?? '')) !== ''
            || !empty($draft['script_lines']) || !empty($draft['subjects']) || !empty($draft['locations']);
        $unit = (string)($state['__stream_unit'] ?? ''); $stage = '';
        if (preg_match('/^v3_(?:policy2_)?(?:timed_)?scene_(\d+)_(\d+)(?:_|$)/D', $unit, $match)) $stage = '第' . (int)$match[1] . '场，从第' . (int)$match[2] . '个分镜开始';
        elseif (preg_match('/^v3_(?:policy2_)?timed_batch_(\d+)_(\d+)$/D', $unit, $match)) $stage = '第' . (int)$match[1] . '至' . (int)$match[2] . '场';
        elseif (preg_match('/^v3_(?:policy2_)?(?:timed_)?skeleton(?:_|$)/D', $unit)) $stage = '场次规划';
        return ['has_draft'=>$hasDraft, 'saved_shots'=>$shots, 'recent_stage'=>$stage];
    }

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
