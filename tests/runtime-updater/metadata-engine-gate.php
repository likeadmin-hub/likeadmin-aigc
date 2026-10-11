<?php
declare(strict_types=1);
namespace think\facade {
    class Db {
        public static function query(string $sql, array $bindings=[]): array {
            if (!$bindings) return array_map(fn($name)=>['table_name'=>$name], array_keys($GLOBALS['fixture_engines']));
            $name=$bindings[0];return isset($GLOBALS['fixture_engines'][$name])?[['engine'=>$GLOBALS['fixture_engines'][$name]]]:[];
        }
    }
}
namespace {
    function config(string $name) { return 'site_'; }
    require __DIR__.'/../../app/common/service/database/RuntimeMetadataTransactionGate.php';
    use app\common\service\database\RuntimeMetadataTransactionGate as Gate;
    $tables=['app','app_version','tenant_app','app_api','app_frontend_entry','app_install','app_migration','system_menu','tenant_system_menu','system_role_menu','tenant_system_role_menu','app_plan','membership_plan','membership_plan_app','tenant_package_app_plan','recharge_package','update_package','update_task','fixture','tenant_system_menu_abc'];
    $GLOBALS['fixture_engines']=array_fill_keys(array_map(fn($t)=>'site_'.$t,$tables),'InnoDB');
    Gate::assertMetadata();Gate::assertTables(['la_fixture']);
    foreach ([['site_app'=>'MyISAM'],['site_tenant_system_menu_abc'=>'MyISAM']] as $engines) {
        $GLOBALS['fixture_engines']=$engines;
        try { Gate::assertMetadata();throw new LogicException('nontransactional metadata accepted'); } catch(RuntimeException $e) {}
    }
    $GLOBALS['fixture_engines']=[];
    try { Gate::assertMetadata();throw new LogicException('unknown metadata accepted'); } catch(RuntimeException $e) {}
    try { Gate::assertTables(['la_unknown']);throw new LogicException('unknown DML table accepted'); } catch(RuntimeException $e) {}
    echo "PASS: metadata and split-menu engines checked; unknown/non-InnoDB DML target blocked without changing engines\n";
}
