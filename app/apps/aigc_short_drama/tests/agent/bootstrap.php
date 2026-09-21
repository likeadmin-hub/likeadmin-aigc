<?php
declare(strict_types=1);

// Only usable in the isolated, non-egress Docker test network. Never falls
// back to .env database settings, and never loads live supplier credentials.
if (getenv('SHORT_DRAMA_AGENT_TEST') !== 'isolated-mysql') {
    throw new RuntimeException('Refusing test writes without isolated test environment');
}
require dirname(__DIR__, 5) . '/vendor/autoload.php';
(new think\App())->initialize();
$database = config('database');
$database['default'] = 'mysql';
$database['connections']['mysql'] = array_replace($database['connections']['mysql'], [
    'hostname' => 'mysql-test', 'hostport' => '3306',
    'database' => 'short_drama_agent_test', 'username' => 'root', 'password' => '',
    'prefix' => 'la_', 'fields_cache' => false, 'trigger_sql' => false,
]);
config($database, 'database');
think\facade\Db::setConfig($database);
if (think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] !== 'short_drama_agent_test') {
    throw new RuntimeException('Wrong test database');
}
function agentCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ', $name, PHP_EOL;
}
