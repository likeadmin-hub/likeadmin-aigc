<?php
/** Read-only DTO regression against an existing local published version. No provider calls. */
require dirname(__DIR__, 2) . '/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__, 2)))->initialize();

use app\common\service\app\aigc_copywriting\CopywritingStore as Store;
use app\common\service\app\aigc_copywriting\CopywritingTaskService as Tasks;

$database = config('database.connections.mysql');
if (($database['hostname'] ?? '') !== '127.0.0.1' || ($database['database'] ?? '') !== 'z_cn') {
    throw new RuntimeException('Local development database required');
}
$version = Store::table('version')->order('id')->find();
if (!$version) throw new RuntimeException('Missing local copywriting version fixture');
$row = [
    'id' => 99999999, 'tenant_id' => $version['tenant_id'], 'user_id' => 99999999,
    'version_id' => $version['id'], 'status' => 'success', 'provider_request_id' => 'receipt-fixture-42',
    'input_snapshot' => '{}', 'model_snapshot' => '{"name":"fixture","api_key":"never-public"}',
    'request_hash' => 'never-public', 'prompt_snapshot' => 'never-public',
];
$public = Tasks::publicTask($row);
if (($public['provider_request_id'] ?? '') !== 'receipt-fixture-42') throw new RuntimeException('Provider receipt absent from public task');
if (isset($public['request_hash']) || isset($public['prompt_snapshot']) || isset($public['model']['api_key'])) throw new RuntimeException('Private execution data leaked');
if (isset($public['upstream_task_id'])) throw new RuntimeException('Text request receipt mislabeled as asynchronous task');
try {
    Tasks::detail((int)$row['tenant_id'], (int)$row['user_id'], (int)$row['id']);
    throw new RuntimeException('Unowned/nonexistent task was readable');
} catch (InvalidArgumentException $expected) {}
echo "PASS copywriting provider receipt DTO, sensitive-field exclusion and owned detail boundary\n";
