<?php
declare(strict_types=1);

// Only usable in the isolated, non-egress Docker test network. Never falls
// back to .env database settings, and never loads live supplier credentials.
if (getenv('SHORT_DRAMA_AGENT_TEST') !== 'isolated-mysql') {
    throw new RuntimeException('Refusing test writes without isolated test environment');
}
require dirname(__DIR__, 5) . '/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    exit(1);
});
$database = config('database');
$database['default'] = 'mysql';
$database['connections']['mysql'] = array_replace($database['connections']['mysql'], [
    'hostname' => 'mysql-test', 'hostport' => '3306',
    'database' => 'short_drama_agent_test', 'username' => 'root', 'password' => '',
    'prefix' => 'la_', 'fields_cache' => false, 'trigger_sql' => false,
]);
config($database, 'database');
if (think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] !== 'short_drama_agent_test') {
    throw new RuntimeException('Wrong test database');
}
// The isolated database deliberately starts minimal. Apply this app-owned,
// idempotent migration so each P2 behavior test exercises the live safety
// boundary without relying on test order or any business database schema.
foreach ([
    'la_aigc_short_drama_canvas_agent_safety_audit' => 'upgrade_20260921_canvas_agent_safety.sql',
    'la_aigc_short_drama_canvas_quote' => 'upgrade_20260922_canvas_quote_confirmation.sql',
] as $table => $migration) {
    if (!think\facade\Db::query("SHOW TABLES LIKE '" . $table . "'")) {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/migrations/'.$migration);
        think\facade\Db::execute($sql);
    }
}
function agentCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ', $name, PHP_EOL;
}
