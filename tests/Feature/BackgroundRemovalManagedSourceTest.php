<?php
namespace Tests\Feature;

use app\common\service\app\aigc_background_removal\AigcBackgroundRemovalService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BackgroundRemovalManagedSourceTest extends TestCase
{
    public function testLocalManagedResultUsesItsStoredUriInsteadOfDownloadingDisplayUrl(): void
    {
        $source=new ReflectionMethod(AigcBackgroundRemovalService::class,'resultImageSource');
        $local=['image_uri'=>'uploads/isolated.png','image_url'=>'https://fixtures.invalid/expired.png','storage_scope'=>'tenant','storage_engine'=>'local'];
        self::assertSame('uploads/isolated.png',$source->invoke(null,$local));
        $local['storage_engine']='aliyun';
        self::assertSame('https://fixtures.invalid/expired.png',$source->invoke(null,$local));
        self::assertSame('https://fixtures.invalid/external.png',$source->invoke(null,['image_url'=>'https://fixtures.invalid/external.png']));
    }

    public function testRelativeFileReadsCannotEscapeTheManagedPublicRoot(): void
    {
        (new \think\App())->initialize();
        $read=new ReflectionMethod(AigcBackgroundRemovalService::class,'readImageContent');
        self::assertSame('',$read->invoke(null,'../composer.json'));
        self::assertSame('',$read->invoke(null,'/../../etc/passwd'));
    }
}
