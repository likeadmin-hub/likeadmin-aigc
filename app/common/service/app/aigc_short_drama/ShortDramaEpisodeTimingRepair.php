<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Reallocate a fixed episode budget without rewriting any accepted content. */
final class ShortDramaEpisodeTimingRepair
{
    public static function canReconcile(array $plan, array $request): bool
    {
        if (!ShortDramaDialogueSplit::enabled($request) || !ShortDramaEpisodeDuration::active($request)
            || ShortDramaEpisodeDuration::localRevision($request) || !empty($request['_continuity_review_only'])
            || !empty(ShortDramaEpisodeDuration::policy($request)['timeline_segments'])) return false;
        foreach ($plan['storyboard'] ?? [] as $shot) foreach (['image_url','video_url','image_id','video_id'] as $asset) {
            if (!empty($shot[$asset])) return false;
        }
        return true;
    }

    public static function reconcile(array $plan, array $request, callable $call): array
    {
        if (!self::canReconcile($plan, $request)) return $plan;
        $plan = ShortDramaEpisodeDuration::canReallocateShots($request)
            ? self::adapt($plan, $request, $call)
            : ShortDramaDialogueSplit::adapt($plan, $request, $call, 'review_timing_' . substr(hash('sha256', json_encode($plan['storyboard'])), 0, 16));
        ShortDramaEpisodeDuration::assertPlan($plan, $request);
        return $plan;
    }

