<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationArtifactDisplay as Display;

class CanvasArtifactDisplayTest extends TestCase
{
    public function testLegacyArtKeepsOnlyConventionalDetailsWithoutChangingCanonicalData(): void
    {
        $data=['art_style'=>['base_style'=>'电影写实','visual_description'=>'自然光','prompt'=>'private-art'],
            'subjects'=>[['id'=>'private-id','name'=>'林小凡','description'=>'年轻程序员','appearance'=>'重复描述','image_prompt'=>'private-image','three_view_prompt'=>'private-view','negative_prompt'=>'private-negative']],
            'locations'=>[['name'=>'办公室','description'=>'深夜灯光','prompt'=>'private-scene']]];
        $item=['title'=>'本集美术设定','content'=>json_encode($data,JSON_UNESCAPED_UNICODE)];$before=$item;
        $text=Display::item($item)['content'];
        foreach(['电影写实','自然光','林小凡','年轻程序员','办公室','深夜灯光'] as $value) self::assertStringContainsString($value,$text);
        foreach(['private-','appearance','image_prompt','重复描述','{'] as $value) self::assertStringNotContainsString($value,$text);
        self::assertSame(1,substr_count($text,'年轻程序员'));self::assertSame($before,$item);
    }
    public function testPromptArtifactsAreNotConversationBodiesAndOrdinaryTextIsUnchanged(): void
    {
        self::assertSame('',Display::item(['artifact'=>'three_view_prompt','content'=>'internal generation prompt'],'art')['content']);
        self::assertSame('',Display::item(['content'=>'shot prompt'],'video_plan')['content']);
        self::assertSame('普通回复原样保留',Display::item(['content'=>'普通回复原样保留'])['content']);
        self::assertSame('subject_1',Display::item(['key'=>'subject_1','stage'=>'art','content'=>'说明'])['key']);
    }
}
