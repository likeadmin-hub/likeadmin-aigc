<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Preserve each whole recording at its natural speed, including its tail and internal pauses. */
final class AudioTimeline
{
    public const VERSION = 1;

    public static function compile(array $candidate, array $recordings, array $shotSeconds): array
    {
        $segments = AudioHandoff::segments($candidate); $plan = $candidate['shot_plan'];
        $shots = []; $windows = []; $cursor = 0.0; $ids = array_column($plan['shots'], 'shot_id');
        if (array_diff(array_keys($shotSeconds), $ids)) throw new RuntimeException('画面时长包含其他版本的分镜', 409);
        foreach ($plan['shots'] as $shot) {
            $seconds = $shotSeconds[$shot['shot_id']] ?? null;
            if (!is_numeric($seconds) || !is_finite((float)$seconds) || (float)$seconds <= 0 || (float)$seconds > 7200) throw new RuntimeException('请为每个分镜设置有效画面时长', 422);
            $seconds = (float)$seconds;
            $row = ['shot_id' => $shot['shot_id'], 'order' => $shot['order'], 'start_seconds' => $cursor, 'end_seconds' => $cursor + $seconds, 'duration_seconds' => $seconds];
            foreach ($shot['unit_refs'] as $ref) $windows[$ref['unit_id']][] = $row;
            $shots[] = $row; $cursor += $seconds;
            if ($cursor > 86400) throw new RuntimeException('单集制作时间线超过一天，请检查画面时长', 413);
        }
        $audio = []; $audioEnd = 0.0;
        foreach ($segments as $segment) {
            $recording = $recordings[$segment['audio_segment_id']] ?? [];
            $duration = $recording['duration_seconds'] ?? null;
            if (empty($recording['id']) || empty($recording['checksum']) || empty($recording['trace_hash'])
                || ($recording['content_confirmation'] ?? '') !== 'user_confirmed'
                || !is_numeric($duration) || !is_finite((float)$duration) || $duration <= 0 || $duration > 7200) throw new RuntimeException('请先完成本集每段对白和旁白的配音', 409);
            $ranges = $windows[$segment['unit_id']] ?? [];
            if (!$ranges) throw new RuntimeException('对白没有画面承载', 409);
            // A recording may span consecutive visual shots, without being cut or restarted at each reference.
            $first = $ranges[0]; $last = end($ranges);
            $from = max($audioEnd, $first['start_seconds']); $to = $from + (float)$duration;
            if ($to - $last['end_seconds'] > 0.000001) throw new RuntimeException('配音 ' . $segment['order'] . ' 超出对应画面时间，请延长相关分镜；系统不会裁掉尾句', 422);
            $audio[] = ['audio_segment_id' => $segment['audio_segment_id'], 'unit_id' => $segment['unit_id'], 'speaker_id' => $segment['speaker_id'],
                'speaker_name' => $segment['speaker_name'], 'speech_type' => $segment['speech_type'], 'text_hash' => $segment['text_hash'], 'input_hash' => $segment['input_hash'],
                'asset_id' => (int)$recording['id'], 'checksum' => $recording['checksum'], 'recording_trace_hash' => $recording['trace_hash'],
                'start_seconds' => $from, 'end_seconds' => $to, 'source_start_seconds' => 0.0, 'duration_seconds' => (float)$duration,
                'first_shot_id' => $first['shot_id'], 'last_shot_id' => $last['shot_id']];
            $audioEnd = $to;
        }
        $result = ['version' => self::VERSION, 'document_hash' => $candidate['document']['content_hash'], 'shot_hash' => $plan['content_hash'],
            'placement_policy' => 'first_reference_after_previous_recording', 'duration_seconds' => $cursor, 'shots' => $shots, 'audio' => $audio];
        $result['timeline_hash'] = ContentDocument::hash($result); return $result;
    }

    public static function assertCurrent(array $timeline, array $candidate, array $recordings): void
    {
        $hash = $timeline['timeline_hash'] ?? ''; $signed = $timeline; unset($signed['timeline_hash']);
        if ($hash !== ContentDocument::hash($signed)) throw new RuntimeException('制作时间线校验失败', 409);
        $seconds = array_column($timeline['shots'], 'duration_seconds', 'shot_id');
        if (self::compile($candidate, $recordings, $seconds)['timeline_hash'] !== $hash) throw new RuntimeException('制作正文、配音或画面时长已变化，请重新确认制作计划', 409);
    }
}
