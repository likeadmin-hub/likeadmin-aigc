<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaContinuityPatch as Patch;
use PHPUnit\Framework\TestCase;

class ShortDramaContinuityPatchTest extends TestCase
{
    public function testMeaningPromptSeparatesPreviousPremisesFromCurrentEvidence(): void
    {
        $context = ['continuity' => ['state' => ['p1:item_owner' => '钥匙'], 'open_hooks' => ['h1' => '次日赴约']]];
        $review = ['changes' => [], 'hooks' => [['id' => 'h1', 'description' => '次日赴约', 'status' => 'resolved']]];
        $input = json_decode(Patch::meaningMessages($this->plan(), $review, $context)['content'], true);
        self::assertSame($context['continuity']['state'], $input['previous_state']);
        self::assertSame($context['continuity']['open_hooks'], $input['previous_open_hooks']);
        self::assertSame($review, $input['review']);
        self::assertSame($this->plan()['storyboard'], $input['storyboard']);
        self::assertStringContainsString('不要求本集重新发生或复述该约定', implode('\n', $input['instructions']));
        self::assertStringContainsString('一个子句得到支持不能证明整条复合after', implode('\n', $input['instructions']));
    }

    public function testCorrectionMayEchoButCannotChangeHookStatus(): void
    {
        $review = ['summary' => '本集', 'changes' => [], 'hooks' => [
            ['id' => 'h', 'description' => '发现线索', 'status' => 'open', 'shot_id' => '1-2', 'quote' => '概括']], 'warnings' => []];
        $method = new \ReflectionMethod(\app\common\service\app\aigc_short_drama\ShortDramaContinuity::class, 'applyEvidencePatches');
        $method->setAccessible(true);
        $patch = ['collection' => 'hooks', 'index' => 0, 'status' => 'open', 'id' => 'h', 'description' => '发现线索',
            'evidence_refs' => [['shot_id' => '1', 'field' => 'visual_description']]];
        $fixed = $method->invoke(null, $review, ['evidence_patches' => [$patch]], $this->plan());
        self::assertSame('open', $fixed['hooks'][0]['status']);
        self::assertSame([['shot_id' => '1', 'quote' => '原画面']], $fixed['hooks'][0]['evidence']);
        $patch['status'] = 'resolved';
        $this->expectExceptionCode(422);
        $method->invoke(null, $review, ['evidence_patches' => [$patch]], $this->plan());
    }
    public function testMalformedAuditPreservesScriptButDoesNotClaimVerifiedFacts(): void
    {
        $plan = $this->plan();
        $context = ['continuity' => ['state' => ['p1:status' => 'alive'], 'open_hooks' => ['h1' => '尚未解决']]];
        $ledger = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($plan, $context, 1,
            static function () { throw new \RuntimeException('模型输出不是完整 JSON，已保存返回内容', 422); },
            static function () { self::fail('Malformed audit cannot be verified'); });
        self::assertSame('pending_review', $ledger['audit_status']);
        self::assertSame($context['continuity']['state'], $ledger['state']);
        self::assertSame($context['continuity']['open_hooks'], $ledger['open_hooks']);
        self::assertSame($this->plan(), $plan);
    }

    public function testAuditSafetyRefusalCannotBecomePendingReview(): void
    {
        $this->expectExceptionCode(403);
        \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($this->plan(), [], 1,
            static function () { throw new \RuntimeException('内容拒绝', 403); }, static fn ($r) => $r);
    }

    public function testMalformedReviewCannotApproveAddedScriptContent(): void
    {
        $plan = $this->plan(); $plan['_continuity_source_patch'] = ['shot_insertions' => [[]]];
        $this->expectExceptionCode(422);
        \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($plan, [], 1,
            static function () { throw new \RuntimeException('模型输出不是完整 JSON，已保存返回内容', 422); }, static fn ($r) => $r);
    }
    public function testUnsupportedOpenHookDoesNotBlockOrPolluteLedger(): void
    {
        $review = ['summary' => '本集', 'changes' => [], 'hooks' => [
            ['id' => 'invented', 'status' => 'open', 'description' => '正文没有的婚事', 'shot_id' => '1', 'quote' => '原画面']], 'warnings' => []];
        $verified = Patch::verifiedReview($review, ['checks' => [['collection' => 'hooks', 'index' => 0, 'supported' => false, 'reason' => '正文未出现']]], []);
        $ledger = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::ledger($verified, $this->plan(), [], 1);
        self::assertSame([], $ledger['open_hooks']);
        self::assertSame($review['hooks'][0], $ledger['unverified_claims'][0]['claim']);
        self::assertNotEmpty($ledger['warnings']);
    }

