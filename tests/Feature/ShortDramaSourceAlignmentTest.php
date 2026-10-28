<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaSourceBoundary as Source;
use PHPUnit\Framework\TestCase;

class ShortDramaSourceAlignmentTest extends TestCase
{
    private function fixture(): array
    {
        $source = "剧名：归途\r\n林间救援，少女在寒潭救起陌生人。\r\n他终于醒来，约好再见。\r\n少女开设书店，收集城市旧书。\r\n两人共同经营书店。\r\n他们驾船远航，驶入蔚蓝大海。\r\n备注：全文保留。\r\n";
        $envelope = 'L000001 ' . json_encode(['type'=>'file','filename'=>'test.docx','content'=>$source], JSON_UNESCAPED_UNICODE);
        $episodes = [];
        foreach (['林间救援，少女在寒潭救起陌生人，他终于醒来。', '少女开设书店，收集城市旧书，两人共同经营书店。', '他们驾船远航，驶入蔚蓝大海。'] as $index => $summary) {
            $episodes[] = ['episode_no'=>$index + 1,'summary'=>$summary,'content'=>$envelope,'source_range'=>['start_line'=>1,'end_line'=>1]];
        }
        return [$source, $episodes];
    }

    public function testAutomaticOutlineMapsToWholeOriginalWithoutHeadings(): void
    {
        [$source, $episodes] = $this->fixture();
        $mapped = Source::episodes($episodes, 'split_by_ai');
        self::assertSame($source, implode('', array_column($mapped, 'source_content')));
        self::assertSame([['start_line'=>1,'end_line'=>3],['start_line'=>4,'end_line'=>5],['start_line'=>6,'end_line'=>7]], array_column($mapped, 'source_range'));
        self::assertSame('outline_alignment', $mapped[0]['source_scope']['kind']);
        self::assertTrue($mapped[0]['source_scope']['review_required']);
        self::assertSame($mapped, Source::episodes($mapped));
        self::assertSame($mapped, Source::episodes($mapped, 'split_by_ai'));
        foreach ($mapped as $episode) Source::assertRequest(['series_context'=>['current_episode'=>$episode]]);
        self::assertSame(1, $episodes[1]['source_range']['start_line']);
    }

    public function testValidDocumentRangesHavePriorityOverInferredAlignment(): void
    {
        [$source, $episodes] = $this->fixture();
        foreach ([[1,2],[3,4],[5,7]] as $index => [$start,$end]) {
            $episodes[$index]['source_range'] = ['start_line'=>$start,'end_line'=>$end];
            $episodes[$index]['summary'] = '不需要利用梗概猜测范围';
        }
        $mapped = Source::episodes($episodes, 'split_by_ai');
        self::assertSame('document_line_range', $mapped[0]['source_scope']['kind']);
        self::assertSame($source, implode('', array_column($mapped, 'source_content')));
        self::assertSame(3, $mapped[1]['source_range']['start_line']);
    }

    public function testAmbiguousOutlineCannotBeEvenlyDividedOrDuplicated(): void
    {
        [, $episodes] = $this->fixture();
        foreach ($episodes as &$episode) $episode['summary'] = '主人公遇到困难，然后解决困难';
        $this->expectExceptionCode(422);
        Source::episodes($episodes, 'split_by_ai');
    }

    public function testOtherModesStillRejectMissingBoundaries(): void
    {
        [, $episodes] = $this->fixture();
        $this->expectExceptionCode(422);
        Source::episodes($episodes, 'existing_episodes');
    }

    public function testChangedRangeIsRejectedBeforeProduction(): void
    {
        [, $episodes] = $this->fixture();
        $mapped = Source::episodes($episodes, 'split_by_ai');
        $mapped[1]['source_range']['start_line']--;
        $this->expectExceptionCode(422);
        Source::assertRequest(['series_context'=>['current_episode'=>$mapped[1]]]);
    }

    public function testChangedSourceIsRejectedBeforeProduction(): void
    {
        [, $episodes] = $this->fixture();
        $mapped = Source::episodes($episodes, 'split_by_ai');
        $mapped[1]['source_content'] .= '意外拼入另一集';
        $this->expectExceptionCode(422);
        Source::episodes($mapped);
    }

    public function testReorderedOutlineCannotMapToMonotonicSource(): void
    {
        [, $episodes] = $this->fixture();
        [$episodes[0]['summary'], $episodes[2]['summary']] = [$episodes[2]['summary'], $episodes[0]['summary']];
        $this->expectExceptionCode(422);
        Source::episodes($episodes, 'split_by_ai');
    }
}
