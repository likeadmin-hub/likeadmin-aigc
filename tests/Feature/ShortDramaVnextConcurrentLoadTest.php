<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ExecutionStore;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\app\aigc_short_drama\vnext\JobRunner;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Opt-in committed fixtures: validates real DB leases and cross-process recovery, with zero provider I/O. */
final class ShortDramaVnextConcurrentLoadTest extends TestCase
{
    private string $prefix = '';
    private string $directory;
    private array $processes = [];
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_VNEXT_LOAD_TESTS') !== '1') self::markTestSkipped('Opt in to 500 local fixture jobs');
        (new \think\App())->initialize();
        $this->prefix = 'vnext_load_' . bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir() . '/' . $this->prefix;
        new ReceiptBuffer($this->directory);
    }
    protected function tearDown(): void
    {
        if (!$this->prefix) return;
        foreach ($this->processes as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process, 9); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
        }
        foreach (['outbox', 'revision', 'stage', 'job'] as $table) Db::name('aigc_short_drama_vnext_' . $table)
            ->whereIn('tenant_id', [2000000812, 2000000813])->where('user_id', 7)->whereLike('task_id', $this->prefix . '\_%')->delete();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($this->directory);
    }
    private function store(int $index, string $host): ExecutionStore
    { return new ExecutionStore(2000000812 + $index % 2, 7, $this->prefix . '_' . $index, new ReceiptBuffer($this->directory . '/' . $host)); }
    private function seed(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $input = ShortDramaVnextSceneShotTest::input(1); $input['context']['scene_shot_version'] = 0;
            $input['context']['revision_id'] = $this->prefix . '_revision_' . $i;
            $input['context']['canonical_input'] = \app\common\service\app\aigc_short_drama\vnext\ContentDocument::rebind(
                $input['context']['canonical_input'], [], $input['context']['revision_id']);
            $input['execution']['resource_limits'] = ['total_tokens' => 1000, 'tenant_cost_points' => 5, 'user_charge_points' => 10];
            $this->store($i, 'seed')->create($this->prefix . '_' . $i, $input, time() + 600, 2);
        }
    }
    private function startWorker(int $worker, int $count): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/short_drama_vnext_load_worker.php', $this->prefix, $this->directory, (string)$worker, (string)$count],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process); $entry = [$process, $pipes]; $this->processes[] = $entry; return $entry;
    }
    public function testFiveHundredEpisodeJobsWithEightCompetingWorkersRecoverEveryInterruptedReceiptOnce(): void
    {
        $this->seed(500);
        $workers = []; for ($i = 0; $i < 8; $i++) $workers[] = $this->startWorker($i, 500);
        foreach ($workers as [$process, $pipes]) {
            fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($process), $error . $output);
            self::assertStringContainsString('fixture_worker_done', $output, $error . $output);
        }
        $this->processes = [];
        for ($i = 0; $i < 500; $i++) {
            $store = $this->store($i, 'recovery');
            if ($store->snapshot()['execution_status'] === 'reconciling') {
                $store->reconcileReceipts(static fn($raw) => $raw); self::assertTrue($store->resume());
                // Review was not submitted by the interrupted worker. Resume the same job
                // using the actual pipeline and a recorded fixture receipt for that new stage.
                JobRunner::run($store, 'recovery', function ($params, $key, $persist, $admit) {
                    $admit(['total_tokens' => 200, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
                    $marker = @fopen($this->directory . '/submitted_' . $key, 'x'); self::assertIsResource($marker); fclose($marker);
                    $receipt = ShortDramaVnextSceneShotTest::reply(json_decode($params['content'], true)) + ['usage' => ['input_tokens' => 12, 'output_tokens' => 34],
                        'billing' => ['billing_status' => 'settled', 'tenant_cost_points' => .2, 'user_charge_points' => .4]];
                    $persist($receipt); return $receipt;
                });
            }
            self::assertSame('succeeded', $store->snapshot()['execution_status'], 'episode ' . $i . ': ' . substr((string)@file_get_contents($this->directory . '/worker_errors'), 0, 3000));
            $budget = json_decode($store->snapshot()['budget_json'], true);
            self::assertSame(2, $budget['calls']); self::assertSame(92, $budget['resources']['spent']['total_tokens']); self::assertSame([], $budget['resources']['holds']);
            self::assertCount(2, $store->receipts());
            JobRunner::run($store, 'replay', static function () { self::fail('Completed fixture submitted again'); });
        }
        self::assertCount(1000, glob($this->directory . '/submitted_*'));
    }
    public function testKilledProcessRecoversSharedReceiptAfterCompleteLossOfItsSpool(): void
    {
        $this->seed(1); putenv('SHORT_DRAMA_VNEXT_KILL_TEST=1');
        try { [$process, $pipes] = $this->startWorker(0, 1); } finally { putenv('SHORT_DRAMA_VNEXT_KILL_TEST'); }
        stream_set_blocking($pipes[1], false); $output = ''; $deadline = microtime(true) + 10;
        do { $output .= stream_get_contents($pipes[1]); if (str_contains($output, 'raw_receipt_saved')) break; usleep(10000); } while (microtime(true) < $deadline);
        self::assertStringContainsString('raw_receipt_saved', $output);
        proc_terminate($process, 9); foreach ($pipes as $pipe) fclose($pipe); proc_close($process); $this->processes = [];
        foreach (glob($this->directory . '/host_0/*') ?: [] as $file) unlink($file);
        $store = $this->store(0, 'other_host'); $recovered = 0;
        $store->reconcileReceipts(static function ($raw) use (&$recovered) { $recovered++; return $raw; });
        $store->reconcileReceipts(static function () { self::fail('Receipt settled twice'); });
        self::assertSame(1, $recovered); self::assertCount(1, $store->receipts());
        self::assertSame([], json_decode($store->snapshot()['budget_json'], true)['resources']['holds']);
        self::assertCount(1, glob($this->directory . '/submitted_*'));
    }
}
