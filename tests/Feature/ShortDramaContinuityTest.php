<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaContinuity as Continuity;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningContext;
use app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration;
use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use PHPUnit\Framework\TestCase;

class ShortDramaContinuityTest extends TestCase
{
    private function plan(): array
    {
        return ['title' => '钥匙', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '甲寻找钥匙',
            'subjects' => [['id' => 'p1', 'name' => '甲', 'description' => '侦探', 'library_subject_id' => 15]],
            'locations' => [['id' => 'l1', 'name' => '旧宅']],
            'storyboard' => [['shot_id' => 's1', 'scene_ref_id' => 'l1', 'visual_description' => '甲拿走钥匙。', 'dialogue' => '门终于开了。']]];
    }
    private function review(): array
    {
        return ['summary' => '甲获得钥匙', 'changes' => [['entity_id' => 'p1', 'field' => 'item_owner', 'before' => null,
            'after' => '钥匙', 'shot_id' => 's1', 'quote' => '甲拿走钥匙']], 'hooks' => [], 'warnings' => []];
    }
    public function testEvidenceBasedFactsCarryAcrossEpisodesWithoutRuntimeFields(): void
    {
        $plan = $this->plan(); $plan['storyboard'][0]['video_url'] = 'private-media';
        $ledger = Continuity::ledger($this->review(), $plan, [], 1);
        self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
        self::assertSame($ledger['digest'], Continuity::context([$ledger])['previous_digest']);
        self::assertStringNotContainsString('private-media', Continuity::messages($plan, [])['content']);
        self::assertSame(15, ShortDramaPlanningContext::lockedStory($plan)['subjects'][0]['library_subject_id']);
        $plan['series_bible']['characters'] = [['id' => 'p1', 'background' => str_repeat('人物背景', 1000), 'image_url' => 'private']];
        $locked = ShortDramaPlanningContext::lockedStory($plan);
        self::assertSame($plan['series_bible']['characters'][0]['background'], $locked['series_bible']['characters'][0]['background']);
        self::assertArrayNotHasKey('image_url', $locked['series_bible']['characters'][0]);
    }
    public function testInventedEvidenceCannotEnterLedger(): void
    {
        $review = $this->review(); $review['changes'][0]['quote'] = '甲受伤';
        $this->expectExceptionCode(422);
        Continuity::ledger($review, $this->plan(), [], 1);
    }
    public function testEvidencePatchPreservesFactsAndWarnings(): void
    {
        $bad = $this->review(); $bad['changes'][0]['quote'] = '甲...钥匙';
        $bad['warnings'] = ['需要人工复核']; $calls = 0;
        $ledger = Continuity::review($this->plan(), [], 1, static function ($input) use (&$calls, $bad) {
            if (++$calls === 1) return $bad;
            self::assertStringContainsString('evidence_patches', $input['content']);
            return ['evidence_patches' => [['collection' => 'changes', 'index' => 0, 'shot_id' => 's1', 'quote' => '甲拿走钥匙']]];
        });
        self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
        self::assertSame($bad['warnings'], $ledger['warnings']);
    }
    public function testMultipleShotProofsDoNotRequireSplittingTheFact(): void
    {
        $plan = $this->plan(); $plan['storyboard'][] = ['shot_id' => 's2', 'visual_description' => '甲走进旧宅。', 'dialogue' => ''];
        $bad = $this->review(); $bad['changes'][0]['quote'] = '甲...走进旧宅'; $calls = 0;
        $ledger = Continuity::review($plan, [], 1, static function () use (&$calls, $bad) {
            if (++$calls === 1) return $bad;
            return ['evidence_patches' => [
                ['collection' => 'changes', 'index' => 0, 'shot_id' => 's1', 'quote' => '甲拿走钥匙'],
                ['collection' => 'changes', 'index' => 0, 'shot_id' => 's2', 'quote' => '甲走进旧宅']]];
        });
        self::assertSame(2, $calls); self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
    }
    public function testEvidenceCorrectionAlsoReceivesAllHiddenStateErrors(): void
    {
        $bad = $this->review(); $bad['changes'][0]['quote'] = '甲...钥匙';
        $bad['changes'][0]['before'] = '推测';
        $bad['changes'][] = array_replace($bad['changes'][0], ['field' => 'location', 'before' => '猜测地点']);
        $calls = 0;
        $ledger = Continuity::review($this->plan(), [], 1, static function ($input) use (&$calls, $bad) {
            if (++$calls === 1) return $bad;
            self::assertStringContainsString('state_errors', $input['content']);
            self::assertStringContainsString('p1:item_owner', $input['content']);
            self::assertStringContainsString('p1:location', $input['content']);
            return ['evidence_patches' => array_map(static fn($i) => ['collection' => 'changes', 'index' => $i,
                'before' => null, 'shot_id' => 's1', 'quote' => '甲拿走钥匙'], [0, 1])];
        });
        self::assertSame(2, $calls);
        self::assertCount(2, $ledger['state']);
    }
    public function testEvidencePatchCannotChangeFactValuesOrAddRows(): void
    {
        foreach ([['collection' => 'changes', 'index' => 0, 'after' => '改写'],
            ['collection' => 'changes', 'index' => 10, 'quote' => '甲拿走钥匙']] as $patch) {
            $bad = $this->review(); $bad['changes'][0]['quote'] = '甲...钥匙'; $calls = 0;
            try {
                Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad, $patch) {
                    return ++$calls === 1 ? $bad : ['evidence_patches' => [$patch]];
                });
                self::fail('Invalid patch must not pass');
            } catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
        }
    }
    public function testPartialEvidenceRepairAccumulatesWithoutOverwritingVerifiedQuotes(): void
    {
        $bad = $this->review();
        $bad['changes'][0]['quote'] = '错误引用';
        $bad['changes'][] = array_replace($bad['changes'][0], ['field' => 'location']);
        $calls = 0;
        $ledger = Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad) {
            if (++$calls === 1) return $bad;
            if ($calls === 2) return ['evidence_patches' => [
                ['collection' => 'changes', 'index' => 0, 'quote' => '甲拿走钥匙']]];
            return ['evidence_patches' => [
                ['collection' => 'changes', 'index' => 0, 'quote' => '不得覆盖的错误引用'],
                ['collection' => 'changes', 'index' => 1, 'quote' => '甲拿走钥匙']]];
        });
        self::assertSame(3, $calls);
        self::assertSame(2, $ledger['review_repairs']);
        self::assertCount(2, $ledger['state']);
    }
    public function testUnrepairableEvidenceStopsAfterTwoCorrections(): void
    {
        $bad = $this->review(); $bad['changes'][0]['quote'] = '不存在'; $calls = 0;
        try {
            Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad) {
                return ++$calls === 1 ? $bad : ['evidence_patches' => []];
            });
            self::fail('Unproven evidence must not pass');
        } catch (\RuntimeException $error) {
            self::assertSame(422, $error->getCode());
            self::assertSame(3, $calls);
        }
    }
    public function testStringNullForNewStateDoesNotNeedPaidRepair(): void
    {
        $review = $this->review(); $review['changes'][0]['before'] = 'null'; $calls = 0;
        $ledger = Continuity::review($this->plan(), [], 1, static function () use ($review, &$calls) {
            $calls++; return $review;
        });
        self::assertSame(1, $calls);
        self::assertSame(0, $ledger['review_repairs']);
        self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
        self::assertSame('null', $review['changes'][0]['before']);
    }
    public function testStringNullCannotOverwriteKnownState(): void
    {
        $review = $this->review(); $review['changes'][0]['before'] = 'null';
        $this->expectExceptionCode(409);
        Continuity::ledger($review, $this->plan(), ['continuity' => ['state' => ['p1:item_owner' => '地图']]], 2);
    }
    public function testStringNullStillRequiresRealEvidence(): void
    {
        $review = $this->review(); $review['changes'][0]['before'] = 'null';
        $review['changes'][0]['quote'] = '不存在的证据';
        $this->expectExceptionCode(422);
        Continuity::ledger($review, $this->plan(), [], 1);
    }
    public function testSecondChangeCannotReuseFirstRegistrationSentinel(): void
    {
        $review = $this->review(); $review['changes'][0]['before'] = 'null';
        $review['changes'][] = $review['changes'][0];
        $this->expectExceptionCode(422);
        Continuity::ledger($review, $this->plan(), [], 1);
    }
    public function testEvidenceRepairCanRevealAndThenRepairLocalChainWithoutChangingFacts(): void
    {
        $bad = $this->review();
        $bad['changes'][] = $bad['changes'][0];
        $bad['changes'][1]['before'] = '简写的旧状态';
        $bad['changes'][1]['after'] = '保管钥匙';
        $bad['changes'][0]['quote'] = '甲...钥匙';
        $fixedEvidence = $bad; $fixedEvidence['changes'][0]['quote'] = '甲拿走钥匙';
        $fixedChain = $fixedEvidence; $fixedChain['changes'][1]['before'] = '钥匙';
        $calls = 0;
        $ledger = Continuity::review($this->plan(), [], 1, static function ($input) use (&$calls, $bad, $fixedEvidence, $fixedChain) {
            $calls++;
            if ($calls === 3) self::assertStringContainsString('本集内状态链', $input['content']);
            return [$bad, $fixedEvidence, $fixedChain][$calls - 1];
        });
        self::assertSame(3, $calls);
        self::assertSame('保管钥匙', $ledger['state']['p1:item_owner']);
        $calls = 0;
        try {
            Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad, $fixedEvidence) {
                return ++$calls === 1 ? $bad : $fixedEvidence;
            });
            self::fail('Unfixed chain must not pass');
        } catch (\RuntimeException $error) {
            self::assertSame(422, $error->getCode());
            self::assertSame(3, $calls);
        }
    }
    public function testBeforeStateMustMatchAndUnknownHookCannotResolve(): void
    {
        $this->expectExceptionCode(409);
        Continuity::ledger($this->review(), $this->plan(), ['continuity' => ['state' => ['p1:item_owner' => '地图']]], 2);
    }
    public function testHookRequiresExistingUnresolvedFact(): void
    {
        $review = $this->review(); $review['hooks'] = [['id' => 'missing', 'description' => '钥匙', 'status' => 'resolved', 'shot_id' => 's1', 'quote' => '钥匙']];
        $this->expectExceptionCode(409); Continuity::ledger($review, $this->plan(), [], 1);
    }
    public function testNewFieldAuditCanRepairOnceWithoutChangingTheScript(): void
    {
        $plan = $this->plan(); $review = $this->review(); $calls = 0;
        $ledger = Continuity::review($plan, [], 1, static function ($input) use ($review, &$calls) {
            $calls++;
            if ($calls === 1) $review['changes'][0]['before'] = '推断的旧值';
            else self::assertStringContainsString('首次登记的before必须为null', $input['content']);
            return $review;
        });
        self::assertSame(2, $calls);
        self::assertSame(1, $ledger['review_repairs']);
        self::assertSame($this->plan(), $plan);
        self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
    }
    public function testKnownStateConflictIsNotAutomaticallyRepairedOrIgnored(): void
    {
        $calls = 0;
        try {
            Continuity::review($this->plan(), ['continuity' => ['state' => ['p1:item_owner' => '地图']]], 2,
                function ($input) use (&$calls) { $calls++; return $this->review(); });
            self::fail('Must reject conflicting registered state');
        } catch (\RuntimeException $error) {
            self::assertSame(409, $error->getCode());
            self::assertSame(1, $calls);
        }
    }
    public function testEvidenceDiagnosticsAndCorrectionAreScopedAndPreserveInput(): void
    {
        $plan = $this->plan();
        $plan['storyboard'][] = ['shot_id' => 's2', 'visual_description' => '甲收起钥匙，转身离开。', 'dialogue' => '再见。'];
        $bad = $this->review();
        $bad['changes'][0]['quote'] = '甲拿走...钥匙';
        $bad['hooks'] = [['id' => 'key', 'description' => '钥匙去向', 'status' => 'open', 'shot_id' => 's2', 'quote' => '门终于开了。甲收起钥匙']];
        $bad['warnings'] = ['钥匙来源需要确认'];
        $issues = Continuity::evidenceIssues($bad, $plan);
        self::assertSame(['changes.0', 'hooks.0'], array_column($issues, 'path'));
        self::assertSame('甲收起钥匙，转身离开。', $issues[1]['visual_description']);
        $fixed = $bad; $fixed['changes'][0]['quote'] = '甲拿走钥匙'; $fixed['hooks'][0]['quote'] = '甲收起钥匙';
        $calls = [];
        $ledger = Continuity::review($plan, [], 1, static function ($input) use (&$calls, $bad, $fixed, $plan) {
            $calls[] = $input;
            if (count($calls) === 1) { self::assertSame(Continuity::messages($plan, []), $input); return $bad; }
            self::assertStringContainsString('evidence_errors', $input['content']);
            self::assertStringContainsString('hooks.0', $input['content']);
            self::assertStringContainsString('不得概括、加省略号', $input['content']);
            return $fixed;
        });
        self::assertCount(2, $calls);
        self::assertSame(1, $ledger['review_repairs']);
        self::assertSame($bad['warnings'], $ledger['warnings']);
        self::assertSame('钥匙去向', $ledger['open_hooks']['key']);
        self::assertSame('甲拿走钥匙。', $plan['storyboard'][0]['visual_description']);
    }
    public function testLegacyFullResponseRepairAlsoHasThreeResponseLimit(): void
    {
        $bad = $this->review(); $bad['changes'][0]['quote'] = '根本没有发生的情节'; $calls = 0;
        try {
            Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad) { $calls++; return $bad; });
            self::fail('Must not accept unverifiable evidence');
        } catch (\RuntimeException $error) {
            self::assertSame(422, $error->getCode()); self::assertSame(3, $calls);
            self::assertStringContainsString('已保留生成回包', $error->getMessage());
        }
    }
    public function testCorrectionCannotDeleteFactsRewriteMeaningOrDropWarnings(): void
    {
        foreach (['delete', 'identity', 'warning'] as $mutation) {
            $bad = $this->review(); $bad['changes'][0]['before'] = '推测值'; $bad['warnings'] = ['保留疑点'];
            $fixed = $bad; $fixed['changes'][0]['before'] = null;
            if ($mutation === 'delete') $fixed['changes'] = [];
            if ($mutation === 'identity') $fixed['changes'][0]['field'] = '别的字段';
            if ($mutation === 'warning') $fixed['warnings'] = [];
            $calls = 0;
            try {
                Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad, $fixed) { return ++$calls === 1 ? $bad : $fixed; });
                self::fail($mutation . ' must not be accepted');
            } catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); self::assertSame(2, $calls); }
        }
    }
    public function testCorrectionRetainsOriginalStateAssertionInsteadOfRewritingIt(): void
    {
        $bad = $this->review(); $bad['changes'][0]['quote'] = '甲拿走...钥匙';
        $fixed = $this->review(); $fixed['changes'][0]['after'] = '别的物品';
        $calls = 0;
        $ledger = Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad, $fixed) {
            return ++$calls === 1 ? $bad : $fixed;
        });
        self::assertSame(2, $calls);
        self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
        self::assertSame('别的物品', $fixed['changes'][0]['after']);
        $fixed['changes'][0]['quote'] = '仍不存在的证据'; $calls = 0;
        $this->expectExceptionCode(422);
        Continuity::review($this->plan(), [], 1, static function () use (&$calls, $bad, $fixed) {
            return ++$calls === 1 ? $bad : $fixed;
        });
    }
    public function testRepairLocksVerifiedEvidenceAndStillRepairsInvalidEvidence(): void
    {
        $plan = $this->plan();
        $bad = $this->review();
        $bad['hooks'] = [['id' => 'door', 'description' => '门已开启', 'status' => 'open', 'shot_id' => 's1', 'quote' => '门...开了']];
        $bad['warnings'] = ['保留疑点'];
        $fixed = $bad;
        $fixed['changes'][0]['quote'] = '甲拿走钥匙。';
        $fixed['hooks'][0]['quote'] = '门终于开了。';
        $method = new \ReflectionMethod(Continuity::class, 'preserveRepairFacts');
        $method->setAccessible(true);
        $merged = $method->invoke(null, $bad, $fixed, $plan);
        self::assertSame($bad['changes'][0], $merged['changes'][0]);
        self::assertSame($fixed['hooks'][0], $merged['hooks'][0]);
        self::assertSame('甲拿走钥匙。', $fixed['changes'][0]['quote']);
        $calls = 0;
        $ledger = Continuity::review($plan, [], 1, static function () use (&$calls, $bad, $fixed) {
            return ++$calls === 1 ? $bad : $fixed;
        });
        self::assertSame(2, $calls);
        self::assertSame('钥匙', $ledger['state']['p1:item_owner']);
        self::assertSame('门已开启', $ledger['open_hooks']['door']);
        self::assertSame($bad['warnings'], $ledger['warnings']);
    }
    public function testProviderTimeoutTruncationAndCancellationAreNotFormatRetries(): void
    {
        foreach ([0, 409, 413, 425, 429] as $code) {
            $calls = 0;
            try {
                Continuity::review($this->plan(), [], 1, static function () use (&$calls, $code) { $calls++; throw new \RuntimeException('provider failure', $code); });
                self::fail('Must propagate');
            } catch (\RuntimeException $error) { self::assertSame($code, $error->getCode()); self::assertSame(1, $calls); }
        }
    }
    public function testBadShotIdentifiersProduceDiagnosticsWithoutWarnings(): void
    {
        foreach (['missing', [], null] as $id) {
            $bad = $this->review(); $bad['changes'][0]['shot_id'] = $id;
            $issues = Continuity::evidenceIssues($bad, $this->plan());
            self::assertCount(1, $issues); self::assertFalse($issues[0]['shot_exists']);
        }
    }
    public function testAllV3EpisodeDurationsUseSameAuditRepairAndReservedBudget(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/common/service/app/aigc_short_drama/AigcShortDramaService.php');
        self::assertStringContainsString("\$result['_continuity'] = ShortDramaContinuity::review(", $source);
        self::assertStringNotContainsString("\$result['_continuity'] = ShortDramaEpisodeDuration::active", $source);
        $generation = file_get_contents(__DIR__ . '/../../app/common/service/app/aigc_short_drama/ShortDramaScriptGeneration.php');
        self::assertStringContainsString("\$auditReserve = !empty(\$request['series_context']);", $generation);
    }
    public function testOnlyNarrativeEditsInvalidateDependenciesAndPendingRepairIsNotBlocked(): void
    {
        $plan = $this->plan(); $digest = Continuity::fingerprint($plan);
        $plan['storyboard'][0]['video_url'] = 'new'; $plan['storyboard'][0]['camera_movement'] = '推镜';
        $plan = array_reverse($plan, true);
        self::assertSame($digest, Continuity::fingerprint($plan));
        $first = ['version' => 1, 'digest' => $digest, 'previous_digest' => ''];
        $second = ['version' => 1, 'digest' => 'second', 'previous_digest' => $digest];
        $rows = [['episode_number' => 1, 'production_project_id' => 10, 'status' => 'success', 'continuity_json' => $first],
            ['episode_number' => 2, 'production_project_id' => 11, 'status' => 'success', 'continuity_json' => $second]];
        self::assertSame([false, false], array_column(Continuity::dependencyStatus($rows, []), 'needs_review'));
        $versions = [10 => ['ledger' => $first, 'narrative_digest' => 'changed']];
        self::assertSame([true, true], array_column(Continuity::dependencyStatus($rows, $versions), 'needs_review'));
        $rows[0]['status'] = 'pending';
        self::assertSame([false, true], array_column(Continuity::dependencyStatus($rows, $versions), 'needs_review'));
        $rows[0]['continuity_json'] = ['summary' => 'legacy']; $rows[1]['continuity_json'] = [];
        self::assertSame([false, false], array_column(Continuity::dependencyStatus($rows, []), 'needs_review'));
    }
    public function testRoadmapPrecedesEveryBatchAndPreservesConfirmedStory(): void
    {
        $plan = $this->plan(); $plan['storyboard'] = [];
        $calls = [];
        $segments = [['start' => 1, 'end' => 20, 'goal' => '调查', 'reveal' => '逐步揭露', 'ending' => '解开谜题']];
        $result = ShortDramaStoryGeneration::generate(['_generation_version' => 3, 'multi_episode_stage' => 'episodes', 'episode_count' => 20,
            'confirmed_story_snapshot' => $plan], ['max_tokens' => 8192], static fn() => ['system_prompt' => '', 'content' => '大纲'],
            static function ($key, $messages, $budget) use (&$calls, $segments) {
                $calls[] = $key;
                if ($key === 'roadmap') $payload = ['segments' => $segments];
                else {
                    self::assertStringContainsString('逐步揭露', $messages['content']);
                    preg_match('/outline_(\d+)_(\d+)/', $key, $m); $payload = ['episodes' => []];
                    for ($i = (int)$m[1]; $i < (int)$m[1] + (int)$m[2]; $i++) $payload['episodes'][] = ['episode_number' => $i,
                        'title' => '调查' . $i, 'story_outline' => '发现线索' . $i, 'conflict_point' => '线索被隐藏', 'ending_hook' => '继续寻找'];
                }
                return ['result' => ['content' => json_encode($payload)]];
            });
        self::assertSame('roadmap', $calls[0]); self::assertCount(20, $result['result']['episodes']);
        self::assertSame($segments, $result['result']['series_roadmap']);
        self::assertSame($plan['subjects'], $result['result']['subjects']);
    }
    public function testRoadmapRejectsGaps(): void
    {
        $this->expectExceptionCode(422);
        Continuity::assertRoadmap([['start' => 2, 'end' => 10, 'goal' => 'a', 'reveal' => 'b', 'ending' => 'c']], 10);
    }
    public function testNewStandaloneTaskIsWorkerOwnedButEpisodeAndLegacyTasksAreNot(): void
    {
        self::assertTrue(ShortDramaStoryWorkflow::workerOwned(['_generation_version' => 3, 'multi_episode' => false]));
        self::assertFalse(ShortDramaStoryWorkflow::workerOwned(['_generation_version' => 3, 'episode_id' => 12]));
        self::assertFalse(ShortDramaStoryWorkflow::workerOwned([]));
    }
}
