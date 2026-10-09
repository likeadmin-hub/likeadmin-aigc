<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\SceneContentRepair;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextSceneContentRepairTest extends TestCase
{
    public static function input(): array
    {
        $input = ShortDramaVnextSceneReviewTest::input(); unset($input['context']['canonical_input']);
        $input['context']['window_content_repair_version'] = 1;
        return $input;
    }
    public static function screenplay(): array
    {
        $units = [];
        for ($i = 1; $i <= 6; $i++) $units[] = ['type' => 'dialogue', 'speaker' => 'c1', 'text' => '第' . $i . '句完整对白😀。'];
        return ['title' => '完整剧本', 'scenes' => [['location' => 'l1', 'units' => $units]]];
    }
    public static function receipt(string $key, array $data, string $mode = 'patch'): array
    {
        if ($key === 'screenplay') $reply = self::screenplay();
        elseif (isset($data['allowed_patch_unit_ids'])) {
            $units = ContentDocument::units($data['document']);
            if ($mode === 'patch') {
                $unit = $units[$data['allowed_patch_unit_ids'][0]];
                $reply = ['patches' => [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => $unit['text'] . '补正。']], 'insertions' => []];
            } else {
                $issue = array_key_first($data['allowed_insertion_anchors']); $anchor = $data['allowed_insertion_anchors'][$issue][0];
                $reply = ['patches' => [], 'insertions' => [['issue_id' => $issue, 'anchor_unit_id' => $anchor, 'anchor_hash' => $units[$anchor]['text_hash'],
                    'position' => 'after', 'type' => 'dialogue', 'text' => '补正：约定的事情已经办好了。', 'entity_ids' => ['char1'], 'speaker_id' => 'char1', 'speech_type' => 'spoken']]];
            }
        } elseif (!isset($data['shot_plan'])) return ShortDramaVnextSceneShotTest::reply($data);
        else {
            $reply = ShortDramaVnextSceneReviewTest::reply($data); $units = array_values(ContentDocument::units($data['document']));
            $fixed = null; $ending = null;
            foreach ($units as $unit) { if (str_contains($unit['text'], '补正')) $fixed = $unit; if ($unit['ref'] === 'u6') $ending = $unit; }
            foreach ($reply['checks'] as &$check) if ($check['id'] === 'ending') {
                $check['status'] = $fixed ? 'covered' : (isset($data['review_window']) ? ($ending && $mode === 'patch' ? 'contradiction' : 'not_observed') : ($mode === 'patch' ? 'contradiction' : 'missing'));
                $check['reason'] = $fixed ? '结尾要求有完整正文证据' : '结尾与确认要求不符或缺少约定事件';
                $evidence = $fixed ?? $ending;
                $check['evidence'] = $evidence ? [['unit' => $evidence['ref'], 'start' => 0, 'end' => mb_strlen($evidence['text']), 'quote' => $evidence['text']]] : [];
            }
            unset($check);
        }
        return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop'];
    }
    private function runCase(string $mode = 'patch', ?callable $provider = null, ?callable $reserve = null, ?array &$snapshots = null, ?array &$keys = null, ?array $context = null): array
    {
        $plan = null;
        return GenerationPipeline::run($context ?? self::input()['context'], static function ($key, $messages) use ($mode, $provider, &$keys) {
            $keys[] = $key; $data = json_decode($messages['content'], true); $data = $data['input'] ?? $data;
            return $provider ? $provider($key, $data) : self::receipt($key, $data, $mode);
        }, $reserve ?? static function () {}, static function ($candidate) use (&$snapshots) { $snapshots[] = $candidate; },
            ['capacity' => [ShortDramaVnextSceneReviewTest::class, 'capacity'],
                'review_plan' => static function ($document, $shots, $proposal) use (&$plan) { if ($proposal !== null) $plan = $proposal; return $plan; }]);
    }
    public function testLocalizedTextFixReplansOnlyOwnerAndRechecksAffectedBoundary(): void
    {
        $snapshots = []; $keys = []; $reservations = [];
        $result = $this->runCase('patch', null, static function ($key, $count) use (&$reservations) { $reservations[$key] = $count; }, $snapshots, $keys);
        self::assertSame(['content_window_repair_1' => 4], $reservations);
        self::assertSame(['content_window_repair_1_text', 'content_window_repair_1_shots_3', 'content_window_repair_1_review_3', 'content_window_repair_1_global'], array_slice($keys, -4));
        self::assertSame('ready', $result['coverage_report']['status']);
        $old = ContentDocument::units($snapshots[0]['document']); $new = ContentDocument::units($result['document']);
        self::assertSame(array_keys($old), array_keys($new));
        foreach ($old as $id => $unit) {
            self::assertSame($unit['speaker_id'], $new[$id]['speaker_id']);
            self::assertSame($unit['entity_ids'], $new[$id]['entity_ids']);
            self::assertSame($unit['ref'] === 'u6' ? $unit['text'] . '补正。' : $unit['text'], $new[$id]['text']);
        }
        self::assertCount(6, $result['shot_plan']['audio_segments']);
        self::assertNotSame($snapshots[0]['document']['content_hash'], $result['document']['content_hash']);
        self::assertCount(1, $result['coverage_report']['window_review']['windows'][0]['reuse_chain']);
        self::assertSame($result['document']['content_hash'], $result['coverage_report']['document_hash']);
    }
    public function testMissingContentInsertionRetainsOriginalUnitsAndAddsOneVoiceSegment(): void
    {
        $snapshots = []; $keys = []; $reservations = [];
        $result = $this->runCase('insert', null, static function ($key, $count) use (&$reservations) { $reservations[$key] = $count; }, $snapshots, $keys);
        self::assertSame(['content_window_repair_1' => 5], $reservations);
        self::assertContains('content_window_repair_1_review_2', $keys); self::assertNotContains('content_window_repair_1_shots_2', $keys);
        $old = ContentDocument::units($snapshots[0]['document']); $new = ContentDocument::units($result['document']);
        foreach ($old as $id => $unit) self::assertSame($unit['text'], $new[$id]['text']);
        self::assertCount(7, $new); self::assertCount(7, $result['shot_plan']['audio_segments']);
        self::assertSame('ready', $result['coverage_report']['status']);
        self::assertSame('char1', end($new)['speaker_id']); self::assertNotEmpty(end($new)['repair_issue_id']);
    }
    public function testFaithfulOrImportedContentRequiresExplicitConfirmation(): void
    {
        foreach (['canonical_input', 'structured_screenplay', 'source_mode'] as $mode) {
            $context = self::input()['context'];
            $context[$mode] = $mode === 'source_mode' ? 'faithful' : ($mode === 'canonical_input' ? ContentDocument::normalize(self::screenplay(), $context) : self::screenplay());
            $snapshots = []; $keys = [];
            $result = $this->runCase('patch', null, static function () { self::fail('Protected text reserved repair'); }, $snapshots, $keys, $context);
            self::assertSame('source_content_requires_confirmation', $result['stop_reason']); self::assertSame('draft', $result['coverage_report']['status']);
        }
    }
    public function testHistoricalJobDoesNotStartNewTextRepair(): void
    {
        $context = self::input()['context']; unset($context['window_content_repair_version']); $snapshots = []; $keys = [];
        $result = $this->runCase('patch', null, static function () { self::fail('Old job repaired'); }, $snapshots, $keys, $context);
        self::assertSame('windowed_content_requires_revision', $result['stop_reason']); self::assertCount(6, $keys);
    }
    public function testFullRoundBudgetRejectionPrecedesTextChanges(): void
    {
        $snapshots = []; $keys = [];
        $result = $this->runCase('patch', null, static function ($key, $calls) { self::assertSame(4, $calls); throw new \RuntimeException('budget', 429); }, $snapshots, $keys);
        self::assertSame('budget_exhausted', $result['stop_reason']); self::assertCount(6, $keys);
        self::assertSame($snapshots[0]['document'], $result['document']);
    }
    public function testOutOfScopeTextPatchGetsOneFormatRepair(): void
    {
        $snapshots = []; $keys = [];
        $result = $this->runCase('patch', static function ($key, $data) {
            $receipt = self::receipt($key, $data);
            if ($key === 'content_window_repair_1_text') { $reply = json_decode($receipt['content'], true); $reply['patches'][0]['unit_id'] = 'outside_window'; $receipt['content'] = json_encode($reply, JSON_UNESCAPED_UNICODE); }
            return $receipt;
        }, null, $snapshots, $keys);
        self::assertContains('content_window_repair_1_text_format', $keys); self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testFailedRecheckDoesNotReplaceBestDraft(): void
    {
        $snapshots = []; $keys = [];
        $result = $this->runCase('patch', static function ($key, $data) {
            $receipt = self::receipt($key, $data);
            if ($key === 'content_window_repair_1_global') {
                $reply = json_decode($receipt['content'], true);
                foreach ($reply['checks'] as &$check) if ($check['id'] === 'aspect:identity') $check['status'] = 'contradiction';
                $receipt['content'] = json_encode($reply, JSON_UNESCAPED_UNICODE);
            }
            return $receipt;
        }, null, $snapshots, $keys);
        self::assertSame('repair_not_improved', $result['stop_reason']); self::assertSame($snapshots[0]['document'], $result['document']);
        self::assertNotSame($result['document']['content_hash'], end($snapshots)['content_repair']['candidate_document']['content_hash']);
    }
    public function testEvidenceRebaseKeepsSameUnitAcrossAliasShiftAndRejectsChangedText(): void
    {
        $base = ContentDocument::normalize(self::screenplay(), self::input()['context']); $units = array_values(ContentDocument::units($base));
        $issue = ['issue_id' => 'missing', 'severity' => 'blocking', 'code' => 'content_missing'];
        $candidate = ContentDocument::repair($base, [], [['issue_id' => 'missing', 'anchor_unit_id' => $units[0]['unit_id'], 'anchor_hash' => $units[0]['text_hash'],
            'position' => 'after', 'type' => 'action', 'text' => '补正动作', 'entity_ids' => ['char1']]], 'r2', [$issue]);
        $review = ['checks' => [['evidence' => [['unit' => 'u6', 'start' => 0, 'end' => mb_strlen($units[5]['text']), 'quote' => $units[5]['text']]]]], 'narrative_changes' => []];
        $rebased = SceneContentRepair::rebase($base, $candidate, $review);
        self::assertSame('u7', $rebased['checks'][0]['evidence'][0]['unit']); self::assertSame($units[5]['text'], $rebased['checks'][0]['evidence'][0]['quote']);
        $candidate = ContentDocument::patch($candidate, [['unit_id' => $units[5]['unit_id'], 'before_hash' => $units[5]['text_hash'], 'text' => '已变更']], 'r3');
        $this->expectExceptionCode(409); SceneContentRepair::rebase($base, $candidate, $review);
    }
}
