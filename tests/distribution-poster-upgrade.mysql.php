<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\database\SqlMigrationExecutor;
use app\common\service\distribution\BuiltinPosterService;

// No application .env or shared database: run in a disposable MySQL container.
$dsn=getenv('POSTER_UPGRADE_TEST_DSN')?:'';
if($dsn===''){echo "SKIP poster upgrade MySQL regression: explicit disposable DSN required\n";exit;}
if(!preg_match('/^mysql:host=127\.0\.0\.1;dbname=fixture_[a-z0-9_]+$/D',$dsn))throw new RuntimeException('Disposable MySQL DSN required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function columns(PDO $db,string $table):array{return array_column($db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC),null,'Field');}
function snapshot(PDO $db,string $table):array{return $db->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);}
$tables=[];
function fixture(PDO $db,array &$tables,string $suffix='split-blue'):string{
    $p='poster_'.bin2hex(random_bytes(5)).'_';
    $ddl=[
        'tenant'=>"(id int PRIMARY KEY,tactics int NOT NULL,sn varchar(80) NOT NULL)",
        'tenant_file'=>"(id int AUTO_INCREMENT PRIMARY KEY,tenant_id int,cid int,source_id int,source int,type int,name varchar(255),uri varchar(255),create_time int,update_time int,delete_time int NULL)",
        'distribution_poster_template'=>"(id int AUTO_INCREMENT PRIMARY KEY,tenant_id int,name varchar(255),sort int,status int,revision int,published_version int,draft_json text,create_time int,update_time int,delete_time int DEFAULT 0)",
        'distribution_poster_version'=>"(id int AUTO_INCREMENT PRIMARY KEY,tenant_id int,template_id int,revision int,name varchar(255),layout_json text,admin_id int,create_time int,UNIQUE(template_id,revision))",
    ];
    foreach($ddl as $name=>$body){$table=$p.$name;$db->exec("CREATE TABLE `{$table}` {$body} ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$tables[]=$table;}
    if($suffix==='split-blue'){
        $table=$p.'tenant_file_'.$suffix;$tables[]=$table;$db->exec("CREATE TABLE `{$table}` LIKE `{$p}tenant_file`");
        $db->exec("ALTER TABLE `{$table}` ADD storage_scope varchar(64) NOT NULL DEFAULT 'custom-platform'");
        $db->exec("INSERT INTO `{$table}` (tenant_id,name,uri,storage_scope) VALUES (2,'existing split','uploads/old.png','tenant-private')");
    }
    $q=$db->prepare("INSERT INTO `{$p}tenant` VALUES (1,0,'unrelated`shared-serial'),(2,1,?),(3,0,'three')");$q->execute([$suffix]);
    $db->exec("INSERT INTO `{$p}tenant_file` (tenant_id,name,uri) VALUES (1,'existing shared','uploads/shared.png')");
    return $p;
}
$oldMarker=(string)file_get_contents(dirname(__DIR__).'/upgrade/20261029_distribution_poster_builtins.sql');
try{
    $p=fixture($db,$tables);$base=$p.'tenant_file';$shard=$base.'_split-blue';
    $unrelated=$base.'_not_a_tenant';$tables[]=$unrelated;$db->exec("CREATE TABLE `{$unrelated}` LIKE `{$base}`");
    // This is the actual failing upgrade entry, not a schema-helper mock.
    SqlMigrationExecutor::execute($oldMarker,$p,$db);
    foreach([$base,$shard] as $table){
        $c=columns($db,$table);foreach(['storage_scope','storage_engine','storage_domain'] as $name)verify(isset($c[$name]),'legacy storage column prepared: '.$table.'.'.$name);
    }
    $shared=snapshot($db,$base)[0];$split=snapshot($db,$shard)[0];
    verify($shared['name']==='existing shared' && $shared['uri']==='uploads/shared.png' && $shared['storage_scope']==='platform' && $shared['storage_engine']==='local' && $shared['storage_domain']==='','legacy shared receipt preserved with canonical defaults');
    verify($split['name']==='existing split' && $split['storage_scope']==='tenant-private' && columns($db,$shard)['storage_scope']['Type']==='varchar(64)','existing custom storage value and column definition preserved');
    verify(!isset(columns($db,$unrelated)['storage_scope']),'unregistered lookalike table untouched');
    verify((int)$db->query("SELECT COUNT(*) FROM `{$p}distribution_poster_template`")->fetchColumn()===12,'upgrade creates four posters for each shared/split tenant');
    verify((int)$db->query("SELECT COUNT(*) FROM `{$shard}` WHERE tenant_id=2 AND storage_engine='local' AND storage_scope='platform'")->fetchColumn()===4,'split posters reference local platform files in the real split table');
    $db->exec("UPDATE `{$p}distribution_poster_template` SET draft_json='custom edit',status=0,revision=8,delete_time=123 WHERE tenant_id=2 AND builtin_key='atelier'");
    $before=[];foreach([$base,$shard,$p.'distribution_poster_template',$p.'distribution_poster_version'] as $table)$before[$table]=snapshot($db,$table);
    SqlMigrationExecutor::execute($oldMarker,$p,$db);
    foreach($before as $table=>$rows)verify(snapshot($db,$table)===$rows,'repeated upgrade preserves receipts/versions and customized disabled/deleted posters: '.$table);
    $new=dirname(__DIR__).'/upgrade/20261031_tenant_file_storage_compatibility.sql';
    verify(is_file($new) && file_get_contents($new)===file_get_contents(dirname(__DIR__).'/public/upgrade/'.basename($new)),'future system upgrade migration is present in both lifecycle paths');
    SqlMigrationExecutor::execute((string)file_get_contents($new),$p,$db);
    foreach($before as $table=>$rows)verify(snapshot($db,$table)===$rows,'new upgrade marker is also idempotent: '.$table);
    foreach(['missing','unsafe','nontransactional','poster-nontransactional','active-transaction'] as $case){
        $q=fixture($db,$tables,$case==='unsafe'?'bad`serial':($case==='missing'?'missing':'split-blue'));
        if($case==='nontransactional')$db->exec("ALTER TABLE `{$q}tenant_file_split-blue` ENGINE=MyISAM");
        if($case==='poster-nontransactional')$db->exec("ALTER TABLE `{$q}distribution_poster_version` ENGINE=MyISAM");
        if($case==='active-transaction'){$db->beginTransaction();$db->exec("INSERT INTO `{$q}tenant_file` (tenant_id,name,uri) VALUES (1,'uncommitted','pending.png')");}
        try{BuiltinPosterService::migrate($q,$db);throw new LogicException('Unsafe upgrade accepted: '.$case);}
        catch(RuntimeException $e){verify(!($e instanceof PDOException),'preflight reports '.$case.' before a raw SQL failure');if($case==='active-transaction')verify($db->inTransaction(),'rejection leaves the caller transaction open');}
        finally{if($db->inTransaction())$db->rollBack();}
        verify(!isset(columns($db,$q.'tenant_file')['storage_scope']) && !isset(columns($db,$q.'distribution_poster_template')['builtin_key']),'all targets preflighted before any DDL: '.$case);
        verify(count(snapshot($db,$q.'tenant_file'))===1 && snapshot($db,$q.'distribution_poster_template')===[],'preflight failure preserves business rows: '.$case);
    }
    foreach(['public/install/db/like.sql'=>'la_tenant_file','app/platformapi/db/tenant.sql'=>'la_tenant_file_{tenantSn}'] as $file=>$table){
        $source=(string)file_get_contents(dirname(__DIR__).'/'.$file);
        preg_match('/CREATE TABLE `'.preg_quote($table,'/').'`\s*\((.*?)\) ENGINE/s',$source,$match);
        verify(isset($match[1]),'canonical file schema found: '.$file);
        foreach(['storage_scope'=>['20','platform'],'storage_engine'=>['30','local'],'storage_domain'=>['255','']] as $field=>[$size,$default])verify((bool)preg_match('/`'.$field.'`\s+varchar\('.$size.'\)[^\n]*DEFAULT\s+\x27'.preg_quote($default,'/').'\x27/i',$match[1]),'upgrade defaults match install/new-tenant schema: '.$field.' in '.$file);
    }
    echo "PASS builtin poster upgrade legacy schema regression\n";
}finally{if($db->inTransaction())$db->rollBack();foreach(array_reverse($tables) as $table)$db->exec('DROP TABLE IF EXISTS `'.$table.'`');}
