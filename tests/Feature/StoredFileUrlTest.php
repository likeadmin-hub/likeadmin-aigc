<?php
declare(strict_types=1);

use app\common\service\FileService;
use PHPUnit\Framework\TestCase;

final class StoredFileUrlTest extends TestCase
{
    public function testAbsentStoredFilesNeverBecomeBucketRootUrls(): void
    {
        foreach (['', ' ', "\t\n"] as $uri) {
            foreach (['', 'local', 'aliyun', 'qcloud', 'qiniu'] as $engine) {
                self::assertSame('', FileService::getFileUrlByStorage($uri, 'tenant', $engine, 'https://bucket.test'));
            }
        }
    }

    public function testExplicitRootAndNonemptyLocalFileKeepTheirExistingContracts(): void
    {
        (new \think\App())->initialize();
        request()->setHost('localhost');
        self::assertSame(public_path(), FileService::getFileUrl('', 'public_path'));
        self::assertSame(request()->domain().'/uploads/frame.jpg', FileService::getFileUrlByStorage('uploads/frame.jpg','platform','local',''));
    }
}
