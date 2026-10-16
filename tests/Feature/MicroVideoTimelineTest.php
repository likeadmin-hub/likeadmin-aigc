<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoPolicy;
use app\common\service\app\aigc_micro_video\MicroVideoTimeline;

final class MicroVideoTimelineTest extends TestCase
{
    private function segment(string $prompt, int $seconds=15): array
    {
        return ['title'=>'商品展示','duration'=>$seconds,'prompt'=>$prompt,'image_indices'=>[0],'start_state'=>'完整瓶身','end_state'=>'瓶盖近景','continuity'=>'保持同一商品、背景与光线'];
    }

    public function testNewPlanCannotSaveAnUntimedParagraphAsAWholeSegment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('镜头时间轴无效');
        MicroVideoPolicy::segments([$this->segment('镜头缓缓靠近红色保温瓶。')],[15],1,1,8,true);
    }
    public function testEveryFifteenSecondSegmentHasItsOwnZeroBasedClock(): void
    {
        $prompt="共同设定：同一红色保温瓶，柔和侧光。\n0-3秒：全景缓慢推近。\n3–6秒：延续推进到瓶身。\n6—10秒：平移展示细节。\n10至15秒：沿瓶身上移至瓶盖并停稳。";
        $rows=MicroVideoPolicy::segments([$this->segment($prompt),$this->segment($prompt)],[15],1,1,8,true);
        self::assertCount(2,$rows);self::assertSame($prompt,$rows[0]['prompt']);self::assertSame($prompt,$rows[1]['prompt']);
    }
    public function testFractionalShotClocksAndExplicitSecondsAreAccepted(): void
    {
        $prompt="0秒-1.5秒：缓慢推进。\n1.5s~3s: 保持光线继续推进。";
        MicroVideoTimeline::validate($prompt,3);self::assertTrue(true);
    }
    /** @dataProvider invalidTimelines */
    public function testInvalidShotClocksAreRejected(string $prompt): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MicroVideoTimeline::validate($prompt,15);
    }
    public static function invalidTimelines(): array
    {
        return [
            'one-shot'=>["0-15秒：只有一个镜头。"],
            'gap'=>["0-3秒：全景。\n4-15秒：细节。"],
            'overlap'=>["0-6秒：全景。\n3-15秒：细节。"],
            'nonzero'=>["15-18秒：全景。\n18-30秒：细节。"],
            'negative'=>["-3-3秒：全景。\n3-15秒：细节。"],
            'short'=>["0-3秒：全景。\n3-14秒：细节。"],
            'long'=>["0-3秒：全景。\n3-16秒：细节。"],
            'empty-description'=>["0-3秒：  \n3-15秒：细节。"],
            'zero-length'=>["0-0秒：全景。\n0-15秒：细节。"],
            'reverse'=>["0-6秒：全景。\n6-3秒：细节。\n3-15秒：细节。"],
        ];
    }
    public function testLegacyConfirmedPlanRemainsUntouched(): void
    {
        $prompt='镜头缓缓靠近红色保温瓶。';
        $rows=MicroVideoPolicy::segments([$this->segment($prompt)],[15],1,1,8);
        self::assertSame($prompt,$rows[0]['prompt']);
        self::assertFalse(MicroVideoTimeline::required([]));
        self::assertTrue(MicroVideoTimeline::required(['shot_timeline_version'=>1]));
    }
}
