<?php
/** Explicit local development migration. Never called by request handlers. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
(new \think\App(dirname(__DIR__).'/'))->initialize();
$db=config('database.connections.mysql');
if(!in_array($db['hostname']??'', ['127.0.0.1','localhost'],true)||($db['database']??'')!=='z_cn'||!in_array('--local-development-only',$argv,true)){
    fwrite(STDERR,"Refusing: explicit --local-development-only and verified local z_cn database required.\n");exit(2);
}
$sql=file_get_contents(dirname(__DIR__).'/app/apps/aigc_micro_video/migrations/zzz_20261018_workbench.sql');
\app\common\service\database\SqlMigrationExecutor::execute($sql,(string)($db['prefix']??'la_'));
foreach(['record','version','quote','job','execution','asset'] as $suffix){$table=($db['prefix']??'la_').'aigc_micro_video_workbench_'.$suffix;$exists=\think\facade\Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);if((int)$exists[0]['n']!==1)throw new RuntimeException('Workbench migration incomplete');}
echo "PASS local additive workbench schema; existing task and billing data retained.\n";
