<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries as Series;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePolicy as Policy;

class CanvasScriptDetailsTest extends TestCase
{
    public function testEpisodeShowsConventionalDetailsWithoutLeakingNodePrompts(): void
    {
        $plan=['title'=>'破封之夜','type_judgement'=>'奇幻喜剧','core_theme'=>'协作','script_lines'=>['办公室灯光亮着。','陈默：你是谁？'],
            'art_style'=>['base_style'=>'水墨','visual_description'=>'金色数据流','prompt'=>'hidden-art'],
            'subjects'=>[['id'=>'secret-id','name'=>'陈默','description'=>'疲惫的程序员','visual_prompt'=>'hidden-character']],
            'locations'=>[['name'=>'办公室','story_phase'=>'开场','description'=>'深夜','prompt'=>'hidden-scene']],
            'storyboard'=>[['dialogue'=>'hidden-shot-dialogue','prompt'=>'hidden-shot']]];
        $before=$plan;$text=Series::text($plan,'episode',1);
        foreach(['破封之夜','类型判断：奇幻喜剧','核心主题：协作','主要角色：陈默','陈默：你是谁？','画风设定','基础风格：水墨','主体角色','疲惫的程序员','剧情阶段：开场','深夜'] as $value) self::assertStringContainsString($value,$text);
        foreach(['hidden-','secret-id','分镜','visual_prompt','{"'] as $value) self::assertStringNotContainsString($value,$text);
        self::assertSame($before,$plan);
        self::assertSame($text,Series::proposals($plan,'episode',1)[0]['prompt']);
    }
    public function testSeriesStagesRemainSeparateAndInOrder(): void
    {
        self::assertSame('episode',Policy::firstStage(['slot_values'=>['episode_count'=>'1集']]));
        self::assertSame('story',Policy::firstStage(['slot_values'=>['episode_count'=>'3集']]));
        self::assertSame('outline',Series::next('story'));self::assertSame('episode',Series::next('outline'));
        $plan=['title'=>'测试','story_outline'=>'故事方向','series_bible'=>['world_rules'=>'规则','series_arc'=>'主线'],
            'episodes'=>[['episode_number'=>1,'title'=>'开篇','story_outline'=>'本集大纲','ending_hook'=>'悬念']],
            'script_lines'=>['本集对白'],'storyboard'=>[['prompt'=>'hidden-shot']]];
        $story=Series::text($plan,'story',1);$outline=Series::text($plan,'outline',1);$episode=Series::text($plan,'episode',1);
        self::assertStringContainsString('世界规则：规则',$story);self::assertStringNotContainsString('本集对白',$story);
        self::assertStringContainsString('第1集：开篇',$outline);self::assertStringContainsString('结尾悬念：悬念',$outline);self::assertStringNotContainsString('本集对白',$outline);
        self::assertStringContainsString('本集对白',$episode);self::assertStringNotContainsString('本集大纲',$episode);
    }
}
