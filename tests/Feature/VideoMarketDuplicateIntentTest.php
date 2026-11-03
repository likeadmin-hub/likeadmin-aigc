<?php
namespace Tests\Feature;

use app\common\service\app\aigc_video\AigcVideoService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class VideoMarketDuplicateIntentTest extends TestCase
{
    public function testExplicitNewIntentCannotBeCollapsedByContentDeduplication(): void
    {
        $matches=new ReflectionMethod(AigcVideoService::class,'matchesSubmissionIntent');
        self::assertTrue($matches->invoke(null,['submission_request_key'=>'first'],'first'));
        self::assertFalse($matches->invoke(null,['submission_request_key'=>'first'],'second'));
        self::assertFalse($matches->invoke(null,[],'second'));
        self::assertTrue($matches->invoke(null,[],''));
        self::assertTrue($matches->invoke(null,['submission_request_key'=>'first'],''));
    }
}
