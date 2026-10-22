<?php
declare(strict_types=1);
function config(string $key): array { return $GLOBALS['baseline'] ?? []; }
require __DIR__.'/../../app/common/service/database/SqlMigrationExecutor.php';
require __DIR__.'/../../app/common/service/database/RuntimeMigrationPlan.php';
require __DIR__.'/../../app/common/service/app/AppRegistryService.php';
use app\common\service\app\AppRegistryService;
function preflightCheck(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
$dir=sys_get_temp_dir().'/app-migration-preflight-'.bin2hex(random_bytes(5));mkdir($dir);
try {
    file_put_contents($dir.'/01.sql','CREATE TABLE IF NOT EXISTS la_fixture(id int); INSERT INTO la_fixture VALUES(1);');
    file_put_contents($dir.'/02.sql','TRUNCATE TABLE la_fixture;');
    try { AppRegistryService::validateRuntimeMigrations('aigc_music',$dir);throw new LogicException('late unsafe migration accepted'); }
    catch(RuntimeException $e){preflightCheck(!str_contains($e->getMessage(),'undefined'),'unrelated failure');}
    unlink($dir.'/02.sql');
    $plans=AppRegistryService::validateRuntimeMigrations('aigc_music',$dir);
    preflightCheck(count($plans)===1 && str_contains($plans['01.sql']['plan']['prepare'],'CREATE') && str_contains($plans['01.sql']['plan']['complete'],'INSERT'),'partition changed');
    $GLOBALS['baseline']=['app/apps/aigc_music/migrations/01.sql'=>hash_file('sha256',$dir.'/01.sql')];
    preflightCheck(AppRegistryService::validateRuntimeMigrations('aigc_music',$dir)['01.sql']['plan']===null,'historical SQL would execute');
    file_put_contents($dir.'/01.sql','INSERT INTO la_fixture VALUES(2);');
    try { AppRegistryService::validateRuntimeMigrations('aigc_music',$dir);throw new LogicException('historical drift accepted'); }
    catch(RuntimeException $e){preflightCheck(str_contains($e->getMessage(),'历史迁移字节已改变'),'wrong historical error');}
    $GLOBALS['baseline']=[];
    file_put_contents($dir.'/02-legacy.sql','SET @legacy_done = 1; PREPARE statement FROM @legacy_done;');
    $plans=AppRegistryService::validateRuntimeMigrations('aigc_music',$dir,['02-legacy.sql']);
    preflightCheck($plans['02-legacy.sql']['plan']===null && $plans['02-legacy.sql']['completed']===true,'successful retained migration was reclassified as pending SQL');
    preflightCheck(str_contains($plans['01.sql']['plan']['complete'],'INSERT'),'pending static migration was skipped');
    try {AppRegistryService::validateRuntimeMigrations('aigc_music',$dir);throw new LogicException('pending dynamic migration accepted');}
    catch(RuntimeException $e){preflightCheck(str_contains($e->getMessage(),'会话变量'),'pending dynamic SQL rejected for wrong reason');}
    $GLOBALS['baseline']=['app/apps/aigc_music/migrations/02-legacy.sql'=>str_repeat('0',64)];
    try {AppRegistryService::validateRuntimeMigrations('aigc_music',$dir,['02-legacy.sql']);throw new LogicException('completed historical drift accepted');}
    catch(RuntimeException $e){preflightCheck(str_contains($e->getMessage(),'历史迁移字节已改变'),'completed status bypassed signed history');}
    echo "PASS: all migration files validated without DB access; unsafe late file rejected; historical bytes adopted without replay; drift rejected\n";
} finally { foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir); }
