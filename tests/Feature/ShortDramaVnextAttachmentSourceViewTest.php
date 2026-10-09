<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\AttachmentScreenplay;
use app\common\service\app\aigc_short_drama\vnext\AttachmentSourceView as View;
use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextAttachmentSourceViewTest extends TestCase
{
    private static function fixture(): array
    {
        $context = ShortDramaVnextAttachmentTest::context();
        return [['context' => $context, 'model' => ['api_key' => 'private-key']], ['document' => AttachmentScreenplay::document($context)]];
    }
    public function testPagesKeepCompleteOriginalLinesAndCurrentTextWithoutPrivateInput(): void
    {
        [$input, $candidate] = self::fixture(); $second = View::page($input, $candidate, 2);
        self::assertSame('recorded', $second['status']); self::assertSame(3, $second['total']); self::assertSame(3, $second['next_page']);
        $row = $second['records'][0]; self::assertSame('我记得你😀。', $row['current_text']); self::assertSame($row['current_text'], $row['original_text']);
        self::assertSame("陈默：我记得你😀。\n", $row['original_line']); self::assertSame('verbatim', $row['relation']);
        self::assertSame(4, $row['episode_line']); self::assertSame('陈默', $row['speaker_name']); self::assertSame('书房', $row['scene_name']);
        self::assertSame(mb_strlen($row['original_text']), $row['source_end'] - $row['source_start']);
        self::assertSame('旁白', View::page($input, $candidate, 3)['records'][0]['speaker_name']);
        self::assertNull(View::page($input, $candidate, 3)['next_page']); self::assertSame([], View::page($input, $candidate, 4)['records']);
        self::assertStringNotContainsString('private-key', json_encode($second));
        self::assertArrayNotHasKey('sources', $second); self::assertArrayNotHasKey('source_task_id', $row);
    }
    public function testManualEditAndRestoredWordsRemainIdentifiedAsManualSource(): void
    {
        [$input, $candidate] = self::fixture(); $unit = $candidate['document']['scenes'][0]['units'][1];
        $origin = ['tenant_id' => 10, 'user_id' => 20, 'parent_task_id' => 'original', 'task_id' => 'edited', 'created_at' => 100];
        $next = ApplicationWorkflow::revisionInput($input, $candidate,
            [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => "手工修改😀。\n末尾。"]], $candidate['document']['revision_id'], 'edited', $origin);
        $row = View::page($next, [], 2)['records'][0];
        self::assertSame('edited', $row['relation']); self::assertSame($unit['text'], $row['original_text']); self::assertSame("手工修改😀。\n末尾。", $row['current_text']);
        self::assertSame('verbatim', View::page($next, [], 1)['records'][0]['relation']);
        $edited = $next['context']['canonical_input']; $newUnit = $edited['scenes'][0]['units'][1];
        $restored = ApplicationWorkflow::revisionInput($next, ['document' => $edited],
            [['unit_id' => $newUnit['unit_id'], 'before_hash' => $newUnit['text_hash'], 'text' => $unit['text']]], $edited['revision_id'], 'restored',
            array_replace($origin, ['parent_task_id' => 'edited', 'task_id' => 'restored', 'created_at' => 101]));
        $row = View::page($restored, [], 2)['records'][0]; self::assertSame($row['original_text'], $row['current_text']); self::assertSame('edited', $row['relation']);
    }
    public static function corruption(): array { return [['text'], ['layout'], ['source'], ['revision']]; }
    /** @dataProvider corruption */
    public function testCorruptSourceOrDocumentCannotClaimOriginalPositions(string $kind): void
    {
        [$input, $candidate] = self::fixture();
        if ($kind === 'text') $candidate['document']['scenes'][0]['units'][0]['text'] = '删字';
        elseif ($kind === 'layout') $candidate['document']['transforms'][0]['layout'][0]['end']++;
        elseif ($kind === 'source') $input['context']['sources'] = [];
        else $candidate['document']['revision_id'] = 'other';
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(409); View::page($input, $candidate, 1);
    }
    public function testOldInputAndUnfinishedAttachmentAreDifferentStates(): void
    {
        self::assertSame('not_recorded', View::page([], [], 1)['status']); [$input] = self::fixture();
        self::assertSame('not_ready', View::page($input, [], 1)['status']);
        $this->expectExceptionCode(422); View::page($input, [], -1);
    }
    public function testLongOriginalUnitIsNotTruncatedToAPreview(): void
    {
        $text = str_repeat('每个字都保留。', 3000) . '尾句😀';
        $context = ShortDramaVnextAttachmentTest::context("第1集\n场景：书房\n陈默：" . $text . "\n");
        $row = View::page(['context' => $context], ['document' => AttachmentScreenplay::document($context)], 1)['records'][0];
        self::assertSame($text, $row['original_text']); self::assertSame($text, $row['current_text']);
        self::assertSame("陈默：" . $text . "\n", $row['original_line']);
    }
}
