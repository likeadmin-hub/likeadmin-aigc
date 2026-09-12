<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Drama;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Transactional fixtures only: no generated tasks, provider calls or charges. */
class ShortDramaMentionAssetTest extends TestCase
{
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
}
