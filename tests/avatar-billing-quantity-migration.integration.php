<?php
declare(strict_types=1);
if (getenv('AVATAR_BILLING_DISPOSABLE') !== '1' || getenv('PHP_DATABASE_HOSTNAME') !== 'avatar-billing-mysql'
    || getenv('PHP_DATABASE_DATABASE') !== 'avatar_billing_test') throw new RuntimeException('Disposable avatar fixture required');
require dirname(__DIR__).'/vendor/autoload.php';
(new think\App(dirname(__DIR__)))->setBaseEnvName('avatar-disposable')->setEnvName('avatar-disposable')->initialize();
use think\facade\Db;
use app\common\service\database\RuntimeAvatarBillingQuantity as Migration;
use app\common\service\database\RuntimeMigrationPlan as Plan;
use app\common\service\database\SqlMigrationExecutor as Executor;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
set_exception_handler(static function(Throwable $e){fwrite(STDERR,(string)$e."\n");exit(1);});
$prefix='avatar_quantity_fixture_';$table=$prefix.'aigc_digital_human_billing';
$sql='ALTER TABLE `la_aigc_digital_human_billing` MODIFY COLUMN `quantity` DECIMAL(16,6) UNSIGNED';
check(Plan::build($sql)['prepare']===$sql,'Reviewed migration is not prepared');
Db::execute('DROP TABLE IF EXISTS `'.$table.'`');
try {
    Db::execute('CREATE TABLE `'.$table.'` (`id` INT PRIMARY KEY, `quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT \'historical seconds\', `amount` DECIMAL(10,2)) ENGINE=InnoDB');
    Db::execute('INSERT INTO `'.$table.'` VALUES (1,4294967295,17.23),(2,10,40)');
    Executor::execute($sql,$prefix);
    $rows=Db::query('SELECT * FROM `'.$table.'` ORDER BY id');
    check($rows[0]['quantity']==='4294967295.000000' && $rows[0]['amount']==='17.23' && $rows[1]['quantity']==='10.000000','Historical bill changed or truncated');
    $column=Db::query('SHOW FULL COLUMNS FROM `'.$table.'` LIKE \'quantity\'')[0];
    check($column['Type']==='decimal(16,6) unsigned' && $column['Null']==='NO' && (float)$column['Default']===1.0 && $column['Comment']==='historical seconds','Column semantics changed');
    Db::execute('INSERT INTO `'.$table.'` VALUES (3,2.88,11.52)');
    Executor::execute($sql,$prefix);
    check(Db::query('SELECT quantity FROM `'.$table.'` WHERE id=3')[0]['quantity']==='2.880000','Fraction lost after replay');
    check(Migration::apply($prefix)['status']==='already_sufficient','Widening replay is not a no-op');
    Db::execute('ALTER TABLE `'.$table.'` MODIFY quantity DECIMAL(20,8) UNSIGNED NULL DEFAULT NULL COMMENT \'wider nullable field\'');
    Executor::execute($sql,$prefix);
    $column=Db::query('SHOW FULL COLUMNS FROM `'.$table.'` LIKE \'quantity\'')[0];
    check($column['Type']==='decimal(20,8) unsigned' && $column['Null']==='YES' && $column['Default']===null && $column['Comment']==='wider nullable field','Sufficient decimal was narrowed or metadata changed');
    Db::execute('ALTER TABLE `'.$table.'` MODIFY quantity VARCHAR(30) NOT NULL DEFAULT \'1\'');
    $rejected=false;try{Executor::execute($sql,$prefix);}catch(RuntimeException){$rejected=true;}
    check($rejected,'Unknown type was modified');
    foreach (['ALTER TABLE la_other MODIFY quantity DECIMAL(16,6) UNSIGNED','ALTER TABLE la_aigc_digital_human_billing MODIFY amount DECIMAL(16,6) UNSIGNED'] as $unsafe) {
        $rejected=false;try{Plan::build($unsafe);}catch(RuntimeException){$rejected=true;}check($rejected,'Planner broadened MODIFY permission');
    }
    echo "PASS reviewed quantity widening, historical max integer and amounts, metadata, fractions, replay, sufficient nullable decimal no-op and unknown-type rejection\n";
} finally { Db::execute('DROP TABLE IF EXISTS `'.$table.'`'); }
