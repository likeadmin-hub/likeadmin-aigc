<?php
declare(strict_types=1);
// Dedicated MySQL only: no project environment or live application database is loaded.
if (getenv('SYSTEM_SERVICE_DISPOSABLE') !== '1' || !str_starts_with((string)getenv('PHP_DATABASE_DATABASE'), 'fixture_')) {
    throw new RuntimeException('Disposable fixture database required');
}
require __DIR__.'/../../app/common/service/database/SqlMigrationExecutor.php';
require __DIR__.'/../../app/common/service/database/RuntimeMigrationPlan.php';
require __DIR__.'/../../app/common/service/database/RuntimeAppSalePriceInheritance.php';
use app\common\service\database\RuntimeAppSalePriceInheritance as Price;
use app\common\service\database\SqlMigrationExecutor as Executor;
use app\common\service\database\RuntimeMigrationPlan as Plan;
function check393(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function reject393(callable $call): void {
    try { $call(); } catch (RuntimeException $error) { return; }
    throw new LogicException('Unsafe schema was accepted');
}
$pdo = new PDO('mysql:host='.getenv('PHP_DATABASE_HOSTNAME').';dbname='.getenv('PHP_DATABASE_DATABASE').';charset=utf8mb4',
    getenv('PHP_DATABASE_USERNAME') ?: 'root', getenv('PHP_DATABASE_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$connection = new class($pdo) {
    public array $executed=[];
    public function __construct(private PDO $pdo) {}
    public function query(string $sql, array $bindings=[]): array { $q=$this->pdo->prepare($sql); $q->execute($bindings); return $q->fetchAll(PDO::FETCH_ASSOC); }
    public function execute(string $sql): int {
        $this->executed[]=$sql;
        if (preg_match('/^\s*(?:SELECT|EXECUTE)\b/i', $sql)) {
            $q=$this->pdo->query($sql); $count=$q->rowCount();
            if ($q->columnCount() > 0) $q->fetchAll();
            $q->closeCursor(); return $count;
        }
        return $this->pdo->exec($sql);
    }
};
$prefix='release393_'.bin2hex(random_bytes(6)).'_';
$apps=['aigc_local_redraw','aigc_one_click_cleanup','aigc_product_suite','aigc_product_multi_angle','aigc_fashion_lookbook'];
$root=dirname(__DIR__,2);
try {
    reject393(fn()=>Price::apply('aigc_local_redraw_config', $prefix, $connection));
    $pdo->exec('CREATE TABLE `'.$prefix.'aigc_image_task` (id int PRIMARY KEY,reference_images text,provider_params_json text) ENGINE=InnoDB');
    foreach ($apps as $app) {
        // Genuine historical install, followed by the new app-owned prepare SQL.
        Executor::execute(file_get_contents($root.'/app/apps/'.$app.'/migrations/install.sql'), $prefix, $connection);
        $table=$prefix.$app.'_config';
        $pdo->exec("INSERT INTO `$table` (tenant_id,unit_price) VALUES (1,0),(2,8.50),(3,-1.25)");
        $before=$connection->query("SELECT tenant_id,unit_price FROM `$table` ORDER BY tenant_id");
        $source=file_get_contents($root.'/app/apps/'.$app.'/migrations/zz_20261012_'.$app.'_inherit_market_price.sql');
        $plan=Plan::build($source);
        Executor::execute($plan['prepare'], $prefix, $connection);
        check393($connection->query("SELECT tenant_id,unit_price FROM `$table` ORDER BY tenant_id") === $before, 'Historical numeric/zero prices changed');
        $count=count($connection->executed);
        Executor::execute($plan['prepare'], $prefix, $connection);
        check393(count($connection->executed) === $count, 'Completed nullable migration repeated ALTER');
        $pdo->exec("INSERT INTO `$table` (tenant_id) VALUES (4)");
        check393($connection->query("SELECT unit_price FROM `$table` WHERE tenant_id=4")[0]['unit_price'] === null, 'Fresh app default does not inherit');
    }
    $table=$prefix.'aigc_local_redraw_config';
    $pdo->exec("DROP TABLE `$table`");
    $pdo->exec("CREATE TABLE `$table` (id int PRIMARY KEY,unit_price DECIMAL(18,6) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owned decimal') ENGINE=InnoDB");
    $pdo->exec("INSERT INTO `$table` VALUES (1,987654321098.123456),(2,0)");
    $original=$connection->query("SELECT * FROM `$table` ORDER BY id");
    Price::apply('aigc_local_redraw_config', $prefix, $connection);
    check393($connection->query("SELECT * FROM `$table` ORDER BY id") === $original, 'Custom DECIMAL precision/scale/amount changed');
    $meta=$connection->query('SELECT COLUMN_TYPE,COLUMN_COMMENT,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table,'unit_price'])[0];
    check393($meta === ['COLUMN_TYPE'=>'decimal(18,6) unsigned','COLUMN_COMMENT'=>'Owned decimal','IS_NULLABLE'=>'YES','COLUMN_DEFAULT'=>null], 'Custom type/comment or nullable/default changed');
    $count=count($connection->executed);
    Price::apply('aigc_local_redraw_config', $prefix, $connection);
    check393(count($connection->executed) === $count, 'Custom completed schema repeated ALTER');
    $pdo->exec("ALTER TABLE `$table` ENGINE=MyISAM");
    reject393(fn()=>Price::apply('aigc_local_redraw_config', $prefix, $connection));
    $pdo->exec("ALTER TABLE `$table` ENGINE=InnoDB");
    $pdo->exec("ALTER TABLE `$table` MODIFY unit_price DOUBLE");
    reject393(fn()=>Price::apply('aigc_local_redraw_config', $prefix, $connection));
    $pdo->exec("ALTER TABLE `$table` DROP COLUMN unit_price");
    reject393(fn()=>Price::apply('aigc_local_redraw_config', $prefix, $connection));

    // Builtin conversion: finalization DML is transactional and preserves other apps/tenant content.
    foreach ([
        'app'=>'id int AUTO_INCREMENT PRIMARY KEY,code varchar(60) UNIQUE,name varchar(120),icon varchar(255),description text,category varchar(30),cover varchar(255),client_tags varchar(255),is_builtin int,sort int,current_version varchar(30),status varchar(30),expire_policy varchar(30),install_time int,update_time int',
        'tenant'=>'id int PRIMARY KEY',
        'tenant_app'=>'tenant_id int,app_code varchar(60),version varchar(30),buy_status varchar(30),shelf_status varchar(30),enable_status varchar(30),expire_time int,create_time int,update_time int, UNIQUE KEY owner(tenant_id,app_code)',
        'app_plan'=>'id int PRIMARY KEY,app_code varchar(60),status int',
    ] as $name=>$fields) $pdo->exec('CREATE TABLE `'.$prefix.$name.'` ('.$fields.') ENGINE=InnoDB');
    $pdo->exec('INSERT INTO `'.$prefix.'tenant` VALUES (1),(2)');
    $pdo->exec("INSERT INTO `{$prefix}app_plan` VALUES (1,'aigc_pic_lipsync',1),(2,'other',1)");
    $pdo->exec("INSERT INTO `{$prefix}tenant_app` VALUES(1,'aigc_pic_lipsync','1.0.3','paid','off','disabled',123,1,1),(1,'other','1','paid','off','disabled',99,1,1)");
    $other=$connection->query("SELECT * FROM `{$prefix}tenant_app` WHERE app_code='other'");
    $source=file_get_contents($root.'/app/apps/aigc_pic_lipsync/migrations/zzz_20261012_aigc_pic_lipsync_builtin.sql');
    $plan=Plan::build($source);
    check393($plan['prepare'] === '', 'Builtin DML was assigned to prepare phase');
    $pdo->beginTransaction();
    Executor::execute($plan['complete'], $prefix, $connection);
    $pdo->rollBack();
    check393($connection->query("SELECT * FROM `{$prefix}app`") === [], 'Builtin metadata escaped rollback');
    $pdo->beginTransaction();
    Executor::execute($plan['complete'], $prefix, $connection);
    $before=$connection->query("SELECT tenant_id,app_code,version,buy_status,shelf_status,enable_status,expire_time FROM `{$prefix}tenant_app` ORDER BY tenant_id,app_code");
    Executor::execute($plan['complete'], $prefix, $connection);
    check393($connection->query("SELECT tenant_id,app_code,version,buy_status,shelf_status,enable_status,expire_time FROM `{$prefix}tenant_app` ORDER BY tenant_id,app_code") === $before, 'Builtin replay duplicated or changed tenant state');
    $rows=$connection->query("SELECT * FROM `{$prefix}tenant_app` WHERE app_code='aigc_pic_lipsync' ORDER BY tenant_id");
    check393(count($rows) === 3 && array_column($rows,'shelf_status') === ['on','on','on'] && array_column($rows,'enable_status') === ['enabled','enabled','enabled'], 'Builtin default access missed tenants');
    check393($connection->query("SELECT * FROM `{$prefix}tenant_app` WHERE app_code='other'") === $other
        && array_column($connection->query("SELECT status FROM `{$prefix}app_plan` ORDER BY id"),'status') === [0,1], 'Other app/content/plan was changed');
    $pdo->rollBack();
    echo "PASS: genuine five-app installs gain NULL defaults with all amounts retained; custom DECIMAL preserved; repeats perform no ALTER; missing/unsupported/MyISAM rejected; builtin DML rollback/replay/default-tenant isolation\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $tables=$connection->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,?)=?', [strlen($prefix),$prefix]);
    foreach ($tables as $table) $pdo->exec('DROP TABLE `'.$table['TABLE_NAME'].'`');
}
