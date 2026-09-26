<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaContinuityPatch as Patch;
use PHPUnit\Framework\TestCase;

class ShortDramaContinuityPatchTest extends TestCase
{
    private function plan(): array
    {
        return ['script_lines' => ['甲否认婚约。'], 'subjects' => [['id' => 'p1', 'name' => '玉佩']],
            'locations' => [['id' => 'l1', 'name' => '大厅']],
            'storyboard' => [['shot_id' => '1', 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['p1'],
                'visual_description' => '原画面', 'video_url' => 'retained']]];
    }
    private function patch(): array
    {
        return ['entity_id_remaps' => [['collection' => 'subjects', 'from' => 'p1', 'to' => 'p2', 'name' => '玉佩']],
            'shot_insertions' => [['after_shot_id' => '1', 'source_line_index' => 0, 'source_quote' => '甲否认婚约。',
                'shot' => ['shot_id' => 'repair_1', 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['p2'],
                    'visual_description' => '甲否认婚约。', 'dialogue' => '', 'voice_role' => '', 'speech_type' => 'none', 'recommended_duration_seconds' => 4]]]];
    }
    public function testOnlyAddedShotsAndLocalEntityReferencesChange(): void
    {
        $original = $this->plan(); $result = Patch::apply($original, $this->patch(), [], []);
        $expected = $original['storyboard'][0]; $expected['subject_ref_ids'] = ['p2'];
        self::assertSame($expected, $result['plan']['storyboard'][0]);
        self::assertSame($original['script_lines'], $result['plan']['script_lines']);
        self::assertSame('玉佩', $result['plan']['subjects'][0]['name']);
        self::assertSame(['repair_1'], $result['added_ids']);
        self::assertSame($this->plan(), $original);
    }
    public function testCannotReusePreviousEpisodeId(): void
    {
        $this->expectExceptionCode(422);
        Patch::apply($this->plan(), $this->patch(), ['continuity' => ['state' => ['p2:item' => '古镜']]], []);
    }
    public function testCannotInventSourceOrOverwriteShotOrDuration(): void
    {
        foreach (['source', 'id', 'duration', 'field', 'quote'] as $case) {
            $patch = $this->patch();
            if ($case === 'source') $patch['shot_insertions'][0]['source_quote'] = '新编剧情';
            if ($case === 'id') $patch['shot_insertions'][0]['shot']['shot_id'] = '1';
            if ($case === 'duration') $patch['shot_insertions'][0]['shot']['recommended_duration_seconds'] = 90;
            if ($case === 'field') $patch['story_outline'] = '改写';
            if ($case === 'quote') $patch['shot_insertions'][0]['shot']['visual_description'] = '无关画面';
            try { Patch::apply($this->plan(), $patch, [], []); self::fail($case); }
            catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
        }
    }
    public function testEmptyPatchDoesNotPretendToRepair(): void
    {
        $result = Patch::apply($this->plan(), ['entity_id_remaps' => [], 'shot_insertions' => []], [], []);
        self::assertFalse($result['changed']); self::assertSame($this->plan(), $result['plan']);
    }
    public function testLiteralQuoteCannotBypassMeaningCheck(): void
    {
        $review = ['summary' => '甲开门', 'changes' => [['entity_id' => 'p1', 'field' => 'state', 'before' => null,
            'after' => '开门', 'shot_id' => '1', 'quote' => '原画面']], 'hooks' => [], 'warnings' => []];
        $calls = 0;
        try {
            \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($this->plan(), [], 1,
                static function () use ($review, &$calls) { $calls++; return $review; },
                static function ($audit) { Patch::assertMeaning($audit, ['checks' => [
                    ['collection' => 'changes', 'index' => 0, 'supported' => false, 'reason' => '画面没有开门']]]); });
            self::fail('A literal but unrelated quote must not pass');
        } catch (\RuntimeException $error) {
            self::assertSame(460, $error->getCode()); self::assertSame(3, $calls);
        }
    }
    public function testMeaningChecksMustCoverEveryFactExactlyOnce(): void
    {
        $review = ['changes' => [['after' => '事实']], 'hooks' => []];
        Patch::assertMeaning($review, ['checks' => [['collection' => 'changes', 'index' => 0, 'supported' => true, 'reason' => '明确支持']]]);
        foreach ([[], [['collection' => 'changes', 'index' => 1, 'supported' => true, 'reason' => '错误索引']]] as $checks) {
            try { Patch::assertMeaning($review, ['checks' => $checks]); self::fail('Incomplete evidence check'); }
            catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
        }
    }
}
