<?php
declare(strict_types=1);

namespace Tests\Feature;

use app\common\service\app\aigc_person_replacement\PersonReplacementAssetService as Assets;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * Offline storage receipts exercise the production persistence and repair flow.
 * Database fixtures are rolled back; no supplier, wallet or real upload is used.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PersonReplacementCoverTest extends TestCase
{
    private const TENANT = 987654321;
    private const USER = 987654322;
    private bool $transaction = false;
    private array $resultIds = [];

    protected function setUp(): void
    {
        class_alias(CoverStorageFixture::class, 'app\\common\\service\\storage\\StorageConfigService');
        class_alias(CoverImageFixture::class, 'app\\common\\service\\app\\aigc_image\\AigcImageAssetService');
        class_alias(CoverVideoFixture::class, 'app\\common\\service\\app\\aigc_video\\AigcVideoAssetService');
        (new \think\App())->initialize();
    }

    protected function tearDown(): void
    {
        if ($this->transaction) Db::rollback();
        foreach ($this->resultIds as $id) @unlink(runtime_path().'person_replacement_cover_'.self::TENANT.'_'.$id.'.lock');
    }

    public function testNestedCoverIsSelectedAndInvalidValuesAreIgnored(): void
    {
        self::assertSame('https://provider.test/frame.jpg', Assets::coverUrl(['data'=>['result'=>['data'=>['cover_url'=>' https://provider.test/frame.jpg '], 'cover_url'=>'https://provider.test/other.jpg']]]));
        self::assertSame('https://provider.test/poster.jpg', Assets::coverUrl(['data'=>['result'=>['cover_url'=>[], 'poster_url'=>'https://provider.test/poster.jpg']]]));
        foreach (['file:///etc/passwd', 'javascript:alert(1)', '/unowned.jpg', ''] as $url) {
            self::assertSame('', Assets::coverUrl(['cover_url'=>$url]));
        }
    }

    public function testVideoAndVerifiedCoverShareStorageAndOwner(): void
    {
        $result = Assets::persistVideo('https://provider.test/video.mp4', ['data'=>['result'=>['data'=>['cover_url'=>'https://provider.test/frame.jpg']]]], 7, 9);
        self::assertSame('uploads/video.mp4', $result['uri']);
        self::assertSame('uploads/cover.jpg', $result['cover_uri']);
        self::assertSame('platform', $result['storage_scope']);
        self::assertSame('aliyun', $result['storage_engine']);
        self::assertSame([7], CoverStorageFixture::$selected);
        self::assertSame(['https://provider.test/video.mp4',7,9,CoverStorageFixture::$config], CoverVideoFixture::$calls[0]);
        self::assertSame(['https://provider.test/frame.jpg',7,9,true,16777216,CoverStorageFixture::$config], CoverImageFixture::$calls[0]);
    }

    public function testMissingOrFailedCoverCannotTurnSuccessfulVideoIntoFailure(): void
    {
        $missing = Assets::persistVideo('https://provider.test/video.mp4', [], 7, 9);
        self::assertSame('', $missing['cover_uri']);
        self::assertSame([], CoverImageFixture::$calls);
        CoverImageFixture::$fail = true;
        $failed = Assets::persistVideo('https://provider.test/video.mp4', ['cover_url'=>'https://provider.test/frame.jpg'], 7, 9);
        self::assertSame('uploads/video.mp4', $failed['uri']);
        self::assertSame('', $failed['cover_uri']);
        self::assertSame(2, count(CoverVideoFixture::$calls));
    }

    public function testRepairIsAuditByDefaultScopedAndIdempotentAndPreservesTaskAndVideo(): void
    {
        [$id,$taskId] = $this->fixture();
        $before = Db::name('aigc_person_replacement_result')->find($id);
        $taskBefore = Db::name('aigc_person_replacement_task')->find($taskId);
        self::assertSame('would_repair', Assets::repairCover(self::TENANT, $id)['status']);
        self::assertSame('not_found', Assets::repairCover(self::TENANT + 1, $id, true)['status']);
        self::assertSame([], CoverImageFixture::$calls);
        self::assertSame($before, Db::name('aigc_person_replacement_result')->find($id));
        self::assertSame('repaired', Assets::repairCover(self::TENANT, $id, true)['status']);
        $after = Db::name('aigc_person_replacement_result')->find($id);
        self::assertSame('uploads/cover.jpg', $after['cover_uri']);
        unset($before['cover_uri'], $before['update_time'], $after['cover_uri'], $after['update_time']);
        self::assertSame($before, $after);
        self::assertSame($taskBefore, Db::name('aigc_person_replacement_task')->find($taskId));
        self::assertSame([self::TENANT,self::USER], array_slice(CoverImageFixture::$calls[0], 1, 2));
        self::assertSame('already_present', Assets::repairCover(self::TENANT, $id, true)['status']);
        self::assertCount(1, CoverImageFixture::$calls);
    }

    public function testDeletedUnfinishedAndForeignOwnedResultsAreExcluded(): void
    {
        [$id,$taskId] = $this->fixture();
        Db::name('aigc_person_replacement_result')->where('id',$id)->update(['delete_time'=>1]);
        self::assertSame('not_found', Assets::repairCover(self::TENANT,$id,true)['status']);
        Db::name('aigc_person_replacement_result')->where('id',$id)->update(['delete_time'=>0]);
        foreach ([['status'=>'running'], ['status'=>'success','delete_time'=>1], ['delete_time'=>0,'user_id'=>self::USER + 1]] as $changes) {
            Db::name('aigc_person_replacement_task')->where('id',$taskId)->update($changes);
            self::assertSame('not_found', Assets::repairCover(self::TENANT,$id,true)['status']);
        }
        self::assertSame([], CoverImageFixture::$calls);
    }

    public function testRepairFallsBackToSavedTaskResponseWithoutQueryingSupplier(): void
    {
        [$id] = $this->fixture();
        Db::name('aigc_person_replacement_result')->where('id',$id)->update(['result_json'=>'{}']);
        self::assertSame('repaired', Assets::repairCover(self::TENANT,$id,true)['status']);
        self::assertSame('https://provider.test/task-frame.jpg', CoverImageFixture::$calls[0][0]);
    }

    public function testChangedStorageAndDownloadFailureLeaveTheOriginalResultUntouched(): void
    {
        [$id] = $this->fixture();
        $before = Db::name('aigc_person_replacement_result')->find($id);
        CoverStorageFixture::$config['engine']['aliyun']['domain'] = 'https://changed.test';
        try {
            Assets::repairCover(self::TENANT,$id,true);
            self::fail('Changed historical storage must not receive a cover');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('存储域名已变化', $error->getMessage());
        }
        self::assertSame([], CoverImageFixture::$calls);
        CoverStorageFixture::$config['engine']['aliyun']['domain'] = 'https://assets.test';
        CoverImageFixture::$fail = true;
        try {
            Assets::repairCover(self::TENANT,$id,true);
            self::fail('Failed download must be reported');
        } catch (\RuntimeException $error) {
            self::assertSame('Offline download failure', $error->getMessage());
        }
        self::assertSame($before, Db::name('aigc_person_replacement_result')->find($id));
    }

    public function testMissingSavedCoverIsReportedWithoutMutatingOrDownloading(): void
    {
        [$id,$taskId] = $this->fixture();
        Db::name('aigc_person_replacement_result')->where('id',$id)->update(['result_json'=>'{}']);
        Db::name('aigc_person_replacement_task')->where('id',$taskId)->update(['provider_response'=>'{}']);
        self::assertSame('no_saved_cover', Assets::repairCover(self::TENANT,$id,true)['status']);
        self::assertSame([], CoverImageFixture::$calls);
        self::assertSame('', Db::name('aigc_person_replacement_result')->where('id',$id)->value('cover_uri'));
    }

    private function fixture(): array
    {
        self::assertSame('127.0.0.1', config('database.connections.mysql.hostname'));
        self::assertSame('z_cn', config('database.connections.mysql.database'));
        Db::startTrans();
        $this->transaction = true;
        $task = Db::name('aigc_person_replacement_task')->insertGetId([
            'tenant_id'=>self::TENANT,'user_id'=>self::USER,'status'=>'success',
            'billing_status'=>'charged','user_charge_points'=>12,'tenant_cost_points'=>8,
            'provider_response'=>json_encode(['data'=>['result'=>['cover_url'=>'https://provider.test/task-frame.jpg']]]),
        ]);
        $id = Db::name('aigc_person_replacement_result')->insertGetId([
            'tenant_id'=>self::TENANT,'user_id'=>self::USER,'task_id'=>$task,'video_uri'=>'uploads/original-video.mp4',
            'storage_scope'=>'platform','storage_engine'=>'aliyun','storage_domain'=>'https://assets.test',
            'result_json'=>json_encode(['data'=>['result'=>['data'=>['cover_url'=>'https://provider.test/frame.jpg']]]]),
        ]);
        $this->resultIds[] = $id;
        return [$id,$task];
    }
}

