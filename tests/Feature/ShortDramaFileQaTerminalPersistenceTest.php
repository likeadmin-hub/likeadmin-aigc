<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaFileQaTerminalPersistenceTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional database test');
        (new \think\App())->initialize();
        Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    public static function terminals(): array
    {
        return [['canceled', 'failed'], ['success', 'failed'], ['canceled', 'success'], ['success', 'success']];
    }

    /** @dataProvider terminals */
    public function testLateReceiptCannotOverwriteTerminalTask(string $terminal, string $upstream): void
    {
        $taskId = 'parse_terminal_' . bin2hex(random_bytes(8));
        $scope = ['tenant_id' => 2000000719, 'user_id' => 7];
        $appId = Db::name('ai_app_task')->insertGetId($scope + ['task_no' => $taskId, 'app_code' => 'aigc_short_drama', 'action_code' => 'script_parse']);
        Db::name('ai_consumption_log')->insert($scope + ['consume_no' => $taskId, 'app_task_id' => $appId,
            'app_code' => 'aigc_short_drama', 'run_status' => $upstream, 'response_summary' => '{}', 'error_message' => 'late failure']);
        $id = Db::name('aigc_short_drama_script_task')->insertGetId($scope + ['task_id' => $taskId, 'project_id' => 123,
            'app_task_id' => $appId, 'status' => $terminal, 'result_json' => '{"original":true}', 'error' => 'retained']);
        $before = Db::name('aigc_short_drama_script_task')->where('id', $id)->find();
        $stale = $before; $stale['status'] = 'running';
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'syncMarketFileQaScriptTask');
        $method->setAccessible(true); $method->invoke(null, $stale);
        self::assertSame($before, Db::name('aigc_short_drama_script_task')->where('id', $id)->find());
    }
}
