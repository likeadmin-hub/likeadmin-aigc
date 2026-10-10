<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaDialoguePartition as Partition;
use app\common\service\app\aigc_short_drama\ShortDramaDialogueSplit as Split;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeDuration as Timing;
use app\common\service\app\aigc_short_drama\ShortDramaTimedScriptGeneration as Timed;
use PHPUnit\Framework\TestCase;

class ShortDramaGenerationRecoveryTest extends TestCase
{
    private function request(float $seconds = 0): array
    {
        return ['_input_contract_version' => 5, 'episode_duration_policy' => Timing::snapshot([], $seconds, 0, [])];
    }

    public function testPartitionNeverCopiesModelDialogueOrTiming(): void
    {
        $dialogue = '甲：' . str_repeat('原', 58) . '。”  ' . str_repeat('文', 60) . '！';
        $shot = ['shot_id' => 'shot1', 'dialogue' => $dialogue, 'recommended_duration_seconds' => 5,
            'scene_ref_id' => 'room', 'subject_ref_ids' => ['a'], 'voice_role' => '甲', 'visual_description' => '甲先坐着讲述，再起身说完'];
        $calls = 0;
        $result = Split::adapt(['storyboard' => [$shot]], $this->request(), function ($key, $input) use (&$calls) {
            $calls++;
            $parts = json_decode($input['content'], true)['locked_segments'];
            return ['segments' => array_map(static fn($part) => ['index' => $part['index'],
                'visual_description' => $part['index'] === 0 ? '甲坐着讲述' : '甲起身说完',
                'dialogue' => '模型擅自改写', 'recommended_duration_seconds' => 1], $parts)];
        }, 'test');
        self::assertSame(1, $calls);
        self::assertSame($dialogue, implode('', array_column($result['storyboard'], 'dialogue')));
        self::assertSame(['shot1_p1','shot1_p2'], array_column($result['storyboard'], 'shot_id'));
        foreach ($result['storyboard'] as $part) {
            self::assertSame('甲', $part['voice_role']);
            self::assertSame(['a'], $part['subject_ref_ids']);
            self::assertGreaterThanOrEqual(Split::requiredSeconds($part['dialogue']), $part['recommended_duration_seconds']);
        }
    }

    public function testLockedPartitionPreservesEveryCharacterAndExactTotal(): void
    {
        $shot = ['dialogue' => str_repeat('甲', 54) . '。' . str_repeat('乙', 66) . '。', 'recommended_duration_seconds' => 22];
        $parts = Partition::parts($shot, ['min_seconds'=>4,'max_seconds'=>15], true);
        self::assertEquals(22, array_sum(array_column($parts, 'recommended_duration_seconds')));
        self::assertSame($shot['dialogue'], implode('', array_column($parts, 'dialogue')));
    }

    public function testImpossibleLockedPausesAreRejectedBeforeProvider(): void
    {
        $this->expectExceptionCode(409);
        Partition::parts(['dialogue'=>str_repeat('甲', 60).'。'.str_repeat('乙', 60).'。', 'recommended_duration_seconds'=>21], ['min_seconds'=>4,'max_seconds'=>15], true);
    }

    public function testReferenceOverrunDoesNotDiscardValidShotsButExplicitLimitRemainsHard(): void
    {
        $plan = ['storyboard' => array_fill(0, 14, ['recommended_duration_seconds'=>10])];
        Timing::assertPlan($plan, $this->request());
        $this->expectExceptionCode(422);
        Timing::assertPlan($plan, $this->request(120));
    }

    public function testProvisionalBudgetsPreserveSceneTotalsAndStayWithinRange(): void
    {
        foreach ([[17], [2,10], [2,5], [18,2,12], [1,1,3]] as $durations) {
            $plan = Timed::normalizeSkeletonBudgets(['scene_beats'=>[['shot_durations'=>$durations,'duration_seconds'=>999]]], $this->request());
            $actual = $plan['scene_beats'][0];
            self::assertEquals(array_sum($durations), $actual['duration_seconds']);
            self::assertEquals(array_sum($durations), array_sum($actual['shot_durations']));
            foreach ($actual['shot_durations'] as $duration) {
                self::assertGreaterThanOrEqual(4, $duration); self::assertLessThanOrEqual(15, $duration);
            }
        }
    }

    public function testFixedTimelineBudgetIsNeverRewritten(): void
    {
        $request=$this->request(); $request['episode_duration_policy']['timeline_segments']=[['duration_seconds'=>22]];
        $plan=['scene_beats'=>[['shot_durations'=>[10,12],'duration_seconds'=>22]]];
        self::assertSame($plan, Timed::normalizeSkeletonBudgets($plan, $request));
    }
}
