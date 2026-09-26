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
        return [['canceled', 'failed'], ['success', 'failed'], ['canceled', 'success'], ['success', 'success'], ['canceled', 'malformed'], ['success', 'malformed']];
    }

    /** @dataProvider terminals */
    public function testLateReceiptCannotOverwriteTerminalTask(string $terminal, string $upstream): void
    {
        $taskId = 'parse_terminal_' . bin2hex(random_bytes(8));
        $scope = ['tenant_id' => 2000000719, 'user_id' => 7];
        $projectId = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => '解析终态测试', 'last_task_id' => $taskId]);
        $plan = ['title' => '旧信', 'type_judgement' => '悬疑', 'core_theme' => '寻找真相', 'story_outline' => '调查员寻找失踪者。',
            'subjects' => [['id' => 's1', 'name' => '调查员', 'description' => '追踪失踪者', 'category' => 'character']],
            'locations' => [['id' => 'l1', 'name' => '书房', 'description' => '旧宅的书房']],
            'episodes' => [
                ['episode_number' => 1, 'title' => '旧信', 'story_outline' => '调查员在书房找到旧信。', 'conflict_point' => '署名与记忆矛盾。', 'ending_hook' => '发现信里的地址。'],
                ['episode_number' => 2, 'title' => '证人', 'story_outline' => '调查员抵达地址并找到失踪者的妹妹。', 'conflict_point' => '妹妹拒绝开口。', 'ending_hook' => '妹妹交出日记。']]];
        $appId = Db::name('ai_app_task')->insertGetId($scope + ['task_no' => $taskId, 'app_code' => 'aigc_short_drama', 'action_code' => 'script_parse']);
        Db::name('ai_consumption_log')->insert($scope + ['consume_no' => $taskId, 'app_task_id' => $appId,
            'app_code' => 'aigc_short_drama', 'run_status' => $upstream === 'malformed' ? 'success' : $upstream,
            'response_summary' => $upstream === 'success' ? json_encode(['parse_result' => $plan]) : '{}', 'error_message' => 'late failure']);
        $id = Db::name('aigc_short_drama_script_task')->insertGetId($scope + ['task_id' => $taskId, 'project_id' => $projectId,
            'app_task_id' => $appId, 'status' => $terminal, 'result_json' => '{"original":true}', 'error' => 'retained']);
        $before = Db::name('aigc_short_drama_script_task')->where('id', $id)->find();
        $stale = $before; $stale['status'] = 'running';
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'syncMarketFileQaScriptTask');
        $method->setAccessible(true); $method->invoke(null, $stale);
        self::assertSame($before, Db::name('aigc_short_drama_script_task')->where('id', $id)->find());
    }
}
