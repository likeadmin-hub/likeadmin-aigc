<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ExecutionStore;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
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
    public function testPreviousFenceCannotMutateCandidateAfterLeaseTakeover(): void
    {
        $fence = $this->store->acquire('worker1', 100);
        Db::name('aigc_short_drama_vnext_job')->where($this->scope)->update(['lease_until' => 0]);
        $new = $this->store->acquire('worker2', 100); self::assertGreaterThan($fence, $new);
        $this->store->checkpoint($new, ['title' => 'new']);
        $this->expectExceptionCode(409); $this->store->checkpoint($fence, ['title' => 'old']);
    }
}
