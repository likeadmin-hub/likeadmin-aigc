<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoCatalog;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
use app\common\service\app\aigc_micro_video\MicroVideoStore as S;
use app\common\service\app\aigc_micro_video\MicroVideoWorker;
use app\common\enum\FileEnum;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Actual scheduler/runtime receipt recovery, with no outbound provider calls or committed fixtures. */
final class MicroVideoBatchWorkerTest extends TestCase
{
    private int $id;
    private array $consumptions = [];
    private array $files = [];

    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || $db['database'] !== 'z_cn') self::markTestSkipped('Local transactional acceptance only');
        Db::startTrans();
        $task = S::table('task')->where(['tenant_id' => 1, 'user_id' => 1])->order('id')->find();
        self::assertNotEmpty($task);
        unset($task['id']);
        $task = array_replace($task, ['idempotency_key' => 'batch-test-' . bin2hex(random_bytes(12)), 'status' => 'running', 'generation_mode' => 'reference', 'params_json' => '{}', 'config_snapshot' => '{}', 'model_snapshot' => '{}', 'confirmed_quote' => '{}', 'lease_token' => '', 'lease_until' => 0, 'next_run_at' => 0, 'error' => '', 'delete_time' => 0]);
        $this->id = S::table('task')->insertGetId($task);
        $this->files[] = runtime_path() . 'micro_video_task_' . $this->id . '.lock';
    }

    protected function tearDown(): void
    {
        Db::rollback();
        foreach ($this->files as $file) @unlink($file);
    }

    private function segment(int $sort, string $status = 'submitting', string $receipt = 'pending_confirmation'): int
    {
        $id = S::table('segment')->insertGetId(['tenant_id' => 1, 'user_id' => 1, 'task_id' => $this->id, 'sort' => $sort, 'title' => '批量测试' . $sort, 'duration' => 15, 'prompt' => '0-15秒：同一场景继续动作。', 'image_indices' => '[0]', 'start_state' => '原开始', 'end_state' => '原结束', 'continuity' => '保持原样', 'status' => $status, 'create_time' => time(), 'update_time' => time()]);
        if (in_array($status, ['pending', 'success', 'failed'], true)) return $id;
        $app = Db::name('ai_app_task')->order('id')->find();
        unset($app['id']);
        $app = array_replace($app, ['task_no' => 'MB' . bin2hex(random_bytes(12)), 'idempotency_key' => 'batch-app-' . bin2hex(random_bytes(12)), 'tenant_id' => 1, 'user_id' => 1, 'app_code' => 'aigc_micro_video', 'business_table' => 'aigc_micro_video_segment', 'business_id' => $id, 'status' => 'running']);
        $appId = Db::name('ai_app_task')->insertGetId($app);
        $c = Db::name('ai_consumption_log')->order('id')->find();
        unset($c['id']);
        $c = array_replace($c, ['tenant_id' => 1, 'user_id' => 1, 'app_task_id' => $appId, 'app_code' => 'aigc_micro_video', 'consume_no' => 'MB' . bin2hex(random_bytes(12)), 'run_status' => $receipt, 'billing_status' => $receipt === 'failed' ? 'refunded' : 'reserved', 'upstream_task_id' => '', 'upstream_request_id' => '', 'response_summary' => '{}', 'price_snapshot' => '{}', 'actual_user_price' => 0, 'actual_tenant_cost' => 0, 'reserved_user_price' => 0, 'reserved_tenant_cost' => 0, 'tenant_point_sn' => '', 'user_point_sn' => '', 'error_code' => '', 'error_message' => $receipt === 'failed' ? 'fixture rejection' : '']);
        $cid = Db::name('ai_consumption_log')->insertGetId($c);
        $this->consumptions[] = $cid;
        $result = [];
        if ($status === 'storing') {
            Db::name('ai_consumption_log')->where('id', $cid)->update(['run_status' => 'success', 'billing_status' => 'settled']);
            $result = ['status' => 'success', 'videos' => [['video_uri' => 'uploads/missing-batch-storage-fixture.mp4']]];
        }
        $attempt = S::table('attempt')->insertGetId(['tenant_id' => 1, 'user_id' => 1, 'task_id' => $this->id, 'segment_id' => $id, 'kind' => 'video', 'attempt_no' => 1, 'status' => 'unknown', 'consumption_id' => $cid, 'request_json' => S::encode(['selection' => ['resource_type' => 'model'], 'request' => []]), 'result_json' => S::encode($result)]);
        S::table('segment')->where('id', $id)->update(['attempt_id' => $attempt, 'attempt_no' => 1]);
        return $id;
    }

    private function advance(): void
    {
        S::update($this->id, ['next_run_at' => 0]);
        self::assertSame(1, MicroVideoWorker::tick($this->id));
    }

    public function testEverySubmittedSegmentIsPolledEvenBeyondFourAndRepeatedTicksDoNotRebill(): void
    {
        foreach (range(1, 7) as $sort) $this->segment($sort);
        $attempts = S::table('attempt')->where('task_id', $this->id)->column('id');
        $charges = Db::name('ai_consumption_log')->count();
        $balance = Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->value('user_money');
        $this->advance();
        self::assertSame(array_fill(0, 7, 'running'), array_column(S::segments($this->id), 'status'));
        self::assertSame(0, (int)S::owned(1, 1, $this->id)['lease_until']);
        self::assertSame('', S::owned(1, 1, $this->id)['lease_token']);
        $this->advance();
        self::assertSame($attempts, S::table('attempt')->where('task_id', $this->id)->column('id'));
        self::assertSame($charges, Db::name('ai_consumption_log')->count());
        self::assertSame($balance, Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->value('user_money'));
        self::assertSame(array_fill(0, 7, 'pending_confirmation'), Db::name('ai_consumption_log')->whereIn('id', $this->consumptions)->order('id')->column('run_status'));
    }

    public function testOneRejectedSegmentDoesNotStopOtherAcceptedTasks(): void
    {
        $this->segment(1, 'submitting', 'failed');
        $this->segment(2);
        $this->segment(3);
        $this->advance();
        self::assertSame(['failed', 'running', 'running'], array_column(S::segments($this->id), 'status'));
        self::assertSame('running', S::owned(1, 1, $this->id)['status']);
        S::table('segment')->where('task_id', $this->id)->where('status', 'running')->update(['status' => 'success']);
        $this->advance();
        self::assertSame('waiting_continue', S::owned(1, 1, $this->id)['status']);
        self::assertSame(3, S::table('attempt')->where('task_id', $this->id)->count());
    }

    public function testCompletedSegmentsKeepTheirOriginalResultWhileOthersArePolled(): void
    {
        $success = $this->segment(1, 'success');
        S::table('segment')->where('id', $success)->update(['video_uri' => 'uploads/retained-batch-success.mp4', 'actual_duration' => 15.1]);
        $before = S::table('segment')->where('id', $success)->find();
        $this->segment(2, 'failed');
        $this->segment(3);
        $this->advance();
        self::assertSame($before, S::table('segment')->where('id', $success)->find());
        self::assertSame(['success', 'failed', 'running'], array_column(S::segments($this->id), 'status'));
        self::assertSame('running', S::owned(1, 1, $this->id)['status']);
        self::assertSame(1, S::table('attempt')->where('task_id', $this->id)->count());
    }

    public function testStorageFailureWaitsOnlyAfterOtherAcceptedTasksHaveFinished(): void
    {
        $storing = $this->segment(1, 'storing');
        $other = $this->segment(2);
        $this->advance();
        self::assertSame(['storing', 'running'], array_column(S::segments($this->id), 'status'));
        self::assertSame('running', S::owned(1, 1, $this->id)['status']);
        S::table('segment')->where('id', $other)->update(['status' => 'success']);
        $this->advance();
        self::assertSame('waiting_continue', S::owned(1, 1, $this->id)['status']);
        self::assertSame('storing', S::table('segment')->where('id', $storing)->value('status'));
        self::assertSame(2, S::table('attempt')->where('task_id', $this->id)->count());
    }

    public function testAllPendingSegmentsAreDispatchedDespiteAnIndependentValidationFailure(): void
    {
        $uri = 'uploads/batch-worker-test-' . bin2hex(random_bytes(8)) . '.png';
        $path = dirname(__DIR__, 2) . '/public/' . $uri;
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
        $this->files[] = $path;
        Db::name('tenant_file')->insert(['tenant_id' => 1, 'source_id' => 1, 'source' => FileEnum::SOURCE_USER, 'type' => FileEnum::IMAGE_TYPE, 'cid' => 0, 'uri' => $uri, 'name' => basename($uri), 'storage_scope' => 'platform', 'storage_engine' => 'local', 'storage_domain' => '', 'create_time' => time(), 'delete_time' => 0]);
        $model = MicroVideoCatalog::model(1, 'market_video_model:182');
        $params = ['model_id' => $model['id'], 'quality' => '768P', 'ratio' => '9:16', 'images' => [['uri' => $uri]]];
        S::update($this->id, ['params_json' => S::encode($params), 'model_snapshot' => S::encode($model), 'config_snapshot' => S::encode(MicroVideoSettings::get(1))]);
        foreach (range(1, 7) as $sort) $this->segment($sort, 'pending');
        $charges = Db::name('ai_consumption_log')->count();
        $this->advance();
        self::assertSame(array_fill(0, 7, 'failed'), array_column(S::segments($this->id), 'status'));
        foreach (S::segments($this->id) as $segment) self::assertStringContainsString('缺少已确认报价', $segment['error']);
        self::assertSame('waiting_continue', S::owned(1, 1, $this->id)['status']);
        self::assertSame($charges, Db::name('ai_consumption_log')->count());
    }
}
