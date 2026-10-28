<?php
// Local database rollback test. HTTP and remote audio storage are fixtures;
// task transitions, tenant/user balances and refund ledgers are real services.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/MusicMarketFixture.php';

use think\facade\Db;
use app\common\model\app\aigc_music\AigcMusicTask;
use app\common\service\app\aigc_music\AigcMusicMarketService;
use app\common\service\power\MarketMusicAppRuntimeService as Runtime;
use app\common\service\power\MusicMarketWireFixture as Wire;
use app\common\service\ai\AiMarketTaskRuntimeService as Worker;

(new think\App())->initialize();
function terminalCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$db = config('database.connections.mysql');
terminalCheck(PHP_SAPI === 'cli' && $db['hostname'] === '127.0.0.1' && in_array($db['database'], ['z_cn', 'x_cn'], true), 'Local rollback database only');
$balance = static fn() => [(float)Db::name('tenant')->where('id', 1)->value('point_balance'), (float)Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->value('user_money')];
$before = $balance();
$upstreamId = 'task_aaaaaaaaaaaaaaaaaaaaaaaa';
$failed = ['code' => 1, 'data' => ['task_id' => $upstreamId, 'status' => 'failed', 'error' => ['code' => 'used_up', 'message' => '任务处理失败，请稍后重试']]];
$ambiguous = ['code' => 0, 'msg' => '任务处理失败，请稍后重试', 'data' => null];
$cases = [
    'legacy error confirmed by task endpoint' => [$ambiguous, $failed, true],
    'explicit failure' => [$failed, null, true],
    'failure wins over stale audio' => [array_replace_recursive($failed, ['data' => ['audio_url' => 'https://fixture.invalid/stale.mp3']]), null, true],
    'processing is not failure' => [$ambiguous, ['code' => 1, 'data' => ['task_id' => $upstreamId, 'status' => 'processing']], false],
    'wrong task cannot refund' => [$ambiguous, array_replace_recursive($failed, ['data' => ['task_id' => 'task_bbbbbbbbbbbbbbbbbbbbbbbb']]), false],
    'missing identity cannot refund' => [$ambiguous, ['code' => 1, 'data' => ['status' => 'failed']], false],
    'query unavailable cannot refund' => [$ambiguous, null, false],
    'completed fallback is not failure' => [$ambiguous, ['code' => 1, 'data' => ['task_id' => $upstreamId, 'status' => 'completed']], false],
    'cancelled task releases reservation' => [$ambiguous, array_replace_recursive($failed, ['data' => ['status' => 'cancelled']]), true],
];
foreach ($cases as $label => [$queryResponse, $taskResponse, $shouldRefund]) {
    Db::startTrans();
    try {
        Db::name('tenant')->where('id', 1)->update(['point_balance' => 10000]);
        Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->update(['user_money' => 10000]);
        $option = Runtime::options(1)[0];
        $selection = ['market_sku_id' => (int)$option['market_sku_id']];
        Db::name('power_market_sku')->where('id', $selection['market_sku_id'])->update(['upstream_price' => 1]);
        \app\common\service\power\MarketPriceRuleService::manual(0, (int)$option['market_product_id'], $selection['market_sku_id'], 3);
        \app\common\service\power\MarketPriceRuleService::manual(1, (int)$option['market_product_id'], $selection['market_sku_id'], 7);
        $snapshot = Runtime::quote(1, $selection)['market_snapshot'];
        $native = AigcMusicTask::create(['tenant_id' => 1, 'user_id' => 1, 'provider' => AigcMusicMarketService::PROVIDER, 'status' => 'running', 'billing_status' => 'reserved', 'create_time' => time(), 'update_time' => time()]);
        $reserved = Runtime::reserve(1, 1, (string)$native['id'], $selection, [], 'aigc_music', 'music_generation', 'aigc_music_task', $snapshot);
        $cid = (int)$reserved['consumption_id'];
        Runtime::linkBusinessTask((int)$reserved['app_task_id'], (int)$native['id']);
        $native->save(['consumption_id' => $cid, 'app_task_id' => $reserved['app_task_id']]);
        Db::name('ai_consumption_log')->where('id', $cid)->update(['run_status' => 'running', 'upstream_task_id' => $upstreamId]);
        terminalCheck($balance() === [9997., 9993.], "$label: reservation missing");
        Wire::$queryResponse = $queryResponse;
        Wire::$taskResponse = $taskResponse;
        Worker::refresh($cid);
        Worker::refresh($cid);
        AigcMusicMarketService::syncConsumption($cid, (int)$native['id']);
        $c = Db::name('ai_consumption_log')->where('id', $cid)->find();
        $task = Db::name('aigc_music_task')->where('id', $native['id'])->find();
        terminalCheck($balance() === ($shouldRefund ? [10000., 10000.] : [9997., 9993.]), "$label: incorrect or repeated refund");
        terminalCheck($c['billing_status'] === ($shouldRefund ? 'refunded' : 'reserved'), "$label: incorrect billing state");
        terminalCheck($task['status'] === ($shouldRefund ? 'failed' : 'running'), "$label: native task did not synchronize");
        foreach (['tenant_point_log', 'user_account_log'] as $logTable) {
            terminalCheck(Db::name($logTable)->where('source_sn', $c['consume_no'] . '-release')->count() === ($shouldRefund ? 1 : 0), "$label: duplicated/missing $logTable refund");
        }
        terminalCheck(Db::name('aigc_music_billing')->where('task_id', $native['id'])->count() === ($shouldRefund ? 1 : 0), "$label: duplicated/missing native billing row");
        terminalCheck(Db::name('aigc_music_result')->where('task_id', $native['id'])->count() === 0, "$label: failed query created a result");
        echo "PASS $label\n";
    } finally { Db::rollback(); }
}
terminalCheck($balance() === $before, 'Rollback did not restore original balances');
echo "Music terminal failure/refund cases passed; database rolled back\n";
