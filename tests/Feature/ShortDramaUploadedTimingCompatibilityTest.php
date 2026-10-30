<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service, ShortDramaEpisodeDuration as Duration, ShortDramaSourceBoundary as Source};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ShortDramaUploadedTimingCompatibilityTest extends TestCase
{
    private function invoke(string $name, ...$args)
    {
        $method = new ReflectionMethod(Service::class, $name);
        $method->setAccessible(true);
        return $method->invoke(null, ...$args);
    }

    private function policy(string $source, array $base = [], array $extra = []): array
    {
        return $this->invoke('uploadedEpisodeTimingPolicy', ['_upload_timing_rule_snapshot' => Duration::defaults()],
            ['source_content' => $source] + $extra, $base ?: Duration::snapshot([], 0, 0, []));
    }

    /** @dataProvider commonTimelines */
    public function testCommonUploadedTimelinesRetainAllSegments(string $source, array $durations, float $start = 0): void
    {
        $policy = $this->policy($source);
        self::assertSame('timeline', $policy['source']);
        self::assertEquals($durations, array_column($policy['timeline_segments'], 'duration_seconds'));
        self::assertEquals(array_sum($durations), $policy['target_seconds']);
        self::assertEquals($start, $policy['timeline_segments'][0]['start_seconds']);
        Duration::assertPlan(['storyboard' => array_map(static fn($duration) => ['recommended_duration_seconds' => $duration], $durations)], ['episode_duration_policy' => $policy]);
    }

    public function commonTimelines(): array
    {
        return [
            'seconds' => ["0-5秒 入场\n5-12秒 离场", [5,7]],
            'seconds units' => ["0s - 5s 入场\n5sec - 12sec 离场", [5,7]],
            'uppercase units' => ["0S→5S 入场\n5S→12S 离场", [5,7]],
            'minutes seconds' => ["00:00–00:05 入场\n00:05～00:12 离场", [5,7]],
            'hours' => ["01:00:00 --> 01:00:05 入场\n01:00:05 --> 01:00:12 离场", [5,7], 3600],
            'SRT milliseconds' => ["1\n00:00:00,000 --> 00:00:00,250\n转头\n\n2\n00:00:00,250 --> 00:00:00,900\n抬手", [0.25,0.65]],
            'decimal seconds' => ["0-0.25秒 转头\n0.25-0.9秒 抬手", [0.25,0.65]],
            'Chinese minutes seconds' => ["0分0秒至0分5秒 入场\n0分5秒至0分12秒 离场", [5,7]],
            'full width' => ["００：００－００：０５ 入场\n００：０５－００：１２ 离场", [5,7]],
            'mixed formats' => ["镜头1｜00:00-00:05 入场\n镜头2｜5-10秒 对话\n镜头3｜0分10秒至0分15秒 离场", [5,5,5]],
            'Markdown table' => ["| 镜头 | 时间码 | 内容 |\n| --- | --- | --- |\n| 1 | 00:00-00:05 | 入场 |\n| 2 | 5-12秒 | 离场 |", [5,7]],
            'separate time columns' => ["镜头\t开始时间\t结束时间\t内容\n1\t00:00\t00:05\t入场\n2\t00:05\t00:12\t离场", [5,7]],
            'continuous numbering' => ["镜头1｜0-3秒 入场\n镜头2｜4-8秒 对话\n镜头3｜9-13秒 离场", [3,5,5]],
            'overview and details' => ["节奏：0-10s 入场→10-20s 离场\n镜头1｜00:00-00:05 入场\n镜头2｜5-10秒 找人\n镜头3｜10-20秒 离场", [5,5,10]],
            'one-based second labels' => ["镜头1｜1-5秒 入场\n镜头2｜6-10秒 离场", [5,5]],
            'numbered list' => ["1. 00:00-00:05 入场\n2. 00:05-00:12 离场", [5,7]],
            'short handwritten clock' => ["0:0-0:5 入场\n0:5-0:12 离场", [5,7]],
            'Traditional Chinese' => ["鏡頭1｜0分0秒到0分5秒 入場\n鏡頭2｜0分5秒到0分12秒 離場", [5,7]],
            'bracketed clock' => ["【00:00—00:05】入场\n【00:05—00:12】离场", [5,7]],
            'overview section and details' => ["时间轴概览：\n0-5秒 入场\n5-12秒 离场\n详细分镜：\n00:00-00:05 甲入场。\n00:05-00:12 甲离场。", [5,7]],
            'overview followed by shot labels' => ["时间轴概览\n0-5秒 入场\n5-12秒 离场\n镜头1：0-5秒 甲入场。\n镜头2：5-12秒 甲离场。", [5,7]],
            'overview only' => ["时间轴概览：\n0-5秒 入场\n5-12秒 离场", [5,7]],
            'space after minute label' => ["0 min 0 sec - 0 min 5 sec 入场\n0 min 5 sec - 0 min 12 sec 离场", [5,7]],
            'WebVTT' => ["WEBVTT\n\n00:00.000 --> 00:00.250 align:start\n转头\n\n00:00.250 --> 00:00.900\n抬手", [0.25,0.65]],
            'clock next to shot label' => ["镜头1：00:00-00:05 入场\n镜头2：00:05-00:12 离场", [5,7]],
            'seconds next to shot label' => ["镜头1：0-5秒 入场\n镜头2：5-12秒 离场", [5,7]],
            'clock next to timecode label' => ["时间码：00:00-00:05 入场\n时间码：00:05-00:12 离场", [5,7]],
            'time words inside shot content' => ["0-5秒 甲展示时间轴\n5-12秒 乙离开房间", [5,7]],
        ];
    }

    /** @dataProvider untimedScripts */
    public function testUntimedProseNeverAcquiresAnAccidentalTimeline(string $source): void
    {
        $base = Duration::snapshot([], 0, 0, []);
        self::assertSame($base, $this->policy($source, $base));
    }

    public function untimedScripts(): array
    {
        return [
            ['内景。甲推门进入，乙抬头，两人说话后离开。'],
            ["演员年龄20-30岁，预算100-200元。\n甲：现在是8点，等我。"],
            ["镜头1：甲走入房间，等待3-5秒后坐下。\n镜头2：乙停顿2-4秒后回答。"],
            ["**第一幕 夜晚**\n字幕：10:30。甲：我在这里住了5年。"],
            ["3-5秒后，甲推开房门。\n镜头2：2-4秒后，乙抬头回答。"],
        ];
    }

    /** @dataProvider explicitDurations */
    public function testUploadedDurationDeclarationsAreComplete(string $source, float $expected): void
    {
        $policy = $this->policy($source, [], ['episode_number' => 2]);
        self::assertSame('user', $policy['source']);
        self::assertEquals($expected, $policy['target_seconds']);
    }

    public function explicitDurations(): array
    {
        return [
            ["本集时长：1分30秒\n甲走进房间。",90],
            ["本集时长：00:01:30\n甲走进房间。",90],
            ["时长：９０秒\n甲走进房间。",90],
            ["**本集时长：1.5分钟。**\n甲走进房间。",90],
            ["第二集 时长：90秒\n甲走进房间。",90],
            ["本集时长：90 秒。\n甲走进房间。",90],
            ["第二集 归途（时长：1分30秒）\n甲走进房间。",90],
            ["本集時長：1分鐘30秒\n甲走進房間。",90],
        ];
    }

    /** @dataProvider invalidTimelines */
    public function testInvalidExplicitTimelinesCannotSilentlyFallBack(string $source): void
    {
        $this->expectException(\RuntimeException::class);
        $this->policy($source);
    }

    public function invalidTimelines(): array
    {
        return [
            ["00:00-00:05 正常\n00:05-00:03 倒置"],
            ["00:00-00:05 正常\n00:05-00:05 零时长"],
            ["00:00-00:05 正常\n00:05-00:65 无效秒数"],
            ["0-5秒 正常\n4-8秒 重叠"],
            ["0-5秒 正常\n7-12秒 空档"],
            ["0-5秒 甲\n0-5秒 乙（同一集重复起点）"],
            ["00:61:00-00:61:05 无效分钟"],
            ["-1-5秒 负数起点\n5-10秒 离场"],
        ];
    }

    public function testExplicitUserDurationAndEpisodeDeclarationCannotBeSilentlyOverridden(): void
    {
        $this->expectExceptionMessage('不一致');
        $this->policy("本集时长：12秒\n0-5秒 入场\n5-12秒 离场", Duration::snapshot([],15,0,[]));
    }

    public function testDifferentEpisodeDeclarationsDoNotLeakAcrossBoundaries(): void
    {
        $source = "第一集 旧信（时长：10秒）\n甲找到旧信。\n第二集 故人（时长：15秒）\n乙找到故人。";
        $episodes = Source::episodes([['episode_number'=>1,'source_content'=>$source],['episode_number'=>2,'source_content'=>$source]]);
        foreach ($episodes as $index => $episode) self::assertEquals($index === 0 ? 10 : 15, $this->policy($episode['source_content'])['target_seconds']);
    }

    public function testOverviewCannotReplaceDetailedShotContent(): void
    {
        $policy = $this->policy("时间轴概览\n0-5秒 开场摘要\n5-12秒 离场摘要\n镜头1：0-5秒 甲推开红门。\n镜头2：5-12秒 乙打开旧信。");
        self::assertStringContainsString('甲推开红门', $policy['timeline_segments'][0]['text']);
        self::assertStringContainsString('乙打开旧信', $policy['timeline_segments'][1]['text']);
        self::assertStringNotContainsString('摘要', implode('', array_column($policy['timeline_segments'],'text')));
    }

    public function testEachEpisodeKeepsItsOwnClockAndOriginalSource(): void
    {
        foreach ([false,true] as $globalClock) {
            $secondStart = $globalClock ? 10 : 0;
            $source = "第一集 开端\n0-5秒 甲入场\n5-10秒 甲离开\n第二集 后续\n{$secondStart}-".($secondStart+5)."秒 乙入场\n".($secondStart+5).'-'.($secondStart+10).'秒 乙离开';
            $episodes = Source::episodes([
                ['episode_number'=>1,'source_content'=>$source],
                ['episode_number'=>2,'source_content'=>$source],
            ]);
            foreach ($episodes as $episode) {
                $before = $episode['source_content'];
                $policy = $this->policy($before, [], $episode);
                self::assertEquals(10, $policy['target_seconds']);
                self::assertCount(2, $policy['timeline_segments']);
                self::assertSame($before, $episode['source_content']);
            }
            self::assertEquals($secondStart, $this->policy($episodes[1]['source_content'])['timeline_segments'][0]['start_seconds']);
        }
    }

    public function testNoTimecodeEpisodeDoesNotInheritOtherEpisodeTiming(): void
    {
        $source = "第一集 开端\n0-5秒 甲入场\n5-10秒 甲离开\n第二集 后续\n乙静坐等待，随后推门离开。";
        $episodes = Source::episodes([['episode_number'=>1,'source_content'=>$source],['episode_number'=>2,'source_content'=>$source]]);
        self::assertSame('timeline',$this->policy($episodes[0]['source_content'])['source']);
        self::assertSame(Duration::snapshot([],0,0,[]),$this->policy($episodes[1]['source_content']));
    }
}
