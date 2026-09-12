<?php

namespace Tests\Feature;

use app\common\service\ai\AiTaskJobService as Jobs;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ImageSubmitTransport as Transport;
use Tests\Fixtures\ImageSubmitPoints as Points;
use think\facade\Db;

/**
 * Real MySQL / ORM integration, isolated tables, fake provider and points.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MarketImageSubmitQueueTest extends TestCase
{
    private string $prefix = '';
    private array $tables = [];

    protected function setUp(): void
    {
        if (getenv('AI_QUEUE_DB_TESTS') !== '1') $this->markTestSkipped('Set AI_QUEUE_DB_TESTS=1 for isolated MySQL tests');
        require_once dirname(__DIR__) . '/fixtures/market_image_submit_fakes.php';
        class_alias(Transport::class, 'app\common\service\update\UpdateSourceClient');
        class_alias(Points::class, 'app\common\service\point\PointService');
        class_alias(\Tests\Fixtures\ImageSubmitAssets::class, 'app\common\service\app\aigc_image\AigcImageAssetService');
        class_alias(\Tests\Fixtures\ImageSubmitBusinessResult::class, 'app\common\service\ai\AiTaskBusinessResultService');
        (new \think\App())->initialize();
        $config = Db::getConfig();
        $connection = $config['connections'][$config['default']];
        $originalPrefix = $connection['prefix'];
        $this->prefix = 'queue_test_' . bin2hex(random_bytes(6)) . '_';
        $connection['prefix'] = $this->prefix;
        $config['default'] = $this->prefix;
        $config['connections'][$this->prefix] = $connection;
        \think\facade\Config::set($config, 'database');
        foreach (['ai_task_job', 'ai_consumption_event', 'ai_consumption_log', 'ai_app_task'] as $table) {
            Db::execute('CREATE TABLE `' . $this->prefix . $table . '` LIKE `' . $originalPrefix . $table . '`');
            $this->tables[] = $this->prefix . $table;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            // Only test-created, exactly tracked tables can be dropped.
            if (preg_match('/^queue_test_[a-f0-9]{12}_ai_[a-z_]+$/', $table) !== 1) continue;
            Db::execute('DROP TABLE `' . $table . '`');
        }
    }

    private function consumption(): int
    {
        $task = Db::name('ai_app_task')->insertGetId(['task_no' => 'fixture-task', 'tenant_id' => 90000909,
            'user_id' => 90000909, 'app_code' => 'aigc_short_drama', 'status' => 'running', 'idempotency_key' => 'fixture-task']);
        return (int)Db::name('ai_consumption_log')->insertGetId(['consume_no' => 'fixture-consumption', 'app_task_id' => $task,
            'tenant_id' => 90000909, 'user_id' => 90000909, 'app_code' => 'aigc_short_drama',
            'provider' => 'power_market', 'protocol' => 'image_generate', 'run_status' => 'reserved',
            'billing_status' => 'reserved', 'reserved_user_price' => 30, 'reserved_tenant_cost' => 10,
            'price_snapshot' => json_encode(['model_code' => 'fixture-image', 'platform_price' => 10, 'tenant_price' => 30])]);
    }

    public function testConcurrentUpsertsAreUniqueAndKeepOriginalPayload(): void
    {
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('pcntl required');
        // Warm metadata before forking, then close so no PDO socket is shared.
        Jobs::enqueue('fixture', 0, 0, [], 0, false, 'warm');
        Db::connect()->close();
        $children = [];
        for ($worker = 0; $worker < 7; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    Db::connect(null, true);
                    if ($worker === 6) {
                        for ($i = 0; $i < 200; $i++) {
                            foreach (Jobs::claim('fixture-worker', 90, 5) as $job) Jobs::succeed($job);
                            usleep(1000);
                        }
                        exit(0);
                    }
                    for ($i = 0; $i < 40; $i++) {
                        Jobs::enqueue(Jobs::TYPE_QUERY_RESULT, 0, 0, ['owner' => $worker], $worker, true, 'shared-' . $i);
                        Jobs::enqueue(Jobs::TYPE_QUERY_RESULT, 0, 0, [], 0, false, 'unique-' . $worker . '-' . $i);
                    }
                    exit(0);
                } catch (\Throwable $e) { fwrite(STDERR, 'worker ' . $worker . ': ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()); exit(1); }
            }
            $children[] = $pid;
        }
        $exits = [];
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); $exits[] = pcntl_wexitstatus($status); }
        Db::connect(null, true);
        self::assertSame(array_fill(0, 7, 0), $exits);
        self::assertSame(281, (int)Db::name('ai_task_job')->count());
        self::assertSame(0, (int)Db::name('ai_task_job')->where('attempts', '>', 1)->count());
        $id = Jobs::enqueue('fixture', 0, 0, ['first' => true], 0, false, 'stable');
        self::assertSame($id, Jobs::enqueue('fixture', 0, 0, ['replacement' => true], 0, false, 'stable'));
        self::assertSame(['first' => true], json_decode(Db::name('ai_task_job')->where('id', $id)->value('payload'), true));
    }

    public function testWakeDoesNotStealLeasesOrReopenTerminalJobs(): void
    {
        foreach (['pending', 'retrying', 'running', 'success', 'dead'] as $status) {
            $id = Jobs::enqueue('fixture', 0, 0, [], 3, false, $status);
            Db::name('ai_task_job')->where('id', $id)->update(['status' => $status, 'lease_token' => 'owner', 'lease_expire_time' => time() + 90]);
            self::assertSame($id, Jobs::enqueue('fixture', 0, 0, [], 8, true, $status));
            $row = Db::name('ai_task_job')->where('id', $id)->find();
            if (in_array($status, ['pending', 'retrying'], true)) {
                self::assertSame('pending', $row['status']); self::assertSame('', $row['lease_token']);
                self::assertSame(8, (int)$row['priority']);
            } else {
                self::assertSame($status, $row['status']); self::assertSame('owner', $row['lease_token']);
            }
        }
    }

    public function testQueryIsDurableBeforeSubmissionAndDuplicateSubmitDoesNotCallProvider(): void
    {
        $id = $this->consumption();
        Transport::$beforeResponse = static function () use ($id) {
            self::assertSame(1, (int)Db::name('ai_task_job')->where('idempotency_key', 'query_result:' . $id)->count());
        };
        $result = Images::submit($id, ['prompt' => 'fixture']);
        self::assertSame('running', $result['status']);
        self::assertSame('fixture-upstream', $result['provider_task_id']);
        self::assertSame($result, Images::submit($id, ['prompt' => 'fixture']));
        self::assertSame(1, Transport::$calls);
        self::assertSame(0, Points::$refunds);
        self::assertSame('reserved', Db::name('ai_consumption_log')->where('id', $id)->value('billing_status'));
    }

    public function testQueueFailureStopsBeforePaidSubmission(): void
    {
        $id = $this->consumption();
        Db::execute('DROP TABLE `' . $this->prefix . 'ai_task_job`');
        $this->tables = array_values(array_diff($this->tables, [$this->prefix . 'ai_task_job']));
        try { Images::submit($id, ['prompt' => 'fixture']); self::fail('Expected local queue failure'); }
        catch (\Exception $e) { self::assertSame(0, Transport::$calls); }
        self::assertSame(1, Points::$refunds);
        self::assertSame('refunded', Db::name('ai_consumption_log')->where('id', $id)->value('billing_status'));
    }

    public function testRealProviderRejectionStillRefundsOnlyOnce(): void
    {
        $id = $this->consumption();
        Transport::$response = ['error' => 'fixture provider rejected'];
        try { Images::submit($id, ['prompt' => 'fixture']); self::fail('Expected rejection'); }
        catch (\Exception $e) { self::assertSame(1, Transport::$calls); }
        Images::fail($id, 'duplicate failure');
        self::assertSame(1, Points::$refunds);
        self::assertSame('failed', Db::name('ai_consumption_log')->where('id', $id)->value('run_status'));
    }

    public function testPostSubmitLocalFailureRetainsReceiptAndRecoversWithoutRefund(): void
    {
        $id = $this->consumption();
        Transport::$response = ['task_id' => 'fixture-upstream', 'images' => ['https://fixture.invalid/image.png']];
        Points::$failSettlement = true;
        $result = Images::submit($id, ['prompt' => 'fixture']);
        self::assertSame('running', $result['status']);
        self::assertSame('fixture-upstream', $result['provider_task_id']);
        self::assertSame(0, Points::$refunds);
        self::assertSame('running', Db::name('ai_consumption_log')->where('id', $id)->value('run_status'));
        self::assertSame('reserved', Db::name('ai_consumption_log')->where('id', $id)->value('billing_status'));
        self::assertSame(1, (int)Db::name('ai_task_job')->where('consumption_id', $id)->count());
        Points::$failSettlement = false;
        $jobs = Jobs::claim('recovery-fixture', 90, 1);
        self::assertCount(1, $jobs);
        self::assertTrue(Jobs::run($jobs[0]));
        Jobs::succeed($jobs[0]);
        self::assertSame('success', Db::name('ai_task_job')->where('id', $jobs[0]['id'])->value('status'));
        self::assertSame(1, (int)Db::name('ai_task_job')->where('job_type', 'process_result')->count());
        Images::refresh($id);
        self::assertSame(1, Points::$settlements);
        self::assertSame(0, Points::$refunds);
        self::assertSame('settled', Db::name('ai_consumption_log')->where('id', $id)->value('billing_status'));
        self::assertSame(2, Transport::$calls); // submit + one query, never a resubmit
    }

    public function testProcessingEnqueueFailureDoesNotTurnSettledImageIntoFailure(): void
    {
        $id = $this->consumption();
        // Fault injection on a TEST table: query jobs work, process jobs fail.
        Db::execute('ALTER TABLE `' . $this->prefix . 'ai_task_job` MODIFY job_type ENUM(\'query_result\') NOT NULL');
        Transport::$response = ['task_id' => 'fixture-upstream', 'images' => ['https://fixture.invalid/image.png']];
        $result = Images::submit($id, ['prompt' => 'fixture']);
        self::assertSame('success', $result['status']);
        self::assertCount(1, $result['images']);
        self::assertSame(1, Points::$settlements);
        self::assertSame(0, Points::$refunds);
        self::assertSame('settled', Db::name('ai_consumption_log')->where('id', $id)->value('billing_status'));
        self::assertSame(1, (int)Db::name('ai_task_job')->where('job_type', 'query_result')->count());
        self::assertSame(1, (int)Db::name('ai_consumption_event')->where('event_type', 'post_submit_deferred')->count());
        Db::execute('ALTER TABLE `' . $this->prefix . 'ai_task_job` MODIFY job_type VARCHAR(30) NOT NULL');
        self::assertGreaterThan(0, Jobs::enqueueProcessResult($id));
        Images::refresh($id);
        self::assertSame(1, Points::$settlements);
        self::assertSame(1, Transport::$calls);
    }

    public function testContentionRetriesAreBoundedAndNeverReplayAnOuterTransaction(): void
    {
        $retry = new \ReflectionMethod(Jobs::class, 'withContentionRetry');
        $retry->setAccessible(true);
        $calls = 0;
        $result = $retry->invoke(null, static function () use (&$calls) {
            if (++$calls < 3) throw new \RuntimeException('SQLSTATE[40001]: 1213 Deadlock found');
            return 42;
        });
        self::assertSame(42, $result);
        self::assertSame(3, $calls);
        $calls = 0;
        try {
            $retry->invoke(null, static function () use (&$calls) { $calls++; throw new \RuntimeException('SQLSTATE[40001]: 1213 Deadlock found'); });
            self::fail('Expected bounded retry failure');
        } catch (\RuntimeException $e) { self::assertSame(5, $calls); }
        Db::startTrans();
        try {
            $calls = 0;
            $retry->invoke(null, static function () use (&$calls) { $calls++; throw new \RuntimeException('SQLSTATE[40001]: 1213 Deadlock found'); });
            self::fail('Expected outer transaction error');
        } catch (\RuntimeException $e) { self::assertSame(1, $calls); }
        finally { Db::rollback(); }
        self::assertSame(0, Points::$refunds);
    }
}
