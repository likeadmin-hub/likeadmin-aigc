<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse as Response;
use PHPUnit\Framework\TestCase;

class ShortDramaTruncatedStoryboardTest extends TestCase
{
    public function testCompleteObjectsSurviveAnIncompleteStringAndEscapedQuotes(): void
    {
        $shot=['shot_id'=>'s1_1','visual_description'=>'文字含引号"和括号}，位置[内]','nested'=>['a'=>[1,2]]];
        $content='{"storyboard":['.json_encode($shot,JSON_UNESCAPED_UNICODE).',{"shot_id":"s1_2","dialogue":"未完成';
        self::assertSame(['storyboard'=>[$shot]],Response::partialStoryboard(['content'=>$content,'finish_reason'=>'length']));
    }

    public function testNestedOrMalformedArraysAndRefusalAreNeverExtracted(): void
    {
        $shot='{"shot_id":"s1_1"}';
        foreach (['{"nested":{"storyboard":['.$shot, '{"storyboard":['.$shot.' {"shot_id":"s1_2"}',
            '{"storyboard":['.$shot.',{"a":]}', '{"storyboard":[{"shot_id":"unfinished'] as $content) {
            self::assertNull(Response::partialStoryboard(['content'=>$content,'finish_reason'=>'length']));
        }
        self::assertNull(Response::partialStoryboard(['content'=>'{"storyboard":['.$shot,'finish_reason'=>'content_filter']));
    }
}
