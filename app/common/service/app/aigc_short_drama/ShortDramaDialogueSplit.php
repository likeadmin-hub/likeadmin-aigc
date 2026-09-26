<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Bounded editorial repair; provider calls use the existing durable unit callback. */
final class ShortDramaDialogueSplit
{
    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= 2;
    }

    public static function instruction(array $rule = []): string
    {
        $rule = ShortDramaShotDuration::modelRule($rule);
        return '完整台词时长规则：先结合对白自然语速、动作、运镜和必要停顿估时，不以空白分镜初始秒数判断拆镜。'
            . '若所需时间在单镜上限' . $rule['max_seconds'] . '秒内，只适配时长；超过上限才按完整句意、说话轮次或动作转折拆为连续镜头。'
            . '不删、缩写、换序或补造台词，不靠慢放、静止画面凑时长。每段保留真实说话主体，人物、场景、动作前后连续。'
            . '明确时间码或固定时长内的拆镜必须保持原时间段总长；放不下时报告冲突，不挪用其他镜头时间。'
            . '在剧本/分镜规划阶段完成拆镜并分配唯一编号，再准备对应分镜图和视频；已确认图片或视频引用不能事后静默拆分。';
    }

    public static function requiredSeconds(string $dialogue): float
    {
        $count = mb_strlen(preg_replace('/[\s\p{P}]/u', '', $dialogue) ?? $dialogue, 'UTF-8');
        return $count ? ceil($count / 6) + 1 : 0;
    }

    public static function adapt(array $plan, array $request, callable $call, string $unit): array
    {
        if (!self::enabled($request)) return $plan;
        $rule = ShortDramaShotDuration::rule($request);
        $locked = in_array(ShortDramaEpisodeDuration::policy($request)['source'] ?? '', ['user', 'timeline'], true)
            || ShortDramaEpisodeDuration::localRevision($request);
        $output = [];
        foreach ((array)($plan['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot) || !is_string($shot['dialogue'] ?? '')) throw new RuntimeException('分镜对白结构无效，原文保留', 409);
            $duration = $shot['recommended_duration_seconds'] ?? null;
            if (!is_numeric($duration) || !is_finite((float)$duration) || $duration <= 0) throw new RuntimeException('分镜时长无效，原文保留', 409);
            $needed = max((float)$duration, self::requiredSeconds($shot['dialogue'] ?? ''));
            if ($locked && $needed > (float)$duration) throw new RuntimeException('完整台词无法放入已锁定时间段，请调整时间码或时长；原文保留', 409);
            if ($needed <= $rule['max_seconds']) {
                $shot['recommended_duration_seconds'] = max($rule['min_seconds'], $needed);
                $output[] = $shot;
                continue;
            }
            // Local edits must not expand the selected target or change other IDs.
            if (ShortDramaEpisodeDuration::localRevision($request)) throw new RuntimeException('局部修改需要增加分镜，请明确重新规划该场景后继续；原文保留', 409);
            if ($needed > $rule['max_seconds'] * 8) throw new RuntimeException('单镜内容过长，请按场景缩小处理范围；原文保留', 409);
            $input = ['system_prompt' => self::instruction($rule)
                . '只返回 {"segments":[{"dialogue":"","visual_description":"","composition":"","camera_movement":"","recommended_duration_seconds":0}]}。'
                . '仅拆解给定原镜头，不改其他镜头。每段 dialogue 是原 dialogue 的连续原文切片，逐段拼接必须与原文逐字相等（含标点和角色前缀），不要重复前缀。'
                . '说话主体由原 voice_role 继承。按语义断句，不在词语中间切开。画面分配原动作，后段承接前段状态，不重复已完成动作、不新增剧情。最多8段。'
                . ($locked ? '各段秒数合计必须精确等于原镜头秒数。' : '按内容自然估时，勿将每段都填满上限。'),
                'content' => json_encode(['original_shot' => $shot, 'shot_duration_rule' => ShortDramaShotDuration::modelRule($rule)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $reply = [];
                try {
                    $reply = $call($unit . '_dialogue_split_' . $index . '_' . $attempt, $input, 6000);
                    $segments = self::validate($shot, $reply, $rule, $locked);
                    break;
                } catch (RuntimeException $error) {
                    if (!in_array($error->getCode(), [409, 422], true)) throw $error;
                    if ($attempt) throw new RuntimeException('拆镜未通过完整性校验，原文保留，请调整后重试', 409, $error);
                    $input['content'] .= "\n上次分段未通过：" . $error->getMessage()
                        . "\n仅修复分段，原镜头是唯一事实依据。上次返回=" . json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
            }
            // These prompts describe the unsplit shot. The normal downstream builder regenerates them.
            unset($shot['image_prompt'], $shot['video_prompt'], $shot['negative_prompt']);
            foreach ($segments as $number => $segment) {
                $output[] = array_replace($shot, $segment, [
                    'shot_id' => (string)$shot['shot_id'] . '_p' . ($number + 1),
                    'split_source_shot_id' => (string)$shot['shot_id'],
                ]);
            }
        }
        $ids = array_column($output, 'shot_id');
        if (count($ids) !== count(array_unique($ids))) throw new RuntimeException('拆镜编号冲突，原文保留', 409);
        $plan['storyboard'] = $output;
        return $plan;
    }

    public static function validate(array $shot, array $reply, array $rule, bool $locked): array
    {
        $parts = $reply['segments'] ?? null;
        if (!is_array($parts) || !array_is_list($parts) || count($parts) < 2 || count($parts) > 8) throw new RuntimeException('拆镜须返回2至8个连续镜头', 409);
        $dialogue = ''; $total = 0;
        foreach ($parts as $part) {
            if (!is_array($part) || array_diff(array_keys($part), ['dialogue','visual_description','composition','camera_movement','recommended_duration_seconds'])
                || !is_string($part['dialogue'] ?? null) || !is_string($part['visual_description'] ?? null) || trim($part['visual_description']) === ''
                || !is_string($part['composition'] ?? '') || !is_string($part['camera_movement'] ?? '')
                || !ShortDramaShotDuration::contains($part['recommended_duration_seconds'] ?? null, $rule)) throw new RuntimeException('拆镜字段或时长不合法', 409);
            if (self::requiredSeconds($part['dialogue']) > (float)$part['recommended_duration_seconds']) throw new RuntimeException('拆分后的完整台词仍超过片段时长', 409);
            $dialogue .= $part['dialogue']; $total += (float)$part['recommended_duration_seconds'];
        }
        if ($dialogue !== (string)($shot['dialogue'] ?? '')) throw new RuntimeException('拆镜必须逐字保留全部台词和顺序', 409);
        if ($locked && abs($total - (float)$shot['recommended_duration_seconds']) > 0.001) throw new RuntimeException('拆镜不得改变锁定时间段的总长', 409);
        return $parts;
    }
}
