<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** V3 single-film engine, also used by each episode. No DB/provider access here. */
final class ShortDramaScriptGeneration
{
    public const VERSION = 3;

    public static function generate(array $request, array $model, array $messages, callable $provider, ?callable $progress = null): array
    {
        $receipts = [];
        $calls = 0;
        $reservedOutput = 0;
        $call = static function (string $key, array $input, int $desired = 8192) use (&$receipts, &$calls, &$reservedOutput, &$model, $provider): array {
            if (++$calls > 48) throw new RuntimeException('本集已达到自动处理上限，已保留完成内容，请缩小生成范围', 429);
            $budget = ShortDramaPlanningBudget::stage($input['system_prompt'] . $input['content'], $model, 'script', $desired);
            $reservedOutput += $budget['max_tokens'];
            if ($reservedOutput > 192000) throw new RuntimeException('本集自动生成预算已达上限，已完成内容保留，请缩小范围后继续', 429);
            $receipt = $provider('v3_' . $key, $input, $budget, $model);
            $model = (array)($receipt['model'] ?? $model);
            $receipts[$key] = (array)($receipt['result'] ?? []);
            return ShortDramaStructuredResponse::decode($receipts[$key]);
        };
        $duration = (int)($request['target_duration_seconds'] ?? 0);
        $budget = ShortDramaPlanningBudget::stage($messages['system_prompt'] . $messages['content'], $model, 'script', 12000);
        // Local revisions must never fall back to rewriting the entire film.
        $revision = !empty($request['revision_message']) || !empty($request['revision_target']);
        if ($revision || ($budget['max_tokens'] >= 6000 && $duration < 120)) {
            try {
                $payload = $call('script', $messages, 12000);
                if (!$revision) self::assertPlan($payload);
                return self::result($payload, $receipts, $model);
            } catch (RuntimeException $error) {
                if (!in_array($error->getCode(), [413, 422], true)) throw $error;
                if ($revision) {
                    $repair = $messages;
                    $repair['content'] .= "\n上次输出结构不完整。只返回当前 revision_target 所需的有效 JSON 字段，不得扩大修改范围。";
                    return self::result($call('revision_format_repair', $repair, 12000), $receipts, $model);
                }
                // One bounded repair for formatting/required fields. A length
                // failure goes straight to smaller, independently durable units.
                if ($error->getCode() === 422) {
                    $repair = $messages;
                    $repair['content'] .= "\n上次结果缺失或格式不正确：" . $error->getMessage() . '。仅返回完整 JSON，不要说明或 Markdown。';
                    try {
                        $payload = $call('format_repair', $repair, 12000);
                        self::assertPlan($payload);
                        return self::result($payload, $receipts, $model);
                    } catch (RuntimeException $repairError) {
                        if (!in_array($repairError->getCode(), [413, 422], true)) throw $repairError;
                    }
                }
            }
        }
        if ($progress) $progress('stage', ['status' => 'running', 'progress' => 25, 'current_step' => '按场景分段生成，已完成内容将保留']);
        $skeletonMessages = $messages;
        $skeletonMessages['system_prompt'] .= "\n本次是分段生成的骨架步骤，覆盖完整输出要求：暂不写详细分镜。保留全部用户剧情约束，返回 title、type_judgement、core_theme、story_outline、script_lines、subjects、locations、art_style，以及 scene_beats 数组。scene_beats 每项含 scene_ref_id（必须引用 locations.id）、goal（本场完整剧情和关键台词）、entry（开场状态）、exit（结束状态）、shot_count（本场计划镜头数量，1至40）。按剧情顺序覆盖整集，最多24场；不要缩写掉原文事件。storyboard 暂为空。";
        $skeleton = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $skeleton = $call('skeleton' . ($attempt ? '_repair' : ''), $skeletonMessages, 8192);
                self::assertSkeleton($skeleton);
                break;
            } catch (RuntimeException $error) {
                if ($attempt || !in_array($error->getCode(), [413, 422], true)) throw $error;
                $skeletonMessages['content'] .= "\n上次骨架未通过：" . $error->getMessage() . '。修复结构，保留完整剧情。';
            }
        }
        $shots = [];
        foreach ($skeleton['scene_beats'] as $sceneIndex => $beat) {
            $sceneShots = [];
            $count = (int)$beat['shot_count'];
            $expand = function (int $start, int $size) use (&$expand, &$sceneShots, &$shots, $beat, $sceneIndex, $skeleton, $messages, $call, $progress): void {
                $input = $messages;
                $input['system_prompt'] .= "\n本次只生成当前场景指定范围的分镜，返回 {\"storyboard\":[...]}，不要重复人物和场景列表，不生成其他场景。必须按 scene_beat.goal/entry/exit 衔接剧情。每镜头必须包含 shot_id、scene_ref_id、subject_ref_ids、visual_description、dialogue、voice_role、speech_type、recommended_duration_seconds，沿用原结构其他镜头字段。不得补造未发生事件或提前跳到本场结尾。";
                $input['content'] .= "\n分段任务=" . json_encode([
                    'locked_plan' => array_intersect_key($skeleton, array_flip(['title', 'story_outline', 'subjects', 'locations', 'art_style'])),
                    'scene_beat' => $beat, 'shot_start' => $start, 'shot_count' => $size,
                    'required_shot_ids' => array_map(static fn($n) => 's' . ($sceneIndex + 1) . '_' . $n, range($start, $start + $size - 1)),
                    'previous_shots' => array_slice(array_merge($shots, $sceneShots), -2),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $key = 'scene_' . ($sceneIndex + 1) . '_' . $start . '_' . $size;
                try {
                    $part = $call($key, $input, 1600 + $size * 650);
                    self::assertShots($part, $skeleton, $beat, $sceneIndex, $start, $size);
                } catch (RuntimeException $error) {
                    if (!in_array($error->getCode(), [413, 422], true)) throw $error;
                    if ($size > 1) {
                        $left = intdiv($size, 2); $expand($start, $left); $expand($start + $left, $size - $left); return;
                    }
                    $input['content'] .= "\n修复本镜头：" . $error->getMessage();
                    $part = $call($key . '_repair', $input, 2400);
                    self::assertShots($part, $skeleton, $beat, $sceneIndex, $start, $size);
                }
                foreach ($part['storyboard'] as $shot) $sceneShots[] = $shot;
                if ($progress) $progress('stage', ['status' => 'running', 'progress' => min(75, 30 + $sceneIndex), 'current_step' => '正在生成第' . ($sceneIndex + 1) . '场，已保留' . (count($shots) + count($sceneShots)) . '个分镜']);
            };
            for ($start = 1; $start <= $count; $start += 4) $expand($start, min(4, $count - $start + 1));
            $shots = array_merge($shots, $sceneShots);
        }
        $skeleton['storyboard'] = $shots;
        $skeleton['episodes'] = [];
        self::assertPlan($skeleton);
        return self::result($skeleton, $receipts, $model);
    }

    private static function assertPlan(array $plan): void
    {
        foreach (['title', 'story_outline', 'subjects', 'locations', 'storyboard'] as $key) {
            if (empty($plan[$key])) throw new RuntimeException('剧本缺少必要内容：' . $key, 422);
        }
    }

    private static function assertSkeleton(array $plan): void
    {
        foreach (['title', 'story_outline', 'subjects', 'locations', 'scene_beats'] as $key) {
            if (empty($plan[$key])) throw new RuntimeException('骨架缺少必要内容：' . $key, 422);
        }
        if (!is_array($plan['scene_beats']) || count($plan['scene_beats']) > 24) throw new RuntimeException('场景数量超出单次分段范围，请缩小范围', 422);
        foreach ($plan['scene_beats'] as $beat) {
            if (!is_array($beat) || !in_array($beat['scene_ref_id'] ?? '', array_column($plan['locations'], 'id'), true)
                || empty($beat['goal']) || empty($beat['entry']) || empty($beat['exit'])
                || !is_int($beat['shot_count'] ?? null) || $beat['shot_count'] < 1 || $beat['shot_count'] > 40) {
                throw new RuntimeException('场景骨架、衔接或引用不完整', 422);
            }
        }
    }

    private static function assertShots(array $part, array $plan, array $beat, int $scene, int $start, int $count): void
    {
        $shots = $part['storyboard'] ?? [];
        if (!is_array($shots) || count($shots) !== $count) throw new RuntimeException('本段分镜数量不完整', 422);
        foreach (array_values($shots) as $index => $shot) {
            if (!is_array($shot) || ($shot['shot_id'] ?? '') !== 's' . ($scene + 1) . '_' . ($start + $index)
                || ($shot['scene_ref_id'] ?? '') !== $beat['scene_ref_id'] || empty($shot['visual_description'])
                || !is_array($shot['subject_ref_ids'] ?? null)
                || array_diff($shot['subject_ref_ids'], array_column($plan['subjects'], 'id'))
                || !ShortDramaShotDuration::contains($shot['recommended_duration_seconds'] ?? null)) {
                throw new RuntimeException('分镜标识、素材引用或正文无效，单镜头时长须为 4-15 秒', 422);
            }
        }
    }

    private static function result(array $payload, array $receipts, array $model): array
    {
        return ['payload' => $payload, 'receipts' => array_values($receipts), 'model' => $model];
    }
}
