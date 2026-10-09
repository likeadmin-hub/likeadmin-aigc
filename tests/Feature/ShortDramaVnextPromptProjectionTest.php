<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\PromptProjection;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextPromptProjectionTest extends TestCase
{
    private function document(array $dependencies = []): array
    {
        return ContentDocument::normalize(['title' => '末尾不能丢', 'scenes' => [['location' => 'l1', 'time_context' => '夜', 'units' => [
            ['type' => 'dialogue', 'speaker' => 'c1', 'text' => "先说你好😀，\n再说最后一句。"],
        ]]]], ['revision_id' => 'r1', 'episode_id' => 'series:7:episode:500', 'entity_scope' => 't1:p1', 'dependency_versions' => $dependencies,
            'entities' => [['entity_id' => 'c1id', 'ref' => 'c1', 'kind' => 'character', 'name' => '陈默', 'identity_facts' => ['age' => 40]],
                ['entity_id' => 'l1id', 'ref' => 'l1', 'kind' => 'location', 'name' => '书房']]]);
    }
    public function testDocumentAndShotCopiesRetainAllContentIdentityAndOffsets(): void
    {
        $document = $this->document(['episodes' => ['1' => ['revision_id' => 'prior']]]);
        $plan = ShotCompiler::compile($document, ['shots' => [['visual_description' => '陈默问候', 'refs' => [['unit' => 'u1']]],
            ['visual_description' => '老师倾听', 'refs' => [['unit' => 'u1']]]]], 's1');
        $data = ['document' => $document, 'shot_plan' => $plan]; $before = $data;
        $projected = PromptProjection::apply($data);
        self::assertSame($before, $data, 'Canonical input must never be mutated');
        self::assertSame($document['entities'], $projected['document']['entities']);
        self::assertSame($document['scenes'], $projected['document']['scenes']);
        self::assertSame($document['content_hash'], $projected['document']['content_hash']);
        self::assertSame($plan['content_hash'], $projected['shot_plan']['content_hash']);
        self::assertArrayNotHasKey('dependency_versions', $projected['document']);
        self::assertCount(1, $projected['shot_plan']['audio_segments']);
        foreach ($projected['shot_plan']['shots'] as $i => $shot) {
            self::assertSame($plan['shots'][$i]['visual_description'], $shot['visual_description']);
            self::assertTrue($shot['dialogue_from_unit_refs']); self::assertArrayNotHasKey('dialogue_preview', $shot);
            $ref = $shot['unit_refs'][0]; $unit = ContentDocument::units($document)[$ref['unit_id']];
            self::assertSame($plan['shots'][$i]['dialogue_preview'], '陈默：' . ContentDocument::slice($unit['text'], $ref['start'], $ref['end']));
        }
        self::assertSame($projected, PromptProjection::apply($projected));
    }
    public function testNonDuplicateShotTextIsNeverDiscarded(): void
    {
        $document = $this->document();
        $plan = ShotCompiler::compile($document, ['shots' => [['visual_description' => '问候', 'refs' => [['unit' => 'u1']]]]], 's1');
        $plan['shots'][0]['dialogue_preview'] .= '额外文字保留';
        $projected = PromptProjection::apply(['document' => $document, 'shot_plan' => $plan]);
        self::assertSame($plan['shots'][0]['dialogue_preview'], $projected['shot_plan']['shots'][0]['dialogue_preview']);
    }
    public function testSourcesOnlyUseReferencesWhenExactFullTextAlreadyExists(): void
    {
        $data = ['prompt' => "保留原话😀\n末尾。", 'sources' => [
            'first' => ['source_id' => 'first', 'text' => "保留原话😀\n末尾。", 'hash' => 'hash1'],
            'extra' => ['source_id' => 'extra', 'text' => '独立附件中的额外要求'],
            'near' => ['source_id' => 'near', 'text' => '保留原话😀 末尾。'],
        ], 'input_history' => [['text' => '第二次意见必须保留', 'source_id' => 'later']],
            'requirements' => [['id' => 'r1', 'text' => '核心要求']], 'events' => [['id' => 'e1', 'text' => '实际事件']]];
        $data['sources']['later'] = $data['input_history'][0];
        $projected = PromptProjection::apply($data);
        self::assertSame(['source_id' => 'first', 'hash' => 'hash1', 'text_ref' => 'prompt'], $projected['sources']['first']);
        self::assertSame('input_history.0.text', $projected['sources']['later']['text_ref']);
        foreach (['extra', 'near'] as $key) self::assertSame($data['sources'][$key], $projected['sources'][$key]);
        foreach (['prompt', 'input_history', 'requirements', 'events'] as $key) self::assertSame($data[$key], $projected[$key]);
    }
    public function testAllFactsBeliefsObserversAndQuotesRemainPresent(): void
    {
        $record = ['fact_id' => 'f1', 'subject_id' => 'c1', 'observer_id' => 'c2', 'kind' => 'belief', 'predicate' => 'alive',
            'value' => '误以为陈默已死', 'certainty' => 'confirmed', 'document_hash' => 'h1', 'episode_id' => 'e4',
            'evidence' => [['unit_id' => 'u1', 'start' => 0, 'end' => 3, 'quote' => '他死了', 'text_hash' => 'h2']]];
        $data = ['previous_narrative' => ['world_facts' => [array_replace($record, ['kind' => 'world', 'observer_id' => null, 'value' => '仍然活着'])], 'character_beliefs' => [$record]]];
        $projected = PromptProjection::apply($data);
        foreach (['world_facts', 'character_beliefs'] as $group) {
            $original = $data['previous_narrative'][$group][0]; unset($original['document_hash'], $original['evidence'][0]['text_hash']);
            self::assertSame([$original], $projected['previous_narrative'][$group]);
        }
    }
    public function testFiveHundredEpisodeDependencyVectorDoesNotConsumeModelWindow(): void
    {
        $dependencies = [];
        for ($episode = 1; $episode < 500; $episode++) $dependencies[$episode] = [
            'revision_id' => 'revision_' . hash('sha256', 'revision' . $episode),
            'document_hash' => hash('sha256', 'document' . $episode), 'ledger_hash' => hash('sha256', 'ledger' . $episode)];
        $document = $this->document(['episodes' => $dependencies]);
        $data = ['document' => $document];
        $model = ['context_window' => 32768, 'max_output_tokens' => 8192];
        $json = static fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try { ShortDramaPlanningBudget::stage($json($data), $model, 'script'); self::fail('Fixture did not reproduce capacity failure'); }
        catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        $projected = PromptProjection::apply($data);
        self::assertGreaterThan(1200, ShortDramaPlanningBudget::stage($json($projected), $model, 'script')['max_tokens']);
        self::assertLessThan(strlen($json($data)) / 10, strlen($json($projected)));
        self::assertCount(499, $document['dependency_versions']['episodes']);
        self::assertSame($document['scenes'], $projected['document']['scenes']);
    }
    public function testUniqueLongContentStillFailsCapacityRatherThanBeingTruncated(): void
    {
        $text = str_repeat('原文必须保留。', 10000) . '最后一句😀';
        $data = ['prompt' => $text, 'requirements' => [['id' => 'r1', 'text' => $text]]];
        $projected = PromptProjection::apply($data); self::assertSame($data, $projected);
        $this->expectExceptionCode(413);
        ShortDramaPlanningBudget::stage(json_encode($projected, JSON_UNESCAPED_UNICODE), ['context_window' => 32768, 'max_output_tokens' => 8192], 'script');
    }
}
