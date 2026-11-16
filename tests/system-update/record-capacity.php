<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/common/service/database/RuntimeUpdateRecordCapacity.php';
require dirname(__DIR__,2).'/app/common/service/database/SqlMigrationExecutor.php';
require dirname(__DIR__,2).'/app/common/service/database/RuntimeMigrationPlan.php';
use app\common\service\database\RuntimeUpdateRecordCapacity as Capacity;
use app\common\service\database\RuntimeMigrationPlan as Plan;
function capCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function capReject(callable $call): void { try { $call(); } catch (RuntimeException $error) { return; } throw new LogicException('Unsafe schema/SQL accepted'); }
$column=['column_name'=>'preflight_json','data_type'=>'text','column_type'=>'text','is_nullable'=>'YES','column_default'=>null,'character_set_name'=>'utf8mb4','collation_name'=>'utf8mb4_bin','column_comment'=>"Owner's \\ comment",'extra'=>''];
$sql=Capacity::columnSql('update_task','preflight_json',['engine'=>'InnoDB'],$column,'site_');
capCheck(str_contains($sql,'`site_update_task`') && str_contains($sql,'MEDIUMTEXT CHARACTER SET `utf8mb4` COLLATE `utf8mb4_bin` NULL DEFAULT NULL'), 'Live column semantics changed');
capCheck(str_contains($sql,"COMMENT 'Owner''s \\\\ comment'"),'Comment escaping changed');
capCheck(str_contains(Capacity::columnSql('update_task','preflight_json',['engine'=>'InnoDB'],$column,'',true),"COMMENT 'Owner''s \\ comment'"),'NO_BACKSLASH_ESCAPES changed');
foreach(['mediumtext','longtext'] as $type) capCheck(Capacity::columnSql('update_task','preflight_json',['engine'=>'InnoDB'],array_replace($column,['data_type'=>$type,'column_type'=>$type]),'site_')===null,'Sufficient type was downgraded');
foreach([
    ['table'=>'other'], ['name'=>'error'], ['prefix'=>'x`;DROP'], ['engine'=>'MyISAM'],
    ['column'=>['data_type'=>'json','column_type'=>'json']], ['column'=>['extra'=>'STORED GENERATED']],
    ['column'=>['column_type'=>'text binary']], ['column'=>['is_nullable'=>'unknown']],
    ['column'=>['column_default'=>123]], ['column'=>['collation_name'=>'utf8mb4_bin;DROP']],
] as $bad) capReject(fn()=>Capacity::columnSql($bad['table']??'update_task',$bad['name']??'preflight_json',['engine'=>$bad['engine']??'InnoDB'],array_replace($column,$bad['column']??[]),$bad['prefix']??'site_'));
$root=dirname(__DIR__,2);
$source=file_get_contents($root.'/upgrade/20261116_update_record_capacity.sql');
capCheck($source===file_get_contents($root.'/public/upgrade/20261116_update_record_capacity.sql'),'Migration mirrors drifted');
$plan=Plan::build($source);
capCheck($plan['complete']==='' && count(app\common\service\database\SqlMigrationExecutor::split($plan['prepare']))===3,'Capacity DDL escaped preparation');
foreach(['ALTER TABLE la_update_task MODIFY preflight_json LONGTEXT','ALTER TABLE la_update_task MODIFY error MEDIUMTEXT','ALTER TABLE la_update_task MODIFY preflight_json MEDIUMTEXT NOT NULL','ALTER TABLE la_update_task MODIFY preflight_json MEDIUMTEXT, DROP error','ALTER TABLE la_update_package MODIFY preflight_json MEDIUMTEXT'] as $bad) capReject(fn()=>Plan::build($bad));
$install=file_get_contents($root.'/public/install/db/like.sql');
foreach(['update_task'=>['preflight_json','result_json'],'update_package'=>['manifest_json']] as $table=>$columns){
    preg_match('/CREATE TABLE IF NOT EXISTS `la_'.$table.'` \((.*?)\) ENGINE=/s',$install,$match);
    foreach($columns as $name) capCheck(str_contains($match[1],'`'.$name.'` mediumtext'),'Fresh install capacity missed '.$name);
}
$baseline=require $root.'/config/runtime_migration_baseline.php';
capCheck(hash_file('sha256',$root.'/app/apps/aigc_image/migrations/install.sql')===$baseline['app/apps/aigc_image/migrations/install.sql'],'Signed historical install bytes changed');
echo "PASS bounded updater capacity intents, live semantics, fresh-install parity and immutable history\n";
