<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ExecutionStore;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\app\aigc_short_drama\vnext\JobRunner;
use app\common\service\database\SqlMigrationExecutor;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextExecutionTest extends TestCase
{
    private ?ExecutionStore $store = null;
    private array $scope;
    private string $directory;
    private ReceiptBuffer $buffer;
    public static function setUpBeforeClass(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') return;
        (new \think\App())->initialize();
        $sql = file_get_contents(root_path() . 'app/apps/aigc_short_drama/migrations/upgrade_20261009_vnext_execution.sql');
        $prefix = (string)config('database.connections.mysql.prefix', 'la_');
        SqlMigrationExecutor::execute($sql, $prefix);
        SqlMigrationExecutor::execute($sql, $prefix); // Migration is repeatable and preserves existing data.
        $retry = file_get_contents(root_path() . 'app/apps/aigc_short_drama/migrations/upgrade_20261009_vnext_retry.sql');
        SqlMigrationExecutor::execute($retry, $prefix);
        SqlMigrationExecutor::execute($retry, $prefix);
    }
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        Db::startTrans();
        $task = 'vnext_test_' . bin2hex(random_bytes(8));
        $this->scope = ['tenant_id' => 2000000721, 'user_id' => 7, 'task_id' => $task];
        $this->directory = sys_get_temp_dir() . '/' . $task;
        $this->buffer = new ReceiptBuffer($this->directory);
        $this->store = new ExecutionStore(2000000721, 7, $task, $this->buffer);
        $this->store->create($task, ['prompt' => '测试正文'], time() + 600);
    }
    protected function tearDown(): void
    {
        if ($this->store === null) return;
        Db::rollback();
        foreach (glob($this->directory . '/*') ?: [] as $path) unlink($path);
        rmdir($this->directory);
    }
    private function resourceStore(int $tokens = 200): void
    {
        $this->scope['task_id'] .= '_r';
        $this->store = new ExecutionStore($this->scope['tenant_id'], $this->scope['user_id'], $this->scope['task_id'], $this->buffer);
        $this->store->create($this->scope['task_id'], ['execution' => ['resource_limits' => [
            'total_tokens' => $tokens, 'tenant_cost_points' => 2, 'user_charge_points' => 4]]], time() + 600);
    }
    private function bound(): array { return ['total_tokens' => 100, 'tenant_cost_points' => .5, 'user_charge_points' => 1]; }
    private function billedReceipt(): array
    { return $this->receipt() + ['billing' => ['billing_status' => 'settled', 'tenant_cost_points' => .2, 'user_charge_points' => .4]]; }
    private function resources(): array { return json_decode($this->store->snapshot()['budget_json'], true)['resources']; }
    public function testResourceAdmissionStopsBeforeNextPaidCallAndKeepsCandidate(): void
    {
        $this->resourceStore(145); $fence = $this->store->acquire('worker', 100); $paid = 0;
        $provider = function ($input, $key, $persist, $admit) use (&$paid) { $admit($this->bound()); $paid++; return $this->billedReceipt(); };
        $receipt = $this->store->call($fence, 'a', [], $provider);
        $this->store->checkpoint($fence, ['document' => ['title' => '保留正文']]);
        self::assertSame($receipt, $this->store->call($fence, 'a', [], $provider));
        try { $this->store->call($fence, 'b', [], $provider); self::fail('Budget bypassed'); }
        catch (\RuntimeException $e) { self::assertSame(429, $e->getCode()); }
        self::assertSame(1, $paid); self::assertSame(46, $this->resources()['spent']['total_tokens']);
        self::assertSame(400000, $this->resources()['spent']['user_charge_points']);
        self::assertSame('needs_attention', $this->store->snapshot()['execution_status']);
        self::assertSame('budget_exhausted', $this->store->snapshot()['error_code']);
        self::assertStringContainsString('保留正文', $this->store->snapshot()['candidate_json']);
        self::assertSame('not_submitted', Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->where('stage_key', 'b')->value('status'));
        self::assertSame([], $this->resources()['holds']);
    }
    public function testResourceHoldSurvivesSettlementFaultAndRecoveryAccountsOnlyOnce(): void
    {
        $this->resourceStore(); $fence = $this->store->acquire('worker', 100); $paid = 0;
        try {
            $this->store->call($fence, 'a', [], function ($input, $key, $persist, $admit) use (&$paid) {
                $admit($this->bound()); $paid++; $persist($this->billedReceipt()); throw new \RuntimeException('settlement fault');
            });
            self::fail('Fault ignored');
        } catch (\RuntimeException $e) { self::assertSame('settlement fault', $e->getMessage()); }
        self::assertSame(0, $this->resources()['spent']['total_tokens']); self::assertCount(1, $this->resources()['holds']);
        $recover = static fn($receipt) => $receipt;
        $this->store->reconcileReceipts($recover); $this->store->reconcileReceipts($recover);
        self::assertTrue($this->store->resume()); $fence = $this->store->acquire('worker2', 100);
        $this->store->call($fence, 'a', [], static function () { self::fail('Paid replay'); });
        self::assertSame(1, $paid); self::assertSame(46, $this->resources()['spent']['total_tokens']);
        self::assertSame([], $this->resources()['holds']);
        self::assertSame(200, $this->resources()['limits']['total_tokens']);
    }
    public function testUnknownSubmissionAndCancelNeverFreeResourceHold(): void
    {
        $this->resourceStore(); $fence = $this->store->acquire('worker', 100);
        try { $this->store->call($fence, 'a', [], function ($input, $key, $persist, $admit) { $admit($this->bound()); throw new \RuntimeException('request timeout'); }); }
        catch (\RuntimeException $e) { self::assertSame('request timeout', $e->getMessage()); }
        self::assertSame('reconciling', $this->store->snapshot()['execution_status']);
        $this->store->cancel(); self::assertCount(1, $this->resources()['holds']);
        try { $this->store->resume(); self::fail('Unknown submission resumed'); }
        catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        self::assertCount(1, $this->resources()['holds']);
    }
    public function testUnsubmittedConnectionRetryReleasesHoldWithoutResettingBudget(): void
    {
        $this->resourceStore(100); $fence = $this->store->acquire('worker', 100);
        try { $this->store->call($fence, 'a', [], function ($input, $key, $persist, $admit) { $admit($this->bound()); throw new \RuntimeException('Could not resolve host'); }); self::fail('Failure ignored'); }
        catch (\RuntimeException $e) { self::assertSame(425, $e->getCode()); }
        self::assertSame([], $this->resources()['holds']);
        Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        $fence = $this->store->acquire('worker', 100);
        $this->store->call($fence, 'a', [], function ($input, $key, $persist, $admit) { $admit($this->bound()); return $this->billedReceipt(); });
        self::assertSame(46, $this->resources()['spent']['total_tokens']);
        self::assertSame(2, json_decode($this->store->snapshot()['budget_json'], true)['calls']);
    }
    public function testFinalKnownUnsubmittedFailureDoesNotBecomeUnknownOrKeepQuota(): void
    {
        $this->resourceStore(100);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $fence = $this->store->acquire('worker', 100);
            try { $this->store->call($fence, 'a', [], function ($input, $key, $persist, $admit) { $admit($this->bound()); throw new \RuntimeException('Could not resolve host'); }); self::fail('Failure ignored'); }
            catch (\RuntimeException $e) { self::assertSame($attempt < 3 ? 425 : 429, $e->getCode()); }
            self::assertSame([], $this->resources()['holds']);
            Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        }
        self::assertSame('needs_attention', $this->store->snapshot()['execution_status']);
        self::assertSame(0, $this->resources()['spent']['total_tokens']);
        self::assertSame(3, json_decode($this->store->snapshot()['budget_json'], true)['calls']);
    }
    public function testRepairConnectionRetryUsesExtraBudgetWithoutConsumingFinalReviewReservation(): void
    {
        $this->resourceStore(1000); $fence = $this->store->acquire('worker', 100);
        $provider = function ($input, $key, $persist, $admit) { $admit($this->bound()); return $this->billedReceipt(); };
        $this->store->call($fence, 'initial', [], $provider);
        $this->store->reserveRepair($fence, 'repair', 3);
        $this->store->call($fence, 'patch', [], $provider, 'repair');
        try { $this->store->call($fence, 'local_review', [], function ($input, $key, $persist, $admit) {
            $admit($this->bound()); throw new \RuntimeException('Could not resolve host');
        }, 'repair'); self::fail('Connection failure ignored'); }
        catch (\RuntimeException $e) { self::assertSame(425, $e->getCode()); }
        self::assertSame(1, json_decode($this->store->snapshot()['budget_json'], true)['reservations']['repair']);
        self::assertSame(1, $this->resources()['rounds']['repair']['remaining']);
        Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        $fence = $this->store->acquire('worker', 100);
        $this->store->call($fence, 'local_review', [], $provider, 'repair');
        self::assertSame(1, json_decode($this->store->snapshot()['budget_json'], true)['reservations']['repair']);
        self::assertSame(1, $this->resources()['rounds']['repair']['remaining']);
        $this->store->call($fence, 'global_review', [], $provider, 'repair');
        self::assertSame(0, json_decode($this->store->snapshot()['budget_json'], true)['reservations']['repair']);
        self::assertSame(0, $this->resources()['rounds']['repair']['remaining']);
        self::assertSame(5, json_decode($this->store->snapshot()['budget_json'], true)['calls']);
        self::assertSame(184, $this->resources()['spent']['total_tokens']);
    }
    public function testUnverifiedReceiptCannotAuthorizeAnotherCall(): void
    {
        $this->resourceStore(); $fence = $this->store->acquire('worker', 100);
        $this->store->call($fence, 'a', [], function ($input, $key, $persist, $admit) { $admit($this->bound()); return ['content' => '正文保留']; });
        self::assertSame('resource_usage_unverified', $this->resources()['blocked']); self::assertCount(1, $this->resources()['holds']);
        $this->expectExceptionCode(429);
        $this->store->call($fence, 'b', [], static function () { self::fail('Unverified usage allowed another paid call'); });
    }
    public function testCancellationDuringAdmissionCannotBeOverwrittenByRejection(): void
    {
        $this->resourceStore(); $fence = $this->store->acquire('worker', 100);
        try { $this->store->call($fence, 'a', [], function ($input, $key, $persist, $admit) { $this->store->cancel(); $admit($this->bound()); self::fail('Canceled task submitted'); }); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        self::assertSame('canceled', $this->store->snapshot()['execution_status']); self::assertSame([], $this->resources()['holds']);
    }
    private function receipt(): array { return ['result' => ['content' => '{"title":"完整返回"}'], 'usage' => ['input_tokens' => 12, 'output_tokens' => 34, 'user_points' => 2]]; }
    public function testReplayKeepsOneProviderCallAndOneUsageCharge(): void
    {
        $fence = $this->store->acquire('worker1', 100); $calls = 0;
        $provider = function () use (&$calls) { $calls++; return $this->receipt(); };
        $a = $this->store->call($fence, 'screenplay', ['prompt' => 'same'], $provider);
        $b = $this->store->call($fence, 'screenplay', ['prompt' => 'same'], $provider);
        self::assertSame($a, $b); self::assertSame(1, $calls);
        $budget = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame(1, $budget['calls']); self::assertSame(34, $budget['output_tokens']); self::assertSame(2, $budget['user_points']);
        $this->expectExceptionCode(409); $this->store->call($fence, 'screenplay', ['prompt' => 'changed'], $provider);
    }
    public function testRawReplySurvivesSettlementFailureAndResumesWithoutAnotherProviderCall(): void
    {
        $fence = $this->store->acquire('worker1', 100); $calls = 0;
        try {
            $this->store->call($fence, 'screenplay', [], function ($input, $key, $persist) use (&$calls) {
                $calls++; $persist($this->receipt());
                // Even misleading connection wording after a reply must not authorize resubmission.
                throw new \RuntimeException('Could not resolve host while writing settlement audit');
            });
            self::fail('Settlement fault ignored');
        } catch (\RuntimeException $e) { self::assertStringContainsString('settlement audit', $e->getMessage()); }
        self::assertSame('reconciling', $this->store->snapshot()['execution_status']);
        self::assertSame('receipt_settlement_pending', $this->store->snapshot()['error_code']);
        self::assertSame([], $this->store->receipts());
        try { $this->store->reconcileReceipts(static function () { throw new \RuntimeException('insufficient balance', 409); }); }
        catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        self::assertSame([], $this->store->receipts());
        $settlements = 0;
        $recover = static function (array $receipt) use (&$settlements): array { $settlements++; return $receipt; };
        $this->store->reconcileReceipts($recover);
        $this->store->reconcileReceipts($recover);
        self::assertTrue($this->store->resume());
        $fence = $this->store->acquire('worker2', 100);
        self::assertSame($this->receipt(), $this->store->call($fence, 'screenplay', [], static function () { self::fail('Recovered reply resubmitted'); }));
        self::assertSame(1, $calls); self::assertSame(1, $settlements);
        $budget = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame(1, $budget['calls']); self::assertSame(34, $budget['output_tokens']); self::assertSame(2, $budget['user_points']);
    }
    public function testWorkerRecoversRawReplyWithSameInputButNeverPublishesAfterCancellation(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        try { $this->store->call($fence, 'screenplay', [], function ($input, $key, $persist) {
            $persist($this->receipt()); throw new \RuntimeException('settlement unavailable');
        }); } catch (\RuntimeException $ignored) {}
        $fence = $this->store->acquire('worker1', 100);
        $receipt = $this->store->call($fence, 'screenplay', [], static function () { self::fail('Provider resubmitted'); }, '',
            function (array $raw): array { $this->store->cancel(); return $raw; });
        self::assertSame($this->receipt(), $receipt);
        self::assertSame('canceled', $this->store->snapshot()['execution_status']);
        self::assertCount(1, $this->store->receipts());
        $this->expectExceptionCode(409); $this->store->checkpoint($fence, ['document' => []]);
    }
    public function testAutomaticSettlementRecoveryIsBoundedAndCancellationCannotResumeWork(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        try { $this->store->call($fence, 'screenplay', [], function ($input, $key, $persist) {
            $persist($this->receipt()); throw new \RuntimeException('billing unavailable');
        }); } catch (\RuntimeException $ignored) {}
        self::assertFalse($this->store->workerReady());
        self::assertFalse($this->store->reconcileAutomatically(static function () { self::fail('Backoff bypassed'); }));
        $attempts = 0;
        for ($i = 0; $i < 3; $i++) {
            Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['update_time' => time() - 301]);
            try { $this->store->reconcileAutomatically(static function () use (&$attempts) { $attempts++; throw new \RuntimeException('still unavailable'); }); }
            catch (\RuntimeException $ignored) {}
        }
        self::assertFalse($this->store->canAutomaticallyReconcile());
        self::assertFalse($this->store->reconcileAutomatically(static function () { self::fail('Recovery limit bypassed'); }));
        self::assertSame(3, $attempts);
        self::assertSame(1, json_decode($this->store->snapshot()['budget_json'], true)['calls']);
        $this->store->cancel();
        $this->store->reconcileReceipts(static fn(array $raw): array => $raw);
        self::assertFalse($this->store->resume(true));
        self::assertSame('canceled', $this->store->snapshot()['execution_status']);
        self::assertTrue($this->store->resume(), 'Explicit continuation remains available');
    }
    public function testWholeRepairRoundMustFitAndBudgetSurvivesWorkerChange(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        for ($i = 0; $i < 3; $i++) $this->store->call($fence, 'base' . $i, [], fn() => $this->receipt());
        $this->store->reserveRepair($fence, 'round1', 3);
        $this->store->reserveRepair($fence, 'round1', 3);
        for ($i = 0; $i < 3; $i++) $this->store->call($fence, 'repair' . $i, [], fn() => $this->receipt(), 'round1');
        Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['lease_until' => 0]);
        $fence = $this->store->acquire('worker2', 100);
        self::assertSame(6, json_decode($this->store->snapshot()['budget_json'], true)['calls']);
        $this->expectExceptionCode(429); $this->store->reserveRepair($fence, 'round2', 3);
    }
    public function testUnknownSubmissionCannotBeRepeated(): void
    {
        $fence = $this->store->acquire('worker1', 100); $calls = 0;
        try { $this->store->call($fence, 'screenplay', [], function () use (&$calls) { $calls++; throw new \RuntimeException('read timeout'); }); }
        catch (\RuntimeException $e) { self::assertSame('read timeout', $e->getMessage()); }
        self::assertSame('reconciling', $this->store->snapshot()['execution_status']);
        $fence = $this->store->acquire('worker1', 100);
        try { $this->store->call($fence, 'screenplay', [], function () use (&$calls) { $calls++; return $this->receipt(); }); self::fail('Unknown submission retried'); }
        catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        self::assertSame(1, $calls);
    }
    public function testOnlyProvenConnectionFailureMayRetryAfterBackoff(): void
    {
        $fence = $this->store->acquire('worker1', 100); $calls = 0;
        try { $this->store->call($fence, 'screenplay', [], function () use (&$calls) { $calls++; throw new \RuntimeException('Could not resolve host'); }); }
        catch (\RuntimeException $e) { self::assertSame(425, $e->getCode()); }
        self::assertSame('retry_wait', $this->store->snapshot()['execution_status']);
        $fence = $this->store->acquire('worker1', 100);
        try { $this->store->call($fence, 'screenplay', [], static function () { self::fail('Backoff bypassed'); }); }
        catch (\RuntimeException $e) { self::assertSame(425, $e->getCode()); }
        Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        $this->store->call($fence, 'screenplay', [], function () use (&$calls) { $calls++; return $this->receipt(); });
        self::assertSame(2, $calls); self::assertSame(2, json_decode($this->store->snapshot()['budget_json'], true)['calls']);
    }
    public function testPrivateBufferRestoresReceiptBeforeAnyResubmission(): void
    {
        $fence = $this->store->acquire('worker1', 100); $input = ['prompt' => 'same']; $hash = ContentDocument::hash($input);
        Db::name('aigc_short_drama_vnext_stage')->insert($this->scope + ['stage_key' => 'screenplay', 'input_hash' => $hash,
            'input_json' => json_encode($input), 'status' => 'submitted', 'fence' => $fence, 'create_time' => time(), 'update_time' => time()]);
        $key = $this->buffer->key($this->scope + ['stage_key' => 'screenplay'], $hash);
        $this->buffer->write($key, $this->receipt());
        $receipt = $this->store->call($fence, 'screenplay', $input, static function () { self::fail('Buffered receipt was resubmitted'); });
        self::assertSame($this->receipt(), $receipt);
        self::assertSame('received', Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->value('status'));
    }
    public function testCancelFencesLateProviderResultWithoutLosingItsReceipt(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        $this->store->call($fence, 'screenplay', [], function () { $this->store->cancel(); return $this->receipt(); });
        self::assertSame('canceled', $this->store->snapshot()['execution_status']);
        self::assertSame('received', Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->value('status'));
        $this->expectExceptionCode(409); $this->store->checkpoint($fence, ['title' => 'late result']);
    }
    public function testOneEpisodeLaneAndTenantUserIsolation(): void
    {
        $other = new ExecutionStore(2000000722, 7, $this->scope['task_id'], $this->buffer);
        self::assertSame([], $other->snapshot());
        $other->create($this->scope['task_id'], [], time() + 600);
        self::assertSame('queued', $other->snapshot()['execution_status']);
        $same = new ExecutionStore(2000000721, 7, 'other_' . $this->scope['task_id'], $this->buffer);
        $this->expectException(\Throwable::class); $same->create($this->scope['task_id'], [], time() + 600);
    }
    public function testExplicitResumeKeepsBudgetAndDeadlineAndReplaysPaidStage(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        $this->store->call($fence, 'screenplay', [], fn() => $this->receipt());
        $before = $this->store->snapshot();
        $this->store->cancel();
        self::assertContains('retry', $this->store->publicSnapshot()['allowed_actions']);
        self::assertTrue($this->store->resume()); self::assertFalse($this->store->resume());
        self::assertSame($before['budget_json'], $this->store->snapshot()['budget_json']);
        self::assertSame($before['deadline_at'], $this->store->snapshot()['deadline_at']);
        $fence = $this->store->acquire('worker2', 100);
        $this->store->call($fence, 'screenplay', [], static function () { self::fail('Paid stage was repeated'); });
    }
    public function testCanceledUnknownSubmissionCannotResetIntoNewPaidAttempt(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        try { $this->store->call($fence, 'screenplay', [], static function () { throw new \RuntimeException('read timeout'); }); }
        catch (\RuntimeException $ignored) {}
        $this->store->cancel();
        self::assertNotContains('retry', $this->store->publicSnapshot()['allowed_actions']);
        self::assertContains('check_receipt', $this->store->publicSnapshot()['allowed_actions']);
        $this->expectExceptionCode(409); $this->store->resume();
    }
    public function testExpiredJobStopsPollingAsRunningAndCannotResetItsDeadline(): void
    {
        Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['deadline_at' => time() - 1]);
        try { $this->store->acquire('worker1', 100); self::fail('Expired job acquired'); }
        catch (\RuntimeException $error) { self::assertSame(429, $error->getCode()); }
        self::assertSame('needs_attention', $this->store->snapshot()['execution_status']);
        self::assertSame('deadline_exceeded', $this->store->snapshot()['error_code']);
        $this->store->cancel();
        self::assertNotContains('retry', $this->store->publicSnapshot()['allowed_actions']);
        $this->expectExceptionCode(429); $this->store->resume();
    }
    public function testPreviousFenceCannotMutateCandidateAfterLeaseTakeover(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['lease_until' => 0]);
        $new = $this->store->acquire('worker2', 100); self::assertGreaterThan($fence, $new);
        $this->store->checkpoint($new, ['title' => 'new']);
        $this->expectExceptionCode(409); $this->store->checkpoint($fence, ['title' => 'old']);
    }
    private function sceneStore(bool $review = false, bool $repair = false, bool $textRepair = false): array
    {
        $input = $review ? ShortDramaVnextSceneReviewTest::input() : ShortDramaVnextSceneShotTest::input();
        if ($repair) $input['context'] = ShortDramaVnextSceneShotRepairTest::context();
        if ($textRepair) $input = ShortDramaVnextSceneContentRepairTest::input();
        $input['execution']['resource_limits'] = ['total_tokens' => 10000, 'tenant_cost_points' => 3, 'user_charge_points' => 6];
        if ($textRepair) $input['execution']['resource_limits'] = ['total_tokens' => 10000, 'tenant_cost_points' => 6, 'user_charge_points' => 12];
        $this->scope['task_id'] .= '_scene';
        $this->store = new ExecutionStore($this->scope['tenant_id'], $this->scope['user_id'], $this->scope['task_id'], $this->buffer);
        $this->store->create($this->scope['task_id'], $input, time() + 600, $textRepair ? 3 : 2);
        return $input;
    }
    public function testFrozenScenePlanChangesOnlyPlannedCallCountOnce(): void
    {
        $input = $this->sceneStore(); $document = $input['context']['canonical_input'];
        $fence = $this->store->acquire('worker', 100);
        $plan = \app\common\service\app\aigc_short_drama\vnext\SceneShotExecution::partition($document, static fn() => ['max_tokens' => 1536], 1, false);
        $before = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame($plan, $this->store->sceneShotPlan($fence, 'shots', $document, $plan));
        self::assertSame($plan, $this->store->sceneShotPlan($fence, 'shots', $document, null));
        self::assertSame($plan, $this->store->sceneShotPlan($fence, 'shots', $document, $plan));
        $after = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame(3, $after['base_calls']); self::assertSame(8, $after['call_limit']);
        self::assertSame(0, $after['calls']); self::assertSame($before['resources'], $after['resources']);
        $ids = array_keys(ContentDocument::units($document));
        $changed = $plan; $changed['windows'] = [array_slice($ids, 0, 3), array_slice($ids, 3)];
        unset($changed['plan_hash']); $changed['plan_hash'] = ContentDocument::hash($changed);
        $this->expectExceptionCode(409); $this->store->sceneShotPlan($fence, 'shots', $document, $changed);
    }
    public function testSceneWorkerResumesWithoutRegeneratingCompletedWindowOrResettingUsage(): void
    {
        $input = $this->sceneStore(); $attempts = 0; $paid = [];
        $provider = function ($params, $receiptKey, $persist, $admit) use (&$attempts, &$paid): array {
            $data = json_decode($params['content'], true);
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            $label = isset($data['window']) ? 'window_' . $data['window']['number'] : 'review';
            if ($label === 'window_2' && $attempts++ === 0) throw new \RuntimeException('Could not resolve host');
            $paid[] = $label;
            $receipt = $this->billedReceipt(); unset($receipt['result']);
            $receipt = ShortDramaVnextSceneShotTest::reply($data) + $receipt;
            $persist($receipt); return $receipt;
        };
        try { JobRunner::run($this->store, 'worker', $provider); self::fail('Connection failure ignored'); }
        catch (\RuntimeException $error) { self::assertSame(425, $error->getCode()); }
        $job = $this->store->snapshot(); $budget = json_decode($job['budget_json'], true);
        self::assertSame('retry_wait', $job['execution_status']); self::assertSame(['window_1'], $paid);
        self::assertSame(1, json_decode($job['candidate_json'], true)['shot_windows']['completed']);
        self::assertSame($input['context']['canonical_input']['scenes'], json_decode($job['candidate_json'], true)['document']['scenes']);
        self::assertSame(46, $budget['resources']['spent']['total_tokens']); self::assertSame([], $budget['resources']['holds']);
        Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        $result = JobRunner::run($this->store, 'worker', $provider);
        self::assertSame(['window_1', 'window_2', 'review'], $paid);
        self::assertSame('succeeded', $this->store->snapshot()['execution_status']);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertCount(6, $result['shot_plan']['audio_segments']);
        $final = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame($budget['scene_shot_plan'], $final['scene_shot_plan']); self::assertSame(3, $final['base_calls']);
        self::assertSame(4, $final['calls']); self::assertSame(138, $final['resources']['spent']['total_tokens']);
        self::assertSame(1200000, $final['resources']['spent']['user_charge_points']);
        JobRunner::run($this->store, 'other_worker', static function () { self::fail('Published result resubmitted'); });
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
    }
    public function testFrozenReviewPlanOnlyAddsItsBaseCallsOnceAndRejectsReplacement(): void
    {
        $input = $this->sceneStore(true); $context = $input['context']; $document = $context['canonical_input'];
        $shots = \app\common\service\app\aigc_short_drama\vnext\ShotCompiler::compile($document,
            json_decode(ShortDramaVnextSceneShotTest::reply(['document' => $document])['content'], true), 'shots');
        $plan = \app\common\service\app\aigc_short_drama\vnext\SceneReviewExecution::partition($context, $document, $shots, [ShortDramaVnextSceneReviewTest::class, 'capacity'], 1);
        $fence = $this->store->acquire('worker', 100); $before = $this->store->snapshot();
        self::assertNull($this->store->sceneReviewPlan($fence, $document, $shots, null));
        self::assertSame($plan, $this->store->sceneReviewPlan($fence, $document, $shots, $plan));
        self::assertSame($plan, $this->store->sceneReviewPlan($fence, $document, $shots, null));
        self::assertSame($plan, $this->store->sceneReviewPlan($fence, $document, $shots, $plan));
        $after = $this->store->snapshot(); $budget = json_decode($after['budget_json'], true);
        self::assertSame(5, $budget['base_calls']); self::assertSame(10, $budget['call_limit']); self::assertSame(0, $budget['calls']);
        self::assertSame(json_decode($before['budget_json'], true)['resources'], $budget['resources']); self::assertSame($before['deadline_at'], $after['deadline_at']);
        $replacement = \app\common\service\app\aigc_short_drama\vnext\SceneReviewExecution::partition($context, $document, $shots, static fn() => ['max_tokens' => 8192], 1, true);
        $this->expectExceptionCode(409); $this->store->sceneReviewPlan($fence, $document, $shots, $replacement);
    }
    public function testWindowReviewWorkerResumesAndDoesNotExpandFrozenBudgetAgain(): void
    {
        $input = $this->sceneStore(true); $paid = []; $attempts = 0;
        $provider = function ($params, $key, $persist, $admit) use (&$paid, &$attempts): array {
            $data = json_decode($params['content'], true);
            $label = isset($data['review_window']) ? 'window_' . $data['review_window']['number']
                : (isset($data['window_reviews']) ? 'global' : (isset($data['shot_plan']) ? 'review' : 'shots'));
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            if ($label === 'window_2' && $attempts++ === 0) throw new \RuntimeException('Could not resolve host');
            $paid[] = $label; $receipt = $this->billedReceipt(); unset($receipt['result']);
            $receipt = ($label === 'review' ? ['content' => '{partial', 'finish_reason' => 'length'] : ShortDramaVnextSceneReviewTest::receipt($data)) + $receipt;
            $persist($receipt); return $receipt;
        };
        try { JobRunner::run($this->store, 'worker', $provider); self::fail('Connection failure ignored'); }
        catch (\RuntimeException $e) { self::assertSame(425, $e->getCode()); }
        $job = $this->store->snapshot(); $budget = json_decode($job['budget_json'], true); $candidate = json_decode($job['candidate_json'], true);
        self::assertSame(['shots', 'review', 'window_1'], $paid);
        self::assertSame('retry_wait', $job['execution_status']); self::assertSame(1, $candidate['review_windows']['completed']);
        self::assertSame($input['context']['canonical_input'], $candidate['document']);
        self::assertCount(6, $candidate['shot_plan']['shots']); self::assertArrayNotHasKey('coverage_report', $candidate);
        self::assertSame(4, $budget['base_calls']); self::assertSame(138, $budget['resources']['spent']['total_tokens']);
        Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        $result = JobRunner::run($this->store, 'worker', $provider);
        self::assertSame(['shots', 'review', 'window_1', 'window_2', 'global'], $paid);
        self::assertSame('ready', $result['coverage_report']['status']);
        $final = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame(4, $final['base_calls']); self::assertSame(6, $final['calls']);
        self::assertSame($budget['scene_review_plan'], $final['scene_review_plan']);
        self::assertSame(230, $final['resources']['spent']['total_tokens']); self::assertSame(2000000, $final['resources']['spent']['user_charge_points']);
        self::assertSame($budget['resources']['limits'], $final['resources']['limits']); self::assertSame($job['deadline_at'], $this->store->snapshot()['deadline_at']);
        JobRunner::run($this->store, 'other', static function () { self::fail('Published review resubmitted'); });
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
    }
    public function testCancelBetweenReviewWindowsKeepsReceiptsAndRequiresExplicitResume(): void
    {
        $this->sceneStore(true); $paid = []; $canceled = false;
        $provider = function ($params, $key, $persist, $admit) use (&$paid): array {
            $data = json_decode($params['content'], true);
            $label = isset($data['review_window']) ? 'window_' . $data['review_window']['number']
                : (isset($data['window_reviews']) ? 'global' : (isset($data['shot_plan']) ? 'review' : 'shots'));
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            $paid[] = $label; $receipt = $this->billedReceipt(); unset($receipt['result']);
            $receipt = ($label === 'review' ? ['content' => '{partial', 'finish_reason' => 'length'] : ShortDramaVnextSceneReviewTest::receipt($data)) + $receipt;
            $persist($receipt); return $receipt;
        };
        try { JobRunner::run($this->store, 'worker', $provider, function ($event, $payload) use (&$canceled) {
            if (!$canceled && $event === 'stage' && $payload['stage'] === 'review_window_2') { $canceled = true; $this->store->cancel(); }
        }); self::fail('Canceled job continued'); }
        catch (\RuntimeException $e) { self::assertContains($e->getCode(), [409, 422]); }
        self::assertSame(['shots', 'review', 'window_1'], $paid);
        self::assertSame('canceled', $this->store->snapshot()['execution_status']);
        self::assertSame(1, json_decode($this->store->snapshot()['candidate_json'], true)['review_windows']['completed']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        self::assertTrue($this->store->resume()); JobRunner::run($this->store, 'worker', $provider);
        self::assertSame(['shots', 'review', 'window_1', 'window_2', 'global'], $paid);
        self::assertSame('succeeded', $this->store->snapshot()['execution_status']);
    }
    public static function visualRepairInterruptions(): array { return ['connection' => [false], 'cancel' => [true]]; }
    /** @dataProvider visualRepairInterruptions */
    public function testVisualRepairRecoveryKeepsBestContentAndNeverRepeatsPaidPatch(bool $cancel): void
    {
        $input = $this->sceneStore(true, true); $paid = []; $failed = false;
        $provider = function ($params, $key, $persist, $admit) use (&$paid, &$failed, $cancel): array {
            $data = json_decode($params['content'], true);
            if (isset($data['repair_targets'])) $stage = 'window_repair_1_patch_' . $data['review_window']['number'];
            elseif (isset($data['review_window'])) $stage = (str_contains($data['shot_plan']['revision_id'], 'window_repair_1') ? 'window_repair_1_review_' : 'review_window_') . $data['review_window']['number'];
            elseif (isset($data['window_reviews'])) $stage = str_contains($data['shot_plan']['revision_id'], 'window_repair_1') ? 'window_repair_1_global' : 'review_global';
            else $stage = isset($data['shot_plan']) ? 'review' : 'shots';
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            if (!$cancel && !$failed && $stage === 'window_repair_1_review_2') { $failed = true; throw new \RuntimeException('Could not resolve host'); }
            $paid[] = $stage; $receipt = $this->billedReceipt(); unset($receipt['result']);
            $receipt = ($stage === 'review' ? ['content' => '{partial', 'finish_reason' => 'length'] : ShortDramaVnextSceneShotRepairTest::receipt($stage, $data)) + $receipt;
            $persist($receipt); return $receipt;
        };
        try { JobRunner::run($this->store, 'worker', $provider, function ($event, $payload) use ($cancel, &$failed) {
            if ($cancel && !$failed && $event === 'stage' && $payload['stage'] === 'window_repair_1_review_2') { $failed = true; $this->store->cancel(); }
        }); self::fail('Repair interruption ignored'); }
        catch (\RuntimeException $e) { self::assertContains($e->getCode(), $cancel ? [409, 422] : [425]); }
        $before = $this->store->snapshot(); $candidate = json_decode($before['candidate_json'], true); $budget = json_decode($before['budget_json'], true);
        self::assertSame($cancel ? 'canceled' : 'retry_wait', $before['execution_status']);
        self::assertSame('draft', $candidate['coverage_report']['status']); self::assertSame($input['context']['canonical_input'], $candidate['document']);
        self::assertSame(1, $candidate['shot_repair']['completed_patches']);
        self::assertSame($candidate['shot_plan']['content_hash'], $candidate['shot_repair']['base_shot_hash']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        if ($cancel) self::assertTrue($this->store->resume());
        else Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        $result = JobRunner::run($this->store, 'worker', $provider);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertSame($input['context']['canonical_input'], $result['document']);
        self::assertSame($candidate['shot_plan']['audio_segments'], $result['shot_plan']['audio_segments']);
        self::assertSame(['shots', 'review', 'review_window_1', 'review_window_2', 'review_global', 'window_repair_1_patch_2', 'window_repair_1_review_2', 'window_repair_1_global'], $paid);
        $after = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame($cancel ? 8 : 9, $after['calls']); self::assertSame(1, $after['repair_rounds']); self::assertSame(0, $after['reservations']['window_repair_1']);
        self::assertSame($budget['base_calls'], $after['base_calls']); self::assertSame($budget['call_limit'], $after['call_limit']);
        self::assertSame($budget['resources']['limits'], $after['resources']['limits']); self::assertSame(368, $after['resources']['spent']['total_tokens']);
        self::assertSame(3200000, $after['resources']['spent']['user_charge_points']);
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
    }
    public static function textRepairInterruptions(): array { return ['cancel' => ['cancel'], 'settlement' => ['settlement']]; }
    /** @dataProvider textRepairInterruptions */
    public function testTextRepairRestoresPaidPatchWithoutLosingBestContentOrExpandingBudget(string $mode): void
    {
        $this->sceneStore(false, false, true); $paid = []; $interrupted = false;
        $provider = function ($params, $key, $persist, $admit) use (&$paid, &$interrupted, $mode): array {
            $data = json_decode($params['content'], true); $repaired = str_contains($data['document']['revision_id'] ?? '', '_cw1');
            if (isset($data['allowed_patch_unit_ids'])) $stage = 'content_window_repair_1_text';
            elseif (isset($data['repair_window'])) $stage = 'content_window_repair_1_shots_' . $data['repair_window']['number'];
            elseif (isset($data['review_window'])) $stage = ($repaired ? 'content_window_repair_1_review_' : 'review_window_') . $data['review_window']['number'];
            elseif (isset($data['window_reviews'])) $stage = $repaired ? 'content_window_repair_1_global' : 'review_global';
            elseif (isset($data['shot_plan'])) $stage = 'review';
            else $stage = isset($data['document']) ? 'shots' : 'screenplay';
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            $paid[] = $stage; $receipt = $this->billedReceipt(); unset($receipt['result']);
            $receipt = ($stage === 'review' ? ['content' => '{partial', 'finish_reason' => 'length'] : ShortDramaVnextSceneContentRepairTest::receipt($stage, $data)) + $receipt;
            $persist($receipt);
            if ($mode === 'settlement' && !$interrupted && $stage === 'content_window_repair_1_text') { $interrupted = true; throw new \RuntimeException('settlement unavailable'); }
            return $receipt;
        };
        try { JobRunner::run($this->store, 'worker', $provider, function ($event, $payload) use ($mode, &$interrupted) {
            if ($mode === 'cancel' && !$interrupted && $event === 'stage' && $payload['stage'] === 'content_window_repair_1_shots_2') { $interrupted = true; $this->store->cancel(); }
        }); self::fail('Interruption ignored'); }
        catch (\RuntimeException $e) { if ($mode === 'cancel') self::assertContains($e->getCode(), [409, 422]); else self::assertSame('settlement unavailable', $e->getMessage()); }
        $snapshot = $this->store->snapshot(); $budget = json_decode($snapshot['budget_json'], true); $best = json_decode($snapshot['candidate_json'], true);
        self::assertSame($mode === 'cancel' ? 'canceled' : 'reconciling', $snapshot['execution_status']);
        self::assertSame('draft', $best['coverage_report']['status']);
        self::assertStringNotContainsString('补正', implode('', ContentDocument::render($best['document'])));
        if ($mode === 'cancel') self::assertStringContainsString('补正', implode('', ContentDocument::render($best['content_repair']['candidate_document'])));
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        if ($mode === 'settlement') $this->store->reconcileReceipts(static fn($receipt) => $receipt);
        self::assertTrue($this->store->resume()); $result = JobRunner::run($this->store, 'worker', $provider);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertCount(6, $result['shot_plan']['audio_segments']);
        self::assertStringContainsString('补正', implode('', ContentDocument::render($result['document'])));
        self::assertSame(['screenplay', 'shots', 'review', 'review_window_1', 'review_window_2', 'review_global', 'content_window_repair_1_text',
            'content_window_repair_1_shots_2', 'content_window_repair_1_review_2', 'content_window_repair_1_global'], $paid);
        $final = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame(10, $final['calls']); self::assertSame(1, $final['repair_rounds']); self::assertSame(0, $final['reservations']['content_window_repair_1']);
        self::assertSame($budget['base_calls'], $final['base_calls']); self::assertSame($budget['call_limit'], $final['call_limit']);
        self::assertSame($budget['resources']['limits'], $final['resources']['limits']); self::assertSame(460, $final['resources']['spent']['total_tokens']);
        self::assertSame(4000000, $final['resources']['spent']['user_charge_points']);
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        JobRunner::run($this->store, 'another', static function () { self::fail('Published text repair recharged'); });
    }
    private function screenplayStore(int $tokens = 10000): array
    {
        $input = ShortDramaVnextSceneScreenplayTest::input();
        $input['execution']['resource_limits'] = ['total_tokens' => $tokens, 'tenant_cost_points' => 3, 'user_charge_points' => 6];
        $this->scope['task_id'] .= '_screenplay';
        $this->store = new ExecutionStore($this->scope['tenant_id'], $this->scope['user_id'], $this->scope['task_id'], $this->buffer);
        $this->store->create($this->scope['task_id'], $input, time() + 600);
        return $input;
    }
    public function testCreativePlanFreezesContextAndBudgetWithoutResettingResources(): void
    {
        $input = $this->screenplayStore(); $context = $input['context']; $fence = $this->store->acquire('worker', 100);
        $plan = \app\common\service\app\aigc_short_drama\vnext\SceneScreenplayExecution::plan($context, ShortDramaVnextSceneScreenplayTest::proposal());
        $before = $this->store->snapshot();
        self::assertNull($this->store->sceneScreenplayPlan($fence, $context, null));
        self::assertSame($plan, $this->store->sceneScreenplayPlan($fence, $context, $plan));
        self::assertSame($plan, $this->store->sceneScreenplayPlan($fence, $context, $plan));
        $after = $this->store->snapshot(); $budget = json_decode($after['budget_json'], true);
        self::assertSame(5, $budget['base_calls']); self::assertSame(10, $budget['call_limit']); self::assertSame(0, $budget['calls']);
        self::assertSame(json_decode($before['budget_json'], true)['resources'], $budget['resources']); self::assertSame($before['deadline_at'], $after['deadline_at']);
        $proposal = ShortDramaVnextSceneScreenplayTest::proposal(); $proposal['scenes'][0]['brief'] .= '改写';
        $changed = \app\common\service\app\aigc_short_drama\vnext\SceneScreenplayExecution::plan($context, $proposal);
        $this->expectExceptionCode(409); $this->store->sceneScreenplayPlan($fence, $context, $changed);
    }
    public static function screenplayInterruptions(): array { return [['connection'], ['cancel'], ['settlement']]; }
    /** @dataProvider screenplayInterruptions */
    public function testCreativeSceneRecoveryReusesPaidContentAndPublishesOnlyAfterFullReview(string $mode): void
    {
        $this->screenplayStore(); $paid = []; $interrupted = false;
        $provider = function ($params, $key, $persist, $admit) use (&$paid, &$interrupted, $mode): array {
            $data = json_decode($params['content'], true);
            $stage = isset($data['scene_number']) ? 'screenplay_scene_' . $data['scene_number']
                : (isset($data['target_ids']) ? 'screenplay_plan' : (isset($data['shot_plan']) ? 'review' : (isset($data['document']) ? 'shots' : 'screenplay')));
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            if ($mode === 'connection' && !$interrupted && $stage === 'screenplay_scene_2') { $interrupted = true; throw new \RuntimeException('Could not resolve host'); }
            $paid[] = $stage; $receipt = $this->billedReceipt(); unset($receipt['result']);
            $receipt = ShortDramaVnextSceneScreenplayTest::reply($stage, $data) + $receipt; $persist($receipt);
            if ($mode === 'settlement' && !$interrupted && $stage === 'screenplay_scene_2') { $interrupted = true; throw new \RuntimeException('settlement unavailable'); }
            return $receipt;
        };
        try {
            JobRunner::run($this->store, 'worker', $provider, function ($event, $payload) use ($mode, &$interrupted) {
                if ($mode === 'cancel' && !$interrupted && $event === 'stage' && $payload['stage'] === 'screenplay_scene_2') { $interrupted = true; $this->store->cancel(); }
            }); self::fail('Interruption ignored');
        } catch (\RuntimeException $e) {
            if ($mode === 'connection') self::assertSame(425, $e->getCode());
            elseif ($mode === 'cancel') self::assertContains($e->getCode(), [409, 422]);
            else self::assertSame('settlement unavailable', $e->getMessage());
        }
        $snapshot = $this->store->snapshot(); $before = json_decode($snapshot['budget_json'], true); $draft = json_decode($snapshot['candidate_json'], true);
        self::assertSame(['connection' => 'retry_wait', 'cancel' => 'canceled', 'settlement' => 'reconciling'][$mode], $snapshot['execution_status']);
        self::assertSame(1, $draft['screenplay_windows']['completed']); self::assertFalse($draft['screenplay_windows']['complete']);
        self::assertCount(2, ContentDocument::units($draft['document'])); self::assertSame('draft', $snapshot['document_status']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        if ($mode === 'settlement') $this->store->reconcileReceipts(static fn($receipt) => $receipt);
        if ($mode === 'connection') Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        else self::assertTrue($this->store->resume());
        $result = JobRunner::run($this->store, 'worker', $provider);
        self::assertSame(['screenplay', 'screenplay_plan', 'screenplay_scene_1', 'screenplay_scene_2', 'shots', 'review'], $paid);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertCount(4, $result['shot_plan']['audio_segments']);
        self::assertSame($draft['document']['scenes'][0], $result['document']['scenes'][0]);
        $final = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame($before['scene_screenplay_plan'], $final['scene_screenplay_plan']); self::assertSame(5, $final['base_calls']);
        self::assertSame(10, $final['call_limit']); self::assertSame($mode === 'connection' ? 7 : 6, $final['calls']);
        self::assertSame($before['resources']['limits'], $final['resources']['limits']); self::assertSame($snapshot['deadline_at'], $this->store->snapshot()['deadline_at']);
        self::assertSame(276, $final['resources']['spent']['total_tokens']); self::assertSame(2400000, $final['resources']['spent']['user_charge_points']);
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        JobRunner::run($this->store, 'another', static function () { self::fail('Published screenplay recharged'); });
    }
    public function testReplayingEarlierCreativeSceneCannotDiscardLaterDraft(): void
    {
        $input = $this->screenplayStore(); $snapshots = []; $plan = null;
        \app\common\service\app\aigc_short_drama\vnext\GenerationPipeline::run($input['context'],
            static fn($key, $messages) => ShortDramaVnextSceneScreenplayTest::reply($key, json_decode($messages['content'], true)),
            static function () {}, static function ($candidate) use (&$snapshots) { $snapshots[] = $candidate; },
            ShortDramaVnextSceneScreenplayTest::execution($plan));
        $fence = $this->store->acquire('worker', 100);
        $this->store->checkpoint($fence, $snapshots[1]); $before = $this->store->snapshot();
        $this->store->checkpoint($fence, $snapshots[0]);
        self::assertSame($before['candidate_json'], $this->store->snapshot()['candidate_json']);
        self::assertSame($before['event_seq'], $this->store->snapshot()['event_seq']);
        $this->store->checkpoint($fence, end($snapshots)); $later = $this->store->snapshot();
        $this->store->checkpoint($fence, $snapshots[1]);
        self::assertSame($later['candidate_json'], $this->store->snapshot()['candidate_json']);
    }
    public function testCreativeExpansionCannotIncreaseResourceLimitAndKeepsCompletedScene(): void
    {
        $this->screenplayStore(600); $paid = [];
        try {
            JobRunner::run($this->store, 'worker', function ($params, $key, $persist, $admit) use (&$paid): array {
                $data = json_decode($params['content'], true);
                $stage = isset($data['scene_number']) ? 'screenplay_scene_' . $data['scene_number'] : (isset($data['target_ids']) ? 'screenplay_plan' : 'screenplay');
                $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
                $paid[] = $stage; $receipt = $this->billedReceipt(); unset($receipt['result']);
                $receipt = ShortDramaVnextSceneScreenplayTest::reply($stage, $data) + $receipt; $persist($receipt); return $receipt;
            }); self::fail('Resource limit expanded');
        } catch (\RuntimeException $e) { self::assertSame(429, $e->getCode()); }
        $job = $this->store->snapshot(); $draft = json_decode($job['candidate_json'], true); $budget = json_decode($job['budget_json'], true);
        self::assertSame(['screenplay', 'screenplay_plan', 'screenplay_scene_1'], $paid);
        self::assertSame('needs_attention', $job['execution_status']); self::assertSame('budget_exhausted', $job['error_code']);
        self::assertSame(1, $draft['screenplay_windows']['completed']); self::assertFalse($draft['screenplay_windows']['complete']);
        self::assertSame(600, $budget['resources']['limits']['total_tokens']); self::assertSame(138, $budget['resources']['spent']['total_tokens']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
    }
    public function testFullPreviousScenesAreNeverTrimmedToPassInputCapacity(): void
    {
        // Deliberately oversized received fixture tests retention/preflight, not real model output capacity.
        $this->screenplayStore(); $text = str_repeat('第一场完整内容不可删。', 4000); $paid = [];
        try {
            JobRunner::run($this->store, 'worker', function ($params, $key, $persist, $admit) use ($text, &$paid): array {
                $data = json_decode($params['content'], true);
                $stage = isset($data['scene_number']) ? 'screenplay_scene_' . $data['scene_number'] : (isset($data['target_ids']) ? 'screenplay_plan' : 'screenplay');
                self::assertNotSame('screenplay_scene_2', $stage);
                $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]); $paid[] = $stage;
                $receipt = $this->billedReceipt(); unset($receipt['result']); $reply = ShortDramaVnextSceneScreenplayTest::reply($stage, $data);
                if ($stage === 'screenplay_scene_1') {
                    $body = json_decode($reply['content'], true); $body['scene']['units'][0]['text'] = $text;
                    $reply['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
                }
                $receipt = $reply + $receipt; $persist($receipt); return $receipt;
            }); self::fail('Previous content trimmed');
        } catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        $job = $this->store->snapshot(); $draft = json_decode($job['candidate_json'], true);
        self::assertSame(['screenplay', 'screenplay_plan', 'screenplay_scene_1'], $paid);
        self::assertSame('input_capacity_exceeded', $job['error_code']); self::assertSame($text, $draft['document']['scenes'][0]['units'][0]['text']);
        self::assertSame(3, json_decode($job['budget_json'], true)['calls']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
    }
    public static function locationInterruptions(): array { return [['connection'], ['cancel'], ['settlement']]; }
    /** @dataProvider locationInterruptions */
    public function testLongCreativeJobRecoversMissingLocationReceiptWithinItsOriginalBudget(string $mode): void
    {
        $input = ShortDramaVnextMissingContentLocationTest::input(); $input['context']['scene_screenplay_version'] = 1;
        $input['model']['max_output_tokens'] = $input['execution']['max_output_tokens'] = 8192;
        $input['execution']['resource_limits'] = ['total_tokens' => 40000, 'tenant_cost_points' => 30, 'user_charge_points' => 60];
        $this->scope['task_id'] .= '_location';
        $this->store = new ExecutionStore($this->scope['tenant_id'], $this->scope['user_id'], $this->scope['task_id'], $this->buffer);
        $this->store->create($this->scope['task_id'], $input, time() + 600);
        $paid = []; $interrupted = false;
        $provider = function ($params, $key, $persist, $admit) use (&$paid, &$interrupted, $mode): array {
            $data = json_decode($params['content'], true); $repaired = str_contains($data['document']['revision_id'] ?? '', '_cw1');
            if (isset($data['target_ids'])) $stage = 'screenplay_plan';
            elseif (isset($data['scene_number'])) $stage = 'screenplay_scene_' . $data['scene_number'];
            elseif (isset($data['localization_issues'])) $stage = 'content_window_location_1';
            elseif (isset($data['allowed_patch_unit_ids'])) $stage = 'content_window_repair_1_text';
            elseif (isset($data['repair_window'])) $stage = 'content_window_repair_1_shots_' . $data['repair_window']['number'];
            elseif (isset($data['review_window'])) $stage = ($repaired ? 'content_window_repair_1_review_' : 'review_window_') . $data['review_window']['number'];
            elseif (isset($data['window_reviews'])) $stage = $repaired ? 'content_window_repair_1_global' : 'review_global';
            elseif (isset($data['shot_plan'])) $stage = 'review';
            else $stage = isset($data['document']) ? 'shots' : 'screenplay';
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            if ($mode === 'connection' && !$interrupted && $stage === 'content_window_repair_1_text') { $interrupted = true; throw new \RuntimeException('Could not resolve host'); }
            if (in_array($stage, ['screenplay', 'review'], true)) $reply = ['content' => '{partial', 'finish_reason' => 'length'];
            elseif ($stage === 'screenplay_plan') {
                $plan = ShortDramaVnextSceneScreenplayTest::proposal(); $first = $plan['scenes'][0]; $later = $plan['scenes'][1];
                $plan['scenes'] = array_merge([$first], array_fill(0, 11, $later)); $plan['scenes'][11]['target_ids'] = ['ending'];
                $reply = ['content' => json_encode($plan, JSON_UNESCAPED_UNICODE)];
            } elseif (str_starts_with($stage, 'screenplay_scene_')) $reply = ShortDramaVnextSceneScreenplayTest::reply($stage, $data);
            else $reply = ShortDramaVnextMissingContentLocationTest::receipt($stage, $data);
            $paid[] = $stage; $receipt = $this->billedReceipt(); unset($receipt['result']); $receipt = $reply + $receipt; $persist($receipt);
            if ($mode === 'settlement' && !$interrupted && $stage === 'content_window_location_1') { $interrupted = true; throw new \RuntimeException('settlement unavailable'); }
            return $receipt;
        };
        try {
            JobRunner::run($this->store, 'worker', $provider, function ($event, $payload) use ($mode, &$interrupted) {
                if ($mode === 'cancel' && !$interrupted && $event === 'stage' && $payload['stage'] === 'content_window_repair_1_text') { $interrupted = true; $this->store->cancel(); }
            }); self::fail('Interruption ignored');
        } catch (\RuntimeException $e) {
            if ($mode === 'connection') self::assertSame(425, $e->getCode());
            elseif ($mode === 'cancel') self::assertContains($e->getCode(), [409, 422]);
            else self::assertSame('settlement unavailable', $e->getMessage());
        }
        $snapshot = $this->store->snapshot(); $budget = json_decode($snapshot['budget_json'], true); $best = json_decode($snapshot['candidate_json'], true);
        self::assertSame('draft', $best['coverage_report']['status']); self::assertCount(24, ContentDocument::units($best['document']));
        self::assertSame([], $best['coverage_report']['issues'][0]['repair_unit_ids']);
        if ($mode !== 'settlement') self::assertNotEmpty($best['missing_location']['locations']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        if ($mode === 'settlement') $this->store->reconcileReceipts(static fn($receipt) => $receipt);
        if ($mode === 'connection') Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->update(['retry_after' => 0]);
        else self::assertTrue($this->store->resume());
        $result = JobRunner::run($this->store, 'worker', $provider); $final = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertCount(25, $result['shot_plan']['audio_segments']);
        self::assertCount(25, $paid); self::assertSame(1, array_count_values($paid)['content_window_location_1']);
        self::assertSame($mode === 'connection' ? 26 : 25, $final['calls']); self::assertSame(17, $final['base_calls']); self::assertSame(26, $final['call_limit']);
        self::assertSame($budget['resources']['limits'], $final['resources']['limits']); self::assertSame($snapshot['deadline_at'], $this->store->snapshot()['deadline_at']);
        self::assertSame(1150, $final['resources']['spent']['total_tokens']); self::assertSame(10000000, $final['resources']['spent']['user_charge_points']);
        self::assertSame(1, $final['repair_rounds']); self::assertSame(0, $final['reservations']['content_window_repair_1']);
        self::assertNotEmpty($result['coverage_report']['content_repair']['issues'][0]['insertion_location']['location_hash']);
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        JobRunner::run($this->store, 'another', static function () { self::fail('Location recharged after publication'); });
    }
    public function testIndexedHistoricalEvidenceReplaysWithoutChangingFullLedgerOrPaidRequests(): void
    {
        [, $ledger] = ShortDramaVnextNarrativeHistoryIndexTest::fixture();
        $input = ShortDramaVnextSceneShotTest::input(); $input['context']['scene_shot_version'] = 0;
        $input['context']['history_evidence_index_version'] = 1; $input['context']['narrative_contract_version'] = 1;
        $input['context']['previous_narrative'] = $ledger;
        $input['execution']['resource_limits'] = ['total_tokens' => 10000, 'tenant_cost_points' => 3, 'user_charge_points' => 6];
        $this->scope['task_id'] .= '_history';
        $this->store = new ExecutionStore($this->scope['tenant_id'], $this->scope['user_id'], $this->scope['task_id'], $this->buffer);
        $this->store->create($this->scope['task_id'], $input, time() + 600, 2); $paid = []; $interrupted = false;
        $provider = function ($params, $key, $persist, $admit) use (&$paid, &$interrupted, $ledger): array {
            $data = json_decode($params['content'], true); $stage = isset($data['shot_plan']) ? 'review' : 'shots';
            $admit(['total_tokens' => 500, 'tenant_cost_points' => .5, 'user_charge_points' => 1]); $paid[] = $stage;
            $reply = ShortDramaVnextSceneShotTest::reply($data);
            if ($stage === 'review') {
                self::assertSame(1, $data['previous_narrative']['evidence_index_version']);
                $expanded = \app\common\service\app\aigc_short_drama\vnext\NarrativeHistoryIndex::expand($data['previous_narrative']);
                self::assertSame(ContentDocument::hash(\app\common\service\app\aigc_short_drama\vnext\NarrativeLedger::relevant($ledger, ['char1'])), ContentDocument::hash($expanded));
                $body = json_decode($reply['content'], true); $body['narrative_changes'] = []; $reply['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
            $receipt = $this->billedReceipt(); unset($receipt['result']); $receipt = $reply + $receipt; $persist($receipt);
            if ($stage === 'review' && !$interrupted) { $interrupted = true; throw new \RuntimeException('settlement unavailable'); }
            return $receipt;
        };
        try { JobRunner::run($this->store, 'worker', $provider); self::fail('Settlement failure ignored'); }
        catch (\RuntimeException $e) { self::assertSame('settlement unavailable', $e->getMessage()); }
        $before = $this->store->snapshot(); self::assertSame('reconciling', $before['execution_status']);
        $this->store->reconcileReceipts(static fn($receipt) => $receipt); self::assertTrue($this->store->resume());
        $result = JobRunner::run($this->store, 'worker', $provider);
        self::assertSame(['shots', 'review'], $paid); self::assertSame('ready', $result['coverage_report']['status']);
        self::assertSame($ledger['state'], $result['coverage_report']['narrative_ledger']['state']);
        self::assertSame($ledger['content_hash'], $result['coverage_report']['history_retrieval']['ledger_hash']);
        self::assertCount(60, $result['coverage_report']['history_retrieval']['fact_ids']);
        self::assertSame($before['input_json'], $this->store->snapshot()['input_json']);
        $budget = json_decode($this->store->snapshot()['budget_json'], true);
        self::assertSame(2, $budget['calls']); self::assertSame(92, $budget['resources']['spent']['total_tokens']);
        self::assertSame(800000, $budget['resources']['spent']['user_charge_points']);
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
    }
    public function testOversizedCanonicalInputStopsBeforeProviderAndKeepsEntireDraft(): void
    {
        $text = str_repeat('全文必须保留。', 6000) . '最后一句😀';
        $context = ['revision_id' => 'r1', 'episode_id' => 'e1', 'entity_scope' => 't1:p1', 'prompt_projection_version' => 1,
            'entities' => [['ref' => 'l1', 'entity_id' => 'room1', 'kind' => 'location', 'name' => '书房']]];
        $document = ContentDocument::normalize(['title' => '长原文', 'scenes' => [['location' => 'l1', 'units' => [
            ['type' => 'dialogue', 'speech_type' => 'narration', 'text' => $text]]]]], $context);
        $context['canonical_input'] = $document;
        $input = ['context' => $context, 'model' => ['context_window' => 32768, 'max_output_tokens' => 8192],
            'execution' => ['lease_seconds' => 150, 'stage_timeout_seconds' => 100]];
        Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['input_json' => json_encode($input), 'input_hash' => ContentDocument::hash($input)]);
        try { JobRunner::run($this->store, 'worker', static function () { self::fail('Oversized request reached provider'); }); self::fail('Input was truncated'); }
        catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        $job = $this->store->snapshot();
        self::assertSame('needs_attention', $job['execution_status']); self::assertSame('input_capacity_exceeded', $job['error_code']);
        self::assertSame(0, json_decode($job['budget_json'], true)['calls']);
        self::assertSame($text, json_decode($job['candidate_json'], true)['document']['scenes'][0]['units'][0]['text']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_stage')->where($this->scope)->count());
    }
    public static function publicationConflicts(): array { return [[false, false], [true, false], [false, true]]; }

    /** @dataProvider publicationConflicts */
    public function testThreeCallsPublishAtomicallyAndReplayDoesNotRepublishOrCharge(bool $dependencyChanged, bool $invalidUsage): void
    {
        if ($invalidUsage) $this->resourceStore(400);
        $context = ['revision_id' => $this->scope['task_id'], 'episode_id' => 'e1', 'entity_scope' => 't1:p1', 'prompt' => '旁白说天亮了',
            'entities' => [['ref' => 'l1', 'entity_id' => 'room1', 'kind' => 'location', 'name' => '书房']]];
        $input = ['context' => $context, 'model' => ['context_window' => 32768, 'max_output_tokens' => 8192],
            'execution' => ['lease_seconds' => 150, 'stage_timeout_seconds' => 100]];
        Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['input_json' => json_encode($input), 'input_hash' => ContentDocument::hash($input)]);
        $calls = 0;
        $provider = function ($params, $key, $persist, $admit) use (&$calls, $invalidUsage): array {
            if ($admit) $admit($this->bound());
            $calls++; $data = json_decode($params['content'], true);
            if (!isset($data['document'])) $reply = ['title' => '天亮', 'scenes' => [['location' => 'l1', 'units' => [['type' => 'dialogue', 'speech_type' => 'narration', 'text' => '天亮了。']]]]];
            elseif (!isset($data['shot_plan'])) $reply = ['shots' => [['visual_description' => '阳光照进书房', 'refs' => [['unit' => 'u1']]]]];
            else {
                $checks = [];
                foreach (['identity', 'continuity', 'shot_fidelity'] as $aspect) $checks[] = ['id' => 'aspect:' . $aspect, 'status' => 'covered',
                    'evidence' => [['unit' => 'u1', 'start' => 0, 'end' => 4, 'quote' => '天亮了。']]];
                $reply = ['document_hash' => $data['document']['content_hash'], 'shot_hash' => $data['shot_plan']['content_hash'], 'checks' => $checks];
            }
            $receipt = ['result' => ['content' => json_encode($reply), 'finish_reason' => 'stop']];
            if ($invalidUsage) {
                $receipt += $this->billedReceipt();
                if ($calls === 3) $receipt['usage']['total_tokens'] = 101;
            }
            return $receipt;
        };
        try {
            JobRunner::run($this->store, 'worker1', $provider, static function () { throw new \RuntimeException('display connection lost'); },
                function () {
                    Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['error_code' => 'must_rollback']);
                    throw new \RuntimeException('projection write failed');
                });
            self::fail('Failed projection was published');
        } catch (\RuntimeException $error) {
            if ($invalidUsage) {
                self::assertSame(429, $error->getCode());
                self::assertSame('resource_bound_exceeded', $this->resources()['blocked']);
                self::assertSame('needs_attention', $this->store->snapshot()['execution_status']);
                self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
                self::assertStringContainsString('天亮了。', $this->store->snapshot()['candidate_json']);
                self::assertSame(3, $calls);
                return;
            }
            self::assertSame('projection write failed', $error->getMessage());
        }
        self::assertSame('', $this->store->snapshot()['error_code']);
        self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        self::assertSame(0, Db::name('aigc_short_drama_vnext_outbox')->where($this->scope)->count());
        Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['lease_until' => 0]);
        if ($dependencyChanged) {
            try {
                JobRunner::run($this->store, 'worker2', $provider, null, static function () {
                    throw new \RuntimeException('前集修订已变化', 409);
                });
                self::fail('Stale dependency was published');
            } catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            self::assertSame('needs_attention', $this->store->snapshot()['execution_status']);
            self::assertSame(0, (int)$this->store->snapshot()['lease_until']);
            self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
            self::assertNotEmpty($this->store->snapshot()['candidate_json']);
            self::assertSame(3, $calls);
            // A content conflict requires a new revision/context, never a blind replay.
            $this->expectExceptionCode(409);
            $this->store->resume();
            return;
        }
        JobRunner::run($this->store, 'worker2', $provider, null, static function ($candidate, $receipts) {
            self::assertCount(3, $receipts); self::assertSame('ready', $candidate['document']['status']);
        });
        JobRunner::run($this->store, 'worker2', $provider);
        self::assertSame(3, $calls);
        self::assertSame(1, Db::name('aigc_short_drama_vnext_outbox')->where($this->scope)->count());
        self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where($this->scope)->count());
        $snapshot = $this->store->publicSnapshot();
        self::assertSame('succeeded', $snapshot['execution_status']); self::assertSame('ready', $snapshot['document_status']);
        self::assertSame('not_started', $snapshot['production_status']); self::assertSame(['read_content', 'revise_content'], $snapshot['allowed_actions']);
        self::assertArrayNotHasKey('budget_json', $snapshot); self::assertArrayNotHasKey('input_json', $snapshot);
    }
}