final class CoverStorageFixture
{
    public static array $config = ['scope'=>'platform','default'=>'aliyun','engine'=>['aliyun'=>['domain'=>'https://assets.test']]];
    public static array $selected = [];
    public static function getEffectiveConfig(int $tenant): array { self::$selected[] = $tenant; return self::$config; }
    public static function getStoredFileConfig(int $tenant, string $scope, string $engine): array { return self::$config; }
    public static function getStorageDomain(array $config): string { return $config['engine'][$config['default']]['domain']; }
}
final class CoverImageFixture
{
    public static array $calls = [];
    public static bool $fail = false;
    public static function persistGeneratedImage(string $url, int $tenant, int $user, bool $safe, int $pixels, array $storage): array
    {
        self::$calls[] = [$url,$tenant,$user,$safe,$pixels,$storage];
        if (self::$fail) throw new \RuntimeException('Offline download failure');
        return ['uri'=>'uploads/cover.jpg','stored'=>true];
    }
}
final class CoverVideoFixture
{
    public static array $calls = [];
    public static function persistGeneratedVideo(string $url, int $tenant, int $user, array $storage): array
    {
        self::$calls[] = [$url,$tenant,$user,$storage];
        return ['uri'=>'uploads/video.mp4','storage_scope'=>$storage['scope'],'storage_engine'=>$storage['default'],'storage_domain'=>'https://assets.test'];
    }
}
