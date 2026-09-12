<?php

namespace app\common\service\app\aigc_short_drama;

use Exception;

/** Resolve displayed shot numbers before character names; never guess a missing shot. */
class ShortDramaRevisionScope
{
    private static function number(string $value): int
    {
        if (ctype_digit($value)) return (int)$value;
        $digits = ['零' => 0, '〇' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        $total = 0; $digit = 0;
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if ($char === '十' || $char === '百') { $total += max(1, $digit) * ($char === '十' ? 10 : 100); $digit = 0; }
            else $digit = $digits[$char] ?? 0;
        }
        return $total + $digit;
    }

    public static function shotTarget(string $message, array $plan): array
    {
        $shots = array_values(array_filter((array)($plan['storyboard'] ?? []), 'is_array'));
        $locations = [];
        foreach ((array)($plan['locations'] ?? []) as $location) $locations[(string)($location['id'] ?? '')] = $location;
        $order = static function (array $shot, int $index) use ($locations): array {
            $sceneId = (string)($shot['scene_ref_id'] ?? $shot['scene_ref'] ?? '');
            $scene = $locations[$sceneId] ?? [];
            $numeric = static function ($value): int { preg_match('/\d+/', (string)$value, $match); return (int)($match[0] ?? 0); };
            return [(int)($scene['story_order'] ?? $shot['scene_order'] ?? 0), $numeric($shot['sort'] ?? '') ?: ($numeric($shot['shot_id'] ?? '') ?: $index + 1)];
        };
        $indexed = [];
        foreach ($shots as $index => $shot) $indexed[] = ['shot' => $shot, 'order' => $order($shot, $index), 'index' => $index];
        usort($indexed, static function (array $a, array $b): int {
            if ($a['order'][0] > 0 && $b['order'][0] > 0 && $a['order'][0] !== $b['order'][0]) return $a['order'][0] <=> $b['order'][0];
            return ($a['order'][1] <=> $b['order'][1]) ?: ($a['index'] <=> $b['index']);
        });
        $shots = array_column($indexed, 'shot');
        $number = '[0-9零〇一二两三四五六七八九十百]+';
        $expression = $number . '(?:\s*(?:、|,|，|和|及|到|至|[-~～])\s*(?:第\s*)?' . $number . ')*';
        preg_match_all('/(?:第\s*(' . $expression . ')\s*(?:个\s*)?(?:分镜头|分镜|镜头)|(?:分镜头|分镜|镜头)\s*(?:第\s*)?(' . $expression . '))/u', $message, $matches, PREG_SET_ORDER);
        if (!$matches) return [];

        $indices = [];
        foreach ($matches as $match) {
            $selection = ($match[1] ?? '') !== '' ? $match[1] : $match[2];
            preg_match_all('/' . $number . '/u', $selection, $numbers);
            $values = array_map([self::class, 'number'], $numbers[0]);
            if (preg_match('/到|至|[-~～]/u', $selection)) {
                if (count($values) !== 2 || $values[0] > $values[1] || $values[1] > count($shots)) throw new Exception('请指定有效的分镜编号范围');
                $values = range($values[0], $values[1]);
            }
            foreach ($values as $value) {
                if ($value < 1 || $value > count($shots)) throw new Exception('指定的分镜不存在，请按页面显示的分镜编号修改');
                $indices[$value - 1] = true;
            }
        }

        $fields = [];
        foreach ([
            '/台词|对白|对话|说的话/u' => ['dialogue'],
            '/配音角色|说话人/u' => ['voice_role'],
            '/运镜|镜头运动/u' => ['camera_movement'],
            '/构图/u' => ['composition'],
            '/景别/u' => ['shot_type'],
            '/音效|环境声/u' => ['sound_effect'],
            '/背景音乐|配乐/u' => ['bgm_prompt'],
            '/(?:图片|生图|图像)提示词/u' => ['image_prompt', 'image_negative_prompt'],
            '/视频提示词/u' => ['video_prompt', 'video_negative_prompt'],
            '/画面|动作|表情/u' => ['visual_description', 'action', 'result', 'image_prompt', 'video_prompt'],
        ] as $pattern => $allowed) {
            if (preg_match($pattern, $message)) $fields = array_merge($fields, $allowed);
        }
        $selected = array_intersect_key($shots, $indices);
        $ids = array_map(static fn(array $shot): string => (string)($shot['shot_id'] ?? $shot['id'] ?? ''), $selected);
        if (in_array('', $ids, true)) throw new Exception('分镜缺少标识，请刷新后重试');
        return ['type' => 'shot_fields', 'id' => reset($ids), 'ids' => array_values($ids),
            'fields' => array_values(array_unique($fields)), 'payload' => array_values($selected),
            'rule' => 'Modify only these exact shot IDs and the listed fields. An empty fields list permits changing the selected shots only. Preserve all other values and all shot IDs/order.'];
    }