    public function testInvalidReferenceIsQuarantinedWithoutRewritingScript(): void
    {
        $plan = $this->plan();
        $review = ['summary' => '本集', 'changes' => [], 'hooks' => [
            ['id' => 'invented', 'status' => 'open', 'description' => '不存在', 'shot_id' => null, 'quote' => null]], 'warnings' => []];
        $calls = 0;
        $ledger = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($plan, [], 1,
            static function () use ($review, &$calls) { $calls++; return $review; },
            static fn ($filtered) => Patch::verifiedReview($filtered, ['checks' => []], []));
        self::assertSame(3, $calls);
        self::assertSame([], $ledger['open_hooks']);
        self::assertCount(1, $ledger['unverified_claims']);
        self::assertSame($this->plan(), $plan);
        self::assertSame(implode("\n", $plan['script_lines']), $ledger['summary']);
    }

    public function testUnsupportedStateDoesNotOverwritePreviousState(): void
    {
        $review = ['summary' => '本集', 'changes' => [['entity_id' => 'p1', 'field' => 'status', 'before' => 'alive', 'after' => 'dead', 'shot_id' => '1', 'quote' => '原画面']], 'hooks' => [], 'warnings' => []];
        $context = ['continuity' => ['state' => ['p1:status' => 'alive']]];
        $verified = Patch::verifiedReview($review, ['checks' => [['collection' => 'changes', 'index' => 0, 'supported' => false, 'reason' => '没有死亡证据']]], $context);
        $ledger = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::ledger($verified, $this->plan(), $context, 1);
        self::assertSame($context['continuity']['state'], $ledger['state']);
    }

    public function testQuarantineStillRejectsIncompleteVerifier(): void
    {
        $this->expectExceptionCode(422);
        Patch::verifiedReview(['changes' => [[]], 'hooks' => [[]]], ['checks' => [
            ['collection' => 'hooks', 'index' => 0, 'supported' => false, 'reason' => '无依据']]], []);
    }

    public function testUnsupportedTransitionDoesNotCreateFalseConflictInFollowingTransition(): void
    {
        $review = ['summary' => '本集', 'changes' => [
            ['entity_id' => 'p1', 'field' => 'status', 'before' => 'alive', 'after' => 'injured', 'shot_id' => '1', 'quote' => '原画面'],
            ['entity_id' => 'p1', 'field' => 'status', 'before' => 'injured', 'after' => 'recovered', 'shot_id' => '1', 'quote' => '原画面']], 'hooks' => [], 'warnings' => []];
        $context = ['continuity' => ['state' => ['p1:status' => 'alive']]];
        $ledger = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($this->plan(), $context, 1,
            static fn () => $review, static fn ($r) => Patch::verifiedReview($r, ['checks' => [
                ['collection' => 'changes', 'index' => 0, 'supported' => false, 'reason' => '无受伤依据'],
                ['collection' => 'changes', 'index' => 1, 'supported' => true, 'reason' => '恢复']]], $context));
        self::assertSame($context['continuity']['state'], $ledger['state']);
        self::assertCount(2, $ledger['unverified_claims']);
    }

    public function testSupportedCrossEpisodeConflictIsStillBlocked(): void
    {
        $review = ['summary' => '本集', 'changes' => [
            ['entity_id' => 'p1', 'field' => 'status', 'before' => 'alive', 'after' => 'injured', 'shot_id' => '1', 'quote' => '原画面']], 'hooks' => [], 'warnings' => []];
        $context = ['continuity' => ['state' => ['p1:status' => 'dead']]];
        $this->expectExceptionCode(409);
        \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($this->plan(), $context, 1,
            static fn () => $review, static fn ($r) => Patch::verifiedReview($r, ['checks' => [
                ['collection' => 'changes', 'index' => 0, 'supported' => true, 'reason' => '正文支持']]], $context));
    }

