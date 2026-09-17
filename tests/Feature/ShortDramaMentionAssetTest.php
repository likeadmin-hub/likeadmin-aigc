<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Drama;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Transactional fixtures only: no generated tasks, provider calls or charges. */
class ShortDramaMentionAssetTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $method = new \ReflectionMethod(Drama::class, $method);
        $method->setAccessible(true);
        return $method->invoke(null, ...$args);
    }

    public function testLibraryReferenceIsAuthorizedRegisteredAndReusable(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') !== '1') $this->markTestSkipped('Requires transactional MySQL test mode');
        (new \think\App())->initialize();
        Db::startTrans();
        try {
            $tenant = 90000913;
            $user = 90000913;
            $project = Db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'title' => '引用事务测试', 'delete_time' => 0, 'create_time' => time(), 'update_time' => time()]);
            $subject = Db::name('aigc_short_drama_subject')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'source' => 'user', 'name' => '引用角色', 'description' => '深色外套', 'image' => 'https://example.test/mention.png', 'status' => 1, 'delete_time' => 0, 'create_time' => time(), 'update_time' => time()]);
            $params = ['project_id' => $project, 'task_id' => 'mention-contract-test', 'library_subject_id' => $subject];
            $first = Drama::registerAsset($tenant, $user, $params);
            $second = Drama::registerAsset($tenant, $user, $params);
            self::assertSame($first['id'], $second['id']);
            self::assertSame('reference_image', $first['asset_type']);
            self::assertSame('library:' . $subject, $first['meta']['subject_id']);
            self::assertSame('https://example.test/mention.png', $first['url']);
            $method = new \ReflectionMethod(Drama::class, 'referencePayloadFromAssetIds');
            $method->setAccessible(true);
            $payload = $method->invoke(null, $tenant, $user, $project, [$first['id']]);
            self::assertSame([$first['url']], $payload['reference_images']);
            self::assertSame([], $method->invoke(null, $tenant + 1, $user, $project, [$first['id']])['reference_images']);
            Db::name('aigc_short_drama_subject')->where('id', $subject)->update(['user_id' => $user + 1]);
            try {
                Drama::registerAsset($tenant, $user, $params);
                self::fail('Foreign subject must not be referenced');
            } catch (\Exception $error) {
                self::assertStringContainsString('无权引用', $error->getMessage());
            }
        } finally {
            Db::rollback();
        }
    }

    public function testBoundLibrarySubjectMediaIsAliasedAndResolvedForShotReferences(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') !== '1') $this->markTestSkipped('Requires transactional MySQL test mode');
        (new \think\App())->initialize();
        Db::startTrans();
        try {
            $tenant = 90000914;
            $user = 90000914;
            $project = Db::name('aigc_short_drama_project')->insertGetId([
                'tenant_id' => $tenant,
                'user_id' => $user,
                'title' => '主体引用别名测试',
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $plan = [
                'subjects' => [[
                    'id' => 'subject_1',
                    'library_subject_id' => '42',
                    'name' => '栾恋',
                    'category' => 'character',
                    'image' => 'https://example.test/luan-main.png',
                    'raw_image' => 'https://example.test/luan-main.png',
                    'three_view_image' => 'https://example.test/luan-three-view.png',
                    'three_view_raw_image' => 'https://example.test/luan-three-view.png',
                ]],
                'locations' => [],
            ];

            $updatedPlan = $this->call(
                'ensureCurrentProjectSubjectReferenceAssets',
                $tenant,
                $user,
                $project,
                'plan-reference-contract-test',
                $plan
            );
            $aliases = Db::name('aigc_short_drama_asset')->where([
                'tenant_id' => $tenant,
                'user_id' => $user,
                'project_id' => $project,
                'status' => 'ready',
                'delete_time' => 0,
            ])->whereIn('asset_type', ['subject_image', 'three_view'])->select()->toArray();
            self::assertCount(2, $aliases);
            $aliasTypes = array_values(array_unique(array_column($aliases, 'asset_type')));
            sort($aliasTypes);
            self::assertSame(['subject_image', 'three_view'], $aliasTypes);
            foreach ($aliases as $alias) {
                $meta = json_decode((string)$alias['meta_json'], true);
                self::assertSame('library_subject_reference', $meta['source']);
                self::assertSame('42', $meta['library_subject_id']);
                self::assertSame('subject_1', $meta['subject_id']);
            }

            $references = $this->call(
                'shotReferenceAssets',
                $tenant,
                $user,
                $project,
                ['subject_ref_ids' => ['subject_1']],
                $updatedPlan
            );
            self::assertCount(2, $references['input_asset_ids']);
            $referenceTypes = array_values(array_unique(array_column($references['reference_assets'], 'asset_type')));
            sort($referenceTypes);
            self::assertSame(['subject_image', 'three_view'], $referenceTypes);

            $videoReferences = $this->call(
                'shortDramaVideoThreeViewAssets',
                $tenant,
                $user,
                $project,
                ['subject_ref_ids' => ['subject_1']]
            );
            self::assertCount(1, $videoReferences);
            self::assertSame('three_view', $videoReferences[0]['asset_type']);

            $noSubjectReferences = $this->call(
                'shotReferenceAssets',
                $tenant,
                $user,
                $project,
                ['shot_type' => '空镜'],
                $updatedPlan
            );
            self::assertSame([], $noSubjectReferences['input_asset_ids']);
        } finally {
            Db::rollback();
        }
    }
}
