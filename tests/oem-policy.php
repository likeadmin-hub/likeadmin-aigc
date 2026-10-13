<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use app\common\service\brand\BrandOrderPolicy as Policy;
function check($actual, $expected, string $name): void {
    if ($actual !== $expected) throw new RuntimeException($name . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}
if (!class_exists(Policy::class)) throw new RuntimeException('OEM money and workflow policy is missing');
check(Policy::cents('0.01'), 1, 'cent precision');
check(Policy::cents('9999999999.99'), 999999999999, 'maximum decimal precision');
check(Policy::amount(101), '1.01', 'canonical amount');
foreach (['-1', '1.001', '1e3', 'NaN', '', '10000000000.00'] as $bad) {
    try { Policy::cents($bad); throw new LogicException('Accepted invalid money ' . $bad); }
    catch (InvalidArgumentException $e) {}
}
$base = ['flow_version'=>2,'pay_status'=>1,'workflow_state'=>'tenant_review','reserve_status'=>0,'tenant_review'=>'pending','platform_review'=>'pending','refund_status'=>0,'open_status'=>0];
check(Policy::state($base), '待租户审核', 'payment is not delivery');
check(Policy::actions($base, 'platform'), [], 'platform cannot bypass seller review');
check(Policy::actions($base, 'user'), ['request_refund'], 'paid customer may ask refund');
check(Policy::actions(array_replace($base, ['tenant_review'=>'approved','workflow_state'=>'awaiting_quota']), 'tenant'), ['purchase_quota','reserve'], 'zero inventory waits procurement');
check(Policy::actions(array_replace($base, ['workflow_state'=>'platform_review','tenant_review'=>'approved','reserve_status'=>1]), 'platform'), ['confirm','return','reject'], 'platform approval');
check(Policy::actions(array_replace($base, ['workflow_state'=>'opening','tenant_review'=>'approved','platform_review'=>'approved','reserve_status'=>1]), 'tenant'), [], 'running order cannot be refunded by seller');
check(Policy::actions(array_replace($base, ['workflow_state'=>'delivered','open_status'=>1]), 'user'), ['renew','domain'], 'delivered order actions');
$private = $base + ['id'=>9,'tenant_id'=>3,'user_id'=>4,'order_amount'=>'1.00','admin_password_hash'=>'secret','package_snapshot'=>'{"quota_price":"10","app_plans":[]}', 'open_error'=>'SQLSTATE secret', 'transaction_id'=>'gateway-secret','brand_snapshot'=>'{"logo":"uploads/logo.png","contact":"13300000000"}'];
$public = Policy::present($private, 'user');
foreach (['admin_password_hash','package_snapshot','open_error','transaction_id','tenant_id','user_id'] as $key) check(array_key_exists($key, $public), false, 'private ' . $key);
check($public['next_actions'], ['request_refund'], 'public actions');
check(Policy::renewBase(200, 100), 200, 'renew valid service');
check(Policy::renewBase(50, 100), 100, 'renew expired service');
echo "PASS OEM money, workflow, privacy, renewal policy\n";
