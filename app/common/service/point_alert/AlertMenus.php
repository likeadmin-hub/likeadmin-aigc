<?php
namespace app\common\service\point_alert;
use think\facade\Db;

/** Menu DML only. Existing custom menus are preserved. */
final class AlertMenus
{
 public static function sync(int $tenant=0,bool $platform=false):void
 {
  $q=$platform?Db::name('system_menu'):AlertStore::scoped('tenant_system_menu',$tenant);
  $table=$q->getTable();$scope=$platform?[]:['tenant_id'=>$tenant];
  $notice=Db::table($table)->where($scope)->where('perms','notice.notice/settingLists')->find();
  if(!$notice)return;
  self::seed($table,$scope,(int)$notice['pid']);
 }
 private static function seed(string $table,array $scope,int $parent):void
 {
  $q=fn()=>Db::table($table)->where($scope);
  $existing=$q()->where('perms','notice.point_alert/rules')->find();
  if($existing)$id=$existing['id'];else $id=Db::table($table)->insertGetId($scope+self::row($parent,'C','点数预警','notice.point_alert/rules','point-alert','message/point_alert/index',1));
  $actions=['save'=>'保存规则','records'=>'查看记录','detail'=>'查看详情','preview'=>'检查预览','check'=>'执行检查'];
  if($scope)$actions+=['receiving'=>'接收设置','saveReceiving'=>'保存接收设置','readReceiving'=>'预警已读'];
  foreach($actions as $action=>$name){$perms='notice.point_alert/'.$action;if(!$q()->where('perms',$perms)->find())Db::table($table)->insert($scope+self::row((int)$id,'A',$name,$perms));}
 }
 private static function row(int $pid,string $type,string $name,string $perms,string $path='',string $component='',int $show=0):array
 {
  return ['pid'=>$pid,'type'=>$type,'name'=>$name,'icon'=>$type==='C'?'el-icon-Bell':'','sort'=>5,'perms'=>$perms,'paths'=>$path,'component'=>$component,'selected'=>'','params'=>'','is_cache'=>0,'is_show'=>$show,'is_disable'=>0,'create_time'=>time(),'update_time'=>time()];
 }
 public static function all():void
 {
  self::sync(0,true);
  foreach(Db::name('tenant')->whereNull('delete_time')->column('id') as $tenant)self::sync((int)$tenant);
 }
}
