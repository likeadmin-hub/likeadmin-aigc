<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaSceneProgress as Progress;
use PHPUnit\Framework\TestCase;

class ShortDramaSceneProgressTest extends TestCase
{
    public function testSplittingARequestDoesNotMoveOrRepeatEventStarts(): void
    {
        $beat = ['key_events'=>['取出信件','逐字朗读长信','收好信件']];
        $whole = Progress::window($beat, 12, 0, 12, []);
        $starts = [];
        foreach ([[0,4],[4,2],[6,2],[8,4]] as [$offset,$size]) {
            $part = Progress::window($beat, 12, $offset, $size, []);
            self::assertSame($whole['event_windows'], $part['event_windows']);
            foreach ($part['current_events'] as $event) if ($event['phase'] === 'start') $starts[] = $event['text'];
        }
        self::assertSame($beat['key_events'], $starts);
        self::assertSame('continue', Progress::window($beat, 12, 6, 2, [])['current_events'][0]['phase']);
    }
    public function testCompletedNarrativeKeepsActionsAndDialogueWithoutProductionPayload(): void
    {
        $part = Progress::window(['goal'=>'原始剧情'], 8, 4, 4, [['shot_id'=>'s1','dialogue'=>'完整台词',
            'visual_description'=>'手册已经放入抽屉','video_prompt'=>str_repeat('大容量提示',1000),'image_url'=>'private']]);
        self::assertSame('手册已经放入抽屉', $part['completed_narrative'][0]['visual_description']);
        self::assertSame('完整台词', $part['completed_narrative'][0]['dialogue']);
        self::assertArrayNotHasKey('video_prompt', $part['completed_narrative'][0]);
        self::assertFalse($part['at_scene_start']); self::assertTrue($part['at_scene_end']);
        self::assertSame('原始剧情', $part['current_events'][0]['text']);
    }
}
