<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** The application owns spoken text and timing; the model only stages the frozen parts. */
final class ShortDramaDialoguePartition
{
    public static function parts(array $shot, array $rule, bool $locked): array
    {
        $text = (string)($shot['dialogue'] ?? '');
        $maximum = (float)$rule['max_seconds'];
        $minimum = (float)$rule['min_seconds'];
        // Keep delimiters, closing quotes and whitespace in their original positions.
        preg_match_all('/.*?[。！？!?；;，,：:\n][”’"\x27）)】\]]*\s*|.+$/us', $text, $matches);
        $sentences = $matches[0];
        if (implode('', $sentences) !== $text) throw new RuntimeException('无法完整定位台词分段，原文保留', 409);
        $chunks = [];
        foreach ($sentences as $sentence) {
            if (ShortDramaDialogueSplit::requiredSeconds($sentence) > $maximum) {
                throw new RuntimeException('单句台词超过镜头时长上限，请为该句补充分句停顿或调整镜头范围；原文保留', 409);
            }
            $last = count($chunks) - 1;
            if ($last >= 0 && ShortDramaDialogueSplit::requiredSeconds($chunks[$last] . $sentence) <= $maximum) $chunks[$last] .= $sentence;
            else $chunks[] = $sentence;
        }
        $durationCount = (int)ceil((float)$shot['recommended_duration_seconds'] / $maximum);
        while (count($chunks) < $durationCount) $chunks[] = '';
        if (count($chunks) < 2 || count($chunks) > 8) throw new RuntimeException('当前镜头无法在2至8段内完整拆分，请调整该镜头；原文保留', 409);
        $durations = array_map(static fn($part) => max($minimum, ShortDramaDialogueSplit::requiredSeconds($part)), $chunks);
        if ($locked || (float)$shot['recommended_duration_seconds'] > array_sum($durations)) {
            $remaining = (float)$shot['recommended_duration_seconds'] - array_sum($durations);
            if ($remaining < -0.001 || $remaining > count($durations) * $maximum - array_sum($durations) + 0.001) {
                throw new RuntimeException('完整台词及分句停顿无法放入已锁定时间段，请调整时间码或时长；原文保留', 409);
            }
            foreach ($durations as &$duration) {
                $extra = min(max(0, $remaining), $maximum - $duration);
                $duration += $extra; $remaining -= $extra;
            }
            unset($duration);
        }
        return array_map(static fn($index) => ['index' => $index, 'dialogue' => $chunks[$index],
            'recommended_duration_seconds' => $durations[$index]], array_keys($chunks));
    }

    public static function generate(array $shot, array $rule, bool $locked, callable $call, string $key): array
    {
        $parts = self::parts($shot, $rule, $locked);
        $input = ['system_prompt' => '把原镜头的画面动作分配到已确定的连续台词片段。不得新增剧情或重复已完成动作。'
            . '台词、片段数量和时长由系统锁定，不要重写台词或秒数。只返回JSON {"segments":[{"index":0,"visual_description":"本段具体画面","composition":"","camera_movement":"","action":"","result":"","sound_effect":""}]}。'
            . '每个index恰好出现一次，保持给定顺序；visual_description必填，其他画面字段可留空。',
            'content' => json_encode(['original_shot' => array_intersect_key($shot, array_flip(['visual_description', 'composition', 'camera_movement', 'action', 'result', 'sound_effect', 'voice_role'])),
                'locked_segments' => $parts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $reply = $call($key . '_partition_' . $attempt, $input, 6000,
                    static fn($reply) => self::fromReply($shot, $reply, $parts, $rule, $locked));
                return self::fromReply($shot, $reply, $parts, $rule, $locked);
            } catch (RuntimeException $error) {
                if (!in_array($error->getCode(), [409, 413, 422], true) || $attempt) throw $error;
                $input['content'] .= "\n只修复画面分段：" . $error->getMessage();
            }
        }
        throw new RuntimeException('画面分段尚未完成，已保留原文', 409);
    }

    private static function fromReply(array $shot, array $reply, array $parts, array $rule, bool $locked): array
    {
        $segments = $reply['segments'] ?? [];
        if (!is_array($segments) || !array_is_list($segments) || count($segments) !== count($parts)) throw new RuntimeException('画面分段数量不完整', 422);
        foreach ($segments as $index => &$segment) {
            if (!is_array($segment) || ($segment['index'] ?? null) !== $index
                || !is_string($segment['visual_description'] ?? null) || trim($segment['visual_description']) === '') throw new RuntimeException('画面分段编号或内容无效', 422);
            $segment = array_intersect_key($segment, array_flip(['visual_description','composition','camera_movement','action','result','sound_effect']));
            foreach (['composition','camera_movement','action','result','sound_effect'] as $field) {
                if (isset($segment[$field]) && !is_string($segment[$field])) throw new RuntimeException('画面字段须为文字', 422);
                $segment[$field] = $segment[$field] ?? '';
            }
            $segment['dialogue'] = $parts[$index]['dialogue'];
            $segment['recommended_duration_seconds'] = $parts[$index]['recommended_duration_seconds'];
        }
        unset($segment);
        return ShortDramaDialogueSplit::validate($shot, ['segments' => $segments], $rule, $locked);
    }
}
