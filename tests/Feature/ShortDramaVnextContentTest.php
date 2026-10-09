<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument as Document;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler as Compiler;
use app\common\service\app\aigc_short_drama\vnext\ContentCoverage as Coverage;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextContentTest extends TestCase
{
    private function context(): array
    {
        return ['revision_id' => 'r1', 'episode_id' => 'e9', 'entity_scope' => 'tenant:1:project:2',
            'entities' => [['ref' => 'c1', 'entity_id' => 'person1', 'name' => '陈默', 'kind' => 'character', 'identity_facts' => ['age' => 30]],
                ['ref' => 'l1', 'entity_id' => 'room1', 'name' => '书房', 'kind' => 'location']]];
    }
    private function reply(): array
    {
        return ['title' => '首次拆信', 'entities' => [], 'scenes' => [['location' => 'l1', 'time_context' => '夜', 'units' => [
            ['type' => 'action', 'characters' => ['c1'], 'text' => '陈默首次拆开信封。'],
            ['type' => 'dialogue', 'speaker' => 'c1', 'text' => '老师，您好😀！'],
        ]]]];
    }
    private function review(array $document, array $plan): array
    {
        $checks = [];
        foreach (['req1', 'event1', 'aspect:identity', 'aspect:continuity', 'aspect:shot_fidelity'] as $id) {
            $checks[] = ['id' => $id, 'status' => 'covered', 'evidence' => [['unit' => 'u2', 'start' => 0, 'end' => 7, 'quote' => '老师，您好😀！']]];
        }
        return ['document_hash' => $document['content_hash'], 'shot_hash' => $plan['content_hash'], 'checks' => $checks];
    }
    public function testCanonicalTextAndOverlappingVisualsHaveOneAudioUtterance(): void
    {
        $document = Document::normalize($this->reply(), $this->context());
        // PHP offsets count Unicode code points; emoji is one, not two UTF-16 units.
        self::assertSame(7, mb_strlen('老师，您好😀！', 'UTF-8'));
        $plan = Compiler::compile($document, ['shots' => [
            ['visual_description' => '拆信', 'refs' => [['unit' => 'u1'], ['unit' => 'u2']]],
            ['visual_description' => '信件反打', 'refs' => [['unit' => 'u2']]],
        ]], 's1');
        self::assertCount(2, $plan['shots']); self::assertCount(1, $plan['audio_segments']);
        self::assertSame('陈默：老师，您好😀！', Document::render($document)[2]);
        self::assertSame('person1', array_values(Document::units($document))[1]['speaker_id']);
    }
    public function testCompatibleDuplicateEntitiesDoNotOverwriteConfirmedIdentity(): void
    {
        $reply = $this->reply();
        $reply['entities'] = [['ref' => 'c2', 'name' => '陈默', 'kind' => 'character', 'description' => '身穿外套', 'identity_facts' => ['age' => 30]]];
        $reply['scenes'][0]['units'][1]['speaker'] = 'c2';
        $document = Document::normalize($reply, $this->context());
        self::assertCount(2, $document['entities']); self::assertCount(1, $document['transforms']);
        self::assertSame('', $document['entities'][0]['description']);
        self::assertSame(['身穿外套'], $document['entities'][0]['candidate_descriptions']);
        self::assertSame('person1', array_values(Document::units($document))[1]['speaker_id']);
    }
    public function testConflictingIdentityCannotBeSilentlyMerged(): void
    {
        $reply = $this->reply(); $reply['entities'] = [['ref' => 'c1', 'name' => '陈默', 'kind' => 'character', 'identity_facts' => ['age' => 18]]];
        $this->expectExceptionCode(409); Document::normalize($reply, $this->context());
    }
    public function testUnknownSpeakerIsBlocked(): void
    {
        $reply = $this->reply(); $reply['scenes'][0]['units'][1]['speaker'] = '不存在';
        $this->expectExceptionCode(422); Document::normalize($reply, $this->context());
    }
    public function testTwoConfirmedPeopleWithSameNameUseDistinctShortAliases(): void
    {
        $context = $this->context();
        $context['entities'][] = ['ref' => 'c2', 'entity_id' => 'person2', 'name' => '陈默', 'kind' => 'character', 'identity_facts' => ['age' => 18]];
        $reply = $this->reply(); $reply['scenes'][0]['units'][1]['speaker'] = 'c2';
        $document = Document::normalize($reply, $context);
        self::assertSame('person2', array_values(Document::units($document))[1]['speaker_id']);
        $reply['scenes'][0]['units'][1]['speaker'] = '陈默';
        $this->expectExceptionCode(422); Document::normalize($reply, $context);
    }
    public function testMalformedRowsProduceRecoverableContractError(): void
    {
        $reply = $this->reply(); $reply['entities'] = ['not-an-object'];
        $this->expectExceptionCode(422); Document::normalize($reply, $this->context());
    }
    public function testMissingDialogueTailCannotBecomeProductionReady(): void
    {
        $document = Document::normalize($this->reply(), $this->context());
        $this->expectExceptionCode(422);
        Compiler::compile($document, ['shots' => [['visual_description' => '读信', 'refs' => [['unit' => 'u1'], ['unit' => 'u2', 'start' => 0, 'end' => 4]]]]], 's1');
    }
    public function testDialogueTailCannotBeMovedBeforeItsBeginning(): void
    {
        $document = Document::normalize($this->reply(), $this->context());
        $this->expectExceptionCode(422);
        Compiler::compile($document, ['shots' => [
            ['visual_description' => '尾句', 'refs' => [['unit' => 'u1'], ['unit' => 'u2', 'start' => 3, 'end' => 7]]],
            ['visual_description' => '开头', 'refs' => [['unit' => 'u2', 'start' => 0, 'end' => 3]]],
        ]], 's1');
    }
    public function testEvidenceMustMatchUnicodeTextAndCurrentRevision(): void
    {
        self::assertSame('😀', Document::slice('老师，您好😀！', 5, 6, '😀'));
        $this->expectExceptionCode(422); Document::slice('老师，您好😀！', 5, 7, '😀');
    }
    public function testCoverageRequiresEveryApplicableConclusionAndKeepsFutureEventsSeparate(): void
    {
        $document = Document::normalize($this->reply(), $this->context()); $plan = $this->validPlan($document); $review = $this->validReview($document, $plan);
        $report = Coverage::evaluate($document, $plan, [['id' => 'req1']], [['id' => 'event1'], ['id' => 'future', 'episode_ids' => ['e10']]], $review);
        self::assertSame('ready', $report['status']); self::assertSame('not_applicable', $report['coverage'][2]['status']);
        array_shift($review['checks']);
        $this->expectExceptionCode(422); Coverage::evaluate($document, $plan, [['id' => 'req1']], [['id' => 'event1']], $review);
    }
    public function testLocalEditKeepsIdentityAndInvalidatesOnlyDependentUnits(): void
    {
        $before = Document::normalize($this->reply(), $this->context()); $plan = $this->validPlan($before);
        $report = Coverage::evaluate($before, $plan, [['id' => 'req1']], [['id' => 'event1']], $this->validReview($before, $plan));
        $unit = array_values(Document::units($before))[1];
        $after = Document::patch($before, [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => '老师，我们想您了。']], 'r2');
        self::assertSame($unit['unit_id'], array_values(Document::units($after))[1]['unit_id']);
        self::assertSame([$unit['unit_id']], Coverage::impacted($before, $after, $plan, $report)['unit_ids']);
        self::assertNotSame($before['content_hash'], $after['content_hash']);
        $this->expectExceptionCode(409); Compiler::assertCurrent($after, $plan);
    }
    public function testFabricatedEvidenceIsRejected(): void
    {
        $document = Document::normalize($this->reply(), $this->context()); $plan = $this->validPlan($document); $review = $this->validReview($document, $plan);
        $review['checks'][0]['evidence'][0]['quote'] = '信已经烧毁';
        $this->expectExceptionCode(422); Coverage::evaluate($document, $plan, [['id' => 'req1']], [['id' => 'event1']], $review);
    }
    private function validPlan(array $document): array
    {
        return Compiler::compile($document, ['shots' => [['visual_description' => '拆信并读出问候', 'refs' => [['unit' => 'u1'], ['unit' => 'u2']]]]], 's1');
    }
    private function validReview(array $document, array $plan): array
    {
        return $this->review($document, $plan);
    }
    public function testRepairCannotInventASpeakerOrClaimAnUnverifiedMissingEvent(): void
    {
        $doc = Document::normalize($this->reply(), $this->context()); $unit = $doc['scenes'][0]['units'][0];
        $insertion = ['issue_id' => 'missing1', 'anchor_unit_id' => $unit['unit_id'], 'anchor_hash' => $unit['text_hash'],
            'position' => 'after', 'type' => 'dialogue', 'text' => '新增内容。', 'speaker_id' => 'stranger'];
        try { Document::repair($doc, [], [$insertion], 'r2', []); self::fail('Unverified addition accepted'); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        $issues = [['issue_id' => 'missing1', 'severity' => 'blocking', 'code' => 'content_missing']];
        $this->expectExceptionCode(422); Document::repair($doc, [], [$insertion], 'r2', $issues);
    }

}