    public static function mergeShots(array $base, array $result, array $target): array
    {
        $ids = array_fill_keys((array)($target['ids'] ?? [$target['id']]), true);
        $fields = (array)($target['fields'] ?? []);
        $revised = [];
        foreach ((array)($result['storyboard'] ?? []) as $shot) {
            $id = (string)($shot['shot_id'] ?? $shot['id'] ?? '');
            if (isset($ids[$id])) {
                if (isset($revised[$id])) throw new Exception('修改结果的分镜编号重复，请重试');
                $revised[$id] = $shot;
            }
        }
        foreach ($base['storyboard'] as &$shot) {
            $id = (string)($shot['shot_id'] ?? $shot['id'] ?? '');
            if (!isset($ids[$id])) continue;
            if (!isset($revised[$id])) throw new Exception('修改结果缺少指定分镜，请重试');
            if ($fields) {
                foreach ($fields as $field) {
                    if (!array_key_exists($field, $revised[$id])) throw new Exception('修改结果缺少指定内容，请重试');
                    if (!is_string($revised[$id][$field])) throw new Exception('修改内容格式异常，请重试');
                    $shot[$field] = $revised[$id][$field];
                }
                // Keep the structured video instructions in sync without accepting unrelated model edits.
                foreach (['camera_movement' => '运镜手法', 'composition' => '构图', 'shot_type' => '景别'] as $field => $label) {
                    if (in_array($field, $fields, true) && !empty($shot['video_prompt'])) {
                        $shot['video_prompt'] = preg_replace_callback('/^' . $label . '[：:].*$/mu', static fn() => $label . '：' . $shot[$field], $shot['video_prompt']);
                    }
                }
                if (array_intersect($fields, ['dialogue', 'voice_role', 'sound_effect']) && !empty($shot['video_prompt'])) {
                    $sound = trim((string)($shot['dialogue'] ?? ''));
                    $sound = $sound === '' ? '无对白' : (string)($shot['voice_role'] ?? '') . '：' . $sound;
                    if (!empty($shot['sound_effect'])) $sound .= '；' . $shot['sound_effect'];
                    $shot['video_prompt'] = preg_replace_callback('/^声音[：:].*$/mu', static fn() => '声音：' . $sound, $shot['video_prompt']);
                }
            } else {
                // A full selected-shot edit still cannot change its identity, ordering or asset selections.
                $allowed = ['dialogue', 'voice_role', 'visual_description', 'composition', 'camera_movement', 'shot_type',
                    'action', 'result', 'image_prompt', 'image_negative_prompt', 'video_prompt', 'video_negative_prompt', 'bgm_prompt', 'sound_effect'];
                foreach (array_intersect_key($revised[$id], array_flip($allowed)) as $field => $value) {
                    if (!is_string($value)) throw new Exception('修改内容格式异常，请重试');
                    $shot[$field] = $value;
                }
            }
        }
        unset($shot);
        return $base;
    }
}
