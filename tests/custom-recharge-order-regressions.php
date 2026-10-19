<?php
/** Executes real order/validation/credit code with in-memory persistence, without payments or a database. */
class RechargeRegressionConfig {
    public static array $values = ['recharge_ratio' => '80', 'status' => 1, 'min_amount' => 10];
    public static function get($type, $key, $default = null) { return self::$values[$key] ?? $default; }
}
class RechargeRegressionOrder {
    public static array $orders = [];
    public static function create(array $data): ArrayObject {
        $data['id'] = count(self::$orders) + 1;
        $order = new ArrayObject($data, ArrayObject::ARRAY_AS_PROPS);
        self::$orders[$data['id']] = $order;
        return $order;
    }
}
class RechargeRegressionPricing {
    public static array $calls = [];
    public static function create(...$params): array { self::$calls[] = $params; return ['order_id' => 707, 'from' => 'recharge']; }
}
class RechargeRegressionPolicy { public static function error($e): string { return $e->getMessage(); } }
class RechargeRegressionPointUnit { public static function unit(): string { return '算力'; } }
class RechargeRegressionValidate {}
foreach ([
    RechargeRegressionConfig::class => 'app\\common\\service\\ConfigService',
    RechargeRegressionOrder::class => 'app\\common\\model\\recharge\\RechargeOrder',
    RechargeRegressionPricing::class => 'app\\common\\service\\promotion\\PackagePricingService',
    RechargeRegressionPolicy::class => 'app\\common\\service\\virtualpay\\VirtualPayPolicy',
    RechargeRegressionPointUnit::class => 'app\\common\\service\\PointUnitService',
    RechargeRegressionValidate::class => 'app\\common\\validate\\BaseValidate',
] as $fake => $real) class_alias($fake, $real);
function generate_sn(...$params): string { return 'safe-order-' . (count(RechargeRegressionOrder::$orders) + 1); }
require __DIR__ . '/../app/common/logic/BaseLogic.php';
require __DIR__ . '/../app/common/enum/PayEnum.php';
require __DIR__ . '/../app/common/service/recharge/CustomRechargeService.php';
require __DIR__ . '/../app/common/service/recharge/RechargePackageService.php';
require __DIR__ . '/../app/common/service/recharge/RechargeCreditService.php';
require __DIR__ . '/../app/api/validate/RechargeValidate.php';
require getenv('RECHARGE_LOGIC_SOURCE') ?: __DIR__ . '/../app/api/logic/RechargeLogic.php';
use app\api\logic\RechargeLogic;
use app\api\validate\RechargeValidate;
use app\common\service\recharge\RechargeCreditService;
$failed = 0;
function verify($condition, $message): void { if (!$condition) throw new RuntimeException($message); }
function runCase($name, $callback): void {
    global $failed;
    try { $callback(); echo "PASS $name\n"; }
    catch (Throwable $error) { $failed++; echo "FAIL $name: {$error->getMessage()}\n"; }
}
function recharge($points): array|false { return RechargeLogic::recharge(['money' => $points, 'tenant_id' => 2, 'user_id' => 9, 'terminal' => 1]); }
runCase('real RechargeLogic persists 800 points as 10 yuan with ownership and unpaid status', function () {
    RechargeRegressionConfig::$values['recharge_ratio'] = '80';
    $result = recharge('800');
    verify($result !== false, RechargeLogic::getError());
    $order = RechargeRegressionOrder::$orders[$result['order_id']];
    verify($order['order_amount'] === '10.00', 'expected amount 10.00, got ' . $order['order_amount']);
    verify($order['recharge_points'] === '800.00', 'points were not frozen at 800.00');
    verify($order['tenant_id'] === 2 && $order['user_id'] === 9 && $order['pay_status'] === 0, 'ownership/payment state changed');
});
runCase('later ratio changes affect new orders but actual credit reads the original frozen points', function () {
    RechargeRegressionConfig::$values['recharge_ratio'] = '80';
    $original = recharge('800');
    $oldOrder = RechargeRegressionOrder::$orders[$original['order_id']];
    RechargeRegressionConfig::$values['recharge_ratio'] = '160';
    $new = recharge('800');
    $newOrder = RechargeRegressionOrder::$orders[$new['order_id']];
    verify($oldOrder['order_amount'] === '10.00', 'old payment changed');
    verify($newOrder['order_amount'] === '5.00', 'new order ignored updated ratio');
    verify(RechargeCreditService::points($oldOrder) === 800.0, 'credit recalculated frozen points');
    verify(RechargeCreditService::points($newOrder) === 800.0, 'new credit differs from selected points');
});
runCase('real order persistence retains cent ceiling and maximum decimal precision', function () {
    RechargeRegressionConfig::$values['recharge_ratio'] = '80';
    $fractional = recharge('10');
    verify(RechargeRegressionOrder::$orders[$fractional['order_id']]['order_amount'] === '0.13', 'fractional cent rounded down');
    RechargeRegressionConfig::$values['recharge_ratio'] = '1';
    $max = recharge('99999999.99');
    $order = RechargeRegressionOrder::$orders[$max['order_id']];
    verify($order['order_amount'] === '99999999.99' && $order['recharge_points'] === '99999999.99', 'maximum decimal precision changed');
});
runCase('invalid custom inputs fail without creating persisted orders', function () {
    RechargeRegressionConfig::$values['recharge_ratio'] = '80';
    foreach (['-1', '0', '1e3', '0.001', 'abc', '100000000'] as $input) {
        $before = count(RechargeRegressionOrder::$orders);
        verify(recharge($input) === false, 'accepted invalid points ' . $input);
        verify(count(RechargeRegressionOrder::$orders) === $before, 'invalid input persisted');
    }
});
runCase('fixed packages still delegate original tenant user terminal and quoted price hash', function () {
    RechargeRegressionConfig::$values['recharge_ratio'] = 'invalid';
    $before = count(RechargeRegressionOrder::$orders);
    $result = RechargeLogic::recharge(['tenant_id' => 2, 'user_id' => 9, 'terminal' => 1, 'package_id' => 55, 'quote_hash' => 'confirmed-price']);
    verify($result === ['order_id' => 707, 'from' => 'recharge'], 'package result not returned unchanged');
    verify(RechargeRegressionPricing::$calls === [[2, 9, 1, 'recharge', 55, 'package', 'confirmed-price']], 'package pricing delegation changed');
    verify(count(RechargeRegressionOrder::$orders) === $before, 'custom order created for fixed package');
});
runCase('actual validation keeps minimum in points and ignores custom points on fixed-package input', function () {
    $method = new ReflectionMethod(RechargeValidate::class, 'checkMoney');
    $method->setAccessible(true);
    $validator = new RechargeValidate();
    verify($method->invoke($validator, '10', '', []) === true, 'minimum points rejected');
    verify($method->invoke($validator, '9.99', '', []) === '最低充值10算力', 'minimum compared against yuan instead of points');
    verify($method->invoke($validator, '0.001', '', []) !== true, 'overprecision accepted');
    verify($method->invoke($validator, 'invalid', '', ['package_id' => 55]) === true, 'fixed package forced through custom validation');
    RechargeRegressionConfig::$values['status'] = 0;
    verify($method->invoke($validator, '800', '', []) === '充值功能已关闭', 'disabled custom recharge accepted');
});
exit($failed ? 1 : 0);
