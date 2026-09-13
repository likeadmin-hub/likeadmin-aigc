<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaCrossEpisodeVisualTest extends TestCase
{
    public function testDistinctStableIdsCannotMatchByName(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'episodeVisualAssetMatchesTarget');
        $method->setAccessible(true);
        foreach (['subject_image' => 'subject_id', 'scene_image' => 'scene_id', 'three_view' => 'subject_id'] as $type => $key) {
            self::assertFalse($method->invoke(null, $type, [$key => 'variant_a', 'item_name' => '同名'], ['id' => 'variant_b', 'name' => '同名']));
            self::assertTrue($method->invoke(null, $type, [$key => 'shared'], ['id' => 'shared']));
            self::assertFalse($method->invoke(null, $type, [$key => 'shared', 'item_name' => '手电筒'], ['id' => 'shared', 'name' => '银色徽章']));
        }
    }

    public function testLaterEpisodeAssetsAreReusedByFirstWithoutCopiesOrOverwrites(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional local DB fixture opt-in');
        (new \think\App())->initialize();
        Db::startTrans();
        try {
            $scope = ['tenant_id' => 2000000719, 'user_id' => 7];
            $parent = Db::name('aigc_short_drama_project')->insertGetId($scope);
            $children = [];
            foreach ([1, 2, 3] as $n) {
                $child = Db::name('aigc_short_drama_project')->insertGetId($scope);
                $children[] = $child;
                Db::name('aigc_short_drama_episode_task')->insert($scope + ['project_id' => $parent, 'episode_number' => $n, 'production_project_id' => $child, 'completed_once' => 1]);
                Db::name('aigc_short_drama_plan_version')->insert($scope + ['project_id' => $child, 'is_current' => 1, 'plan_json' => json_encode([
                    'subjects' => [['id' => 'shared', 'name' => '主角']], 'locations' => [['id' => 'room', 'name' => '房间']], 'storyboard' => [],
                ])]);
            }
            foreach (['subject_image' => 'shared', 'scene_image' => 'room', 'three_view' => 'shared'] as $type => $id) {
                Db::name('aigc_short_drama_asset')->insert($scope + ['project_id' => $children[1], 'asset_type' => $type, 'status' => 'ready',
                    'uri' => '/fixture/shared.png', 'meta_json' => json_encode(['item_id' => $id])]);
            }
            $method = new \ReflectionMethod(AigcShortDramaService::class, 'reuseEpisodeVisualAssetsForProject');
            $method->setAccessible(true);
            foreach ([$children[0], $children[2], $children[1], $children[0], $children[2]] as $child) {
                $method->invoke(null, $scope['tenant_id'], $scope['user_id'], $child);
                self::assertSame(3, Db::name('aigc_short_drama_asset')->where($scope + ['project_id' => $child])->count());
            }
            $copy = Db::name('aigc_short_drama_asset')->where($scope + ['project_id' => $children[0]])->find();
            self::assertSame('/fixture/shared.png', $copy['uri']);
        } finally { Db::rollback(); }
    }
}
