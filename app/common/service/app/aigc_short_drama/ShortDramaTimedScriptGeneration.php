<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Budget-first generation, using the existing durable provider-unit callback. */
final class ShortDramaTimedScriptGeneration
{
    public static function generate(array $request, array $messages, callable $call, ?callable $progress): array
    {
        $policy = ShortDramaEpisodeDuration::policy($request);
        $rule = ShortDramaShotDuration::rule($request);
        $base = ['system_prompt' => $messages['system_prompt'] . "\n" . ShortDramaEpisodeDuration::instruction($request),
            'content' => $messages['_stage_content'] ?? $messages['content']];
        $skeletonInput = $base;
        $skeletonInput['system_prompt'] .= '\n本阶段仅生成骨架JSON：title、type_judgement、core_theme、story_outline、script_lines、series_bible、subjects、locations、art_style、scene_beats。'
            . '沿用稳定主体和场景id。scene_beats按剧情顺序，每项为scene_ref_id、goal、entry、exit、duration_seconds、shot_durations（每张卡片秒数数组）、key_events（必须保留的具体事件或台词数组）。'
            . '每场shot_durations合计等于duration_seconds，整集合计满足时间策略。镜头数量按内容决定，不使用固定数量档位。每个片段时长遵守任务范围。'
            . '时间码存在时，每条scene_beat严格对应一个时间段，时长完全一致；不要跨段合并。每场最多40个片段，总场次最多24。不要返回storyboard。';
        $skeleton = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $skeleton = $call('timed_skeleton_' . $attempt, $skeletonInput, 8192);
                self::assertSkeleton($skeleton, $request);
                break;
            } catch (RuntimeException $e) {
                if ($attempt === 2 || !in_array($e->getCode(), [413, 422], true)) throw $e;
                $skeletonInput['content'] = $base['content'] . "\n骨架修复：" . $e->getMessage()
                    . "\n保留已确定的角色、场景和事实，仅修复不合格预算或结构，返回完整骨架。已有骨架=" . self::json($skeleton);
            }
        }
        if ($progress) $progress('stage', ['status' => 'running', 'progress' => 25, 'current_step' => '已规划各场时长，正在生成分镜']);
        $shots = [];
        $contentRepairs = 0;
        foreach ($skeleton['scene_beats'] as $sceneIndex => $beat) {
            $sceneRepairUsed = false;
            $durations = array_values($beat['shot_durations']);
            for ($offset = 0; $offset < count($durations); $offset += 4) {
                $partDurations = array_slice($durations, $offset, 4);
                $ids = array_map(static fn($n) => 's' . ($sceneIndex + 1) . '_' . ($offset + $n + 1), array_keys($partDurations));
                $input = $base;
                $input['system_prompt'] .= '\n本阶段仅返回{"storyboard":[...]}，每项包含shot_id、scene_ref_id、subject_ref_ids、visual_description、composition、camera_movement、dialogue、voice_role、speech_type、recommended_duration_seconds。'
                    . '严格采用required_shots的ID及时长，不重新决定数量。覆盖当前片段应承载的key_events并保持前后衔接，不重复其他分段事件。'
                    . '对白务必能在片段内自然说完，保留停顿；speech_type为character、narration或none。voice_role写真实说话角色，静默留空。';
                $input['content'] = self::json([
                    'creative_context' => $base['content'],
                    'locked_plan' => array_intersect_key($skeleton, array_flip(['title', 'story_outline', 'series_bible', 'subjects', 'locations', 'art_style'])),
                    'scene_continuity' => $skeleton['scene_beats'], 'scene_beat' => $beat,
                    'required_shots' => array_map(static fn($id, $duration) => ['shot_id' => $id, 'duration_seconds' => $duration], $ids, $partDurations),
                    'shot_offset' => $offset, 'previous_shots' => array_slice($shots, -2),
                ]);
                $part = [];
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    try {
                        $part = $call('timed_scene_' . ($sceneIndex + 1) . '_' . ($offset + 1) . ($attempt ? '_repair' : ''), $input, 1800 + count($ids) * 750);
                        self::assertPart($part, $skeleton, $beat, $ids, $partDurations);
                        break;
                    } catch (RuntimeException $e) {
                        if ($attempt || $sceneRepairUsed || !in_array($e->getCode(), [413, 422], true)) throw $e;
                        $sceneRepairUsed = true;
                        $contentRepairs++;
                        $input['content'] .= "\n仅修复本段：" . $e->getMessage() . "\n原返回=" . self::json($part);
                    }
                }
                array_push($shots, ...$part['storyboard']);
                if ($progress) $progress('stage', ['status' => 'running', 'progress' => min(78, 25 + (int)(50 * ($sceneIndex + 1) / count($skeleton['scene_beats']))),
                    'current_step' => '已生成' . count($shots) . '个分镜，约' . round(array_sum(array_column($shots, 'recommended_duration_seconds'))) . '秒']);
            }
        }
        $skeleton['storyboard'] = $shots;
        $skeleton['episodes'] = [];
        $skeleton['timing_diagnostics'] = ['version' => ShortDramaEpisodeDuration::VERSION, 'content_repairs' => $contentRepairs,
            'total_seconds' => array_sum(array_column($shots, 'recommended_duration_seconds')), 'policy' => $policy];
        ShortDramaEpisodeDuration::assertPlan($skeleton, $request);
        return $skeleton;
    }

    public static function assertSkeleton(array $plan, array $request): void
    {
        foreach (['title', 'story_outline', 'subjects', 'locations', 'scene_beats'] as $key) {
            if (empty($plan[$key])) throw new RuntimeException('骨架缺少必要内容：' . $key, 422);
        }
        $beats = $plan['scene_beats'];
        if (!is_array($beats) || count($beats) > 24) throw new RuntimeException('骨架场次数量超出处理范围', 422);
        $timeline = ShortDramaEpisodeDuration::policy($request)['timeline_segments'] ?? [];
        if ($timeline && count($timeline) !== count($beats)) throw new RuntimeException('骨架场次必须逐条对应用户时间码', 422);
        $shots = [];
        $rule = ShortDramaShotDuration::rule($request);
        foreach ($beats as $index => $beat) {
            if (!in_array($beat['scene_ref_id'] ?? '', array_column($plan['locations'], 'id'), true)
                || empty($beat['goal']) || empty($beat['entry']) || empty($beat['exit']) || empty($beat['key_events'])
                || !is_array($beat['shot_durations'] ?? null) || !$beat['shot_durations'] || count($beat['shot_durations']) > 40) {
                throw new RuntimeException('场景骨架、关键事件或分镜时间预算缺失', 422);
            }
            foreach ($beat['shot_durations'] as $duration) {
                if (!is_numeric($duration) || $duration <= 0 || !is_finite((float)$duration)
                    || (!$timeline && !ShortDramaShotDuration::contains($duration, $rule)) || $duration > $rule['max_seconds']) {
                    throw new RuntimeException('骨架分镜时间预算不符合片段范围', 422);
                }
                $shots[] = ['recommended_duration_seconds' => $duration];
            }
            $sum = array_sum($beat['shot_durations']);
            if (abs($sum - (float)($beat['duration_seconds'] ?? 0)) > 0.001) throw new RuntimeException('场次时长与片段合计不一致', 422);
            if ($timeline && abs($sum - (float)$timeline[$index]['duration_seconds']) > 0.001) throw new RuntimeException('场次时长与用户时间码不一致', 422);
        }
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => $shots], $request);
    }

    public static function assertPart(array $part, array $plan, array $beat, array $ids, array $durations): void
    {
        $shots = $part['storyboard'] ?? [];
        if (!is_array($shots) || count($shots) !== count($ids)) throw new RuntimeException('本段分镜数量不完整', 422);
        foreach (array_values($shots) as $index => $shot) {
            if (($shot['shot_id'] ?? '') !== $ids[$index] || ($shot['scene_ref_id'] ?? '') !== $beat['scene_ref_id']
                || empty($shot['visual_description']) || !is_array($shot['subject_ref_ids'] ?? null)
                || array_diff($shot['subject_ref_ids'], array_column($plan['subjects'], 'id'))
                || abs((float)($shot['recommended_duration_seconds'] ?? 0) - $durations[$index]) > 0.001) {
                throw new RuntimeException('本段标识、主体场景绑定或时长与已确认预算不一致', 422);
            }
            $dialogue = $shot['dialogue'] ?? '';
            $text = is_string($dialogue) ? $dialogue : self::json($dialogue);
            // A deliberately generous guard catches impossible delivery, not acting style.
            $characters = mb_strlen(preg_replace('/[\s\p{P}]/u', '', $text) ?? $text, 'UTF-8');
            if ($characters > $durations[$index] * 8) throw new RuntimeException('本段对白过密，请保留关键含义并缩短台词，为动作和停顿留出时间', 422);
        }
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
