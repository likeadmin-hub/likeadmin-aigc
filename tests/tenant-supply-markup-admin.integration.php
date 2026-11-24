<?php
/** Run against a local migrated schema. All writes use a randomly prefixed set of fixture tables, removed after the run. */
declare(strict_types=1);

use app\platformapi\controller\tenant\TenantController;
use app\platformapi\http\middleware\AuthMiddleware;
use app\platformapi\service\TenantSupplyMarkupAdmin;
use think\facade\Cache;
use think\facade\Db;

require dirname(__DIR__).'/runtime-loader.php';
$app = new AigcRuntimeApp(dirname(__DIR__));
$app->initialize();
$config = config('database.connections.mysql');
if ($config['hostname'] !== '127.0.0.1' || !in_array($config['database'], ['z_cn', 'x_cn'], true)) {
    throw new RuntimeException('Requires a local migrated fixture schema');
}
$child = ($argv[1] ?? '') === '--worker';
$prefix = $child ? ($argv[2] ?? '') : 'smarkup_'.bin2hex(random_bytes(8)).'_';
if (!preg_match('/^smarkup_[0-9a-f]{16}_$/D', $prefix)) throw new RuntimeException('Invalid fixture prefix');
$source = $config['prefix'];
$config['prefix'] = $prefix;
config(['default' => 'fixture', 'connections' => ['fixture' => $config]], 'database');
$cacheDir = sys_get_temp_dir().'/'.$prefix;
config(['default' => 'fixture', 'stores' => ['fixture' => ['type' => 'File', 'path' => $cacheDir]]], 'cache');
function checkMarkup(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function routeMarkup(string $action, int $adminId, array $params, ?string $method = null): array
{
    global $app;
    $method ??= $action === 'supplyMarkup' ? 'GET' : 'POST';
    $request = (new app\Request())->withServer(['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '127.0.0.1']);
    $request->setController('tenant.Tenant')->setAction($action);
    $request->withGet($method === 'GET' ? $params : [])->withPost($method === 'POST' ? $params : []);
    $request->adminInfo = ['admin_id' => $adminId, 'root' => 0, 'login_ip' => $request->ip()];
    $app->instance('request', $request);
    $controller = new TenantController($app);
    $request->controllerObject = $controller;
    return (new AuthMiddleware())->handle($request, static fn() => $controller->$action())->getData();
}
function okMarkup(array $result): array
{
    checkMarkup($result['code'] === 1, $result['msg']);
    return $result['data'];
}
function deniedMarkup(array $result, string $needle): void
{
    checkMarkup($result['code'] === 0 && str_contains($result['msg'], $needle), json_encode($result, JSON_UNESCAPED_UNICODE));
}

if ($child) {
    $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $ready = $cacheDir.'.'.$job['index'].'.ready';
    touch($ready);
    $deadline = microtime(true) + 10;
    while (!is_file($cacheDir.'.start') && microtime(true) < $deadline) usleep(1000);
    checkMarkup(is_file($cacheDir.'.start'), 'Worker barrier timed out');
    echo json_encode(routeMarkup('applySupplyMarkup', $job['admin'], $job['params']), JSON_THROW_ON_ERROR);
    exit;
}

function raceMarkup(array $jobs): array
{
    global $prefix, $cacheDir;
    $workers = [];
    try {
        foreach ($jobs as $index => $job) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $prefix],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            checkMarkup(is_resource($process), 'Worker startup failed');
            $workers[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(['index' => $index] + $job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
        }
        $deadline = microtime(true) + 10;
        foreach (array_keys($jobs) as $index) {
            $ready = $cacheDir.'.'.$index.'.ready';
            while (!is_file($ready) && microtime(true) < $deadline) usleep(1000);
            checkMarkup(is_file($ready), 'Workers did not reach barrier');
        }
        touch($cacheDir.'.start');
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $result = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            checkMarkup($error === '', 'Worker error: '.$error);
            $results[] = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
        foreach (array_keys($jobs) as $index) if (is_file($cacheDir.'.'.$index.'.ready')) unlink($cacheDir.'.'.$index.'.ready');
        if (is_file($cacheDir.'.start')) unlink($cacheDir.'.start');
    }
}

$created = [];
try {
    foreach (['tenant', 'admin_role', 'system_menu', 'system_role_menu', 'power_market_product', 'power_market_sku',
             'power_market_price_rule', 'tenant_power_market_sku_price', 'tenant_power_supply_markup', 'tenant_power_supply_markup_log'] as $name) {
        Db::execute("CREATE TABLE `$prefix$name` LIKE `$source$name`");
        $created[] = $prefix.$name;
    }
    foreach ([1 => 'tenant.tenant/supplyMarkup', 2 => 'tenant.tenant/applySupplyMarkup'] as $id => $perm) {
        Db::name('system_menu')->insert(['id' => $id, 'perms' => $perm, 'type' => 'A', 'source_menu_key' => 'fixture_'.$id]);
        Db::name('system_role_menu')->insert(['role_id' => $id, 'menu_id' => $id]);
        Db::name('admin_role')->insert(['admin_id' => $id, 'role_id' => $id]);
    }
    Db::name('admin_role')->insert(['admin_id' => 4, 'role_id' => 2]);
    foreach ([1, 2, 3] as $id) Db::name('tenant')->insert(['id' => $id, 'sn' => 'fixture'.$id, 'create_time' => time()]);
    Db::name('tenant')->where('id', 3)->update(['delete_time' => time()]);
    Db::name('power_market_product')->insert(['id' => 1, 'name' => 'Fixture', 'resource_type' => 'model']);
    $skus = [];
    for ($id = 1; $id <= 102; $id++) {
        $skus[] = ['id' => $id, 'product_id' => 1, 'sku_key' => 'sku_'.$id, 'title' => '规格 '.$id, 'upstream_price' => 10];
    }
    Db::name('power_market_sku')->insertAll($skus);
    Db::name('power_market_sku')->where('id', 1)->update(['source_payload' => json_encode([
        'effective_rate_config' => ['input' => 2, 'cached_input' => 0, 'output' => 12], 'usage_unit_size' => 1000000])]);
    Db::name('power_market_sku')->where('id', 2)->update(['source_payload' => json_encode([
        'image_item_billing' => ['mode' => 'image_count', 'included' => 1, 'items' => [
            'base' => ['label' => '基础费', 'unit' => '张', 'rate' => '5.000000'],
            'input_images' => ['label' => '输入图', 'unit' => '张', 'rate' => '1.111113']]]])]);
    $tenant = ['tenant_id' => 1];
    $params = $tenant + ['expected_revision' => 0, 'rate_percent' => '20'];
    $read = okMarkup(routeMarkup('supplyMarkup', 1, $tenant));
    checkMarkup($read['revision'] === 0 && $read['rate_percent'] === '0.00', 'Missing default');
    deniedMarkup(routeMarkup('supplyMarkup', 3, $tenant), '权限');
    deniedMarkup(routeMarkup('previewSupplyMarkup', 1, $params), '权限');
    deniedMarkup(routeMarkup('applySupplyMarkup', 1, $tenant), '权限');
    deniedMarkup(routeMarkup('supplyMarkup', 1, ['tenant_id' => 3]), '删除');
    deniedMarkup(routeMarkup('previewSupplyMarkup', 2, $params, 'GET'), 'POST');
    deniedMarkup(routeMarkup('previewSupplyMarkup', 2, array_replace($params, ['rate_percent' => '0.001'])), '两位');
    deniedMarkup(routeMarkup('previewSupplyMarkup', 2, array_replace($params, ['expected_revision' => 1])), '重新预览');

    foreach ([100, 101, 102] as $id) {
        Db::name('power_market_price_rule')->insert(['owner_id' => 1, 'scope_type' => 'sku', 'scope_id' => $id, 'mode' => 'manual', 'value' => '11.000000']);
    }
    Db::name('power_market_sku')->where('id', 100)->update(['sale_status' => 0]);
    $preview = okMarkup(routeMarkup('previewSupplyMarkup', 2, $params));
    checkMarkup($preview['summary'] === ['total_skus' => 102, 'affected_skus' => 102, 'blocking_conflicts' => 2,
        'existing_conflicts' => 0, 'off_shelf_skus' => 1], 'Incomplete catalogue or shelf counts');
    $rows = array_column($preview['samples'], null, 'item_code');
    checkMarkup($rows['cached_input']['proposed_cost'] === '0.000000' && $rows['output']['proposed_cost'] === '14.400000'
        && $rows['input_images']['proposed_cost'] === '1.333336', 'Component billing differences');
    $token = $tenant + ['preview_token' => $preview['preview_token']];
    $page = okMarkup(routeMarkup('previewSupplyMarkup', 2, $token + ['page_no' => 2, 'page_size' => 1]));
    checkMarkup($page['conflicts']['count'] === 3 && $page['conflicts']['lists'][0]['sku_id'] === 102
        && $page['samples'][0]['minimum_sale'] === '12.000000', 'Missing last-page conflict');
    deniedMarkup(routeMarkup('applySupplyMarkup', 2, $token), '倒挂');
    deniedMarkup(routeMarkup('previewSupplyMarkup', 4, $token), '不属于');
    deniedMarkup(routeMarkup('applySupplyMarkup', 2, array_replace($token, ['tenant_id' => 2])), '不属于');
    Db::name('power_market_price_rule')->whereIn('scope_id', [101, 102])->update(['value' => '16.00']);
    deniedMarkup(routeMarkup('applySupplyMarkup', 2, $token), '已变化');

    $token['preview_token'] = okMarkup(routeMarkup('previewSupplyMarkup', 2, $params))['preview_token'];
    $competing = $tenant + ['preview_token' => okMarkup(routeMarkup('previewSupplyMarkup', 4, $params))['preview_token']];
    $first = okMarkup(routeMarkup('applySupplyMarkup', 2, $token));
    checkMarkup($first['supply_markup']['rate_percent'] === '20.00' && $first['supply_markup']['revision'] === 1, 'Wrong write');
    deniedMarkup(routeMarkup('applySupplyMarkup', 4, $competing), '已变化');
    deniedMarkup(routeMarkup('applySupplyMarkup', 4, $token), '不属于');
    $tenants = [['id' => 1], ['id' => 2]];
    TenantSupplyMarkupAdmin::attachSummaries($tenants);
    checkMarkup(array_column(array_column($tenants, 'supply_markup'), 'rate_percent') === ['20.00', '0.00'], 'Summary leakage');

    $restoreParams = array_replace($params, ['rate_percent' => '0', 'expected_revision' => 1]);
    Db::name('power_market_price_rule')->where('scope_id', 102)->update(['value' => '9.00']);
    $restore = okMarkup(routeMarkup('previewSupplyMarkup', 2, $restoreParams));
    checkMarkup($restore['summary']['blocking_conflicts'] === 0 && $restore['summary']['existing_conflicts'] === 1, 'Improving existing conflict blocked');
    $restoreToken = $tenant + ['preview_token' => $restore['preview_token']];
    // A failure after updating settings must roll back both the write and its receipt.
    $logTable = Db::name('tenant_power_supply_markup_log')->getTable();
    Db::execute("ALTER TABLE `$logTable` ADD UNIQUE KEY force_audit_failure (tenant_id)");
    checkMarkup(routeMarkup('applySupplyMarkup', 2, $restoreToken)['code'] === 0, 'Audit failure unexpectedly accepted');
    checkMarkup(okMarkup(routeMarkup('supplyMarkup', 1, $tenant))['revision'] === 1
        && Db::name('tenant_power_supply_markup_log')->count() === 1, 'Audit failure left a partial write');
    Db::execute("ALTER TABLE `$logTable` DROP INDEX force_audit_failure");
    $restored = okMarkup(routeMarkup('applySupplyMarkup', 2, $restoreToken));
    checkMarkup($restored['supply_markup']['revision'] === 2 && $restored['supply_markup']['rate_percent'] === '0.00', 'Restore lost revision');
    Cache::delete('tenant_supply_markup_preview:'.$token['preview_token']);
    checkMarkup(okMarkup(routeMarkup('applySupplyMarkup', 2, $token)) === $first, 'Replay lost original response after restoration');
    checkMarkup(Db::name('tenant_power_supply_markup_log')->count() === 2, 'Audit count differs from successful adjustments');
    $expired = okMarkup(routeMarkup('previewSupplyMarkup', 2, array_replace($params, ['expected_revision' => 2])));
    Cache::delete('tenant_supply_markup_preview:'.$expired['preview_token']);
    deniedMarkup(routeMarkup('applySupplyMarkup', 2, $tenant + ['preview_token' => $expired['preview_token']]), '过期');
    // Race on a tenant that has never had a supply setting or tenant rule. Both callers observed revision zero.
    $jobs = [];
    foreach ([2, 4] as $admin) {
        $candidate = ['tenant_id' => 2, 'rate_percent' => (string)(10 * $admin), 'expected_revision' => 0];
        $receipt = okMarkup(routeMarkup('previewSupplyMarkup', $admin, $candidate));
        $jobs[] = ['admin' => $admin, 'params' => ['tenant_id' => 2, 'preview_token' => $receipt['preview_token']]];
    }
    $results = raceMarkup($jobs);
    $winners = array_keys(array_filter($results, static fn(array $row): bool => $row['code'] === 1));
    checkMarkup(count($winners) === 1, 'Concurrent editors did not yield exactly one winner');
    $winner = $winners[0];
    deniedMarkup($results[1 - $winner], '已变化');
    $winnerRate = (string)(10 * $jobs[$winner]['admin']).'.00';
    checkMarkup(okMarkup(routeMarkup('supplyMarkup', 1, ['tenant_id' => 2]))['rate_percent'] === $winnerRate, 'Race saved wrong rate');
    foreach (raceMarkup([$jobs[$winner], $jobs[$winner]]) as $replay) checkMarkup($replay === $results[$winner], 'Concurrent replay changed response');
    checkMarkup(Db::name('tenant_power_supply_markup_log')->where('tenant_id', 2)->count() === 1, 'Concurrent writes duplicated audit');
    echo "PASS concurrent first-write contention, both replay workers, audit rollback, platform controller and permission middleware, 102-SKU preview, token/image items, pagination, tenant/admin binding, stale revision/catalogue, restoration, audit and replay\n";
} finally {
    Cache::store('fixture')->clear();
    foreach (array_reverse($created) as $table) Db::execute("DROP TABLE `$table`");
}
