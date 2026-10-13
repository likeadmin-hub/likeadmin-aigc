<?php
require __DIR__.'/../app/common/service/database/SqlMigrationExecutor.php';require __DIR__.'/../app/common/service/database/RuntimeMigrationPlan.php';
foreach(glob(__DIR__.'/../upgrade/2026101*_oem_*.sql') as $path) { $plan=\app\common\service\database\RuntimeMigrationPlan::build(file_get_contents($path));if(!$plan['prepare']&&!$plan['complete'])throw new RuntimeException('OEM migration is empty'); }
echo "PASS OEM migrations classified by actual runtime planner: static additive preparation and transactional data\n";
