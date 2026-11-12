<?php
declare(strict_types=1);
namespace app\common\service\app;
use InvalidArgumentException;
use think\facade\Db;

/** Product retirement disables admission and discovery while keeping paid history and owned files. */
final class RetiredAppService
{
    public const CODES = ['aigc_micro_video'];
    public static function contains(string $code): bool { return in_array($code,self::CODES,true); }
    public static function assertActive(string $code): void { if(self::contains($code))throw new InvalidArgumentException('该工具已退役，历史记录已保留'); }
    public static function historyAvailable(string $suffix='task'): bool
    {
        if(!in_array($suffix,['task','segment','frame'],true))return false;
        return (bool)Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[(string)config('database.connections.mysql.prefix').'aigc_micro_video_'.$suffix]);
    }
    public static function apply(?string $prefix=null,$connection=null): array
    {
        $prefix??=(string)config('database.connections.mysql.prefix');
        if(!preg_match('/^[a-zA-Z0-9_]*$/D',$prefix))throw new InvalidArgumentException('Invalid retirement table prefix');
        $db=$connection??Db::connect();$changed=[];
        $tables=array_column($db->query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'),'name');
        $exists=static fn(string $name):bool=>in_array($prefix.$name,$tables,true);
        $db->transaction(static function()use($db,$prefix,$tables,$exists,&$changed){
            foreach(self::CODES as $code){
                if($exists('app'))$changed['app']=$db->execute('UPDATE `'.$prefix.'app` SET status=?,is_builtin=0 WHERE code=?',['removed',$code]);
                if($exists('tenant_app'))$db->execute('UPDATE `'.$prefix.'tenant_app` SET enable_status=?,shelf_status=? WHERE app_code=?',['disabled','off',$code]);
                foreach(['app_frontend_entry','app_api'] as $name)if($exists($name))$db->execute('DELETE FROM `'.$prefix.$name.'` WHERE app_code=?',[$code]);
                if($exists('app_case'))$db->execute('UPDATE `'.$prefix.'app_case` SET status=0 WHERE app_code=?',[$code]);
                // Explicit app-owned menus only. Tenant-authored menus and other apps are untouched.
                foreach($tables as $menu){
                    if(!in_array($menu,[$prefix.'system_menu',$prefix.'tenant_system_menu'],true)&&!str_starts_with($menu,$prefix.'tenant_system_menu_'))continue;
                    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$menu))continue;
                    $columns=array_column($db->query('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$menu]),'name');
                    if(!in_array('app_code',$columns,true)||!in_array('source',$columns,true))continue;
                    $ids=array_column($db->query('SELECT id FROM `'.$menu.'` WHERE app_code=? AND source=?',[$code,'app']),'id');
                    if(!$ids)continue;
                    $role=str_replace('system_menu','system_role_menu',$menu);
                    if(in_array($role,$tables,true)){
                        $roleColumns=array_column($db->query('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$role]),'name');
                        if(in_array('menu_id',$roleColumns,true))$db->execute('DELETE FROM `'.$role.'` WHERE menu_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
                    }
                    $db->execute('DELETE FROM `'.$menu.'` WHERE app_code=? AND source=?',[$code,'app']);
                }
            }
        });
        return $changed;
    }
}
