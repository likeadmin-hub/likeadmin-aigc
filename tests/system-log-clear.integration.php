<?php
/** Test the real journal endpoints against a connection-local temporary log table. */
require dirname(__DIR__).'/runtime-loader.php';

use app\common\model\OperationLog;
use app\platformapi\controller\setting\system\LogController;
use think\facade\Db;

$app = new AigcRuntimeApp(dirname(__DIR__));
$app->initialize();
$config = config('database.connections.mysql');
if ($config['hostname'] !== '127.0.0.1' || $config['database'] !== 'z_cn') {
    throw new RuntimeException('Requires the local test database');
}
function checkJournal(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$connection = Db::connect();
checkJournal($connection->query('SELECT DATABASE() AS db')[0]['db'] === 'z_cn', 'Unexpected database');
$pdo = $connection->getPdo();
$schema = $pdo->query('SHOW CREATE TABLE la_operation_log')->fetchColumn(1);
$pdo->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $schema));
$insert = $pdo->prepare('INSERT INTO la_operation_log (admin_id,admin_name,type,url) VALUES (?,?,?,?)');
for ($i = 0; $i < 47; $i++) {
    $platform = $i < 37;
    $insert->execute([$platform ? 1 : 2, $platform ? 'platform' : 'tenant', 'GET', $platform ? '/platformapi/example' : '/adminapi/example']);
}
$requestFor = static function (string $method, array $params = []) use ($app): LogController {
    $request = (new \app\Request())->setMethod($method)->withGet($params);
    $request->adminId = 1;
    $request->adminInfo = ['admin_id'=>1];
    $app->instance('request', $request);
    return new LogController($app);
};
$controller = $requestFor('GET', ['admin_name'=>'platform', 'page_no'=>2, 'page_size'=>15]);
$page = $controller->lists()->getData()['data'];
checkJournal($page['count'] === 37 && count($page['lists']) === 15, 'Expected a filtered second page');
checkJournal($controller->clear()->getData()['code'] === 0 && OperationLog::count() === 47, 'GET must preserve logs');

$controller = $requestFor('POST');
$result = $controller->clear()->getData();
checkJournal($result['code'] === 1 && $result['data']['deleted'] === 47, 'Clear must include all pages and all administrators');
$controller = $requestFor('GET', ['page_no'=>1, 'page_size'=>15]);
$page = $controller->lists()->getData()['data'];
checkJournal($page['count'] === 0 && $page['lists'] === [] && OperationLog::count() === 0, 'Unfiltered list must also be empty');
$controller = $requestFor('POST');
checkJournal($controller->clear()->getData()['data']['deleted'] === 0, 'Repeated clear must be safe');
echo "PASS filtered second page showed 15 of 37; cleared all 47 logs; unfiltered count is zero\n";
