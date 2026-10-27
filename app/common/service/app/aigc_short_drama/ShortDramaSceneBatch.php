<?php
namespace app\common\service\app\aigc_short_drama;

/** Pair only small, adjacent, complete scenes. Scene identities and event ownership never change. */
final class ShortDramaSceneBatch
{
    public const CONTRACT_VERSION = 14;
    public const POLICY = ['version' => 1, 'enabled' => false, 'max_scenes' => 2, 'max_shots' => 4];

    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= self::CONTRACT_VERSION
            && ($request['_scene_batch_policy'] ?? null) === array_replace(self::POLICY, ['enabled' => true]);
    }

    public static function isUnit(string $key, array $request): bool
    {
        return self::enabled($request) && (bool)preg_match('/^timed_batch_\d+_\d+$/D', $key);
    }

    public static function plan(array $request, array $messages, array $skeleton, int $partSize): array
    {
        $groups = [];
        $trusted = is_string($messages['_stage_content'] ?? null);
        $timeline = (array)(ShortDramaEpisodeDuration::policy($request)['timeline_segments'] ?? []);
        if (self::enabled($request) && $trusted && !$timeline && !ShortDramaEpisodeDuration::localRevision($request)
            && array_is_list($skeleton['scene_beats'])) {
            $beats = array_values($skeleton['scene_beats']);
            for ($i = 0; $i + 1 < count($beats); $i++) {
                if (!self::small($beats[$i]) || !self::small($beats[$i + 1])) continue;
                if (count($beats[$i]['shot_durations']) + count($beats[$i + 1]['shot_durations']) > min(4, $partSize)) continue;
                $groups[] = [$i, $i + 1];
                $i++;
            }
        }
        return ['version' => 1, 'signature' => hash('sha256', self::json($skeleton)),
            'part_size' => $partSize, 'groups' => $groups];
    }

    private static function small(array $beat): bool
    {
        $count = count((array)($beat['shot_durations'] ?? []));
        if ($count < 1 || $count > 2 || !array_is_list($beat['shot_durations'])) return false;
        $text = implode(' ', [(string)($beat['goal'] ?? ''), (string)($beat['entry'] ?? ''), (string)($beat['exit'] ?? ''),
            implode(' ', (array)($beat['key_events'] ?? []))]);
        if (mb_strlen($text) > 400 || preg_match('/闪回|回忆|梦境|倒叙|蒙太奇|时间跳|交叉剪辑|平行剪辑|重大反转|真相揭晓|多年[前后]|次日|翌日/u', $text)) return false;
        preg_match_all('/[“「"]([^”」"]+)[”」"]/u', $text, $matches);
        foreach ($matches[1] as $dialogue) {
            if (mb_strlen($dialogue) > 80 || ShortDramaDialogueSplit::requiredSeconds($dialogue) > array_sum($beat['shot_durations'])) return false;
        }
        return true;
    }

    public static function restore(array $proposed, array $saved, array $skeleton): array
    {
        $invalid = ($saved['version'] ?? null) !== 1 || ($saved['signature'] ?? null) !== $proposed['signature']
            || !is_int($saved['part_size'] ?? null) || $saved['part_size'] < 1 || $saved['part_size'] > 4
            || !is_array($saved['groups'] ?? null) || !array_is_list($saved['groups']);
        if ($invalid) throw new \RuntimeException('已保存的分场合并规划不完整，原有结果保留，请核对后继续', 409);
        $last = -1;
        foreach ($saved['groups'] as $group) {
            if (!is_array($group) || !array_is_list($group) || count($group) !== 2 || !is_int($group[0]) || !is_int($group[1])
                || $group[0] <= $last || $group[1] !== $group[0] + 1 || !isset($skeleton['scene_beats'][$group[0]], $skeleton['scene_beats'][$group[1]])) {
                throw new \RuntimeException('已保存的分场合并顺序无效，原有结果保留，请核对后继续', 409);
            }
            $shots = 0;
            foreach ($group as $index) {
                $count = count($skeleton['scene_beats'][$index]['shot_durations']);
                if ($count < 1 || $count > 2) throw new \RuntimeException('已保存的分场合并范围无效，原有结果保留', 409);
                $shots += $count;
            }
            if ($shots > min(4, $saved['part_size'])) throw new \RuntimeException('已保存的分场合并容量无效，原有结果保留', 409);
            $last = $group[1];
        }
        return $saved;
    }

    public static function input(array $request, array $messages, array $base, array $skeleton, array $group, array $completed): array
    {
        $scenes = []; $required = [];
        foreach ($group as $index) {
            $beat = $skeleton['scene_beats'][$index];
            $shots = [];
            foreach (array_values($beat['shot_durations']) as $offset => $duration) {
                $shots[] = ['shot_id' => 's' . ($index + 1) . '_' . ($offset + 1),
                    'scene_ref_id' => $beat['scene_ref_id'], 'duration_seconds' => $duration];
            }
            $scenes[] = ['scene_index' => $index + 1, 'scene_beat' => $beat, 'required_shots' => $shots,
                'scene_progress' => ShortDramaSceneProgress::window($beat, count($shots), 0, count($shots), [])];
            array_push($required, ...$shots);
        }
        $context = ['creative_context' => $base['content'],
            'locked_plan' => array_intersect_key($skeleton, array_flip(['title', 'story_outline', 'series_bible', 'subjects', 'locations', 'art_style'])),
            'scene_groups' => $scenes, 'scene_continuity' => $skeleton['scene_beats'],
            'required_shots' => $required, 'previous_shots' => array_slice($completed, -2)];
        $input = ['system_prompt' => $base['system_prompt'] . "\n本次按原剧情顺序生成两个相邻小场景，只返回{\"storyboard\":[...]}。"
            . '按scene_groups的顺序逐场完成，严格逐项使用required_shots的shot_id、scene_ref_id和duration_seconds，不增删、换序、合并场景或挪用另一场的时长。'
            . '每镜包含shot_id、scene_ref_id、scene_name、subject_ref_ids、interior_exterior、visual_description、composition、camera_movement、dialogue、voice_role、speech_type、recommended_duration_seconds。'
            . '每场分别遵守其scene_beat和scene_progress；前场exit与后场entry衔接，后一场承接本次输出中前一场已发生的事实和道具状态，不重演交接、发现等动作。'
            . '前场只演出前场key_events，不能提前完成后场事件；不混淆原文中不同信件、道具、角色身份或知情顺序，不提前收束后续剧情。'
            . '完整保留原始对白和说话主体，不因合并请求删减台词或镜头。对白须为带角色名的字符串，speech_type为character、narration或none。'
            . ShortDramaShotSemantics::INSTRUCTION
            . '仅输出上述分镜字段，不输出素材定义、image_prompt、video_prompt或negative_prompt；visual_description每镜最多120字、composition最多40字、camera_movement最多100字。',
            'content' => self::json($context)];
        if (is_string($messages['_stage_content'] ?? null) && $base['content'] === $messages['_stage_content']) $input['_share_script_context'] = true;
        return $input;
    }

    /** Keep valid scenes from a received response. Invalid or missing scenes use their ordinary local repair. */
    public static function partition(array $reply, array $skeleton, array $group, array $request): array
    {
        $shots = $reply['storyboard'] ?? null;
        $result = array_fill_keys($group, null);
        if (!is_array($shots) || !array_is_list($shots)) return $result;
        $order = [];
        foreach ($group as $index) foreach (array_keys($skeleton['scene_beats'][$index]['shot_durations']) as $offset) {
            $order[] = 's' . ($index + 1) . '_' . ($offset + 1);
        }
        $last = -1;
        foreach ($shots as $shot) {
            if (!is_array($shot)) return $result;
            $position = array_search($shot['shot_id'] ?? null, $order, true);
            // Unknown, duplicate or reordered IDs make scene ownership ambiguous.
            if ($position === false || $position <= $last) return $result;
            $last = $position;
        }
        foreach ($group as $index) {
            $beat = $skeleton['scene_beats'][$index];
            $ids = array_map(static fn($offset) => 's' . ($index + 1) . '_' . ($offset + 1), array_keys($beat['shot_durations']));
            $part = ['storyboard' => array_values(array_filter($shots, static fn($shot) => in_array($shot['shot_id'], $ids, true)))];
            try {
                $normalized = ShortDramaSceneBinding::normalize(array_replace($skeleton, $part), $request);
                $part = ['storyboard' => $normalized['storyboard']];
                ShortDramaTimedScriptGeneration::assertPart($part, $skeleton, $beat, $ids, array_values($beat['shot_durations']), false);
                foreach ($part['storyboard'] as $shot) ShortDramaSameSceneCuts::assertShot($shot, $skeleton, $request);
                $result[$index] = $part;
            } catch (\RuntimeException $error) {
                if (!in_array($error->getCode(), [409, 413, 422], true)) throw $error;
            }
        }
        return $result;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
