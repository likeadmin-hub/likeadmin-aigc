<?php
/** Local-only worker/result regression; fixtures and all writes roll back, no provider requests. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();

use think\facade\Db;
use app\common\service\ai\AiTaskBusinessResultService as Worker;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService as Native;

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fixture(string $status = 'success', string $billing = 'settled', bool $stored = true): array
{
    $scope = ['tenant_id'=>1, 'user_id'=>2147483000];
    $key = 'dh-sync-' . bin2hex(random_bytes(8));
    $taskId = Db::name('aigc_digital_human_task')->insertGetId($scope + [
        'title'=>$key, 'provider'=>'xhadmin', 'status'=>'running', 'provider_stage'=>'market_lipsync',
        'channel'=>'market_lipsync_app:4:5', 'quality'=>'source', 'ratio'=>'adaptive', 'duration'=>2, 'error'=>'retrying',
    ]);
    $appId = Db::name('ai_app_task')->insertGetId($scope + [
        'task_no'=>$key, 'idempotency_key'=>$key, 'app_code'=>'aigc_digital_human', 'business_table'=>'aigc_digital_human_task', 'business_id'=>$taskId,
    ]);
    $uri = 'uploads/dh-sync/' . $key . '.mp4';
    $video = ['video_uri'=>$uri, 'storage_scope'=>'platform', 'storage_engine'=>'local', 'storage_domain'=>''];
    $consumptionId = Db::name('ai_consumption_log')->insertGetId($scope + [
        'consume_no'=>$key, 'app_task_id'=>$appId, 'app_code'=>'aigc_digital_human', 'protocol'=>'application_api',
        'provider'=>'power_market', 'run_status'=>$status, 'billing_status'=>$billing, 'quantity'=>2,
        'actual_tenant_cost'=>3.25, 'actual_user_price'=>7.5, 'upstream_task_id'=>'fixture-provider-task',
        'price_snapshot'=>json_encode(['app_code'=>'lipsync', 'platform_price'=>1.625, 'tenant_price'=>3.75, 'usage_unit_size'=>1]),
        'response_summary'=>json_encode(['videos'=>$status === 'success' ? [$video] : []]), 'error_message'=>'fixture terminal',
    ]);
    Db::name('aigc_digital_human_task')->where('id', $taskId)->update(['provider_payload_json'=>json_encode([
        'market_consumption_id'=>$consumptionId,
        'market_selection'=>['channel'=>['code'=>'market_lipsync_app:4:5'], 'spec'=>['quality'=>'source', 'ratio'=>'adaptive']],
        // Stale quote must never replace the settled amount.
        'market_estimate'=>['tenant_cost_points'=>99, 'user_charge_points'=>199, 'billing_unit'=>'second'],
    ])]);
    $assetId = 0;
    if ($status === 'success') $assetId = Db::name('ai_task_result_asset')->insertGetId($scope + [
        'app_task_id'=>$appId, 'consumption_id'=>$consumptionId, 'asset_type'=>'video', 'external_url'=>$uri,
        'local_uri'=>$stored ? $uri : '', 'storage_scope'=>'platform', 'storage_engine'=>'local',
        'storage_domain'=>'', 'storage_meta'=>json_encode(['width'=>720, 'height'=>1280]),
        'transfer_status'=>$stored ? 'stored' : 'external',
    ]);
    return compact('taskId', 'appId', 'consumptionId', 'assetId', 'uri');
}
function task(array $fixture): array { return Db::name('aigc_digital_human_task')->where('id', $fixture['taskId'])->find(); }
function rejected(callable $call): void
{
    $rejected = false;
    try { $call(); } catch (Exception $error) { $rejected = true; }
    check($rejected, 'Invalid persisted binding unexpectedly accepted');
}

try {
$db = config('database.connections.mysql');
check(PHP_SAPI === 'cli' && in_array($db['hostname'], ['127.0.0.1', 'localhost'], true)
    && in_array(Db::query('SELECT DATABASE() db')[0]['db'], ['x_cn', 'z_cn'], true), 'Local database only');
request()->tenantId = 1;
request()->userId = 1;
$wallets = fn() => [Db::name('user')->where(['tenant_id'=>1, 'id'=>1])->value('user_money'), Db::name('tenant')->where('id', 1)->value('point_balance')];
$before = $wallets();
Db::startTrans();
try {
    Db::name('aigc_digital_human_quota')->insert(['tenant_id'=>1, 'user_id'=>2147483000, 'total_quota'=>100, 'used_quota'=>0]);
    $success = fixture();
    $billBefore = Db::name('ai_consumption_log')->where('id', $success['consumptionId'])->find();
    check(Worker::syncTerminalByConsumptionId($success['consumptionId']), 'Worker did not recognize digital-human adapter');
    check(task($success)['status'] === 'success' && task($success)['error'] === '', 'Worker did not finalize native success');
    check((float)task($success)['tenant_cost_points'] === 3.25 && (float)task($success)['user_charge_points'] === 7.5, 'Stale estimate replaced settled costs');
    $results = Db::name('aigc_digital_human_result')->where('task_id', $success['taskId'])->select()->toArray();
    check(count($results) === 1 && $results[0]['video_uri'] === $success['uri'], 'Stored video was not persisted');
    $bills = Db::name('aigc_digital_human_billing')->where('task_id', $success['taskId'])->select()->toArray();
    check(count($bills) === 1 && json_decode($bills[0]['extra_json'], true)['market_consumption_id'] === $success['consumptionId'], 'Native bill missing market trace');
    $saved = task($success);
    check(Worker::syncTerminalByConsumptionId($success['consumptionId']), 'Duplicate sync rejected');
    check(task($success) === $saved && Db::name('aigc_digital_human_result')->where('task_id', $success['taskId'])->count() === 1
        && Db::name('aigc_digital_human_billing')->where('task_id', $success['taskId'])->count() === 1, 'Duplicate sync mutated terminal task or duplicated rows');
    check((int)Db::name('aigc_digital_human_quota')->where(['tenant_id'=>1, 'user_id'=>2147483000])->value('used_quota') === 1, 'Duplicate sync consumed quota twice');
    check(Db::name('ai_consumption_log')->where('id', $success['consumptionId'])->find() === $billBefore && $wallets() === $before, 'Sync changed settlement or wallets');

    foreach (['failed'=>'failed', 'canceled'=>'canceled', 'cancelled'=>'canceled'] as $remote=>$native) {
        $failed = fixture($remote, 'refunded');
        check(Worker::syncTerminalByConsumptionId($failed['consumptionId']) && task($failed)['status'] === $native, 'Refunded terminal was not synced');
        $saved = task($failed);
        check(Worker::syncTerminalByConsumptionId($failed['consumptionId']) && task($failed) === $saved, 'Failure replay changed native terminal');
        check(Db::name('aigc_digital_human_billing')->where('task_id', $failed['taskId'])->count() === 0, 'Failed sync created billing');
    }
    $pending = fixture('success', 'settled', false);
    check(!Worker::syncTerminalByConsumptionId($pending['consumptionId']) && task($pending)['status'] === 'running', 'Unstored result finalized task');
    $unsettled = fixture('success', 'reserved');
    rejected(fn() => Native::syncMarketConsumption($unsettled['consumptionId'], $unsettled['taskId']));
    check(task($unsettled)['status'] === 'running', 'Unsettled result failed the business task');
    $unrefunded = fixture('failed', 'reserved');
    rejected(fn() => Native::syncMarketConsumption($unrefunded['consumptionId'], $unrefunded['taskId']));
    check(task($unrefunded)['status'] === 'running', 'Unrefunded result finalized business failure');
    foreach ([['ai_app_task', 'tenant_id', 2], ['ai_app_task', 'user_id', 2], ['ai_app_task', 'business_id', 0],
        ['aigc_digital_human_task', 'tenant_id', 2], ['aigc_digital_human_task', 'user_id', 2],
        ['aigc_digital_human_task', 'provider_payload_json', '{}']] as [$table, $field, $value]) {
        $invalid = fixture();
        Db::name($table)->where('id', $table === 'ai_app_task' ? $invalid['appId'] : $invalid['taskId'])->update([$field=>$value]);
        rejected(fn() => Native::syncMarketConsumption($invalid['consumptionId'], $invalid['taskId']));
        check(task($invalid)['status'] === 'running', 'Invalid binding mutated native task');
    }
    foreach (['failed', 'canceled'] as $terminal) {
        $late = fixture();
        Db::name('aigc_digital_human_task')->where('id', $late['taskId'])->update(['status'=>$terminal]);
        $saved = task($late);
        Native::syncMarketConsumption($late['consumptionId'], $late['taskId']);
        check(task($late) === $saved, 'Late success resurrected native terminal');
    }
    $deleted = fixture();
    Db::name('aigc_digital_human_task')->where('id', $deleted['taskId'])->update(['delete_time'=>time()]);
    $saved = task($deleted);
    Native::syncMarketConsumption($deleted['consumptionId'], $deleted['taskId']);
    check(task($deleted) === $saved, 'Late success resurrected deleted native task');
    check($wallets() === $before, 'Terminal reconciliation changed wallets');
    echo "PASS worker success/failure/cancellation, stored-media gate, settled snapshot, binding isolation, replay idempotency; no provider or billing calls\n";
} finally { Db::rollback(); }
check($wallets() === $before, 'Rollback changed wallets');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
