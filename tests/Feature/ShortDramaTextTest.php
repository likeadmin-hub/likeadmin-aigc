<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaText;
use app\common\service\app\aigc_short_drama\ShortDramaContinuityPatch;
use PHPUnit\Framework\TestCase;

class ShortDramaTextTest extends TestCase
{
    public function testMetadataPreservesStructuredTextAndWholeDialogueLines(): void
    {
        self::assertSame('温情', ShortDramaText::genre(['story_type'=>'温情','reason'=>'家庭和解']));
        self::assertSame(['甲：你好。乙：再见。'], ShortDramaText::lines('甲：你好。乙：再见。'));
        self::assertSame(['甲：你好。','乙：再见。'], ShortDramaText::lines("甲：你好。\n乙：再见。"));
        self::assertSame(['{"speaker":"甲","text":"你好。"}'], ShortDramaText::lines([['speaker'=>'甲','text'=>'你好。']]));
        self::assertSame('', ShortDramaText::value([]));
    }
    public function testVerifierContractContainsOnlyActualChecksAndStillRejectsPlaceholder(): void
    {
        $review=['changes'=>[['after'=>'甲']], 'hooks'=>[]];
        $input=json_decode(ShortDramaContinuityPatch::meaningMessages([], $review)['content'],true);
        self::assertSame(['changes'], array_column($input['response_contract']['checks'],'collection'));
        self::assertSame([0], array_column($input['response_contract']['checks'],'index'));
        $this->expectExceptionCode(422);
        ShortDramaContinuityPatch::assertMeaning($review, ['checks'=>[
            ['collection'=>'changes','index'=>0,'supported'=>true,'reason'=>'有证据'],
            ['collection'=>'insertions','index'=>0,'supported'=>true,'reason'=>'没有补镜']]]);
    }
}
