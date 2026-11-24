<?php
declare(strict_types=1);

use app\common\service\database\RuntimeMigrationPlan;

require __DIR__.'/../app/common/service/database/SqlMigrationExecutor.php';
require __DIR__.'/../app/common/service/database/RuntimeMigrationPlan.php';

$errors = [];
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $path => $sql) {
    try {
        RuntimeMigrationPlan::build($sql);
    } catch (Throwable $e) {
        $errors[] = $path . ': ' . $e->getMessage();
    }
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
