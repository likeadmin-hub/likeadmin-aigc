<?php
declare(strict_types=1);
require __DIR__.'/../../app/common/service/database/SqlMigrationExecutor.php';
require __DIR__.'/../../app/common/service/database/RuntimeMigrationPlan.php';
require __DIR__.'/../../app/common/service/database/RuntimeAppSalePriceInheritance.php';
use app\common\service\database\RuntimeMigrationPlan as Plan;
use app\common\service\database\RuntimeAppSalePriceInheritance as Price;
use app\common\service\database\SqlMigrationExecutor as Executor;
function priceCheck(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function priceReject(callable $call): void {
    try { $call(); } catch (RuntimeException $error) { return; }
    throw new LogicException('Unsupported SQL/schema was accepted');
}
$root = dirname(__DIR__, 2);
$apps = ['aigc_local_redraw','aigc_one_click_cleanup','aigc_product_suite','aigc_product_multi_angle','aigc_fashion_lookbook'];
$column = ['column_name'=>'unit_price','data_type'=>'decimal','column_type'=>'decimal(18,6) unsigned',
    'is_nullable'=>'NO','column_default'=>'0.000000','character_set_name'=>null,'collation_name'=>null,
    'column_comment'=>"Owned 'price' \\ comment",'extra'=>''];
foreach ($apps as $app) {
    $table = $app.'_config';
    $name = 'zz_20261012_'.$app.'_inherit_market_price.sql';
    $source = file_get_contents($root.'/app/apps/'.$app.'/migrations/'.$name);
    priceCheck(file_get_contents($root.'/upgrade/'.$name) === $source && file_get_contents($root.'/public/upgrade/'.$name) === $source, 'SQL mirrors differ');
    $plan = Plan::build($source);
    priceCheck($plan['complete'] === '' && count(Executor::split($plan['prepare'])) === 1 && $plan['tables'] === [], 'Nullable DDL crossed final transaction boundary');
    $sql = Price::columnSql($table, ['engine'=>'InnoDB'], $column, 'fixture_');
    priceCheck(str_contains($sql, 'decimal(18,6) unsigned NULL DEFAULT NULL') && str_contains($sql, '`fixture_'.$table.'`'), 'Original precision/scale/sign changed');
    priceCheck(str_contains($sql, "COMMENT 'Owned ''price'' \\\\ comment'"), 'Original comment changed');
    priceCheck(str_contains(Price::columnSql($table, ['engine'=>'InnoDB'], $column, '', true), "COMMENT 'Owned ''price'' \\ comment'"), 'Unprefixed/NO_BACKSLASH_ESCAPES semantics changed');
    priceCheck(Price::columnSql($table, ['engine'=>'InnoDB'], array_replace($column, ['is_nullable'=>'YES', 'column_default'=>null]), 'fixture_') === null, 'Completed schema must be no-op');
    priceCheck(Price::columnSql($table, ['engine'=>'InnoDB'], array_replace($column, ['is_nullable'=>'YES']), 'fixture_') !== null, 'Nullable column with old default was falsely adopted');
}
foreach ([['data_type'=>'float','column_type'=>'float'], ['column_type'=>'decimal(66,2)'], ['column_type'=>'decimal(12,13)'],
    ['column_type'=>'decimal(12,31)'], ['column_type'=>'decimal(12,2) zerofill'], ['column_name'=>'other'],
    ['extra'=>'STORED GENERATED'], ['extra'=>'DEFAULT_GENERATED'], ['column_default'=>'expression()'],
    ['is_nullable'=>'unknown'], ['column_comment'=>null], ['character_set_name'=>'utf8mb4']] as $bad) {
    priceReject(fn()=>Price::columnSql('aigc_local_redraw_config', ['engine'=>'InnoDB'], array_replace($column, $bad), 'fixture_'));
}
priceReject(fn()=>Price::columnSql('aigc_local_redraw_config', ['engine'=>'MyISAM'], $column, 'fixture_'));
priceReject(fn()=>Price::columnSql('other_config', ['engine'=>'InnoDB'], $column, 'fixture_'));
priceReject(fn()=>Price::columnSql('aigc_local_redraw_config', ['engine'=>'InnoDB'], $column, 'bad`;DROP'));
foreach ([
    "ALTER TABLE la_other_config MODIFY COLUMN unit_price decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费'",
    "ALTER TABLE la_aigc_local_redraw_config MODIFY COLUMN other decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费'",
    'ALTER TABLE la_aigc_local_redraw_config MODIFY COLUMN unit_price decimal(12,2) NULL DEFAULT NULL',
    "ALTER TABLE la_aigc_local_redraw_config MODIFY COLUMN unit_price decimal(10,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费'",
    'ALTER TABLE la_aigc_local_redraw_config ADD COLUMN x INT, MODIFY unit_price INT',
    'INSERT INTO la_tenant_app SELECT * FROM la_tenant JOIN la_app ON 1=1',
    'SET @sql=1; EXECUTE sql',
] as $bad) priceReject(fn()=>Plan::build($bad));
$missing = new class {
    public function query(string $sql, array $bindings=[]): array { return []; }
    public function execute(string $sql): int { throw new LogicException('Missing table caused DDL'); }
};
priceReject(fn()=>Price::apply('aigc_local_redraw_config', 'fixture_', $missing));
$connection = new class($column) {
    public array $executed = [];
    public function __construct(public array $column) {}
    public function query(string $sql, array $bindings=[]): array {
        if (str_contains($sql, 'information_schema.TABLES')) return [['engine'=>'InnoDB']];
        if (str_contains($sql, 'information_schema.COLUMNS')) return [$this->column];
        return [['sql_mode'=>'STRICT_TRANS_TABLES']];
    }
    public function execute(string $sql): int {
        $this->executed[]=$sql;
        $this->column['is_nullable']='YES'; $this->column['column_default']=null;
        return 0;
    }
};
$source = file_get_contents($root.'/upgrade/zz_20261012_aigc_local_redraw_inherit_market_price.sql');
Executor::execute($source, 'fixture_', $connection);
Executor::execute($source, 'fixture_', $connection);
priceCheck(count($connection->executed) === 1 && str_contains($connection->executed[0], 'decimal(18,6) unsigned'), 'Executor replayed ALTER or imposed the marker precision');
foreach (['aigc_image'=>'zz_20261012_aigc_image_retire_channel_price.sql', 'aigc_pic_lipsync'=>'zzz_20261012_aigc_pic_lipsync_builtin.sql'] as $app=>$name) {
    $source=file_get_contents($root.'/app/apps/'.$app.'/migrations/'.$name);
    priceCheck($source === file_get_contents($root.'/upgrade/'.$name) && $source === file_get_contents($root.'/public/upgrade/'.$name), 'Metadata mirrors differ');
    $plan=Plan::build($source);
    priceCheck($plan['prepare'] === '' && $plan['complete'] !== '', 'Metadata DML crossed preparation boundary');
    if ($app==='aigc_pic_lipsync') priceCheck(strcmp($name, 'zz_default_plan.sql') > 0 && $plan['tables'] === ['la_app','la_tenant_app','la_app_plan'], 'Builtin plan order/write targets changed');
}
echo "PASS: five exact nullable-price plans; preserved DECIMAL/default/comment semantics; true replay no-op; unsafe schema/SQL rejection; unique exact mirrors; builtin metadata ordering\n";
