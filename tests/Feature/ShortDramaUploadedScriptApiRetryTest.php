<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service, ShortDramaStoryWorkflow as Workflow, ShortDramaFileParseFallback as Fallback};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaUploadedScriptApiRetryTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Local rollback fixtures only');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    private function fixture(string $status = 'failed'): array
    {
        $scope = ['tenant_id' => 2000000719, 'user_id' => 7]; $key = 'api_retry_' . bin2hex(random_bytes(8));
        $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => '原项目', 'last_task_id' => $key]);
        $app = Db::name('ai_app_task')->insertGetId($scope + ['task_no' => $key, 'app_code' => 'aigc_short_drama', 'status' => 'failed']);
        $consumption = Db::name('ai_consumption_log')->insertGetId($scope + ['consume_no' => $key, 'app_task_id' => $app,
            'app_code' => 'aigc_short_drama', 'run_status' => 'failed', 'billing_status' => 'settlement_failed', 'error_code' => 'provider_stream_incomplete']);
        $id = Db::name('aigc_short_drama_script_task')->insertGetId($scope + ['task_id' => $key, 'project_id' => $project,
            'app_task_id' => $app, 'status' => $status, 'prompt' => '按照剧本进行策划', 'error' => '旧模型回复中断',
            'billing_status' => 'settlement_failed', 'result_json' => '{"partial":true}',
            'request_json' => json_encode(['source' => 'market_file_qa_parse', 'file_urls' => ['https://files.example/script.txt'],
                'script_asset_id' => 123, 'parse_mode' => 'split_by_ai', 'preserve_original' => true,
                'model_selections' => ['script_plan' => ['id' => '6']],
                '_file_parse_model' => ['text' => "原文甲\n原文乙", 'model' => ['id' => '6']],
                '_file_parse_fallback' => ['version' => 1, 'text' => "原文甲\n原文乙", 'model' => ['id' => '6'], 'state' => 'failed']])]);
        return ['task' => Db::name('aigc_short_drama_script_task')->where('id', $id)->find(),
            'consumption' => Db::name('ai_consumption_log')->where('id', $consumption)->find()];
    }
    private function retry(array $task): array
    {
        // Creates only local rows. The public action dispatches after this transaction.
        return (new \ReflectionMethod(Service::class, 'createUploadedScriptRetryTask'))->invoke(null, $task['tenant_id'], $task['user_id'], $task['task_id']);
    }
    public function testExplicitRetryCreatesApiSuccessorAndKeepsOldTaskAndBillingUntouched(): void
    {
        $fixture = $this->fixture(); $old = $fixture['task']; $result = $this->retry($old);
        self::assertNotSame($old['task_id'], $result['task_id']);
        $new = Db::name('aigc_short_drama_script_task')->where('task_id', $result['task_id'])->find();
        $request = json_decode($new['request_json'], true);
        self::assertSame('pending', $new['status']); self::assertSame(0, (int)$new['app_task_id']);
        self::assertSame($old['task_id'], $new['parent_task_id']);
        self::assertSame(['https://files.example/script.txt'], $request['file_urls']);
        self::assertSame(123, $request['script_asset_id']);
        self::assertArrayNotHasKey('_file_parse_model', $request);
        self::assertFalse(Workflow::workerOwned($request)); self::assertFalse(Fallback::eligible($request));
        self::assertSame(['script_plan' => ['id' => '6']], $request['model_selections']);
        $mirror = Db::name('aigc_short_drama_generation_task')->where('task_id', $new['task_id'])->find();
        self::assertSame('file_qa', $mirror['source_app_code']);
        self::assertSame('parse', json_decode($new['config_snapshot'], true)['market_api_code']);
        self::assertSame($new['task_id'], Db::name('aigc_short_drama_project')->where('id', $old['project_id'])->value('last_task_id'));
        self::assertSame($old, Db::name('aigc_short_drama_script_task')->where('id', $old['id'])->find());
        self::assertSame($fixture['consumption'], Db::name('ai_consumption_log')->where('id', $fixture['consumption']['id'])->find());
    }
    public function testRepeatedRetryReturnsSameSuccessorWithoutAnotherDispatchOrReservation(): void
    {
        $fixture = $this->fixture('canceled');
        $scope = ['tenant_id' => $fixture['task']['tenant_id'], 'user_id' => $fixture['task']['user_id']];
        $consumptions = Db::name('ai_consumption_log')->where($scope)->count();
        $first = $this->retry($fixture['task']); $second = $this->retry($fixture['task']);
        self::assertSame($first['task_id'], $second['task_id']); self::assertArrayNotHasKey('_dispatch_request', $second);
        self::assertSame(2, Db::name('aigc_short_drama_script_task')->where('project_id', $fixture['task']['project_id'])->count());
        self::assertSame($consumptions, Db::name('ai_consumption_log')->where($scope)->count());
    }
    public function testActiveTaskCannotBeRetried(): void
    {
        $fixture = $this->fixture('running'); $this->expectExceptionMessage('当前任务不需要重试'); $this->retry($fixture['task']);
    }
    public function testNewerProjectRevisionCannotBeReplacedByOldRetry(): void
    {
        $fixture = $this->fixture(); Db::name('aigc_short_drama_project')->where('id', $fixture['task']['project_id'])->update(['last_task_id' => 'new_revision']);
        $this->expectExceptionMessage('剧本已更新'); $this->retry($fixture['task']);
    }
    public function testAnotherTenantCannotRetryOrReadTheTask(): void
    {
        $fixture = $this->fixture(); $this->expectException(\Exception::class);
        (new \ReflectionMethod(Service::class, 'createUploadedScriptRetryTask'))->invoke(null, 2000000720, 7, $fixture['task']['task_id']);
    }
}
