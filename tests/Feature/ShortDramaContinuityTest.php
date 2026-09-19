<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaContinuity as Continuity;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningContext;
use app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration;
use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use PHPUnit\Framework\TestCase;

class ShortDramaContinuityTest extends TestCase
{
    private function plan(): array
    {
        return ['title' => '钥匙', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '甲寻找钥匙',
            'subjects' => [['id' => 'p1', 'name' => '甲', 'description' => '侦探', 'library_subject_id' => 15]],
            'locations' => [['id' => 'l1', 'name' => '旧宅']],
            'storyboard' => [['shot_id' => 's1', 'scene_ref_id' => 'l1', 'visual_description' => '甲拿走钥匙。', 'dialogue' => '门终于开了。']]];
    }
    private function review(): array
    {
        return ['summary' => '甲获得钥匙', 'changes' => [['entity_id' => 'p1', 'field' => 'item_owner', 'before' => null,
            'after' => '钥匙', 'shot_id' => 's1', 'quote' => '甲拿走钥匙']], 'hooks' => [], 'warnings' => []];
    }
    public function testEvidenceBasedFactsCarryAcrossEpisodesWithoutRuntimeFields(): void
    {
        $plan = $this->plan(); $plan['storyboard'][0]['video_url'] = 'private-media';
        $ledger = Continuity::ledger($this->review(), $plan, [], 1);
        self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
        self::assertSame($ledger['digest'], Continuity::context([$ledger])['previous_digest']);
        self::assertStringNotContainsString('private-media', Continuity::messages($plan, [])['content']);
        self::assertSame(15, ShortDramaPlanningContext::lockedStory($plan)['subjects'][0]['library_subject_id']);
    }
    public function testInventedEvidenceCannotEnterLedger(): void
    {
        $review = $this->review(); $review['changes'][0]['quote'] = '甲受伤';
        $this->expectExceptionCode(422);
        Continuity::ledger($review, $this->plan(), [], 1);
    }
    public function testBeforeStateMustMatchAndUnknownHookCannotResolve(): void
    {
        $this->expectExceptionCode(409);
        Continuity::ledger($this->review(), $this->plan(), ['continuity' => ['state' => ['p1:item_owner' => '地图']]], 2);
    }
    public function testHookRequiresExistingUnresolvedFact(): void
    {
        $review = $this->review(); $review['hooks'] = [['id' => 'missing', 'description' => '钥匙', 'status' => 'resolved', 'shot_id' => 's1', 'quote' => '钥匙']];
        $this->expectExceptionCode(409); Continuity::ledger($review, $this->plan(), [], 1);
    }
    public function testOnlyNarrativeEditsInvalidateDependenciesAndPendingRepairIsNotBlocked(): void
    {
        $plan = $this->plan(); $digest = Continuity::fingerprint($plan);
        $plan['storyboard'][0]['video_url'] = 'new'; $plan['storyboard'][0]['camera_movement'] = '推镜';
        $plan = array_reverse($plan, true);
        self::assertSame($digest, Continuity::fingerprint($plan));
        $first = ['version' => 1, 'digest' => $digest, 'previous_digest' => ''];
        $second = ['version' => 1, 'digest' => 'second', 'previous_digest' => $digest];
        $rows = [['episode_number' => 1, 'production_project_id' => 10, 'status' => 'success', 'continuity_json' => $first],
            ['episode_number' => 2, 'production_project_id' => 11, 'status' => 'success', 'continuity_json' => $second]];
        self::assertSame([false, false], array_column(Continuity::dependencyStatus($rows, []), 'needs_review'));
        $versions = [10 => ['ledger' => $first, 'narrative_digest' => 'changed']];
        self::assertSame([true, true], array_column(Continuity::dependencyStatus($rows, $versions), 'needs_review'));
        $rows[0]['status'] = 'pending';
        self::assertSame([false, true], array_column(Continuity::dependencyStatus($rows, $versions), 'needs_review'));
        $rows[0]['continuity_json'] = ['summary' => 'legacy']; $rows[1]['continuity_json'] = [];
        self::assertSame([false, false], array_column(Continuity::dependencyStatus($rows, []), 'needs_review'));
    }
    public function testRoadmapPrecedesEveryBatchAndPreservesConfirmedStory(): void
    {
        $plan = $this->plan(); $plan['storyboard'] = [];
        $calls = [];
        $segments = [['start' => 1, 'end' => 20, 'goal' => '调查', 'reveal' => '逐步揭露', 'ending' => '解开谜题']];
        $result = ShortDramaStoryGeneration::generate(['_generation_version' => 3, 'multi_episode_stage' => 'episodes', 'episode_count' => 20,
            'confirmed_story_snapshot' => $plan], ['max_tokens' => 8192], static fn() => ['system_prompt' => '', 'content' => '大纲'],
            static function ($key, $messages, $budget) use (&$calls, $segments) {
                $calls[] = $key;
                if ($key === 'roadmap') $payload = ['segments' => $segments];
                else {
                    self::assertStringContainsString('逐步揭露', $messages['content']);
                    preg_match('/outline_(\d+)_(\d+)/', $key, $m); $payload = ['episodes' => []];
                    for ($i = (int)$m[1]; $i < (int)$m[1] + (int)$m[2]; $i++) $payload['episodes'][] = ['episode_number' => $i,
                        'title' => '调查' . $i, 'story_outline' => '发现线索' . $i, 'conflict_point' => '线索被隐藏', 'ending_hook' => '继续寻找'];
                }
                return ['result' => ['content' => json_encode($payload)]];
            });
        self::assertSame('roadmap', $calls[0]); self::assertCount(20, $result['result']['episodes']);
        self::assertSame($segments, $result['result']['series_roadmap']);
        self::assertSame($plan['subjects'], $result['result']['subjects']);
    }
    public function testRoadmapRejectsGaps(): void
    {
        $this->expectExceptionCode(422);
        Continuity::assertRoadmap([['start' => 2, 'end' => 10, 'goal' => 'a', 'reveal' => 'b', 'ending' => 'c']], 10);
    }
    public function testNewStandaloneTaskIsWorkerOwnedButEpisodeAndLegacyTasksAreNot(): void
    {
        self::assertTrue(ShortDramaStoryWorkflow::workerOwned(['_generation_version' => 3, 'multi_episode' => false]));
        self::assertFalse(ShortDramaStoryWorkflow::workerOwned(['_generation_version' => 3, 'episode_id' => 12]));
        self::assertFalse(ShortDramaStoryWorkflow::workerOwned([]));
    }
}
