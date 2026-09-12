<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** All fixtures roll back; no real uploads, generation or paid requests. */
class ShortDramaInspirationTest extends TestCase
{
    private const TENANT = 90000909;

    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') !== '1') $this->markTestSkipped('Requires transactional MySQL tests');
        (new \think\App())->initialize();
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') === '1') Db::rollback();
    }

    private function video(): array
    {
        $uri = 'tests/inspiration-' . bin2hex(random_bytes(6)) . '.mp4';
        $id = Db::name('tenant_file')->insertGetId(['tenant_id' => self::TENANT, 'type' => 20, 'source' => 0,
            'uri' => $uri, 'storage_scope' => 'tenant', 'storage_engine' => 'oss', 'storage_domain' => 'https://media.example.test',
            'create_time' => time(), 'update_time' => time()]);
        return ['id' => $id, 'uri' => $uri];
    }

    public function testVideoSwitchRoundTripStorageAndSoftDelete(): void
    {
        $file = $this->video();
        $params = ['title' => '上传的视频', 'source_type' => 'video', 'description' => '雨夜车站的故事',
            'video_url' => 'https://media.example.test/' . $file['uri']];
        $saved = Service::saveAdminInspiration(self::TENANT, $params);
        self::assertFalse($saved['allow_generate_same']);
        self::assertSame('', $saved['prompt']);
        self::assertSame('雨夜车站的故事', $saved['description']);
        self::assertSame($params['video_url'], $saved['video_url']);
        self::assertSame('tenant', $saved['config']['video_url_storage']['storage_scope']);
        $params += ['id' => $saved['id'], 'allow_generate_same' => true];
        $updated = Service::saveAdminInspiration(self::TENANT, $params);
        self::assertTrue($updated['allow_generate_same']);
        self::assertSame($params['description'], $updated['prompt']);
        $detail = Service::inspirationDetail(self::TENANT, $saved['id']);
        self::assertTrue($detail['allow_generate_same']);
        $list = Service::adminInspirationLists(self::TENANT, ['keyword' => $params['title']]);
        self::assertSame($params['description'], $list['lists'][0]['description']);
        $params['allow_generate_same'] = '0';
        self::assertFalse(Service::saveAdminInspiration(self::TENANT, $params)['allow_generate_same']);
        Service::deleteAdminInspiration(self::TENANT, $saved['id']);
        self::assertSame(0, Service::adminInspirationLists(self::TENANT, ['keyword' => $params['title']])['count']);
        self::assertNotEmpty(Db::name('tenant_file')->where('id', $file['id'])->find());
    }

    public function testTemplateCompatibilityAndUnrelatedConfigPreserved(): void
    {
        $saved = Service::saveAdminInspiration(self::TENANT, ['title' => '旧模板', 'prompt' => '旧提示词']);
        self::assertSame('template', $saved['source_type']);
        self::assertTrue($saved['allow_generate_same']);
        Db::name('aigc_short_drama_inspiration')->where('id', $saved['id'])->update([
            'config_json' => json_encode(['episode_count' => 4, 'multi_episode' => true, 'style_id' => '7'])]);
        self::assertTrue(Service::inspirationDetail(self::TENANT, $saved['id'])['allow_generate_same']);
        $updated = Service::saveAdminInspiration(self::TENANT, ['id' => $saved['id'], 'title' => '改名', 'prompt' => '提示词']);
        self::assertSame(4, $updated['config']['episode_count']);
        self::assertSame('7', $updated['config']['style_id']);
    }

    public function testTenantCannotEditDeleteOrUseAnotherTenantsFile(): void
    {
        $file = $this->video();
        $saved = Service::saveAdminInspiration(self::TENANT, ['title' => '隔离测试', 'prompt' => '提示词']);
        $calls = [
            fn() => Service::saveAdminInspiration(self::TENANT + 1, ['id' => $saved['id'], 'title' => '篡改', 'prompt' => 'x']),
            fn() => Service::deleteAdminInspiration(self::TENANT + 1, $saved['id']),
            fn() => Service::setInspirationStatus(self::TENANT + 1, $saved['id'], 0),
            fn() => Service::saveAdminInspiration(self::TENANT + 1, ['title' => '越权', 'source_type' => 'video', 'description' => 'x', 'video_url' => $file['uri']]),
        ];
        foreach ($calls as $call) {
            try { $call(); self::fail('Expected tenant isolation'); }
            catch (\Exception $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertSame('隔离测试', Service::inspirationDetail(self::TENANT, $saved['id'])['title']);
    }

    public function testVideoRequiresTitleIntroductionAndManagedVideo(): void
    {
        foreach ([['description' => '介绍'], ['video_url' => 'missing.mp4'], ['description' => '介绍', 'video_url' => 'https://external.test/x.mp4']] as $extra) {
            try { Service::saveAdminInspiration(self::TENANT, ['title' => '视频', 'source_type' => 'video'] + $extra); self::fail('Expected validation failure'); }
            catch (\Exception $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function testCustomStorageDomainWithPathPrefix(): void
    {
        $file = $this->video();
        Db::name('tenant_file')->where('id', $file['id'])->update(['storage_domain' => 'https://media.example.test/bucket']);
        $url = 'https://media.example.test/bucket/' . $file['uri'];
        $saved = Service::saveAdminInspiration(self::TENANT, ['title' => '自定义存储', 'source_type' => 'video', 'description' => '介绍', 'video_url' => $url]);
        self::assertSame($url, $saved['video_url']);
        self::assertSame($file['uri'], $saved['raw_video_url']);
    }
}
