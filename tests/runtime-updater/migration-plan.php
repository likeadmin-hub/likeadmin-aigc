<?php
declare(strict_types=1);
require __DIR__.'/../../app/common/service/database/SqlMigrationExecutor.php';
require __DIR__.'/../../app/common/service/database/RuntimeMigrationPlan.php';
use app\common\service\database\RuntimeMigrationPlan;
function assertPlan(bool $condition, string $message): void { if(!$condition) throw new RuntimeException($message); }
$plan=RuntimeMigrationPlan::build("CREATE TABLE IF NOT EXISTS la_new(id int); INSERT INTO la_new VALUES(1); UPDATE la_app SET update_time=1;");
assertPlan(!str_contains($plan['prepare'],'UPDATE') && str_contains($plan['complete'],'UPDATE'),'DML crossed preparation boundary');
foreach ([
    "SET @tail='; UPDATE la_app SET name=1'; SET @sql=CONCAT('CREATE TABLE IF NOT EXISTS t(id int)',@tail); PREPARE x FROM @sql; EXECUTE x; DEALLOCATE PREPARE x;",
    "SET @sql=IF(1,'CREATE TABLE IF NOT EXISTS t(id int)','DO dangerous()'); PREPARE x FROM @sql; EXECUTE x; DEALLOCATE PREPARE x;",
    'ALTER TABLE t ADD COLUMN x INT, DROP old_column',
    'ALTER TABLE t ADD COLUMN x INT, RENAME TO old_table',
    'ALTER TABLE t ADD COLUMN x INT, MODIFY y INT',
    'ALTER TABLE t ADD COLUMN x INT, CHANGE y z INT',
    'UPDATE t JOIN other ON t.id=other.id SET other.name=1', 'UPDATE t,other SET other.name=1', 'TRUNCATE TABLE la_app', 'CALL unknown()', 'COMMIT', 'INSERT INTO t VALUES(@unknown)',
] as $bad) {
    try { RuntimeMigrationPlan::build($bad); throw new LogicException('unsafe plan accepted: '.$bad); } catch (RuntimeException $e) {}
}
foreach (glob(__DIR__.'/../../app/apps/aigc_short_drama/migrations/upgrade_20261009_canvas_*.sql') as $file) RuntimeMigrationPlan::build(file_get_contents($file));
echo "PASS: new DML transactional; dynamic, transaction-control and mixed destructive SQL blocked\n";
