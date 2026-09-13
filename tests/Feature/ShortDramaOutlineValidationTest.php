<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

class ShortDramaOutlineValidationTest extends TestCase
{
    public function testMissingEpisodeCannotBeFilledByCopyingAnotherEpisode(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'normalizeGeneratedPlanResult');
        $method->setAccessible(true);
        $this->expectException(\Exception::class);
        $method->invoke(null, ['title' => '测试', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '测试剧情',
            'subjects' => [['name' => '主角']], 'locations' => [['name' => '车站']],
            'episodes' => [['episode_number' => 1, 'title' => '第一集', 'story_outline' => '剧情', 'conflict_point' => '冲突', 'ending_hook' => '钩子']]],
            '测试', ['multi_episode' => true, 'episode_count' => 3, 'multi_episode_stage' => 'episodes', 'episode_workflow' => 'outline_queue'], '测试');
    }

    public function testMissingShotsAreNotClonedIntoOtherEpisodes(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'applyStoryboardEpisodeStructure');
        $method->setAccessible(true);
        $result = $method->invoke(null, [['shot_id' => 'one', 'episode_number' => 1, 'visual_description' => '主角走进房间']], [], 3);
        self::assertCount(1, $result);
        self::assertSame([1], array_column($result, 'episode_number'));
    }

    public function testFailedCompletedEpisodeRevisionAlsoPausesFollowingWork(): void
    {
        $rows = [['id' => 3, 'episode_number' => 3, 'status' => 'pending', 'completed_once' => 0],
            ['id' => 1, 'episode_number' => 1, 'status' => 'failed', 'completed_once' => 1],
            ['id' => 2, 'episode_number' => 2, 'status' => 'success', 'completed_once' => 1]];
        self::assertSame(1, Episodes::nextInitialEpisode($rows)['id']);
    }
}
