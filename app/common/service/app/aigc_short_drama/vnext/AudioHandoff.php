<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** A dialogue unit owns one complete recording, regardless of how many shots reference it. */
final class AudioHandoff
{
    public const VERSION = 1;

    public static function segments(array $candidate): array
    {
        $document = $candidate['document'] ?? []; $plan = $candidate['shot_plan'] ?? [];
        if (($candidate['coverage_report']['status'] ?? '') !== 'ready') throw new RuntimeException('剧本尚未通过内容核对', 409);
        self::assertIntegrity($document, $plan);
        $units = ContentDocument::units($document); $names = array_column($document['entities'], 'canonical_name', 'entity_id');
        $expected = [];
        foreach ($units as $unit) if ($unit['type'] === 'dialogue') {
            $expected[] = ['audio_segment_id' => 'audio_' . substr(ContentDocument::hash([$document['revision_id'], $unit['unit_id']]), 0, 24),
                'unit_id' => $unit['unit_id'], 'speaker_id' => $unit['speaker_id'], 'speech_type' => $unit['speech_type'],
                'start' => 0, 'end' => mb_strlen($unit['text'], 'UTF-8'), 'text_hash' => $unit['text_hash'],
                'document_revision_id' => $document['revision_id']];
        }
        if (($plan['audio_segments'] ?? null) !== $expected) throw new RuntimeException('声音段与完整对白或说话人不一致，请重新编译', 409);
        $result = [];
        foreach ($expected as $index => $segment) {
            $unit = $units[$segment['unit_id']];
            if ($unit['speaker_id'] !== null && !isset($names[$unit['speaker_id']])) throw new RuntimeException('配音说话人不存在', 409);
            $segment += ['order' => $index + 1, 'speaker_name' => $unit['speaker_id'] === null ? '旁白' : $names[$unit['speaker_id']],
                'text' => $unit['text'], 'document_hash' => $document['content_hash'], 'compiler_version' => self::VERSION,
                'source_links_hash' => ContentDocument::hash($unit['source_links']), 'dependency_versions' => $document['dependency_versions']];
            $segment['input_hash'] = ContentDocument::hash($segment); $result[] = $segment;
        }
        return $result;
    }

    public static function compile(array $candidate, string $id, string $revision, string $confirmedTextHash): array
    {
        if (($candidate['document']['revision_id'] ?? '') !== $revision) throw new RuntimeException('正文版本已变化，请刷新配音原文', 409);
        foreach (self::segments($candidate) as $segment) if ($segment['audio_segment_id'] === $id) {
            if ($confirmedTextHash !== $segment['text_hash']) throw new RuntimeException('请确认音频包含本段完整原文，且由指定人物发声', 422);
            return $segment;
        }
        throw new RuntimeException('声音段不属于当前剧本', 422);
    }

    public static function assertIntegrity(array $document, array $plan): void
    {
        if (empty($document['content_hash']) || empty($plan['content_hash'])) throw new RuntimeException('制作内容版本不完整', 409);
        $content = $document; unset($content['content_hash'], $content['status'], $content['transforms']);
        $shots = $plan; unset($shots['content_hash']);
        if ($document['content_hash'] !== ContentDocument::hash($content) || $plan['content_hash'] !== ContentDocument::hash($shots)) {
            throw new RuntimeException('正文或分镜内容校验失败，已停止制作', 409);
        }
        ShotCompiler::assertCurrent($document, $plan);
        foreach (ContentDocument::units($document) as $unit) if (($unit['text_hash'] ?? '') !== hash('sha256', $unit['text'])) {
            throw new RuntimeException('正文单元校验失败', 409);
        }
    }

    /** Small immutable metadata; complete text and sources remain in the owned canonical job. */
    public static function recordingTrace(array $segment, array $measurement, string $checksum, int $actor): array
    {
        $signed = $segment; unset($signed['input_hash']);
        if (($segment['input_hash'] ?? '') !== ContentDocument::hash($signed) || !preg_match('/^[a-f0-9]{64}$/D', $checksum) || $actor <= 0) throw new RuntimeException('配音制作输入校验失败', 409);
        $duration = $measurement['duration_seconds'] ?? null;
        if (($measurement['version'] ?? '') !== AudioMediaProbe::VERSION || !is_numeric($duration) || !is_finite((float)$duration) || $duration <= 0 || $duration > 7200) throw new RuntimeException('配音缺少有效的实际音长', 422);
        $trace = array_intersect_key($segment, array_flip(['audio_segment_id', 'unit_id', 'speaker_id', 'speech_type', 'start', 'end', 'text_hash',
            'document_revision_id', 'document_hash', 'compiler_version', 'source_links_hash', 'input_hash']));
        $trace += ['source' => 'user_recording', 'content_confirmation' => 'user_confirmed', 'confirmed_by' => $actor,
            'checksum' => $checksum, 'measurement' => $measurement];
        $trace['trace_hash'] = ContentDocument::hash($trace); return $trace;
    }

    public static function matches(array $trace, array $segment, array $asset): bool
    {
        $signed = $trace; unset($signed['trace_hash']);
        return ($trace['trace_hash'] ?? '') === ContentDocument::hash($signed)
            && ($trace['input_hash'] ?? '') === $segment['input_hash']
            && ($trace['content_confirmation'] ?? '') === 'user_confirmed'
            && ($trace['confirmed_by'] ?? 0) === (int)($asset['user_id'] ?? 0)
            && ($trace['checksum'] ?? '') === ($asset['checksum'] ?? null)
            && ($trace['measurement']['version'] ?? '') === AudioMediaProbe::VERSION
            && (float)($trace['measurement']['duration_seconds'] ?? 0) > 0
            && (float)($trace['measurement']['duration_seconds'] ?? 0) === (float)($asset['duration'] ?? 0);
    }
}
