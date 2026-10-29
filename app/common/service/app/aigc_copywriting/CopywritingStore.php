<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;
use think\facade\Db;
final class CopywritingStore {
 public static function table(string $name){return Db::name('aigc_copywriting_'.$name);}
 public static function json($v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 public static function decode($v):array {return is_array($v)?$v:(json_decode((string)$v,true)?:[]);}
 public static function owned(string $table,int $tenantId,int $id):array {$q=self::table($table)->where(['tenant_id'=>$tenantId,'id'=>$id]);if($table==='tool')$q->where('delete_time',0);$row=$q->find();if(!$row)throw new \InvalidArgumentException('记录不存在或无权访问');return $row;}
 public static function insert(string $table,array $data):int{return (int)self::table($table)->insertGetId($data+['create_time'=>time(),'update_time'=>time()]);}
 public static function audit(int $tenantId,int $actorId,string $action,string $type,int $id):void{self::insert('audit',['tenant_id'=>$tenantId,'actor_id'=>$actorId,'action'=>$action,'target_type'=>$type,'target_id'=>$id]);}
}
