<?php
declare(strict_types=1);
require __DIR__.'/../../app/common/service/database/SqlMigrationExecutor.php';
require __DIR__.'/../../app/common/service/database/RuntimeMigrationPlan.php';
require __DIR__.'/../../app/common/service/database/RuntimeVideoConfigCapacity.php';
use app\common\service\database\RuntimeMigrationPlan as Plan;
use app\common\service\database\RuntimeVideoConfigCapacity as Capacity;
function capacityCheck(bool $value,string $label): void { if(!$value)throw new RuntimeException($label); }
$statement='ALTER TABLE `la_aigc_video_config` MODIFY COLUMN `config_json` mediumtext';
try {
    Capacity::apply('fixture_', new class {
        public function query(string $sql, array $bindings = []): array { return []; }
        public function execute(string $sql): int { throw new LogicException('Missing table caused DDL'); }
    });
    throw new LogicException('Missing table was accepted as completed');
} catch (RuntimeException $e) {
    capacityCheck(str_contains($e->getMessage(),'缺少目标表'),'Missing table must block registry success');
}
$plan=Plan::build($statement.';');
capacityCheck($plan['complete']===''&&str_contains($plan['prepare'],'MODIFY'),'Widening belongs to preparation');
$column=['column_name'=>'config_json','data_type'=>'text','column_type'=>'text','is_nullable'=>'YES','column_default'=>null,'character_set_name'=>'utf8mb4','collation_name'=>'utf8mb4_bin','column_comment'=>"Owned 'comment' \\ value",'extra'=>''];
$sql=Capacity::columnSql(['engine'=>'InnoDB'],$column,'fixture_');
capacityCheck(str_contains(Capacity::columnSql(['engine'=>'InnoDB'],$column,''),'`aigc_video_config`'),'Valid unprefixed installation changed');
try{Capacity::columnSql(['engine'=>'InnoDB'],$column,'bad`;DROP');throw new LogicException('Unsafe prefix accepted');}catch(RuntimeException $e){}
capacityCheck(str_contains($sql,'`fixture_aigc_video_config`')&&str_contains($sql,'MEDIUMTEXT CHARACTER SET `utf8mb4` COLLATE `utf8mb4_bin` NULL DEFAULT NULL'),'Type/charset/collation/null/default changed');
capacityCheck(str_contains($sql,"COMMENT 'Owned ''comment'' \\\\ value'"),'Comment changed');
capacityCheck(str_contains(Capacity::columnSql(['engine'=>'InnoDB'],$column,'fixture_',true),"COMMENT 'Owned ''comment'' \\ value'"),'NO_BACKSLASH_ESCAPES comment changed');
$nonNull=$column;$nonNull['is_nullable']='NO';
capacityCheck(str_contains(Capacity::columnSql(['engine'=>'InnoDB'],$nonNull,'fixture_'),' NOT NULL COMMENT '),'NOT NULL/no-default changed');
$medium=$column;$medium['data_type']=$medium['column_type']='mediumtext';
capacityCheck(Capacity::columnSql(['engine'=>'InnoDB'],$medium,'fixture_')===null,'Already sufficient capacity must be no-op');
foreach([
 ['engine'=>'MyISAM'],['column'=>['data_type'=>'longtext','column_type'=>'longtext']],['column'=>['data_type'=>'json','column_type'=>'json']],
 ['column'=>['extra'=>'STORED GENERATED']],['column'=>['extra'=>'DEFAULT_GENERATED']],['column'=>['column_default'=>'unknown expression']],
 ['column'=>['is_nullable'=>'unknown']],['column'=>['collation_name'=>'utf8mb4_bin; DROP TABLE t']],['column'=>['character_set_name'=>null]],
 ['column'=>['column_name'=>'other']],['column'=>['column_type'=>'text binary']],
] as $bad){
 try{Capacity::columnSql(['engine'=>$bad['engine']??'InnoDB'],array_replace($column,$bad['column']??[]),'fixture_');throw new LogicException('Unsafe schema accepted');}catch(RuntimeException $e){}
}
foreach(['ALTER TABLE la_other MODIFY COLUMN config_json mediumtext','ALTER TABLE la_aigc_video_config MODIFY COLUMN other mediumtext','ALTER TABLE la_aigc_video_config MODIFY COLUMN config_json longtext','ALTER TABLE la_aigc_video_config MODIFY COLUMN config_json mediumtext NOT NULL','ALTER TABLE la_aigc_video_config ADD COLUMN a int, MODIFY COLUMN config_json mediumtext','SET @sql=1;'] as $bad){try{Plan::build($bad);throw new LogicException('Other MODIFY/dynamic SQL accepted');}catch(RuntimeException $e){}}
foreach(array_merge(glob(__DIR__.'/../../app/apps/open_platform/migrations/*.sql'),glob(__DIR__.'/../../app/apps/aigc_video/migrations/upgrade_20261012*.sql')) as $file)Plan::build(file_get_contents($file));
echo "PASS: exact guarded video capacity plan; retained column semantics; other MODIFY/dynamic/multi-target rejected; new app migrations classify\n";
