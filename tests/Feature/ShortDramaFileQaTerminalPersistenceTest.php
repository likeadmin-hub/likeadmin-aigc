<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaAttachmentSource;
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
        return [['canceled', 'failed'], ['success', 'failed'], ['canceled', 'success'], ['success', 'success'], ['canceled', 'malformed'], ['success', 'malformed'], ['running', 'success'],
            ['running', 'success', true], ['running', 'success', true, true]];
    }

    /** @dataProvider terminals */
    public function testLateReceiptCannotOverwriteTerminalTask(string $terminal, string $upstream, bool $attachment = false, bool $foreign = false): void
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
        $request = []; $package = null;
        if ($attachment) {
            $path = tempnam(sys_get_temp_dir(), 'attachment_db_');
            file_put_contents($path, "第1集\n调查员：我记得你😀。\n结尾保留。\n第2集\n妹妹：日记交给你。");
            try { $package = ShortDramaAttachmentSource::capture($path, 'txt', $scope['tenant_id'], $scope['user_id'], $taskId); }
            finally { unlink($path); }
            $assetId = Db::name('aigc_short_drama_asset')->insertGetId(array_replace($scope, ['user_id' => $foreign ? 8 : 7]) +
                ['project_id' => $projectId, 'task_id' => $taskId, 'asset_type' => 'script_document', 'checksum' => $package['file_sha256'], 'delete_time' => 0]);
            $request = ['_attachment_source' => $package, 'script_asset_id' => $assetId];
            $plan['episodes'][0]['source_content'] = '模型省略了结尾';
            $plan['episodes'][1]['source_content'] = $package['source']['text'];
        }
        Db::name('ai_consumption_log')->insert($scope + ['consume_no' => $taskId, 'app_task_id' => $appId,
            'app_code' => 'aigc_short_drama', 'run_status' => $upstream === 'malformed' ? 'success' : $upstream,
            'response_summary' => $upstream === 'success' ? json_encode(['parse_result' => $plan]) : '{}', 'error_message' => 'late failure']);
        $id = Db::name('aigc_short_drama_script_task')->insertGetId($scope + ['task_id' => $taskId, 'project_id' => $projectId,
            'app_task_id' => $appId, 'status' => $terminal, 'request_json' => json_encode($request), 'result_json' => '{"original":true}', 'error' => 'retained']);
        $before = Db::name('aigc_short_drama_script_task')->where('id', $id)->find();
        $stale = $before; $stale['status'] = 'running';
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'syncMarketFileQaScriptTask');
        $method->setAccessible(true); $method->invoke(null, $stale);
        if ($terminal === 'running') {
            $saved = Db::name('aigc_short_drama_script_task')->where('id', $id)->find();
            if ($foreign) {
                self::assertSame('failed', $saved['status']); self::assertSame($before['result_json'], $saved['result_json']);
                self::assertSame($before['request_json'], $saved['request_json']);
                self::assertSame(0, Db::name('aigc_short_drama_plan_version')->where('task_id', $taskId)->count());
                return;
            }
            $request = json_decode($saved['request_json'], true);
            self::assertSame('success', $saved['status']);
            self::assertSame('story_outline_v2', $request['workflow_variant']);
            self::assertSame('story', $request['multi_episode_stage']);
            self::assertCount(2, $request['_imported_outline_snapshot']['episodes']);
            if ($attachment) {
                self::assertSame($package, $request['_attachment_source']);
                $episode = $request['_imported_outline_snapshot']['episodes'][0];
                self::assertSame("第1集\n调查员：我记得你😀。\n结尾保留。\n", $episode['source_content']);
                self::assertSame($package['package_hash'], $episode['attachment_trace']['package_hash']);
                self::assertSame(hash('sha256', '模型省略了结尾'), $episode['attachment_trace']['parser_text_hash']);
                $method->invoke(null, $saved);
                self::assertSame($saved, Db::name('aigc_short_drama_script_task')->where('id', $id)->find());
            }
            self::assertSame([], json_decode($saved['result_json'], true)['episodes']);
            $detail = AigcShortDramaService::scriptPlanDetail($scope['tenant_id'], $scope['user_id'], $taskId, $projectId);
            self::assertSame('story', $detail['story_workspace']['stage']);
            self::assertTrue($detail['story_workspace']['can_confirm']);
            return;
        }
        self::assertSame($before, Db::name('aigc_short_drama_script_task')->where('id', $id)->find());
    }
}
