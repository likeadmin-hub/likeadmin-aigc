<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Reallocate a fixed episode budget without rewriting any accepted content. */
final class ShortDramaEpisodeTimingRepair
{
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
                || !ShortDramaShotDuration::contains($shot['recommended_duration_seconds'], $rule)) $needsRepair = true;
        }
        try { ShortDramaEpisodeDuration::assertPlan($plan, $request); }
        catch (RuntimeException) { $needsRepair = true; }
        if (!$needsRepair) return $plan;

        // Split only genuinely overlong dialogue, retaining every original character.
        // The original request remains immutable; only this temporary allocation is free.
        $free = $request;
        $free['episode_duration_policy'] = array_replace($policy, ['source' => 'default']);
        $candidate = ShortDramaDialogueSplit::adapt($plan, $free, $call, 'episode_budget_split');
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
        foreach ($timings as $index => $timing) {
            if (!is_array($timing) || array_diff(array_keys($timing), ['shot_id', 'duration_seconds'])
                || !ShortDramaShotDuration::contains($timing['duration_seconds'] ?? null, $rule)
                || (float)$timing['duration_seconds'] < $targets[$timing['shot_id']]['minimum_seconds']) {
                throw new RuntimeException('分镜秒数不足以容纳完整对白或超出片段范围', 422);
            }
            $plan['storyboard'][$index]['recommended_duration_seconds'] = (float)$timing['duration_seconds'];
        }
        ShortDramaEpisodeDuration::assertPlan($plan, $request);
        return $plan;
    }
}
