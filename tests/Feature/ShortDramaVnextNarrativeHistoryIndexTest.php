<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\NarrativeHistoryIndex;
use app\common\service\app\aigc_short_drama\vnext\PromptProjection;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextNarrativeHistoryIndexTest extends TestCase
{
    /** Synthetic fact dimensions deliberately share long evidence; not a semantic-quality benchmark. */
    public static function fixture(int $count = 60): array
    {
        $input = ShortDramaVnextSceneShotTest::input(); $context = $input['context']; unset($context['canonical_input']);
        $context['revision_id'] = 'past_r1'; $context['episode_id'] = 'past_e1';
        $text = str_repeat('陈默把钥匙放入书房保险柜，小雨以为钥匙仍在抽屉中。', 20) . '原文末尾😀。';
        $document = ContentDocument::normalize(['title' => '已发布历史', 'scenes' => [['location' => 'l1', 'units' => [
            ['type' => 'action', 'characters' => ['c1'], 'text' => $text]]]]], $context);
        $changes = [];
        for ($i = 0; $i < $count; $i++) $changes[] = ['kind' => $i % 2 ? 'belief' : 'world', 'subject_id' => 'char1',
            'predicate' => 'dimension_' . $i, 'value' => $i % 2 ? '相信钥匙还在抽屉中' : '钥匙已经放入保险柜',
            'observer_id' => $i % 2 ? 'char1' : null, 'certainty' => 'confirmed',
            'evidence' => [['unit' => 'u1', 'start' => 0, 'end' => mb_strlen($text), 'quote' => $text]]];
        $ledger = NarrativeLedger::evaluate($document, $changes);
        return [$document, $ledger, $changes];
    }
    public function testSharedEvidenceRoundTripsEveryFactBeliefAndUnicodeQuote(): void
    {
        [$document, $ledger] = self::fixture(); $before = $ledger;
        $history = NarrativeLedger::relevant($ledger, ['char1']); $index = NarrativeHistoryIndex::pack($history);
        self::assertSame(1, $index['evidence_index_version']); self::assertCount(30, $index['world_facts']); self::assertCount(30, $index['character_beliefs']);
        self::assertCount(1, $index['source_documents']); self::assertCount(1, $index['evidence_index']);
        self::assertSame(ContentDocument::hash($history), ContentDocument::hash(NarrativeHistoryIndex::expand($index)));
        self::assertSame($index, NarrativeHistoryIndex::pack($index)); self::assertSame($before, $ledger);
        self::assertSame($document['scenes'][0]['units'][0]['text'], $index['evidence_index']['q1']['link']['quote']);
        self::assertSame($document['content_hash'], $index['source_documents']['h1']['document_hash']);
        self::assertSame($index, PromptProjection::apply(['previous_narrative' => $index])['previous_narrative']);
    }
    public function testEqualUnitIdsAndQuotesInDifferentRevisionsNeverShareSourceReferences(): void
    {
        [$document, $ledger, $changes] = self::fixture(20);
        $next = ContentDocument::patch($document, [], 'r2');
        foreach ($changes as &$change) $change['predicate'] .= '_next'; unset($change);
        $newLedger = NarrativeLedger::evaluate($next, $changes, $ledger);
        $index = NarrativeHistoryIndex::pack(NarrativeLedger::relevant($newLedger, ['char1']));
        self::assertCount(2, $index['source_documents']); self::assertCount(2, $index['evidence_index']);
        self::assertSame($index['evidence_index']['q1']['link'], $index['evidence_index']['q2']['link']);
        self::assertNotSame($index['evidence_index']['q1']['source_ref'], $index['evidence_index']['q2']['source_ref']);
        self::assertSame(ContentDocument::hash(NarrativeLedger::relevant($newLedger, ['char1'])), ContentDocument::hash(NarrativeHistoryIndex::expand($index)));
    }
    public function testEvidenceIndexRejectsMissingReferencesMutationsAndUnknownVersions(): void
    {
        [, $ledger] = self::fixture(); $index = NarrativeHistoryIndex::pack(NarrativeLedger::relevant($ledger, ['char1']));
        foreach (['source', 'evidence', 'quote', 'value', 'omission', 'cross_source', 'version'] as $case) {
            $bad = $index;
            if ($case === 'source') unset($bad['source_documents']['h1']);
            if ($case === 'evidence') unset($bad['evidence_index']['q1']);
            if ($case === 'quote') $bad['evidence_index']['q1']['link']['quote'] = '未来剧情';
            if ($case === 'value') $bad['character_beliefs'][0]['value'] = '知道全部真相';
            if ($case === 'omission') array_pop($bad['world_facts']);
            if ($case === 'cross_source') $bad['evidence_index']['q1']['source_ref'] = 'h2';
            if ($case === 'version') $bad['evidence_index_version'] = 99;
            try { NarrativeHistoryIndex::expand($bad); self::fail('Broken index accepted: ' . $case); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        }
    }
    public function testIndexFitsRealCapacityWithoutDroppingFactsAndOldJobsRetainTheirInput(): void
    {
        [, $ledger] = self::fixture(); $context = ShortDramaVnextSceneShotTest::input()['context']; unset($context['canonical_input']);
        $context['narrative_contract_version'] = 1; $context['previous_narrative'] = $ledger;
        $old = GenerationPipeline::messages(GenerationPipeline::creativeContext($context), GenerationPipeline::screenplayInstruction($context), 1);
        $model = ['context_window' => 32768, 'max_output_tokens' => 8192];
        try { ShortDramaPlanningBudget::stage($old['system_prompt'] . $old['content'], $model, 'script'); self::fail('Fixture should exceed old capacity'); }
        catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        $fresh = $context + ['history_evidence_index_version' => 1];
        $messages = GenerationPipeline::messages(GenerationPipeline::creativeContext($fresh), GenerationPipeline::screenplayInstruction($fresh), 1);
        self::assertGreaterThanOrEqual(1200, ShortDramaPlanningBudget::stage($messages['system_prompt'] . $messages['content'], $model, 'script')['max_tokens']);
        self::assertLessThan(strlen($old['content']), strlen($messages['content']));
        $history = json_decode($messages['content'], true)['previous_narrative'];
        self::assertSame(ContentDocument::hash(NarrativeLedger::relevant($ledger, ['char1'])), ContentDocument::hash(NarrativeHistoryIndex::expand($history)));
        self::assertSame($old, GenerationPipeline::messages(GenerationPipeline::creativeContext($context), GenerationPipeline::screenplayInstruction($context), 1));
        self::assertStringContainsString('历史引文属于其原集数', $messages['system_prompt']);
    }
    public function testSmallHistoryRemainsPlainAndGlobalFactsRemainVisible(): void
    {
        [$document, $ledger, $changes] = self::fixture(1);
        $changes[0]['subject_id'] = 'world'; $ledger = NarrativeLedger::evaluate($document, $changes);
        $history = NarrativeLedger::relevant($ledger, []);
        self::assertCount(1, $history['world_facts']); self::assertSame($history, NarrativeHistoryIndex::pack($history));
        self::assertSame(['world_facts' => [], 'character_beliefs' => []], NarrativeHistoryIndex::pack(NarrativeLedger::relevant([], [])));
    }
    public function testUnknownFrozenVersionAndInvalidLedgerCannotReachModel(): void
    {
        foreach (['version', 'ledger'] as $mode) {
            [, $ledger] = self::fixture(); $context = ShortDramaVnextSceneShotTest::input()['context']; unset($context['canonical_input']);
            $context['history_evidence_index_version'] = $mode === 'version' ? 99 : 1; $context['narrative_contract_version'] = 1;
            $context['previous_narrative'] = $ledger;
            if ($mode === 'ledger') $context['previous_narrative']['state'][array_key_first($ledger['state'])]['value'] = '未经发布的未来';
            try {
                GenerationPipeline::run($context, static function () { self::fail('Invalid history submitted'); }, static function () {}, static function () {});
                self::fail('Invalid history accepted');
            } catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        }
    }
    public function testCreativeReviewAndFormatRepairUseSameHistoricalSelectionAndKeepFullState(): void
    {
        [, $ledger] = self::fixture(); $context = ShortDramaVnextSceneContentRepairTest::input()['context'];
        $context['scene_review_version'] = 0; $context['history_evidence_index_version'] = 1;
        $context['narrative_contract_version'] = 1; $context['previous_narrative'] = $ledger;
        $keys = []; $selection = null; $repairInput = null;
        $result = GenerationPipeline::run($context, static function ($key, $messages) use (&$keys, &$selection, &$repairInput) {
            $keys[] = $key; $data = json_decode($messages['content'], true); $data = $data['input'] ?? $data;
            if (isset($data['previous_narrative'])) {
                self::assertSame(1, $data['previous_narrative']['evidence_index_version']);
                $hash = ContentDocument::hash(NarrativeHistoryIndex::expand($data['previous_narrative']));
                if ($selection !== null) self::assertSame($selection, $hash);
                $selection = $hash;
                self::assertStringContainsString('evidence_refs', $messages['system_prompt']);
            }
            if ($key === 'screenplay') return ['content' => json_encode(ShortDramaVnextSceneContentRepairTest::screenplay(), JSON_UNESCAPED_UNICODE)];
            if ($key === 'repair_1') { $repairInput = $data; return ['content' => '{malformed']; }
            if ($key === 'repair_1_format') {
                self::assertSame($repairInput, $data); $units = array_values(ContentDocument::units($data['document'])); $unit = end($units);
                return ['content' => json_encode(['patches' => [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => $unit['text'] . '补正。']]], JSON_UNESCAPED_UNICODE)];
            }
            $receipt = ShortDramaVnextSceneShotTest::reply($data, $key === 'review');
            if (isset($data['shot_plan'])) { $body = json_decode($receipt['content'], true); $body['narrative_changes'] = []; $receipt['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
            return $receipt;
        }, static function ($key, $calls) { self::assertSame('repair_1', $key); self::assertSame(3, $calls); }, static function () {});
        self::assertSame(['screenplay', 'shots', 'review', 'repair_1', 'repair_1_format', 'repair_1_shots', 'repair_1_review'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertSame($ledger['state'], $result['coverage_report']['narrative_ledger']['state']);
        self::assertSame($selection, $result['coverage_report']['history_retrieval']['selection_hash']);
    }
    public function testTooManyDistinctFactsStillStopWithoutPruning(): void
    {
        [, $ledger] = self::fixture(500); $original = $ledger;
        $context = ShortDramaVnextSceneShotTest::input()['context']; unset($context['canonical_input']);
        $context['history_evidence_index_version'] = 1; $context['narrative_contract_version'] = 1; $context['previous_narrative'] = $ledger;
        $messages = GenerationPipeline::messages(GenerationPipeline::creativeContext($context), GenerationPipeline::screenplayInstruction($context), 1);
        $history = json_decode($messages['content'], true)['previous_narrative'];
        self::assertCount(250, $history['world_facts']); self::assertCount(250, $history['character_beliefs']);
        self::assertSame(ContentDocument::hash(NarrativeLedger::relevant($ledger, ['char1'])), ContentDocument::hash(NarrativeHistoryIndex::expand($history)));
        self::assertSame($original, $context['previous_narrative']);
        $this->expectExceptionCode(413);
        ShortDramaPlanningBudget::stage($messages['system_prompt'] . $messages['content'], ['context_window' => 32768, 'max_output_tokens' => 8192], 'script');
    }
}
