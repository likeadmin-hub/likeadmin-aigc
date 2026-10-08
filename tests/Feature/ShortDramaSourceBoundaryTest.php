<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaSourceBoundary as Source;
use PHPUnit\Framework\TestCase;

class ShortDramaSourceBoundaryTest extends TestCase
{
    public function testOneLineFileEnvelopeIsDecodedAndTenEpisodesHaveDistinctSource(): void
    {
        $text = "剧名：测试\n时长：一分钟/100集\n**1-20集：**\n全剧背景。\n";
        for ($i = 1; $i <= 10; $i++) $text .= "**第{$i}集**\n本集{$i}的动作与台词。\n";
        $wrapped = 'L000001 ' . json_encode(['content'=>$text, 'type'=>'file', 'filename'=>'story.docx'], JSON_UNESCAPED_UNICODE);
        $episodes = [];
        for ($i = 1; $i <= 10; $i++) $episodes[] = ['episode_number'=>$i, 'content'=>$wrapped, 'source_range'=>['start_line'=>1,'end_line'=>1]];
        $result = Source::episodes($episodes);
        self::assertCount(10, array_unique(array_column($result, 'source_content')));
        self::assertSame("**第2集**\n本集2的动作与台词。\n", $result[1]['source_content']);
        self::assertStringNotContainsString('本集3', $result[1]['source_content']);
        self::assertSame($result, Source::episodes($result));
        self::assertSame($wrapped, $episodes[1]['content']); // original receipt immutable
    }

    public function testFiveHundredHeadingsAreIsolatedIncludingChineseNumbers(): void
    {
        $text = "第 一 集\n第一集正文。\n第二集\n第二集正文。\n";
        for ($i = 3; $i <= 500; $i++) $text .= "### 第{$i}集 标题\n{$i}集正文。\n";
        self::assertCount(500, Source::sections($text));
        $episode = Source::episode(['episode_number'=>500, 'source_content'=>$text]);
        self::assertSame("### 第500集 标题\n500集正文。\n", $episode['source_content']);
    }

    public function testUnrecognizedRepeatedSourceCannotEnterProduction(): void
    {
        $this->expectExceptionCode(422);
        Source::episodes([['episode_number'=>1,'content'=>'全剧没有集标题'],['episode_number'=>2,'content'=>'全剧没有集标题']]);
    }

    public function testOverlappingParserRangesCannotEnterProduction(): void
    {
        $this->expectExceptionCode(422);
        Source::episodes([['episode_number'=>1,'content'=>'甲','source_range'=>['start_line'=>1,'end_line'=>8]],
            ['episode_number'=>2,'content'=>'乙','source_range'=>['start_line'=>8,'end_line'=>12]]]);
    }

    public function testOldTaskWithWrongSourceStopsBeforeProviderCall(): void
    {
        $this->expectExceptionCode(422);
        Source::assertRequest(['series_context'=>['current_episode'=>['episode_number'=>2,'source_content'=>"第1集\n甲\n第2集\n乙"]]]);
    }

    public function testMissingHeadingAndRepeatedHeadingAreNotGuessed(): void
    {
        $this->expectExceptionCode(422);
        Source::episode(['episode_number'=>2,'source_content'=>"第1集\n甲\n第1集\n另一个甲"]);
    }

    public function testOrdinaryCreationAndExactSingleEpisodeSourceRemainUnchanged(): void
    {
        $episode = ['episode_number'=>1,'story_outline'=>'用户自己的故事'];
        self::assertSame($episode, Source::episode($episode));
        $text = "\n完整对白：他说第2集再揭晓。\n";
        self::assertSame($text, Source::episode($episode + ['source_content'=>$text])['source_content']);
        Source::assertRequest(['series_context'=>['current_episode'=>$episode]]);
    }
}
