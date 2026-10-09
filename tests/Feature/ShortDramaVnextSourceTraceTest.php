<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\SourceTrace;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextSourceTraceTest extends TestCase
{
    public function testLongSourceSegmentsPreserveEveryCodePointAndOriginalVersion(): void
    {
        $text = "第九集😀\r\n首次拆信，读出问候。\n" . str_repeat('原文不可截断。', 100);
        $source = SourceTrace::capture('source1', 'v1', $text, 'faithful');
        $segments = SourceTrace::segments($source, 17);
        self::assertSame($text, implode('', array_column($segments, 'text')));
        self::assertSame(mb_strlen($text), end($segments)['end']);
        self::assertSame(['v1'], array_values(array_unique(array_column($segments, 'source_version'))));
    }
    public function testOneRequirementCanMapToSeveralEventsAndFutureScopeIsNotCurrentFact(): void
    {
        $source = SourceTrace::capture('source1', 'v1', '第九集首次拆信并读出学生问候', 'faithful');
        $requirements = SourceTrace::requirements($source, [['start' => 0, 'end' => mb_strlen($source['text']), 'episode_ids' => ['e9']]]);
        $id = $requirements[0]['id'];
        $events = SourceTrace::confirmEvents('outline1', $requirements, [
            ['episode_id' => 'e9', 'text' => '首次拆信', 'requirement_ids' => [$id]],
            ['episode_id' => 'e9', 'text' => '读出学生问候', 'requirement_ids' => [$id]],
            ['episode_id' => 'e10', 'text' => '归还信件'],
        ], ['e9']);
        self::assertCount(3, $events); self::assertTrue($events[0]['confirmed']); self::assertFalse($events[2]['confirmed']);
        self::assertSame('creative', $events[2]['origin']);
        self::assertSame($source['hash'], $requirements[0]['source_hash']);
    }
    public function testConfirmedOutlineCannotSilentlyOmitApplicableRequirement(): void
    {
        $source = SourceTrace::capture('s', 'v1', '必须读出问候', 'inspiration');
        $requirements = SourceTrace::requirements($source, [['start' => 0, 'end' => 6, 'episode_ids' => ['e1']]]);
        $this->expectExceptionCode(422); SourceTrace::confirmEvents('o1', $requirements, [], ['e1']);
    }
}
