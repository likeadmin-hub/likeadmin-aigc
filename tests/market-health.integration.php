<?php
declare(strict_types=1);

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\power\MarketHealth;
use app\common\service\power\PowerMarketService;
use app\common\service\power\TenantPowerMarketService;
use app\platformapi\lists\power\PowerMarketProductLists;
use think\facade\Db;

require dirname(__DIR__) . '/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
$config = config('database.connections.mysql');
if ($config['hostname'] !== '127.0.0.1' || !in_array($config['database'], ['x_cn', 'z_cn'], true)) {
    throw new RuntimeException('Requires the local rollback database');
}

function checkHealth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$prefix = 'market-health-' . bin2hex(random_bytes(5));
$now = time();
$make = static function (string $code, string $app = '') use ($prefix, $now): int {
    $product = PowerMarketProduct::create([
        'product_code' => $prefix . $code, 'name' => $prefix . $code,
        'source_code' => 'likeadmin_api', 'upstream_resource_key' => $prefix . $code,
        'resource_type' => $app ? 'app_api' : 'model', 'model_type' => $app ? '' : 'image',
        'upstream_app_code' => $app, 'upstream_api_code' => $app ? $code : '',
        'status' => 1, 'update_time' => $now,
    ]);
    $id = (int)$product['id'];
    PowerMarketSku::create(['product_id' => $id, 'sku_key' => 'default', 'title' => '默认', 'status' => 1, 'sale_status' => 1]);
    return $id;
};
$log = static function (int $product, string $status, int $age, int $tenant = 1) use ($prefix, $now): void {
    Db::name('ai_consumption_log')->insert([
        'consume_no' => $prefix . bin2hex(random_bytes(4)), 'tenant_id' => $tenant,
        'product_id' => $product, 'run_status' => $status, 'create_time' => $now - $age,
    ]);
};
$platform = static function (string $order, int $offset) use ($prefix): array {
    $list = (new ReflectionClass(PowerMarketProductLists::class))->newInstanceWithoutConstructor();
    $list->request = (new app\Request())->withGet([
        'resource_type' => 'model', 'model_type' => 'image', 'keyword' => $prefix, 'success_order' => $order,
    ]);
    $list->limitOffset = $offset;
    $list->limitLength = 2;
    (new ReflectionProperty($list, 'searchWhere'))->setValue($list, [
        ['resource_type', '=', 'model'], ['model_type', '=', 'image'],
    ]);
    checkHealth($list->count() === 5, 'model counts must be independent of sort');
    return $list->lists();
};

Db::startTrans();
try {
    $high = $make('high');
    $low = $make('low');
    $perfect = $make('perfect');
    $pending = $make('pending');
    $idle = $make('idle');
    $successfulApi = $make('generate', $prefix . '-mixed');
    $failedApi = $make('transform', $prefix . '-mixed');
    $idleApi = $make('other', $prefix . '-idle');
    foreach ([[$high, 'success', 86400], [$high, 'success', 20, 2], [$high, 'failed', 30], [$high, 'canceled', 100],
        [$low, 'failed', 10], [$perfect, 'success', 10], [$pending, 'running', 60], [$idle, 'failed', 86401], [$idle, 'failed', -15],
        [$successfulApi, 'success', 20], [$successfulApi, 'success', 30], [$successfulApi, 'success', 40],
        [$failedApi, 'failed', 20]] as $record) {
        $log(...$record);
    }
    $snapshot = MarketHealth::aggregate($now);
    checkHealth($snapshot[$high] === ['calls' => 4, 'successes' => 2, 'failures' => 1], 'rolling window or cross-tenant count mismatch');
    checkHealth(!isset($snapshot[$idle], $snapshot[$idleApi]), 'outside-window calls entered the calculation');

    $options = MarketHealth::attachOptions([
        'measured' => ['id' => $idle, 'market_product_id' => $high],
        'app' => ['id' => 'app-code', 'market_product_id' => $idleApi],
        'legacy' => ['id' => $high],
    ], $snapshot);
    checkHealth($options['measured']['health_24h']['rate'] === 66.67, 'option health must follow the marketplace product, independent of its selection ID');
    checkHealth($options['app']['health_24h']['rate'] === 100.0 && $options['app']['health_24h']['calls'] === 0, 'unused API options show the no-call default');
    checkHealth(!isset($options['legacy']['health_24h']), 'options without a marketplace identity must not borrow another product rate');

    foreach (['desc' => [$perfect, $high, $low, $pending, $idle], 'asc' => [$low, $high, $perfect, $pending, $idle]] as $order => $expected) {
        $platformRows = array_merge($platform($order, 0), $platform($order, 2), $platform($order, 4));
        $tenantRows = [];
        foreach ([1, 2, 3] as $page) {
            $result = TenantPowerMarketService::models(1, $prefix, '', $page, 2, 'image', false, ['success_order' => $order]);
            $tenantRows = array_merge($tenantRows, $result['lists']);
        }
        foreach ([$platformRows, $tenantRows] as $rows) {
            checkHealth(array_map('intval', array_column($rows, 'id')) === $expected, 'success order must apply before pagination');
            checkHealth($rows[3]['health_24h']['rate'] === null && $rows[4]['health_24h']['rate'] === 100.0, 'pending and idle rates are different states');
        }
    }

    foreach ([PowerMarketService::apps($prefix, '', 1, 1, '', '', 'asc'),
        TenantPowerMarketService::apps(1, $prefix, '', 1, 1, '', '', 'asc')] as $result) {
        checkHealth($result['count'] === 2 && $result['lists'][0]['app_code'] === $prefix . '-mixed', 'idle app must sort behind measured apps');
        checkHealth($result['lists'][0]['health_24h']['rate'] === 75.0, 'app health must weight calls rather than average interface rates');
    }
    foreach ([
        app\platformapi\controller\power\MarketController::class,
        app\tenantapi\controller\power\MarketController::class,
    ] as $class) {
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $request = (new app\Request())->withGet(['app_code' => $prefix . '-mixed', 'success_order' => 'asc']);
        (new ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        if ($class === app\tenantapi\controller\power\MarketController::class) {
            (new ReflectionProperty($controller, 'adminInfo'))->setValue($controller, ['tenant_id' => 1]);
        }
        $apis = $controller->appDetail()->getData()['data']['apis'];
        checkHealth(array_map('intval', array_column($apis, 'id')) === [$failedApi, $successfulApi], 'interface health must sort independently within the application');
        checkHealth($apis[0]['health_24h']['rate'] === 0.0 && $apis[1]['health_24h']['rate'] === 100.0, 'endpoint results must use separate records');
    }
    echo "PASS 24-hour marketplace health, model/app ordering, paging and usage states\n";
} finally {
    Db::rollback();
}
