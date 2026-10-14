<?php
require __DIR__.'/Support/OemDisposable.php';
$content=stream_get_contents(STDIN);if(trim($content)==='')throw new RuntimeException('Migration input missing');\app\common\service\database\RuntimeMigrationPlan::build($content);
\app\common\service\database\SqlMigrationExecutor::execute($content,(string)config('database.connections.mysql.prefix'));
