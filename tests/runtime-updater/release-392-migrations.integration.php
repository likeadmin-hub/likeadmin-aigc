<?php
declare(strict_types=1);
// Run only in the existing disposable MySQL harness; never in a project database.
if (getenv('SYSTEM_SERVICE_DISPOSABLE')!=='1') throw new RuntimeException('Disposable fixture required');
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\database\SqlMigrationExecutor as Executor;
use app\common\service\database\RuntimeVideoConfigCapacity as Capacity;
function releaseCheck(bool $value,string $label): void { if(!$value)throw new RuntimeException($label); }
releaseCheck(str_starts_with((string)config('database.connections.mysql.database'),'fixture_'),'Wrong disposable database');
$pdo=Db::connect()->connect();$prefix='release392_'.bin2hex(random_bytes(4)).'_';
$connection=new class($pdo) { public function __construct(private PDO $pdo){} public function query(string $sql,array $bindings=[]):array{$q=$this->pdo->prepare($sql);$q->execute($bindings);return $q->fetchAll(PDO::FETCH_ASSOC);} public function execute(string $sql):int{if(preg_match('/^\s*(?:SELECT|EXECUTE)\b/i',$sql)){$q=$this->pdo->query($sql);$count=$q->rowCount();if($q->columnCount()>0)$q->fetchAll();$q->closeCursor();return $count;}return $this->pdo->exec($sql);} };
$tables=['aigc_video_config','capacity_journal','system_menu','tenant_system_menu','system_role_menu','tenant_system_role_menu','open_platform_document','tenant_file','aigc_music_asset'];
$q=fn(string $sql,array $bindings=[])=>$connection->query(str_replace('la_',$prefix,$sql),$bindings);
$run=fn(string $sql)=>Executor::execute($sql,$prefix,$connection);
$root=dirname(__DIR__,2);
try {
 try{Capacity::apply($prefix,$connection);throw new LogicException('Missing table was accepted as completed');}catch(RuntimeException $e){releaseCheck(str_contains($e->getMessage(),'缺少目标表'),'Missing table must block registry success');}
 $pdo->exec('CREATE TABLE `'.$prefix.'aigc_video_config` (id int PRIMARY KEY,config_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL COMMENT \'Owned column\') ENGINE=InnoDB');
 $pdo->exec('CREATE TABLE `'.$prefix.'capacity_journal` (id int PRIMARY KEY,status varchar(30)) ENGINE=InnoDB');
 $value=json_encode(['owned'=>str_repeat('配置',10000),'effects'=>['existing'=>false]],JSON_UNESCAPED_UNICODE);
 $q('INSERT INTO la_aigc_video_config(id,config_json) VALUES(1,?)',[$value]);
 $q("INSERT INTO la_capacity_journal VALUES(1,'running')");
 $run(file_get_contents($root.'/app/apps/aigc_video/migrations/upgrade_20261012_effect_config_capacity.sql'));
 releaseCheck(Capacity::apply($prefix,$connection)['status']==='already_sufficient','Applied widening must be schema-proven no-op');
 releaseCheck($q('SELECT config_json FROM la_aigc_video_config WHERE id=1')[0]['config_json']===$value,'Configuration value changed');
 $pdo->beginTransaction();$q("UPDATE la_capacity_journal SET status='prepared' WHERE id=1");$pdo->rollBack();
 releaseCheck($q('SELECT status FROM la_capacity_journal WHERE id=1')[0]['status']==='running','Uncertain journal was promoted after simulated metadata failure');
 releaseCheck(Capacity::apply($prefix,$connection)['status']==='already_sufficient','DDL persists after metadata rollback and cannot repeat ALTER');
 foreach(['LONGTEXT','JSON'] as $type){$pdo->exec('ALTER TABLE `'.$prefix.'aigc_video_config` MODIFY config_json '.$type);try{Capacity::apply($prefix,$connection);throw new LogicException('Unsafe type accepted');}catch(RuntimeException $e){}}
 $pdo->exec('ALTER TABLE `'.$prefix.'aigc_video_config` MODIFY config_json TEXT');$pdo->exec('ALTER TABLE `'.$prefix.'aigc_video_config` ENGINE=MyISAM');
 try{Capacity::apply($prefix,$connection);throw new LogicException('Unsafe engine accepted');}catch(RuntimeException $e){}
 $pdo->exec('ALTER TABLE `'.$prefix.'aigc_video_config` ENGINE=InnoDB');
 // Real391 core URI collation differs from a MySQL8-created app table.
 // Exact upload identity must also preserve case, owner and existing provenance.
 $pdo->exec('CREATE TABLE `'.$prefix.'tenant_file` (id int PRIMARY KEY,tenant_id int,source_id int,source int,uri varchar(200),upload_origin varchar(20) NOT NULL DEFAULT \'\') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
 $pdo->exec('CREATE TABLE `'.$prefix.'aigc_music_asset` (id int PRIMARY KEY,tenant_id int,user_id int,uri varchar(500),source_action varchar(50)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
 $q("INSERT INTO la_tenant_file VALUES (1,1,10,1,'uploads/audio/song.mp3',''),(2,1,20,1,'uploads/audio/song.mp3',''),(3,2,10,1,'uploads/audio/song.mp3',''),(4,1,10,1,'uploads/audio/Song.mp3',''),(5,1,10,1,'uploads/audio/generated.mp3',''),(6,1,10,1,'uploads/audio/song.mp3','owned'),(7,1,10,1,'uploads/images/20261012/upload.png',''),(8,1,10,2,'uploads/audio/song.mp3','')");
 $q("INSERT INTO la_aigc_music_asset VALUES (1,1,10,'uploads/audio/song.mp3','upload_audio'),(2,1,10,'uploads/audio/generated.mp3','generated')");
 $uploadSql=str_replace('la_',$prefix,file_get_contents($root.'/upgrade/20261012_workspace_local_uploads.sql'));
 $collationSql='SELECT TABLE_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?) AND COLUMN_NAME=\'uri\' ORDER BY TABLE_NAME';
 $collationBindings=[$prefix.'tenant_file',$prefix.'aigc_music_asset'];
 $collations=$connection->query($collationSql,$collationBindings);
 releaseCheck(array_column($collations,'COLLATION_NAME')===['utf8mb4_0900_ai_ci','utf8mb4_general_ci'],'Mixed-collation regression fixture lost genuine schema difference');
 $run($uploadSql);
 $origins=array_column($q('SELECT upload_origin FROM la_tenant_file ORDER BY id'),'upload_origin');
 releaseCheck($origins===['local','','','','','owned','local',''],'Mixed-collation URI matching changed ownership, case, generated or existing provenance');
 $run($uploadSql);releaseCheck(array_column($q('SELECT upload_origin FROM la_tenant_file ORDER BY id'),'upload_origin')===$origins,'Provenance replay changed records');
 releaseCheck($connection->query($collationSql,$collationBindings)===$collations,'Provenance migration changed historical collations');
 echo "PASS: mixed MySQL8 collations preserved; URI case/tenant/user/source/provenance isolation and replay verified\n";
 foreach(['system_menu','tenant_system_menu'] as $table)$pdo->exec('CREATE TABLE `'.$prefix.$table.'` (id int AUTO_INCREMENT PRIMARY KEY,tenant_id int NOT NULL DEFAULT 0,pid int NOT NULL DEFAULT 0,type char(1),name varchar(100),sort int,perms varchar(150),paths varchar(150),component varchar(200),app_code varchar(50),source varchar(20),source_menu_key varchar(100),is_core int,create_time int,update_time int) ENGINE=InnoDB');
 foreach(['system_role_menu','tenant_system_role_menu'] as $table)$pdo->exec('CREATE TABLE `'.$prefix.$table.'` (id int AUTO_INCREMENT PRIMARY KEY,role_id int,menu_id int) ENGINE=InnoDB');
 $pdo->exec('CREATE TABLE `'.$prefix.'open_platform_document` (id bigint AUTO_INCREMENT PRIMARY KEY,tenant_id bigint,slug varchar(100),version int,title varchar(150),category varchar(40),status varchar(20),content MEDIUMTEXT,contract_code varchar(100),sort int,create_time int,update_time int, UNIQUE KEY tenant_slug_version(tenant_id,slug,version)) ENGINE=InnoDB');
 $pdo->beginTransaction();
 $q("INSERT INTO la_tenant_system_menu(id,tenant_id,app_code,source,source_menu_key) VALUES(10,1,'aigc_video','app','aigc_video'),(11,1,'aigc_video','app','aigc_video_config'),(12,1,'aigc_video','app','aigc_video_effects'),(13,1,'aigc_video','app','aigc_video_effects'),(14,1,'aigc_video','tenant','aigc_video_effects'),(15,1,'other','app','aigc_video_config'),(20,2,'aigc_video','app','aigc_video'),(21,2,'aigc_video','app','aigc_video_config'),(22,2,'aigc_video','app','aigc_video_effects')");
 $q('INSERT INTO la_tenant_system_role_menu(role_id,menu_id) VALUES(201,11),(201,11),(201,12),(202,21),(203,15)');
 $q("INSERT INTO la_system_menu(id,app_code,source,source_menu_key) VALUES(100,'aigc_video','app','aigc_video_platform'),(101,'aigc_video','app','aigc_video_platform_config'),(102,'aigc_video','app','aigc_video_platform_effects'),(103,'aigc_video','app','aigc_video_platform_effects'),(104,'aigc_video','platform','aigc_video_platform_effects')");
 $q('INSERT INTO la_system_role_menu(role_id,menu_id) VALUES(801,101),(801,101)');
 $menu=file_get_contents($root.'/app/apps/aigc_video/migrations/upgrade_20261012_effect_configuration_menu.sql');$run($menu);
 $tenant=$q('SELECT role_id,menu_id FROM la_tenant_system_role_menu ORDER BY role_id,menu_id,id');$platform=$q('SELECT role_id,menu_id FROM la_system_role_menu ORDER BY role_id,menu_id,id');
 releaseCheck(array_map(fn($r)=>[(int)$r['role_id'],(int)$r['menu_id']],$tenant)===[[201,11],[201,11],[201,12],[201,13],[202,21],[202,22],[203,15]],'Role copy lost duplicate effects or crossed tenant/custom scope');
 releaseCheck(array_map(fn($r)=>[(int)$r['role_id'],(int)$r['menu_id']],$platform)===[[801,101],[801,101],[801,102],[801,103]],'Platform role copy crossed custom/tenant scope');
 $run($menu);releaseCheck($q('SELECT role_id,menu_id FROM la_tenant_system_role_menu ORDER BY role_id,menu_id,id')===$tenant&&$q('SELECT role_id,menu_id FROM la_system_role_menu ORDER BY role_id,menu_id,id')===$platform,'Repeated menu migration duplicated grants');
 $guides=['20261011_open_platform_music_guide.sql','20261011_open_platform_music_guide_v2.sql','20261012_open_platform_music_guide_v3.sql'];
 $q("INSERT INTO la_open_platform_document(tenant_id,slug,version,title,status,content) VALUES(1,'open-platform-music-tools',1,'Owned tenant guide','published','Owned content')");
 foreach($guides as $name)$run(file_get_contents($root.'/app/apps/open_platform/migrations/'.$name));
 $rows=$q("SELECT version,status,content FROM la_open_platform_document WHERE tenant_id=0 AND slug='open-platform-music-tools' ORDER BY version");
 releaseCheck(array_column($rows,'status')===['archived','archived','published']&&trim($rows[2]['content'])===trim(file_get_contents($root.'/app/apps/open_platform/docs/music-tools.md')),'Guide v1/v2/v3 archive or materialized source failed');
 foreach($guides as $name)$run(file_get_contents($root.'/app/apps/open_platform/migrations/'.$name));releaseCheck($q("SELECT version,status,content FROM la_open_platform_document WHERE tenant_id=0 AND slug='open-platform-music-tools' ORDER BY version")===$rows,'Repeated guide migration rewrote rows');
 releaseCheck($q('SELECT content FROM la_open_platform_document WHERE tenant_id=1')[0]['content']==='Owned content','Tenant override changed');
 $q('DELETE FROM la_open_platform_document WHERE tenant_id=0');$q("INSERT INTO la_open_platform_document(tenant_id,slug,version,title,status,content) VALUES(0,'open-platform-music-tools',1,'Owned global guide','published','Owned global content')");
 foreach($guides as $name)$run(file_get_contents($root.'/app/apps/open_platform/migrations/'.$name));
 $owned=$q('SELECT version,status,content FROM la_open_platform_document WHERE tenant_id=0');releaseCheck(count($owned)===1&&$owned[0]['content']==='Owned global content'&&$owned[0]['status']==='published','Manual global guide changed');
 $pdo->rollBack();
 echo "PASS: real guarded DDL preserves config and uncertain journal; no-op/rejections; menu duplicate effects/role idempotency and tenant/platform isolation; guide SQL1093/materialization, versions and manual overrides\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();foreach(array_reverse($tables) as $table)$pdo->exec('DROP TABLE IF EXISTS `'.$prefix.$table.'`');}
