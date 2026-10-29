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

    public function testOptionalCompleteTopLevelFieldsCanPrecedeTheTruncatedStoryboard(): void
    {
        $shot=['shot_id'=>'s1_1','dialogue'=>'甲：完整台词。'];
        $content='{"title":"故事","info":{"text":"保留括号 } 和逗号, "},"storyboard":['.json_encode($shot,JSON_UNESCAPED_UNICODE).',{"shot_id":"s1_2","dialogue":"截断';
        self::assertNull(Response::partialStoryboard(['content'=>$content,'finish_reason'=>'length']));
        self::assertSame(['storyboard'=>[$shot]],Response::partialStoryboard(['content'=>$content,'finish_reason'=>'length'],true));
    }

    public function testExtendedExtractionRejectsNestedOrAmbiguousPrefix(): void
    {
        $shot='{"shot_id":"s1_1"}';
        foreach (['{"nested":{"storyboard":['.$shot, '{"title":"bad","storyboard":[],"storyboard":['.$shot,
            '{"broken": ,"storyboard":['.$shot, '{"storyboard":"text", "storyboard":['.$shot] as $content) {
            self::assertNull(Response::partialStoryboard(['content'=>$content,'finish_reason'=>'length'],true));
        }
        self::assertNull(Response::partialStoryboard(['content'=>'{"title":"a","storyboard":['.$shot,'finish_reason'=>'refusal'],true));
    }

    public function testRecognizedEnvelopesAndShotsAliasAreVersionGatedAndSafe(): void
    {
        $shot = ['shot_id'=>'s1_1', 'visual_description'=>'完整画面'];
        $encoded = json_encode($shot, JSON_UNESCAPED_UNICODE);
        $content = '{"data":{"result":{"title":"故事","shots":['.$encoded.',{"shot_id":"s1_2","dialogue":"未完成';
        self::assertNull(Response::partialStoryboard(['content'=>$content,'finish_reason'=>'length'], true));
        self::assertSame(['storyboard'=>[$shot]], Response::partialStoryboard(['content'=>$content,'finish_reason'=>'length'], true, true));
        foreach (['{"unknown":{"shots":['.$encoded, '{"data":{"shots":['.$encoded.'],"storyboard":[]',
            '{"data":{"broken":,"shots":['.$encoded, '{"data":{"shots":['.$encoded.',{"bad":]}'] as $invalid) {
            self::assertNull(Response::partialStoryboard(['content'=>$invalid,'finish_reason'=>'length'], true, true));
        }
        self::assertNull(Response::partialStoryboard(['content'=>$content,'finish_reason'=>'content_filter'], true, true));
    }

    public function testTruncatedObjectPrefixOnlyReturnsCompleteUnambiguousProperties(): void
    {
        $text=json_encode('含括号 }、逗号, 和引号"',JSON_UNESCAPED_UNICODE);
        $content='{"data":{"title":"故事","story_outline":'.$text.',"subjects":[{"id":"c1"}],"locations":[{"id":"l1"}],"scene_beats":[{"goal":"截断';
        $prefix=Response::partialObjectPrefix(['content'=>$content,'finish_reason'=>'length']);
        self::assertSame('故事',$prefix['title']); self::assertSame('c1',$prefix['subjects'][0]['id']);
        self::assertArrayNotHasKey('scene_beats',$prefix);
        foreach (['{"title":"甲","title":"乙","bad":', '{"title":"甲","broken": ,"next":',
            '{"title":"甲","nested":[}],"next":'] as $bad) self::assertSame([],Response::partialObjectPrefix(['content'=>$bad]));
        self::assertSame([],Response::partialObjectPrefix(['content'=>$content,'finish_reason'=>'refusal']));
    }
}
