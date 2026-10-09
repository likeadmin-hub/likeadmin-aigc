<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextNarrativeTest extends TestCase
{
    private function document(string $revision = 'r1', string $episode = 'e1', string $action = '阿明把钥匙交给小雨。'): array
    {
        return ContentDocument::normalize(['title' => '真相与误认', 'entities' => [], 'scenes' => [['location' => 'l1', 'units' => [
            ['type' => 'action', 'text' => $action, 'characters' => ['c1','c2'], 'props' => ['p1']],
            ['type' => 'dialogue', 'speaker' => 'c2', 'text' => '我以为钥匙还在阿明手里。'],
        ]]]], ['revision_id' => $revision, 'episode_id' => $episode, 'entity_scope' => 'series1', 'entities' => [
            ['ref' => 'c1', 'entity_id' => 'char1', 'kind' => 'character', 'name' => '阿明'],
            ['ref' => 'c2', 'entity_id' => 'char2', 'kind' => 'character', 'name' => '小雨'],
            ['ref' => 'p1', 'entity_id' => 'key1', 'kind' => 'prop', 'name' => '钥匙'],
            ['ref' => 'l1', 'entity_id' => 'room1', 'kind' => 'location', 'name' => '书房'],
        ]]);
    }
    private function change(string $kind = 'world'): array
    {
        $belief = $kind === 'belief';
        return ['kind' => $kind, 'subject_id' => 'key1', 'predicate' => 'holder', 'value' => $belief ? '阿明' : '小雨',
            'observer_id' => $belief ? 'char2' : null, 'certainty' => 'confirmed',
            'evidence' => [['unit' => $belief ? 'u2' : 'u1', 'start' => 0, 'end' => $belief ? 12 : 11,
                'quote' => $belief ? '我以为钥匙还在阿明手里。' : '阿明把钥匙交给小雨。']]];
    }
    public function testWorldAndBeliefStaySeparateWithRevisionEvidence(): void
    {
        $world = $this->change(); $world['evidence'][0]['end'] = mb_strlen($world['evidence'][0]['quote']);
        $belief = $this->change('belief'); $belief['evidence'][0]['end'] = mb_strlen($belief['evidence'][0]['quote']);
        $doc = $this->document(); $ledger = NarrativeLedger::evaluate($doc, [$world, $belief]);
        self::assertCount(2, $ledger['state']);
        $context = NarrativeLedger::relevant($ledger, ['char2']);
        self::assertCount(1, $context['character_beliefs']); self::assertSame([], $context['world_facts']);
        $context = NarrativeLedger::relevant($ledger, ['key1']);
        self::assertSame('小雨', $context['world_facts'][0]['value']);
        self::assertSame('阿明', $context['character_beliefs'][0]['value']);
        self::assertSame($doc['content_hash'], $context['world_facts'][0]['document_hash']);
        self::assertSame(NarrativeLedger::digest($ledger), $ledger['content_hash']);
    }
    public function testUncertainClaimsNeverBecomeConfirmedStateAndChangesRetainSupersession(): void
    {
        $change = $this->change(); $change['evidence'][0]['end'] = mb_strlen($change['evidence'][0]['quote']);
        $old = NarrativeLedger::evaluate($this->document(), [$change]);
        $change['value'] = '归还阿明'; $change['certainty'] = 'uncertain';
        $next = NarrativeLedger::evaluate($this->document('r2', 'e2'), [$change], $old);
        self::assertSame($old['state'], $next['state']); self::assertCount(1, $next['uncertain']); self::assertSame([], $next['delta']);
        $change['certainty'] = 'confirmed'; $change['evidence'][0]['quote'] = '小雨把钥匙归还阿明。';
        $change['evidence'][0]['end'] = mb_strlen($change['evidence'][0]['quote']);
        $next = NarrativeLedger::evaluate($this->document('r2', 'e2', $change['evidence'][0]['quote']), [$change], $old);
        self::assertSame($old['delta'][0]['fact_id'], $next['delta'][0]['supersedes_fact_id']);
        self::assertSame($old['content_hash'], $next['previous_hash']);
    }
    public function testDialogueCannotPromoteACharactersClaimToWorldFact(): void
    {
        $change = $this->change('belief'); $change['kind'] = 'world'; $change['observer_id'] = null;
        $change['evidence'][0]['end'] = mb_strlen($change['evidence'][0]['quote']);
        $this->expectExceptionCode(422); NarrativeLedger::evaluate($this->document(), [$change]);
    }
    public function testForgedEvidenceAndStaleHistoryAreRejected(): void
    {
        $change = $this->change(); $change['evidence'][0]['quote'] = '未来会归还';
        try { NarrativeLedger::evaluate($this->document(), [$change]); self::fail('Invented evidence accepted'); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        $old = NarrativeLedger::evaluate($this->document(), []); $old['state']['forged'] = ['value' => 'future'];
        $this->expectExceptionCode(409); NarrativeLedger::evaluate($this->document('r2', 'e2'), [], $old);
    }
}