    public static function adapt(array $plan, array $request, callable $call): array
    {
        if (!ShortDramaEpisodeDuration::canReallocateShots($request)) return $plan;
        $shots = (array)($plan['storyboard'] ?? []);
        $policy = ShortDramaEpisodeDuration::policy($request);
        $rule = ShortDramaShotDuration::rule($request);
        $needsRepair = false;
        foreach ($shots as $shot) {
            if (!is_array($shot) || !is_string($shot['dialogue'] ?? null)
                || !is_numeric($shot['recommended_duration_seconds'] ?? null)) {
                throw new RuntimeException('分镜对白或时长结构无效，原文保留', 409);
            }
            if (ShortDramaDialogueSplit::requiredSeconds($shot['dialogue']) > (float)$shot['recommended_duration_seconds']
                || ShortDramaDialogueSplit::requiredSeconds($shot['dialogue']) > ShortDramaDialogueSplit::MAX_DIALOGUE_SECONDS
                || !ShortDramaShotDuration::contains($shot['recommended_duration_seconds'], $rule)) $needsRepair = true;
        }
        try { ShortDramaEpisodeDuration::assertPlan($plan, $request); }
        catch (RuntimeException) { $needsRepair = true; }
        if (!$needsRepair) return $plan;

        // Split only genuinely overlong dialogue, retaining every original character.
        // The original request remains immutable; only this temporary allocation is free.
        $free = $request;
        $free['episode_duration_policy'] = array_replace($policy, ['source' => 'default']);
        $candidate = ShortDramaDialogueSplit::adapt($plan, $free, $call,
            'episode_budget_split_' . substr(hash('sha256', json_encode($plan['storyboard'])), 0, 16));
        $targets = [];
        foreach ($candidate['storyboard'] as $shot) {
            $id = (string)($shot['shot_id'] ?? '');
            if ($id === '' || isset($targets[$id])) throw new RuntimeException('分镜编号缺失或重复，无法重排时长', 409);
            $targets[$id] = array_intersect_key($shot, array_flip([
                'shot_id', 'scene_ref_id', 'visual_description', 'action', 'camera_movement', 'dialogue', 'recommended_duration_seconds',
            ]));
            $targets[$id]['minimum_seconds'] = max($rule['min_seconds'], ShortDramaDialogueSplit::requiredSeconds($shot['dialogue']));
        }
        $minimum = array_sum(array_column($targets, 'minimum_seconds'));
        if (!$targets || $minimum > (float)$policy['max_seconds'] + 0.001
            || count($targets) * $rule['max_seconds'] < (float)$policy['min_seconds'] - 0.001) {
            throw new RuntimeException('完整对白与分镜无法放入本集指定总时长，原文已保留；请增加时长或明确调整内容后重试', 409);
        }
        $input = ['system_prompt' => '你是短剧剪辑时长规划师。输入是已经生成的完整有序分镜，只重新分配秒数，不改任何剧情、台词、角色、场景、镜头编号或顺序。'
            . '逐镜minimum_seconds是对白自然语速与停顿所需的最低时间，不代表动作一定能在最低时间完成；结合画面动作和运镜保留合理停留，不能通过加快语速、静帧凑数或删除动作满足总长。'
            . '先给对白超时镜头补足时间，再从有余量的镜头调整；允许跨模型生成分段和场次分配时间，整集总长必须在episode_budget内，每镜不能超过maximum_shot_seconds。'
            . '只返回JSON {"timings":[{"shot_id":"原编号","duration_seconds":0}]}，按原顺序完整覆盖，不输出其他字段。',
            'content' => json_encode(['episode_budget' => $policy, 'maximum_shot_seconds' => $rule['max_seconds'],
                'shots' => array_values($targets)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        $baseContent = $input['content'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                // Include the actual plan in the durable key: retries replay the same
                // receipt; changed content never reuses an unrelated timing patch.
                $key = 'episode_timing_' . substr(hash('sha256', $input['content']), 0, 20);
                $validate = static fn(array $reply) => self::apply($candidate, $reply, $targets, $request);
                $reply = $call($key, $input, 1024 + count($targets) * 60, $validate);
                $candidate = self::apply($candidate, $reply, $targets, $request);
                $candidate['timing_reallocation'] = ['version' => 1, 'attempts' => $attempt + 1,
                    'before_seconds' => array_column($shots, 'recommended_duration_seconds'),
                    'after_seconds' => array_column($candidate['storyboard'], 'recommended_duration_seconds')];
                return $candidate;
            } catch (RuntimeException $error) {
                if (!in_array($error->getCode(), [413, 422], true)) throw $error;
                if ($attempt) throw new RuntimeException('整集时长重新分配未通过核验，已保留内容，请调整时长后重试：' . $error->getMessage(), 409, $error);
                $input['content'] = $baseContent . "\n上次分配未通过：" . $error->getMessage() . '。仅修复时长，原文不可修改。';
            }
        }
        throw new RuntimeException('整集时长核验未完成', 409);
    }

    private static function apply(array $plan, array $reply, array $targets, array $request): array
    {
        $timings = $reply['timings'] ?? null;
        if (array_keys($reply) !== ['timings'] || !is_array($timings) || !array_is_list($timings)
            || array_column($timings, 'shot_id') !== array_column(array_values($targets), 'shot_id')) {
            throw new RuntimeException('时长分配必须按原顺序完整覆盖全部分镜编号', 422);
        }
        $rule = ShortDramaShotDuration::rule($request);
        $durations = []; $minimums = []; $maximums = [];
        foreach ($timings as $index => $timing) {
            if (!is_array($timing) || array_diff(array_keys($timing), ['shot_id', 'duration_seconds'])
                || !is_numeric($timing['duration_seconds'] ?? null)
                || !is_finite((float)$timing['duration_seconds']) || $timing['duration_seconds'] <= 0) {
                throw new RuntimeException('分镜秒数须为有效正数', 422);
            }
            $minimums[$index] = (int)ceil($targets[$timing['shot_id']]['minimum_seconds'] * 1000);
            $maximums[$index] = (int)floor($rule['max_seconds'] * 1000);
            $durations[$index] = max($minimums[$index], min($maximums[$index], (int)round($timing['duration_seconds'] * 1000)));
        }
        // The model supplies editorial preferences; arithmetic constraints belong
        // to the application. Project onto the feasible budget without another
        // paid call, preserving every shot and its complete spoken text.
        $policy = ShortDramaEpisodeDuration::policy($request);
        $total = array_sum($durations);
        $target = max((int)ceil($policy['min_seconds'] * 1000), min((int)floor($policy['max_seconds'] * 1000), $total));
        $delta = $target - $total;
        if ($delta !== 0) {
            $capacity = array_map(static fn($i) => $delta > 0 ? $maximums[$i] - $durations[$i] : $durations[$i] - $minimums[$i], array_keys($durations));
            $available = array_sum($capacity); $remaining = abs($delta);
            if ($available < $remaining) throw new RuntimeException('完整对白无法放入整集时长预算', 422);
            $direction = $delta > 0 ? 1 : -1;
            foreach ($durations as $i => &$duration) {
                $share = (int)floor(abs($delta) * $capacity[$i] / $available);
                $duration += $direction * $share; $capacity[$i] -= $share; $remaining -= $share;
            }
            unset($duration);
            foreach ($durations as $i => &$duration) {
                $share = min($remaining, $capacity[$i]);
                $duration += $direction * $share; $remaining -= $share;
            }
            unset($duration);
        }
        foreach ($durations as $index => $duration) {
            $plan['storyboard'][$index]['recommended_duration_seconds'] = $duration / 1000;
        }
        ShortDramaEpisodeDuration::assertPlan($plan, $request);
        return $plan;
    }
}
