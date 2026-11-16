<?php
declare(strict_types=1);
namespace think\facade {
    class Db { public static $connection; public static function connect() { return self::$connection; } }
}
namespace {
if (getenv('SYSTEM_SERVICE_DISPOSABLE') !== '1' || !str_starts_with((string)getenv('PHP_DATABASE_DATABASE'), 'fixture_')) throw new RuntimeException('Disposable database required');
require dirname(__DIR__,2).'/app/common/service/database/RuntimeUpdateRecordCapacity.php';
require dirname(__DIR__,2).'/app/common/service/database/SqlMigrationExecutor.php';
require dirname(__DIR__,2).'/app/common/service/database/RuntimeMigrationPlan.php';
use app\common\service\database\RuntimeUpdateRecordCapacity as Capacity;
use app\common\service\database\SqlMigrationExecutor as Executor;
use app\common\service\database\RuntimeMigrationPlan as Plan;
function checkCapacity(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label); }
function config(string $key) { return 'fixture_'; }
$pdo=new PDO('mysql:host='.getenv('PHP_DATABASE_HOSTNAME').';dbname='.getenv('PHP_DATABASE_DATABASE').';charset=utf8mb4',getenv('PHP_DATABASE_USERNAME')?:'root',getenv('PHP_DATABASE_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=new class($pdo) {
    public array $ddl=[];
    public function __construct(private PDO $pdo) {}
    public function getPdo():PDO { return $this->pdo; }
    public function query(string $sql,array $bindings=[]):array { $q=$this->pdo->prepare($sql);$q->execute($bindings);return $q->fetchAll(PDO::FETCH_ASSOC); }
    public function execute(string $sql):int { $this->ddl[]=$sql;return $this->pdo->exec($sql); }
};
\think\facade\Db::$connection=$db;
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$pdo->exec('CREATE TABLE fixture_update_task (id INT PRIMARY KEY,preflight_json TEXT NULL,result_json TEXT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE fixture_update_package (id INT PRIMARY KEY,manifest_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT \'Owned metadata\') ENGINE=InnoDB');
$pdo->exec("INSERT INTO fixture_update_task VALUES (1,'original',NULL)");
$pdo->exec("INSERT INTO fixture_update_package VALUES (1,'{\"custom\":true}')");
$before=[$db->query('SELECT * FROM fixture_update_task'),$db->query('SELECT * FROM fixture_update_package')];
$payload=json_encode(['manifest'=>str_repeat('中文记录',9000)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
checkCapacity(strlen($payload)>65535,'Fixture not over TEXT byte limit');
try { $q=$pdo->prepare('UPDATE fixture_update_task SET preflight_json=? WHERE id=1');$q->execute([$payload]);throw new LogicException('Original TEXT accepted oversized preflight'); }
catch(PDOException $e){checkCapacity((string)$e->getCode()==='22001','Original failure not reproduced');}
$inspection=Capacity::all('fixture_',$db,false);
checkCapacity(array_unique(array_column($inspection,'status'))===['requires_widening'] && $db->ddl===[],'Inspection caused DDL');
$pdo->beginTransaction();
$pdo->exec("UPDATE fixture_update_task SET result_json='transaction not committed' WHERE id=1");
try { Capacity::all('fixture_',$db);throw new LogicException('DDL allowed in transaction'); }
catch(RuntimeException $e){checkCapacity($pdo->inTransaction(),'Guard implicitly committed caller transaction');}
$pdo->rollBack();
Capacity::ensure();
checkCapacity([$db->query('SELECT * FROM fixture_update_task'),$db->query('SELECT * FROM fixture_update_package')]===$before,'Repair changed existing rows');
$count=count($db->ddl);Capacity::ensure();checkCapacity(count($db->ddl)===$count,'Repeated check executed DDL');
foreach(['preflight_json','result_json'] as $column){$q=$pdo->prepare('UPDATE fixture_update_task SET '.$column.'=? WHERE id=1');$q->execute([$payload]);checkCapacity($db->query('SELECT '.$column.' FROM fixture_update_task')[0][$column]===$payload,'Large task JSON truncated');}
$q=$pdo->prepare('UPDATE fixture_update_package SET manifest_json=? WHERE id=1');$q->execute([$payload]);checkCapacity($db->query('SELECT manifest_json FROM fixture_update_package')[0]['manifest_json']===$payload,'Large manifest truncated');
$meta=$db->query("SELECT DATA_TYPE,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_update_package' AND COLUMN_NAME='manifest_json'")[0];
checkCapacity($meta===['DATA_TYPE'=>'mediumtext','COLLATION_NAME'=>'utf8mb4_bin','COLUMN_COMMENT'=>'Owned metadata'],'Charset/comment changed');
$pdo->exec('ALTER TABLE fixture_update_package MODIFY manifest_json LONGTEXT NULL');
$count=count($db->ddl);Capacity::all('fixture_',$db);checkCapacity(count($db->ddl)===$count,'LONGTEXT was downgraded');
$plan=Plan::build(file_get_contents(dirname(__DIR__,2).'/upgrade/20261116_update_record_capacity.sql'));
Executor::execute($plan['prepare'],'fixture_',$db);checkCapacity(count($db->ddl)===$count,'Idempotent SQL replay changed sufficient columns');
// Actual migration path on a second legacy schema, with a non-default prefix.
$pdo->exec('CREATE TABLE legacy_update_task LIKE fixture_update_task');
$pdo->exec('ALTER TABLE legacy_update_task MODIFY preflight_json TEXT NULL,MODIFY result_json TEXT NULL');
$pdo->exec('CREATE TABLE legacy_update_package LIKE fixture_update_package');
$pdo->exec('ALTER TABLE legacy_update_package MODIFY manifest_json TEXT NULL');
Executor::execute($plan['prepare'],'legacy_',$db);
checkCapacity(count($db->ddl)===$count+3,'Guarded executor failed to widen three legacy fields');
$pdo->exec('ALTER TABLE legacy_update_task ENGINE=MyISAM');
try{Capacity::all('legacy_',$db);throw new LogicException('Nontransactional table accepted');}catch(RuntimeException $e){}
$pdo->exec('ALTER TABLE legacy_update_task ENGINE=InnoDB');
$pdo->exec('ALTER TABLE legacy_update_task DROP COLUMN preflight_json');
try{Capacity::all('legacy_',$db);throw new LogicException('Missing field accepted');}catch(RuntimeException $e){}
echo 'PASS real MySQL TEXT overflow reproduced; '.strlen($payload)."-byte metadata retained; transaction/idempotency/legacy-prefix/schema guards passed\n";
}
