<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoContentPlanning as P;

final class MicroVideoContentPlanningTest extends TestCase
{
    private function params(string $prompt='',int $target=30): array
    {
        return ['mode'=>$prompt===''?'theme':'prompt','prompt'=>$prompt,'target_duration'=>$target];
    }
    private function parsed(array $durations): array
    {
        return ['continuity'=>'同一人物与场景，连续动作承接。','segments'=>array_map(fn($d)=>[
            'title'=>'连续剧情','duration'=>$d,'prompt'=>"0-2秒：人物自然走向商品。\n2-{$d}秒：接续动作展示商品。",'image_indices'=>[0],
            'start_state'=>'人物面向商品','end_state'=>'人物拿起商品','continuity'=>'保持同一机位和动作方向'
        ],$durations)];
    }
    public function testWireContractOffersCapabilitiesInsteadOfAPrescribedPartition(): void
    {
        $c=P::contract($this->params(),[4,5,6,7,8,9,10,15],24);
        self::assertArrayNotHasKey('durations',$c);self::assertSame(30,$c['target_duration']);
        self::assertSame([4,5,6,7,8,9,10,15],$c['allowed_durations']);
        self::assertNull($c['required_total_duration']);self::assertIsInt($c['output_schema']['segments'][0]['duration']);
        self::assertStringContainsString('内容',$c['requirements']);self::assertStringContainsString('静止',$c['requirements']);
    }
    public function testContentCanChooseOneSegmentOrUnequalSegmentsForTheSameExplicitBrief(): void
    {
        $c=P::contract($this->params('整片总时长15秒，人物发现礼物后拿起展示。'),range(4,15),24);
        self::assertSame(15,$c['required_total_duration']);
        self::assertSame([15],array_column(P::validate($this->parsed([15]),$c,1,3,true),'duration'));
        self::assertSame([6,9],array_column(P::validate($this->parsed([6,9]),$c,1,3,true),'duration'));
    }
    public function testSelectedTargetDoesNotForcePaddingAShorterNaturalStory(): void
    {
        $c=P::contract($this->params('',30),range(4,15),24);
        self::assertSame([6,9],array_column(P::validate($this->parsed([6,9]),$c,1,3,true),'duration'));
        self::assertSame([5,7,10],array_column(P::validate($this->parsed([5,7,10]),$c,1,3,true),'duration'));
    }
    public function testUserTimelineOverridesTheSelectedTargetWithoutReadingPropNumbersAsDuration(): void
    {
        $c=P::contract($this->params("同一500毫升瓶子，门牌15号。\n0-3秒：发现商品。\n3-8秒：拿起展示。\n8-15秒：说完对白后自然离开。",30),range(4,15),24);
        self::assertSame(15,$c['required_total_duration']);self::assertCount(3,$c['source_timeline']);
        self::assertSame(15.0,$c['source_timeline'][2]['end']);
    }
    public function testExistingPerSegmentClocksAreReadAsOneOrderedStory(): void
    {
        $c=P::contract($this->params("第1段：\n0-3秒：发现。\n3-10秒：拿起。\n第2段：\n0-4秒：展示。\n4-10秒：收尾。"),range(4,15),24);
        self::assertSame(20,$c['required_total_duration']);self::assertSame(10.0,$c['source_timeline'][2]['start']);
        self::assertSame(20.0,$c['source_timeline'][3]['end']);
    }
    public function testExplicitDurationCannotBeSilentlyStretchedToAnAvailableModelSku(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('无法匹配');
        P::contract($this->params('整片15秒。'),[10],24);
    }
    public function testConflictingInputTimesAreRejectedBeforeModelCalls(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('不一致');
        P::contract($this->params("全片15秒。\n0-5秒：开场。\n5-20秒：展示。"),range(4,15),24);
    }
    public function testInputTimelineGapsCannotBeSilentlyRewritten(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('时间轴');
        P::contract($this->params("0-3秒：开场。\n5-15秒：展示。"),range(4,15),24);
    }
    public function testOutputMustHonorExplicitTotalEvenWhenEverySegmentIsCallable(): void
    {
        $c=P::contract($this->params('整片15秒。'),range(4,15),24);
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('15秒');
        P::validate($this->parsed([10,10]),$c,1,3,true);
    }
    public function testUnknownDurationBriefDoesNotCreateAHiddenHardTotal(): void
    {
        $c=P::contract($this->params('商品有15种颜色，镜头里显示20号房间。'),range(4,15),24);
        self::assertNull($c['required_total_duration']);
    }
    public function testNaturalLanguageVideoLengthIsAlsoAnExplicitDuration(): void
    {
        $c=P::contract($this->params('生成一个15秒剧情带货视频，女生拿起商品。'),range(4,15),24);
        self::assertSame(15,$c['required_total_duration']);
    }
    public function testPerSegmentLabelsDoNotBecomeWholeVideoDurations(): void
    {
        $c=P::contract($this->params("本段总时长6秒。\n下一段总时长9秒。"),range(4,15),24);
        self::assertNull($c['required_total_duration']);
        $c=P::contract($this->params('视频时长15秒。'),range(4,15),24);
        self::assertSame(15,$c['required_total_duration']);
    }
    public function testSmartPromptKeepsExplicitFifteenSecondClockInsteadOfSelectedThirty(): void
    {
        $c=P::contract($this->params("0-3秒：发现。\n3-15秒：展示。"),range(4,15),24);
        P::validatePrompt("0-3秒：发现同一商品。\n3-15秒：接着展示。",$c);
        $this->expectException(\InvalidArgumentException::class);
        P::validatePrompt("0-3秒：发现。\n3-30秒：缓慢展示。",$c);
    }
    public function testMatchingTotalCannotHideAStretchedOriginalShotClock(): void
    {
        $c=P::contract($this->params("0-3秒：发现。\n3-15秒：展示。"),range(4,15),24);
        $p=$this->parsed([15]);$p['segments'][0]['prompt']="0-5秒：发现。\n5-15秒：展示。";
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('原镜头');
        P::validate($p,$c,1,3,true);
    }
    public function testContentBoundariesCanSplitAClockWithoutChangingOriginalBeatTimes(): void
    {
        $c=P::contract($this->params("0-3秒：发现。\n3-8秒：拿起。\n8-15秒：展示。"),range(4,15),24);
        $p=$this->parsed([6,9]);$p['segments'][0]['prompt']="0-3秒：发现。\n3-6秒：开始拿起。";$p['segments'][1]['prompt']="0-2秒：接着拿起。\n2-9秒：展示。";
        self::assertSame([6,9],array_column(P::validate($p,$c,1,3,true),'duration'));
    }
    public function testModelSpecificationsAndMaximumWorkLengthRemainEnforced(): void
    {
        $c=P::contract($this->params('',600),[15],60);
        $this->expectException(\InvalidArgumentException::class);
        P::validate($this->parsed(array_fill(0,41,15)),$c,1,3,true);
    }
}
