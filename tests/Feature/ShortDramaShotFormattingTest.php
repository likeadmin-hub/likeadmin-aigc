<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
final class ShortDramaShotFormattingTest extends TestCase
{
    public function testPersistedAndAlreadyFormattedShotsKeepIdenticalSubjectReferences():void
    {
        $format=new \ReflectionMethod(AigcShortDramaService::class,'formatShot');
        $row=['shot_id'=>'s1','subject_ref_ids'=>'["person1","person2"]','visual_description'=>'两人重逢','image_prompt'=>'两人重逢','video_prompt'=>'两人重逢'];
        $first=$format->invoke(null,$row);self::assertSame(['person1','person2'],$first['subject_ref_ids']);
        $second=$format->invoke(null,$first);self::assertSame($first['subject_ref_ids'],$second['subject_ref_ids']);
        self::assertSame($first['image_prompt'],$second['image_prompt']);
    }
}
