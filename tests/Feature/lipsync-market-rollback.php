<?php
/** Local-only native billing checks. All changes roll back; no provider calls. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\power\MarketLipsyncAppRuntimeService as Runtime;
use app\common\service\ai\MarketAppGateService;

function check($ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db = config('database.connections.mysql');
check(PHP_SAPI === 'cli' && $db['hostname'] === '127.0.0.1' && in_array($db['database'], ['z_cn', 'x_cn'], true), 'Local database only');
$wallets = fn() => [Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'), Db::name('tenant')->where('id',1)->value('point_balance')];
$before = $wallets();
request()->tenantId = 1;
request()->userId = 1;
Db::startTrans();
try {
    // A fully migrated lipsync flow must work even with the former rollout disabled.
    MarketAppGateService::disable(1, 'aigc_digital_human');
    $option = Runtime::options(1)[0] ?? null;
    check($option !== null, 'Missing local market fixture');
    $payload = ['video_url'=>'https://example.test/avatar.mp4','audio_url'=>'https://example.test/audio.mp3'];
    $key = 'rollback-' . bin2hex(random_bytes(12));
    $reserve = fn($suffix) => Runtime::reserve(1,1,'generate',$key.$suffix,$option,$payload,2,'aigc_digital_human','aigc_digital_human_task');
    $quote = Runtime::quote(1,$option,2);
    $first = $reserve('cancel');
    $afterReserve = $wallets();
    check($afterReserve !== $before, 'Reservation did not charge wallets');
    check($reserve('cancel')['consumption_id'] === $first['consumption_id'] && $wallets() === $afterReserve, 'Repeated reserve charged twice');
    Runtime::fail($first['consumption_id'],'Rollback fixture','canceled');
    Runtime::fail($first['consumption_id'],'Rollback fixture','canceled');
    check($wallets() === $before, 'Repeated cancel did not restore exact balances');
    $second = $reserve('settle');
    $settle = new ReflectionMethod(Runtime::class,'settle');
    $settle->invoke(null,$second['consumption_id'],[['video_uri'=>'fixture.mp4']],'fixture','fixture',['input_second'=>2]);
    $afterSettle = $wallets();
    $settle->invoke(null,$second['consumption_id'],[['video_uri'=>'fixture.mp4']],'fixture','fixture',['input_second'=>2]);
    check($wallets() === $afterSettle, 'Repeated settlement charged twice');
    $bill = Db::name('ai_consumption_log')->where('id',$second['consumption_id'])->find();
    check($bill['billing_status'] === 'settled' && (float)$bill['actual_user_price'] === (float)$quote['user_charge_points'], 'Settlement differs from market price');
    Db::name('power_market_sku')->where('id',$option['market_sku_id'])->update(['sale_status'=>0]);
    check(!array_filter(Runtime::options(1),fn($row) => $row['value'] === $option['value']), 'Off-sale SKU remains selectable');
    $denied = false;
    try { Runtime::quote(1,$option,2); } catch (Exception $e) { $denied = true; }
    check($denied, 'Off-sale SKU can still be quoted');
    echo "PASS market reservation, cancellation, settlement, duplicate protection, off-sale filtering, disabled legacy gate; no provider calls\n";
} finally { Db::rollback(); }
check($wallets() === $before, 'Rollback changed wallet balances');