    public function testUnsupportedScriptInsertionStillBlocks(): void
    {
        $this->expectExceptionCode(460);
        Patch::verifiedReview(['changes' => [], 'hooks' => [[]]], ['checks' => [
            ['collection' => 'hooks', 'index' => 0, 'supported' => false, 'reason' => '无依据'],
            ['collection' => 'insertions', 'index' => 0, 'supported' => false, 'reason' => '改写了剧情']]], [], ['shot_insertions' => [[]]]);
    }

    public function testDiagnosticAliasDoesNotChangeRejectedVerdict(): void
    {
        $this->expectExceptionCode(460);
        Patch::assertMeaning(['changes' => [[]]], ['checks' => [[
            'collection' => 'changes', 'index' => 0, 'supported' => false, 'description' => '镜头未展示此事实',
        ]]]);
    }
    public function testDiagnosticAliasStillRejectsNonBooleanVerdict(): void
    {
        $this->expectExceptionCode(422);
        Patch::assertMeaning(['changes' => [[]]], ['checks' => [[
            'collection' => 'changes', 'index' => 0, 'supported' => 'true', 'description' => '说明',
        ]]]);
    }
    private function plan(): array
    {
        return ['script_lines' => ['甲否认婚约。'], 'subjects' => [['id' => 'p1', 'name' => '玉佩']],
            'locations' => [['id' => 'l1', 'name' => '大厅']],
            'storyboard' => [['shot_id' => '1', 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['p1'],
                'visual_description' => '原画面', 'video_url' => 'retained']]];
    }
    private function patch(): array
    {
        return ['entity_id_remaps' => [['collection' => 'subjects', 'from' => 'p1', 'to' => 'p2', 'name' => '玉佩']],
            'shot_insertions' => [['after_shot_id' => '1', 'source_line_index' => 0, 'source_quote' => '甲否认婚约。',
                'shot' => ['shot_id' => 'repair_1', 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['p2'],
                    'visual_description' => '甲否认婚约。', 'dialogue' => '', 'voice_role' => '', 'speech_type' => 'none', 'recommended_duration_seconds' => 4]]]];
    }
    public function testOnlyAddedShotsAndLocalEntityReferencesChange(): void
    {
        $original = $this->plan(); $result = Patch::apply($original, $this->patch(), [], []);
        $expected = $original['storyboard'][0]; $expected['subject_ref_ids'] = ['p2'];
        self::assertSame($expected, $result['plan']['storyboard'][0]);
        self::assertSame($original['script_lines'], $result['plan']['script_lines']);
        self::assertSame('玉佩', $result['plan']['subjects'][0]['name']);
        self::assertSame(['repair_1'], $result['added_ids']);
        self::assertSame($this->plan(), $original);
    }
    public function testCannotReusePreviousEpisodeId(): void
    {
        $this->expectExceptionCode(422);
        Patch::apply($this->plan(), $this->patch(), ['continuity' => ['state' => ['p2:item' => '古镜']]], []);
    }
    public function testCannotInventSourceOrOverwriteShotOrDuration(): void
    {
        foreach (['source', 'id', 'duration', 'field', 'quote'] as $case) {
            $patch = $this->patch();
            if ($case === 'source') $patch['shot_insertions'][0]['source_quote'] = '新编剧情';
            if ($case === 'id') $patch['shot_insertions'][0]['shot']['shot_id'] = '1';
            if ($case === 'duration') $patch['shot_insertions'][0]['shot']['recommended_duration_seconds'] = 90;
            if ($case === 'field') $patch['story_outline'] = '改写';
            if ($case === 'quote') $patch['shot_insertions'][0]['shot']['visual_description'] = '';
            try { Patch::apply($this->plan(), $patch, [], []); self::fail($case); }
            catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
        }
    }
    public function testEmptyPatchDoesNotPretendToRepair(): void
    {
        $result = Patch::apply($this->plan(), ['entity_id_remaps' => [], 'shot_insertions' => []], [], []);
        self::assertFalse($result['changed']); self::assertSame($this->plan(), $result['plan']);
    }
    public function testNormalizerReorderingCannotReplaceExistingShotOrMoveInsertion(): void
    {
        $shots = [['shot_id' => '1', 'visual_description' => '原镜头', 'video_url' => 'keep'],
            ['shot_id' => 'repair_1', 'visual_description' => '新增镜头']];
        $normalized = [['shot_id' => 'repair_1', 'visual_description' => '新增镜头', 'image_prompt' => '新增提示'],
            ['shot_id' => '1', 'visual_description' => '不能替换原镜头']];
        $result = Patch::mergeNormalizedShots($shots, $normalized, ['repair_1']);
        self::assertSame($shots[0], $result[0]); self::assertSame($normalized[0], $result[1]);
        $this->expectExceptionCode(422);
        Patch::mergeNormalizedShots($shots, [$normalized[1], $normalized[1]], ['repair_1']);
    }
    public function testInsertedShotsCannotEscapeSourceMeaningVerification(): void
    {
        $this->expectExceptionCode(422);
        Patch::assertMeaning(['changes' => [], 'hooks' => []], ['checks' => []], $this->patch());
    }
    public function testExactUniqueSourceQuoteCanCorrectAnOffByOneIndex(): void
    {
        $patch = $this->patch(); $patch['shot_insertions'][0]['source_line_index'] = 1;
        $result = Patch::apply($this->plan(), $patch, [], []);
        self::assertSame(0, $result['patch']['shot_insertions'][0]['source_line_index']);
        $plan = $this->plan(); $plan['script_lines'] = ['甲否认婚约。', '其他内容', '甲否认婚约。'];
        $this->expectExceptionCode(422); Patch::apply($plan, $patch, [], []);
    }
    public function testLiteralQuoteCannotBypassMeaningCheck(): void
    {
        $review = ['summary' => '甲开门', 'changes' => [['entity_id' => 'p1', 'field' => 'state', 'before' => null,
            'after' => '开门', 'shot_id' => '1', 'quote' => '原画面']], 'hooks' => [], 'warnings' => []];
        $calls = 0;
        try {
            \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($this->plan(), [], 1,
                static function () use ($review, &$calls) { $calls++; return $review; },
                static function ($audit) { Patch::assertMeaning($audit, ['checks' => [
                    ['collection' => 'changes', 'index' => 0, 'supported' => false, 'reason' => '画面没有开门']]]); });
            self::fail('A literal but unrelated quote must not pass');
        } catch (\RuntimeException $error) {
            self::assertSame(460, $error->getCode()); self::assertSame(3, $calls);
        }
    }
    public function testMeaningChecksMustCoverEveryFactExactlyOnce(): void
    {
        $review = ['changes' => [['after' => '事实']], 'hooks' => []];
        Patch::assertMeaning($review, ['checks' => [['collection' => 'changes', 'index' => 0, 'supported' => true, 'reason' => '明确支持']]]);
        foreach ([[], [['collection' => 'changes', 'index' => 1, 'supported' => true, 'reason' => '错误索引']]] as $checks) {
            try { Patch::assertMeaning($review, ['checks' => $checks]); self::fail('Incomplete evidence check'); }
            catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
        }
    }
    public function testUnsupportedResolutionPreservesOpenHookAndAuditTrail(): void
    {
        $review = ['summary' => '本集', 'changes' => [], 'hooks' => [
            ['id' => 'mystery', 'status' => 'resolved', 'description' => '危机结束', 'shot_id' => '1', 'quote' => '原画面']], 'warnings' => []];
        $context = ['continuity' => ['open_hooks' => ['mystery' => '危机尚未解释']]];
        $verified = Patch::verifiedReview($review, ['checks' => [['collection' => 'hooks', 'index' => 0, 'supported' => false, 'reason' => '没有解除危机证据']]], $context);
        $ledger = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::ledger($verified, $this->plan(), $context, 1);
        self::assertSame($context['continuity']['open_hooks'], $ledger['open_hooks']);
        self::assertCount(1, $ledger['warnings']); self::assertSame($review['hooks'][0], $ledger['unverified_hook_resolutions'][0]['claim']);
        self::assertSame('resolved', $review['hooks'][0]['status']);
    }
}
