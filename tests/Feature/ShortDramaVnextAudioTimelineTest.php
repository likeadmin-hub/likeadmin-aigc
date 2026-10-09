<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\AudioHandoff;
use app\common\service\app\aigc_short_drama\vnext\AudioTimeline;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextAudioTimelineTest extends TestCase
{
    private function fixture(): array
    {
        $document = ContentDocument::normalize(['title' => '声音时间线', 'entities' => [['ref' => 'c1', 'kind' => 'character', 'name' => '陈默'], ['ref' => 'l1', 'kind' => 'location', 'name' => '书房']],
            'scenes' => [['location' => 'l1', 'units' => [['type' => 'dialogue', 'speaker' => 'c1', 'text' => '第一句话，最后一个字。'],
                ['type' => 'dialogue', 'speech_type' => 'narration', 'text' => '故事继续。']]]]], ['revision_id' => 'r1', 'episode_id' => 'e1', 'entity_scope' => 'fixture']);
        $plan = ShotCompiler::compile($document, ['shots' => [['visual_description' => '角色说话', 'refs' => [['unit' => 'u1']]],
            ['visual_description' => '另一画面', 'refs' => [['unit' => 'u1'], ['unit' => 'u2']]]]], 's1');
        $candidate = ['document' => $document, 'shot_plan' => $plan, 'coverage_report' => ['status' => 'ready']];
        $recordings = [];
        foreach (AudioHandoff::segments($candidate) as $i => $segment) $recordings[$segment['audio_segment_id']] = ['id' => $i + 1,
            'duration_seconds' => $i === 0 ? 3.123375 : 2.0, 'checksum' => str_repeat((string)($i + 1), 64), 'trace_hash' => 'trace' . $i, 'content_confirmation' => 'user_confirmed'];
        return [$candidate, $recordings, array_combine(array_column($plan['shots'], 'shot_id'), [2, 4])];
    }

    public function testVoiceAcrossTwoPicturesIsNotRepeatedCutOrSpedUp(): void
    {
        [$candidate, $recordings, $seconds] = $this->fixture(); $timeline = AudioTimeline::compile($candidate, $recordings, $seconds);
        self::assertCount(2, $timeline['audio']); self::assertSame(6.0, $timeline['duration_seconds']);
        self::assertSame(0.0, $timeline['audio'][0]['start_seconds']); self::assertSame(3.123375, $timeline['audio'][0]['end_seconds']);
        self::assertSame(3.123375, $timeline['audio'][1]['start_seconds']); self::assertEqualsWithDelta(5.123375, $timeline['audio'][1]['end_seconds'], 0.000000001);
        self::assertSame(0.0, $timeline['audio'][0]['source_start_seconds']); AudioTimeline::assertCurrent($timeline, $candidate, $recordings);
    }

    public function testInsufficientPicturesBlockBeforeMediaGenerationAndPreserveAudio(): void
    {
        [$candidate, $recordings, $seconds] = $this->fixture(); $seconds = array_fill_keys(array_keys($seconds), 2);
        $this->expectExceptionCode(422); AudioTimeline::compile($candidate, $recordings, $seconds);
    }

    public function testNewRecordingOrTamperedVoiceTimelineNeedsReconfirmation(): void
    {
        [$candidate, $recordings, $seconds] = $this->fixture(); $timeline = AudioTimeline::compile($candidate, $recordings, $seconds);
        $recordings[array_key_first($recordings)]['id'] = 100;
        $this->expectExceptionCode(409); AudioTimeline::assertCurrent($timeline, $candidate, $recordings);
    }

    public function testMissingAudioForeignShotAndInvalidTimingNeverBecomeReady(): void
    {
        foreach (['audio', 'foreign', 'zero', 'nan'] as $case) {
            [$candidate, $recordings, $seconds] = $this->fixture();
            if ($case === 'audio') array_pop($recordings);
            if ($case === 'foreign') $seconds['other-shot'] = 3;
            if ($case === 'zero') $seconds[array_key_first($seconds)] = 0;
            if ($case === 'nan') $seconds[array_key_first($seconds)] = NAN;
            try { AudioTimeline::compile($candidate, $recordings, $seconds); self::fail('Invalid production timing accepted'); }
            catch (\RuntimeException $error) { self::assertContains($error->getCode(), [409, 422]); }
        }
    }
}
