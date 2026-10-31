<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_image\ImageAppTaskSync;
final class ImageParentLocalReadScopeTest extends TestCase
{
    public function testProjectionScopeNeverEnablesProviderRefreshInNestedCalls(): void
    {
        self::assertTrue(ImageAppTaskSync::providerRefreshAllowed());
        ImageAppTaskSync::localReads(function(){self::assertFalse(ImageAppTaskSync::providerRefreshAllowed());ImageAppTaskSync::localReads(fn()=>self::assertFalse(ImageAppTaskSync::providerRefreshAllowed()));self::assertFalse(ImageAppTaskSync::providerRefreshAllowed());});
        self::assertTrue(ImageAppTaskSync::providerRefreshAllowed());
    }
    public function testFailedProjectionRestoresLaterWorkerProviderQueries(): void
    {
        try{ImageAppTaskSync::localReads(static function(){throw new \RuntimeException('Private projection failure');});self::fail('Expected failure');}catch(\RuntimeException){}
        self::assertTrue(ImageAppTaskSync::providerRefreshAllowed());
    }
}
