<?php
declare(strict_types=1);
// Read-only database snapshot. Output must stay outside the web root and delivery repositories.
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
$destination=$argv[1]??'';
if($destination===''||!str_starts_with($destination,'/')||str_contains($destination,'/server/')||str_contains($destination,'/web/'))throw new RuntimeException('Provide an absolute private backup directory outside source repositories');
if(is_file($destination.'/manifest.json'))throw new RuntimeException('Existing backup will not be overwritten');
if(!is_dir($destination)&&!mkdir($destination,0700,true))throw new RuntimeException('Cannot create backup directory');
chmod($destination,0700);$prefix=(string)config('database.connections.mysql.prefix');$db=(string)config('database.connections.mysql.database');
$tables=Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME',[$db,$prefix.'aigc_micro_video_%']);
$manifest=['app_code'=>'aigc_micro_video','created_at'=>gmdate('c'),'database'=>$db,'files'=>[]];
$save=static function(string $name,array $rows)use($destination,&$manifest){$json=json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$file=$destination.'/'.$name.'.json';if(file_put_contents($file,$json,LOCK_EX)===false)throw new RuntimeException('Backup write failed');chmod($file,0600);$manifest['files'][$name]=['rows'=>count($rows),'sha256'=>hash('sha256',$json)];};
Db::startTrans();try{
 foreach($tables as $row){$table=$row['TABLE_NAME'];if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))throw new RuntimeException('Unexpected table name');$save($table,Db::table($table)->select()->toArray());}
 foreach(['app'=>'code','app_version'=>'app_code','app_install'=>'app_code','tenant_app'=>'app_code','app_api'=>'app_code','app_frontend_entry'=>'app_code','app_case'=>'app_code'] as $table=>$column)$save($prefix.$table,Db::name($table)->where($column,'aigc_micro_video')->select()->toArray());
 foreach(['system_menu','tenant_system_menu'] as $table)$save($prefix.$table,Db::name($table)->where(['app_code'=>'aigc_micro_video','source'=>'app'])->select()->toArray());
 $splits=Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME LIKE ?',[$db,$prefix.'tenant_system_menu_%']);foreach($splits as $row){$table=$row['TABLE_NAME'];if(preg_match('/^[a-zA-Z0-9_]+$/',$table))$save($table,Db::table($table)->where(['app_code'=>'aigc_micro_video','source'=>'app'])->select()->toArray());}
 Db::rollback();
}catch(Throwable $e){Db::rollback();throw $e;}
// Preserve permission assignments for the app-owned menus removed during retirement.
foreach(array_keys($manifest['files']) as $menu){
 if(!str_contains($menu,'system_menu'))continue;
 $rows=json_decode((string)file_get_contents($destination.'/'.$menu.'.json'),true,512,JSON_THROW_ON_ERROR);$ids=array_column($rows,'id');if(!$ids)continue;
 $role=str_replace('system_menu','system_role_menu',$menu);
 $present=Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$role,'menu_id']);
 if($present)$save($role,Db::table($role)->whereIn('menu_id',$ids)->select()->toArray());
}
file_put_contents($destination.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));chmod($destination.'/manifest.json',0600);
echo json_encode(['backup'=>$destination,'tables'=>count($manifest['files']),'rows'=>array_sum(array_column($manifest['files'],'rows'))],JSON_UNESCAPED_UNICODE).PHP_EOL;
