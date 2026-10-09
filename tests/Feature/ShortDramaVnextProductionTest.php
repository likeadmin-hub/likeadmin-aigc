<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\vnext\ProductionCompiler;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextProductionTest extends TestCase
{
    private function fixture(): array
    {
        $document = ContentDocument::normalize(['title' => '长对白', 'scenes' => [['location' => 'l1', 'units' => [
            ['type' => 'dialogue', 'speaker' => 'c1', 'text' => '请完整听我说完这件事，不要遗漏最后一句话。'],
        ]]]], ['revision_id' => 'r1', 'episode_id' => 'e1', 'entity_scope' => 't1:p1', 'entities' => [
            ['ref' => 'c1', 'entity_id' => 'person1', 'kind' => 'character', 'name' => '陈默'],
            ['ref' => 'l1', 'entity_id' => 'room1', 'kind' => 'location', 'name' => '书房'],
        ]]);
        $plan = ShotCompiler::compile($document, ['shots' => [['visual_description' => '坐在窗边讲述', 'refs' => [['unit' => 'u1']]]]], 's1');
        $timing = [$plan['shots'][0]['shot_id'] => ['version' => 'measured-v1', 'actual_seconds' => 23]];
        $assets = [];
        foreach (['person1', 'room1'] as $id) $assets[$id] = ['asset_id' => 'asset:' . $id, 'state_version' => 'look1', 'reference_hash' => 'approved-reference', 'style' => '写实'];
        return [$document, $plan, $timing, $assets];
    }
    public function testLongNarrativeShotCompilesSeveralFragmentsButOnlyOneVoiceSegment(): void
    {
        [$document, $plan, $timing, $assets] = $this->fixture();
        $result = ProductionCompiler::compile($document, $plan, ['version' => 'video1', 'durations' => [5, 10], 'audio_mode' => 'postproduction'], $timing, $assets, '写实');
        self::assertCount(3, $result['fragments']); self::assertCount(1, $result['audio_segments']);
        self::assertSame([10.0, 10.0, 5.0], array_column($result['fragments'], 'requested_seconds'));
        self::assertEquals(23, array_sum(array_column($result['fragments'], 'usable_seconds')));
        self::assertSame($document['scenes'][0]['units'][0]['text'], $result['audio_segments'][0]['text']);
        foreach ($result['fragments'] as $fragment) ProductionCompiler::assertCurrent($fragment, $document, $plan, $assets);
        $other = ProductionCompiler::compile($document, $plan, ['version' => 'video2', 'durations' => [15], 'audio_mode' => 'postproduction'], $timing, $assets, '写实');
        self::assertCount(2, $other['fragments']); self::assertSame($result['document_hash'], $other['document_hash']);
    }
    public function testAppearanceChangeInvalidatesProductionInput(): void
    {
        [$document, $plan, $timing, $assets] = $this->fixture();
        $result = ProductionCompiler::compile($document, $plan, ['version' => 'v1', 'durations' => [10], 'audio_mode' => 'postproduction'], $timing, $assets, '写实');
        $assets['person1']['state_version'] = 'changed-clothes';
        $this->expectExceptionCode(409); ProductionCompiler::assertCurrent($result['fragments'][0], $document, $plan, $assets);
    }
    public function testUnusedGlobalAssetDoesNotBlockCurrentShotProduction(): void
    {
        [$document, $plan, $timing, $assets] = $this->fixture(); $assets['future-character'] = [];
        self::assertSame('ready_to_dispatch', ProductionCompiler::compile($document, $plan, ['version' => 'v1', 'durations' => [10], 'audio_mode' => 'postproduction'], $timing, $assets, '写实')['status']);
    }
}
