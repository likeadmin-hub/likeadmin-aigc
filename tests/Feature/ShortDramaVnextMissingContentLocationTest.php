<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\MissingContentLocation;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextMissingContentLocationTest extends TestCase
{
    public static function input(): array
    {
        $input = ShortDramaVnextSceneContentRepairTest::input(); $input['context']['missing_content_location_version'] = 1;
        return $input;
    }
    public static function receipt(string $key, array $data): array
    {
        if (isset($data['localization_issues'])) {
            $units = array_values(ContentDocument::units($data['document'])); $last = end($units);
            $rows = [];
            foreach ($data['localization_issues'] as $issue) $rows[] = ['issue_id' => $issue['issue_id'], 'status' => 'located', 'reason' => '在本集最后一句后补入已确认的结尾事件',
                'anchor_unit_id' => $last['unit_id'], 'anchor_hash' => $last['text_hash'], 'position' => 'after',
                'evidence' => [['unit' => $last['ref'], 'start' => 0, 'end' => mb_strlen($last['text']), 'quote' => $last['text']]]];
            return ['content' => json_encode(['document_hash' => $data['document']['content_hash'], 'shot_hash' => $data['shot_hash'], 'locations' => $rows], JSON_UNESCAPED_UNICODE)];
        }
        $receipt = ShortDramaVnextSceneContentRepairTest::receipt($key, $data, 'insert');
        if ($key === 'review_global') {
            $reply = json_decode($receipt['content'], true);
            foreach ($reply['checks'] as &$check) if ($check['id'] === 'ending') $check['evidence'] = [];
            unset($check); $receipt['content'] = json_encode($reply, JSON_UNESCAPED_UNICODE);
        }
        return $receipt;
    }
    private function runCase(?callable $provider = null, ?callable $reserve = null, ?array &$keys = null, ?array &$snapshots = null, ?array $context = null): array
    {
        $plan = null;
        return GenerationPipeline::run($context ?? self::input()['context'], static function ($key, $messages) use ($provider, &$keys) {
            $keys[] = $key; $data = json_decode($messages['content'], true); $data = $data['input'] ?? $data;
            return $provider ? $provider($key, $data) : self::receipt($key, $data);
        }, $reserve ?? static function () {}, static function ($candidate) use (&$snapshots) { $snapshots[] = $candidate; },
            ['capacity' => [ShortDramaVnextSceneReviewTest::class, 'capacity'],
                'review_plan' => static function ($document, $shots, $proposal) use (&$plan) { if ($proposal !== null) $plan = $proposal; return $plan; }]);
    }
    public function testUnlocatedOmissionIsLocatedThenRepairedWithoutForgingOriginalEvidence(): void
    {
        $keys = []; $snapshots = []; $reserved = [];
        $result = $this->runCase(null, static function ($key, $calls) use (&$reserved) { $reserved[$key] = $calls; }, $keys, $snapshots);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertSame(['content_window_repair_1' => 5], $reserved);
        self::assertSame(['content_window_location_1', 'content_window_repair_1_text', 'content_window_repair_1_shots_3',
            'content_window_repair_1_review_2', 'content_window_repair_1_review_3', 'content_window_repair_1_global'], array_slice($keys, -6));
        $issue = $result['coverage_report']['content_repair']['issues'][0];
        self::assertSame('content_missing', $issue['code']); self::assertSame([], $issue['evidence']);
        self::assertSame('after', $issue['insertion_location']['position']); self::assertSame('u6', $issue['insertion_location']['evidence'][0]['unit']);
        $located = array_values(array_filter($snapshots, static fn($s) => isset($s['missing_location'])))[0];
        self::assertSame($located['missing_location']['location_hash'], $issue['insertion_location']['location_hash']);
        self::assertSame($located['missing_location'], $result['coverage_report']['content_repair']['history'][0]['missing_location']);
        self::assertSame([], $located['coverage_report']['issues'][0]['repair_unit_ids']);
        self::assertSame('draft', $located['coverage_report']['status']);
        $old = ContentDocument::units($snapshots[0]['document']); $new = ContentDocument::units($result['document']);
        foreach ($old as $id => $unit) self::assertSame($unit['text'], $new[$id]['text']);
        self::assertCount(7, $result['shot_plan']['audio_segments']); self::assertSame($issue['issue_id'], end($new)['repair_issue_id']);
    }
    public function testUncertainLocationLeavesContentAndRepairBudgetUntouched(): void
    {
        $keys = []; $snapshots = [];
        $result = $this->runCase(static function ($key, $data) {
            $receipt = self::receipt($key, $data);
            if (isset($data['localization_issues'])) {
                $reply = json_decode($receipt['content'], true); $id = $reply['locations'][0]['issue_id'];
                $reply['locations'] = [['issue_id' => $id, 'status' => 'uncertain', 'reason' => '需要新人物，现有位置无法安全承接']]; $receipt['content'] = json_encode($reply);
            }
            return $receipt;
        }, static function () { self::fail('Uncertain location reserved repair'); }, $keys, $snapshots);
        self::assertSame('missing_location_unresolved', $result['stop_reason']); self::assertSame($snapshots[0]['document'], $result['document']);
        self::assertCount(7, $keys); self::assertSame('draft', $result['coverage_report']['status']);
    }
    public function testLocalizationUsesOneFormatRepairAndInsertionCannotSwitchSides(): void
    {
        $keys = []; $snapshots = [];
        $result = $this->runCase(static function ($key, $data) {
            $receipt = self::receipt($key, $data); $reply = json_decode($receipt['content'], true);
            if ($key === 'content_window_location_1') $reply['locations'][0]['evidence'][0]['quote'] = '伪造引文';
            if ($key === 'content_window_repair_1_text') $reply['insertions'][0]['position'] = 'before';
            $receipt['content'] = json_encode($reply, JSON_UNESCAPED_UNICODE); return $receipt;
        }, null, $keys, $snapshots);
        self::assertSame('ready', $result['coverage_report']['status']);
        self::assertContains('content_window_location_1_format', $keys); self::assertContains('content_window_repair_1_text_format', $keys);
    }
    public function testBudgetRejectionAfterLocationKeepsEvidenceButDoesNotStartTextRepair(): void
    {
        $keys = []; $snapshots = [];
        $result = $this->runCase(null, static function () { throw new \RuntimeException('budget', 429); }, $keys, $snapshots);
        self::assertSame('budget_exhausted', $result['stop_reason']); self::assertCount(7, $keys);
        self::assertSame($snapshots[0]['document'], $result['document']); self::assertNotEmpty($result['missing_location']);
    }
    public function testHistoricalAndProtectedContextsDoNotRequestLocalization(): void
    {
        foreach (['old', 'faithful', 'import'] as $mode) {
            $context = self::input()['context'];
            if ($mode === 'old') unset($context['missing_content_location_version']);
            if ($mode === 'faithful') $context['source_mode'] = 'faithful';
            if ($mode === 'import') $context['structured_screenplay'] = ShortDramaVnextSceneContentRepairTest::screenplay();
            $keys = []; $snapshots = [];
            $result = $this->runCase(null, static function () { self::fail('Protected/old input reserved repair'); }, $keys, $snapshots, $context);
            self::assertNotContains('content_window_location_1', $keys);
            self::assertSame($mode === 'old' ? 'windowed_content_requires_revision' : 'source_content_requires_confirmation', $result['stop_reason']);
        }
    }
    public function testBoundaryEvidenceMustIncludeTheInsertionSideNeighbor(): void
    {
        $doc = ShortDramaVnextSceneShotTest::input()['context']['canonical_input']; $units = array_values(ContentDocument::units($doc));
        $shots = ['content_hash' => 'shots']; $issues = [['issue_id' => 'missing']]; $anchor = $units[2];
        $evidence = static fn($u) => ['unit' => $u['ref'], 'start' => 0, 'end' => mb_strlen($u['text']), 'quote' => $u['text']];
        $reply = ['document_hash' => $doc['content_hash'], 'shot_hash' => 'shots', 'locations' => [['issue_id' => 'missing', 'status' => 'located', 'reason' => '在前后句之间补充缺失事件',
            'anchor_unit_id' => $anchor['unit_id'], 'anchor_hash' => $anchor['text_hash'], 'position' => 'before', 'evidence' => [$evidence($anchor), $evidence($units[1])]]]];
        self::assertCount(1, MissingContentLocation::validate($doc, $shots, $issues, [$anchor['unit_id']], $reply));
        foreach (['missing_neighbor', 'wrong_side', 'wrong_hash', 'wrong_version', 'unknown_issue', 'outside_anchor', 'duplicate', 'missing_issue', 'rewrite'] as $case) {
            $bad = $reply;
            if ($case === 'missing_neighbor') array_pop($bad['locations'][0]['evidence']);
            if ($case === 'wrong_side') $bad['locations'][0]['evidence'][1] = $evidence($units[3]);
            if ($case === 'wrong_hash') $bad['locations'][0]['anchor_hash'] = 'wrong';
            if ($case === 'wrong_version') $bad['document_hash'] = 'old';
            if ($case === 'unknown_issue') $bad['locations'][0]['issue_id'] = 'unknown';
            if ($case === 'outside_anchor') $bad['locations'][0]['anchor_unit_id'] = $units[0]['unit_id'];
            if ($case === 'duplicate') $bad['locations'][] = $bad['locations'][0];
            if ($case === 'missing_issue') $bad['locations'] = [];
            if ($case === 'rewrite') $bad['locations'][0]['text'] = '偷偷创作';
            try { MissingContentLocation::validate($doc, $shots, $issues, [$anchor['unit_id']], $bad); self::fail('Invalid location accepted: ' . $case); }
            catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        }
    }
    public function testMixedUncertainIssueDoesNotAuthorizeMissingContentLocation(): void
    {
        $keys = []; $snapshots = [];
        $result = $this->runCase(static function ($key, $data) {
            $receipt = self::receipt($key, $data);
            if ($key === 'review_global') {
                $reply = json_decode($receipt['content'], true);
                foreach ($reply['checks'] as &$check) if ($check['id'] === 'aspect:continuity') {
                    $check['status'] = 'uncertain'; $check['reason'] = '人物认知不确定';
                }
                unset($check); $receipt['content'] = json_encode($reply, JSON_UNESCAPED_UNICODE);
            }
            return $receipt;
        }, static function () { self::fail('Mixed issues reserved repair'); }, $keys, $snapshots);
        self::assertSame('windowed_content_requires_revision', $result['stop_reason']); self::assertCount(6, $keys);
    }
    public function testNewProblemAfterLocatedInsertionCannotReplaceBestDraft(): void
    {
        $keys = []; $snapshots = [];
        $result = $this->runCase(static function ($key, $data) {
            $receipt = self::receipt($key, $data);
            if ($key === 'content_window_repair_1_global') {
                $reply = json_decode($receipt['content'], true);
                foreach ($reply['checks'] as &$check) if ($check['id'] === 'aspect:identity') {
                    $check['status'] = 'contradiction'; $check['reason'] = '补入台词不符合登记身份';
                }
                unset($check); $receipt['content'] = json_encode($reply, JSON_UNESCAPED_UNICODE);
            }
            return $receipt;
        }, null, $keys, $snapshots);
        self::assertSame('repair_not_improved', $result['stop_reason']); self::assertSame($snapshots[0]['document'], $result['document']);
        self::assertCount(7, ContentDocument::units(end($snapshots)['content_repair']['candidate_document']));
        self::assertSame('content_missing', $result['coverage_report']['issues'][0]['code']);
        self::assertNotEmpty(end($snapshots)['missing_location']['location_hash']);
    }
    public function testLocalizationCapacityFailureKeepsBestDraftAndNeverStartsRepair(): void
    {
        $keys = []; $snapshots = [];
        try {
            $this->runCase(static function ($key, $data) {
                if (isset($data['localization_issues'])) throw new \RuntimeException('input capacity', 413);
                return self::receipt($key, $data);
            }, static function () { self::fail('Unlocated content reserved repair'); }, $keys, $snapshots);
            self::fail('Capacity failure ignored');
        } catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        self::assertSame($snapshots[0]['document'], end($snapshots)['document']); self::assertSame('draft', end($snapshots)['coverage_report']['status']);
        self::assertCount(7, $keys);
    }
}
