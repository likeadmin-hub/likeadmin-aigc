<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaAttachmentSource;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaFileQaExtractionPersistenceTest extends TestCase
{
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRejectedProjectionRetainsOwnedOriginalWithoutProviderBillingOrRetry(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional DB fixtures');
        require __DIR__ . '/../fixtures/short_drama_upload_fake_storage.php';
        (new \think\App())->initialize(); Db::startTrans();
        $paths = [];
        try {
            $scope = ['tenant_id' => 2000000719, 'user_id' => 7];
            foreach (['image', 'corrupt', 'binary'] as $kind) {
                $taskId = 'upload_source_' . bin2hex(random_bytes(8));
                $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => '原文件保留', 'last_task_id' => $taskId]);
                Db::name('aigc_short_drama_script_task')->insert($scope + ['project_id' => $project, 'task_id' => $taskId,
                    'status' => 'success', 'request_json' => '{"prompt":"原要求","_attachment_source":{"old":true},"_imported_outline_snapshot":{"old":true},"confirmed_story_snapshot":{"old":true},"confirmed_outline_snapshot":{"old":true},"_generation_version":3}', 'result_json' => '{}']);
                $path = tempnam(sys_get_temp_dir(), 'sd-projection-'); $paths[] = $path;
                $extension = $kind === 'binary' ? 'txt' : 'docx';
                if ($kind === 'image') {
                    $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::OVERWRITE);
                    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><w:body><w:p><w:r><w:t>部分文字</w:t><w:drawing><a:blip/></w:drawing></w:r></w:p></w:body></w:document>'); $zip->close();
                } else file_put_contents($path, $kind === 'binary' ? "未核对\0正文" : '损坏压缩包');
                $sha = hash_file('sha256', $path);
                request()->withFiles(['file' => ['tmp_name' => $path, 'name' => '原文.' . $extension, 'type' => 'application/octet-stream', 'size' => filesize($path), 'error' => 0]]);
                $params = ['project_id' => $project, 'task_id' => $taskId, 'submission_key' => 'sd:' . bin2hex(random_bytes(12)), 'submission_version' => 2];
                $created = AigcShortDramaService::parseUploadedScript($scope['tenant_id'], $scope['user_id'], $params);
                self::assertSame('failed', $created['status']); self::assertArrayNotHasKey('_dispatch_request', $created);
                $row = Db::name('aigc_short_drama_script_task')->where('task_id', $created['task_id'])->find();
                $request = json_decode($row['request_json'], true);
                self::assertArrayNotHasKey('_attachment_source', $request);
                foreach (['_imported_outline_snapshot', 'confirmed_story_snapshot', 'confirmed_outline_snapshot'] as $key) self::assertArrayNotHasKey($key, $request);
                self::assertSame($sha, $request['_attachment_extraction']['file_sha256']);
                self::assertSame('failed', $request['_attachment_extraction']['status']);
                self::assertSame([], json_decode($row['result_json'], true)); self::assertSame('none', $row['billing_status']);
                self::assertSame(0, (int)$row['app_task_id']); self::assertGreaterThan(0, (int)$row['finished_at']);
                $asset = Db::name('aigc_short_drama_asset')->where('id', $request['script_asset_id'])->find();
                foreach ($scope + ['project_id' => $project] as $key => $value) self::assertSame($value, (int)$asset[$key]);
                self::assertSame('ready', $asset['status']); self::assertSame($sha, $asset['checksum']);
                self::assertSame($sha, hash_file('sha256', end(\app\common\service\UploadService::$copies)));
                self::assertSame('attachment_text_unverified', json_decode($asset['meta_json'], true)['text_extraction']['error_code']);
                $mirror = Db::name('aigc_short_drama_generation_task')->where('task_id', $created['task_id'])->find();
                self::assertSame('failed', $mirror['status']); self::assertSame('none', $mirror['billing_status']);
                self::assertSame('attachment_text_unverified', $mirror['error_code']);
                self::assertSame(0, Db::name('ai_app_task')->where('task_no', $created['task_id'])->count());
                $detail = AigcShortDramaService::scriptPlanDetail($scope['tenant_id'], $scope['user_id'], $created['task_id'], $project);
                self::assertSame($created['task_id'], $detail['attachment_file']['task_id']);
                self::assertSame($sha, $detail['attachment_file']['file_sha256']);
                self::assertSame('failed', $detail['attachment_file']['extraction_status']);
                $fileView = new \ReflectionMethod(AigcShortDramaService::class, 'attachmentFile'); $fileView->setAccessible(true);
                self::assertNull($fileView->invoke(null, array_replace($row, ['user_id' => 8]), $request));
                $wrongHash = $request; $wrongHash['_attachment_extraction']['file_sha256'] = str_repeat('0', 64);
                self::assertNull($fileView->invoke(null, $row, $wrongHash));
                $calls = count(\app\common\service\UploadService::$copies);
                $replay = AigcShortDramaService::parseUploadedScript($scope['tenant_id'], $scope['user_id'], $params);
                self::assertSame($created['task_id'], $replay['task_id']); self::assertTrue($replay['reused']);
                self::assertSame($calls, count(\app\common\service\UploadService::$copies));
                $stale = $params; $stale['submission_key'] = 'sd:' . bin2hex(random_bytes(12));
                try { AigcShortDramaService::parseUploadedScript($scope['tenant_id'], $scope['user_id'], $stale); self::fail('Stale source must not replace current upload'); }
                catch (\Exception $error) { self::assertSame($calls, count(\app\common\service\UploadService::$copies)); }
                try { AigcShortDramaService::retry($scope['tenant_id'], $scope['user_id'], $created['task_id']); self::fail('Unverified source must not enter generation'); }
                catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
                $foreign = $params; $foreign['submission_key'] = 'sd:' . bin2hex(random_bytes(12));
                try { AigcShortDramaService::parseUploadedScript($scope['tenant_id'], 8, $foreign); self::fail('Foreign project must not upload'); }
                catch (\Exception $error) { self::assertSame($calls, count(\app\common\service\UploadService::$copies)); }
            }
            // A new, verified upload replaces the failure contract rather than inheriting it.
            $path = tempnam(sys_get_temp_dir(), 'sd-projection-'); $paths[] = $path; file_put_contents($path, "完整原文😀\n结尾。");
            request()->withFiles(['file' => ['tmp_name' => $path, 'name' => '修订.txt', 'type' => 'text/plain', 'size' => filesize($path), 'error' => 0]]);
            $method = new \ReflectionMethod(AigcShortDramaService::class, 'createUploadedScriptTask'); $method->setAccessible(true);
            $pending = $method->invoke(null, $scope['tenant_id'], $scope['user_id'], ['project_id' => $project, 'task_id' => $created['task_id']]);
            self::assertSame('pending', $pending['status']);
            ShortDramaAttachmentSource::assertPackage($pending['_dispatch_request']['_attachment_source']);
            self::assertSame("完整原文😀\n结尾。", $pending['_dispatch_request']['_attachment_source']['source']['text']);
            self::assertArrayNotHasKey('_attachment_extraction', $pending['_dispatch_request']);
        } finally {
            request()->withFiles([]); Db::rollback();
            foreach (array_merge($paths, \app\common\service\UploadService::$copies) as $path) if (is_file($path)) unlink($path);
        }
    }
}
