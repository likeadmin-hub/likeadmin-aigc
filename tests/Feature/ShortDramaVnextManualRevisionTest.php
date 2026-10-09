<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ManualContentRevision;
use app\common\service\app\aigc_short_drama\vnext\SourceTrace;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextManualRevisionTest extends TestCase
{
    private function fixture(): array
    {
        $input = ShortDramaVnextSceneShotTest::input(2); $document = $input['context']['canonical_input'];
        $sources = []; $links = [];
        foreach (ContentDocument::units($document) as $id => $unit) {
            $source = SourceTrace::capture('original_' . $id, 'v1', $unit['text'], 'faithful');
            $sources[$source['source_id']] = $source;
            $links[$id] = [['source_id' => $source['source_id'], 'source_version' => 'v1', 'source_hash' => $source['hash'],
                'start' => 0, 'end' => mb_strlen($unit['text'], 'UTF-8'), 'quote' => $unit['text']]];
        }
        $document = ContentDocument::attachExactSources($document, $links, $sources);
        $input['context']['canonical_input'] = $document; $input['context']['sources'] = $sources;
        $patches = [];
        foreach (ContentDocument::units($document) as $id => $unit) $patches[] = ['unit_id' => $id, 'before_hash' => $unit['text_hash'], 'text' => $unit['text']];
        $patches[0]['text'] = "我记得你😀。\n最后一句必须保留。";
        $origin = ['tenant_id' => 10, 'user_id' => 20, 'created_at' => 1791500000, 'parent_task_id' => 'task_old', 'task_id' => 'task_new'];
        return [$input, $document, $patches, $origin];
    }

    public function testExactUnicodeSourceAndOriginalEvidenceSurviveMixedNoOpSubmission(): void
    {
        [$input, $document, $patches, $origin] = $this->fixture();
        $next = ApplicationWorkflow::revisionInput($input, ['document' => $document], $patches, 'r1', 'task_new', $origin);
        $edited = $next['context']['canonical_input']; $before = array_values(ContentDocument::units($document));
        $after = array_values(ContentDocument::units($edited)); $history = $next['context']['manual_edit_history'];
        ManualContentRevision::assertHistory($history); self::assertCount(1, $history['events']);
        $event = $history['events'][0]; self::assertCount(1, $event['changes']); $change = $event['changes'][0];
        self::assertSame($before[0]['text'], $change['before_text']);
        self::assertSame($before[0]['source_links'], $change['before_source_links']);
        self::assertSame($before[1], $after[1], 'Unchanged submitted unit retains original evidence');
        self::assertSame($document['entities'], $edited['entities']);
        foreach (['unit_id', 'scene_id', 'speaker_id'] as $key) self::assertSame($before[0][$key], $after[0][$key]);
        $link = $after[0]['source_links'][0]; $source = $next['context']['sources'][$link['source_id']];
        self::assertSame($patches[0]['text'], $source['text']); self::assertSame($source['text'], $link['quote']);
        self::assertSame(mb_strlen($source['text'], 'UTF-8'), $link['end']);
        self::assertSame($origin['user_id'], $source['origin']['user_id']);
        self::assertSame($document['content_hash'], $event['base_document_hash']);
        self::assertSame($edited['content_hash'], $event['document_hash']);
        self::assertSame($event['event_hash'], end($edited['transforms'])['event_hash']);
        self::assertSame($input['context']['sources'], array_intersect_key($next['context']['sources'], $input['context']['sources']));
        self::assertArrayNotHasKey('reason', $event, 'Never invent user intent');
        self::assertSame($next, ApplicationWorkflow::revisionInput($input, ['document' => $document], $patches, 'r1', 'task_new', $origin));
    }

    public function testSuccessiveEditsChainAndDependencyRecheckPreservesHistory(): void
    {
        [$input, $document, $patches, $origin] = $this->fixture();
        $first = ApplicationWorkflow::revisionInput($input, ['document' => $document], $patches, 'r1', 'task_new', $origin);
        $document = $first['context']['canonical_input']; $unit = array_values(ContentDocument::units($document))[0];
        $patch = [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => '第二次修改。']];
        $origin['parent_task_id'] = 'task_new'; $origin['task_id'] = 'task_third'; $origin['created_at']++;
        $second = ApplicationWorkflow::revisionInput($first, ['document' => $document], $patch, $document['revision_id'], 'task_third', $origin);
        $history = $second['context']['manual_edit_history']; ManualContentRevision::assertHistory($history);
        self::assertCount(2, $history['events']); self::assertSame($first['context']['manual_edit_history']['events'][0], $history['events'][0]);
        self::assertSame($history['events'][0]['event_hash'], $history['events'][1]['previous_event_hash']);
        self::assertSame($unit['text'], $history['events'][1]['changes'][0]['before_text']);
        self::assertSame($unit['source_links'], $history['events'][1]['changes'][0]['before_source_links']);
        self::assertCount(4, $second['context']['sources']);
        $document = $second['context']['canonical_input'];
        $recheck = ApplicationWorkflow::recheckInput($second, ['document' => $document], ['dependency_versions' => []], $document['revision_id'], 'task_recheck');
        self::assertSame($history, $recheck['context']['manual_edit_history']);
        self::assertSame($document['scenes'], $recheck['context']['canonical_input']['scenes']);
    }

    /** @dataProvider invalidEdits */
    public function testInvalidOrUnchangedSubmissionCannotCreateProvenance(string $kind, int $code): void
    {
        [$input, $document, $patches, $origin] = $this->fixture();
        if ($kind === 'noop') $patches = [$patches[1]];
        if ($kind === 'stale_noop') $patches[1]['before_hash'] = 'stale';
        if ($kind === 'duplicate') $patches[] = $patches[1];
        if ($kind === 'speaker') $patches[0]['speaker_id'] = 'forged';
        if ($kind === 'identity') $origin['user_id'] = 0;
        if ($kind === 'task') $origin['task_id'] = 'wrong';
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode($code);
        ApplicationWorkflow::revisionInput($input, ['document' => $document], $patches, 'r1', 'task_new', $origin);
    }
    public static function invalidEdits(): array
    { return [['noop', 422], ['stale_noop', 409], ['duplicate', 409], ['speaker', 422], ['identity', 409], ['task', 409]]; }

    /** @dataProvider damagedHistory */
    public function testHistoryTamperingOrBrokenEventChainIsRejected(string $kind): void
    {
        [$input, $document, $patches, $origin] = $this->fixture();
        $history = ManualContentRevision::capture($input, $document, $patches, 'r2', $origin)['history'];
        if ($kind === 'text') $history['events'][0]['changes'][0]['before_text'] = 'forged';
        if ($kind === 'chain') {
            $history['events'][0]['previous_event_hash'] = 'missing';
            unset($history['events'][0]['event_hash']); $history['events'][0]['event_hash'] = ContentDocument::hash($history['events'][0]);
            unset($history['history_hash']); $history['history_hash'] = ContentDocument::hash($history);
        }
        if ($kind === 'event') {
            $history['events'][0]['event_hash'] = 'invalid';
            unset($history['history_hash']); $history['history_hash'] = ContentDocument::hash($history);
        }
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(409);
        ManualContentRevision::assertHistory($history);
    }
    public static function damagedHistory(): array { return [['text'], ['chain'], ['event']]; }

    /** @dataProvider invalidSources */
    public function testSourceMustMatchFullTextAndVersion(string $kind): void
    {
        [$input, $document] = $this->fixture(); $unit = array_values(ContentDocument::units($document))[0]; $link = $unit['source_links'][0];
        if ($kind === 'version') $link['source_version'] = 'v2';
        else { $link['end'] = 1; $link['quote'] = mb_substr($unit['text'], 0, 1, 'UTF-8'); }
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(422);
        ContentDocument::attachExactSources($document, [$unit['unit_id'] => [$link]], $input['context']['sources']);
    }
    public static function invalidSources(): array { return [['version'], ['partial']]; }
}
