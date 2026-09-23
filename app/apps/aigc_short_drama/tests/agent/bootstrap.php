<?php
declare(strict_types=1);

// Local acceptance runs inside the existing Baota application container.  It
// intentionally uses the application's configured local database; this file
// must never create, redirect to, or migrate a separate test environment.
if (getenv('SHORT_DRAMA_AGENT_TEST') !== 'local-existing') {
    throw new RuntimeException('Refusing test writes without explicit local acceptance mode');
}
require dirname(__DIR__, 5) . '/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    exit(1);
});
// A missing table is a deployment/configuration error, not a reason for a
// test to mutate the local schema.  Local acceptance only verifies already
// applied source migrations and exits before any fixture write otherwise.
if (think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] !== 'x_cn') {
    throw new RuntimeException('Local acceptance must use the configured x_cn database');
}
foreach ([
    'la_aigc_short_drama_canvas_agent_safety_audit',
    'la_aigc_short_drama_canvas_quote',
] as $table) {
    if (!think\facade\Db::query("SHOW TABLES LIKE '" . $table . "'")) {
        throw new RuntimeException('Required local migration is missing: ' . $table);
    }
}
function agentCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ', $name, PHP_EOL;
}
