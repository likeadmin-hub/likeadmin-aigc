<?php
// Local DB test: fixtures and delete operations are rolled back, no provider requests.
require __DIR__.'/contract.php';
use think\facade\Db;
use app\common\service\app\aigc_copywriting\CopywritingStore as Store;
use app\common\service\app\aigc_copywriting\CopywritingTaskService as Tasks;
$app=new think\App();$app->initialize();
check(in_array(config('database.connections.mysql.hostname'),['127.0.0.1','localhost'],true),'Local database required');
$version=Store::table('version')->find();check((bool)$version,'published version fixture required');
$tenant=(int)$version['tenant_id'];$user=987654321;
Db::startTrans();
try {
 $key='delete-test-'.bin2hex(random_bytes(12));
 $id=Store::insert('task',['tenant_id'=>$tenant,'user_id'=>$user,'tool_id'=>0,'version_id'=>$version['id'],'idempotency_key'=>$key,'request_hash'=>Tasks::requestHash([]),'input_snapshot'=>'{}','prompt_snapshot'=>'{}','model_snapshot'=>'{}','execution_fingerprint'=>str_repeat('a',64),'status'=>'success','billing_state'=>'settled','result_text'=>'删除回归测试正文']);
 $before=Store::table('task')->where('id',$id)->find();
 foreach([[$tenant+99999,$user],[$tenant,$user+1]] as [$wrongTenant,$wrongUser]){
  try{Tasks::delete($wrongTenant,$wrongUser,$id);throw new RuntimeException('unauthorized deletion accepted');}catch(InvalidArgumentException $e){}
 }
 check(Tasks::lists($tenant,$user)['count']===1,'fixture visible before delete');
 Tasks::delete($tenant,$user,$id);Tasks::delete($tenant,$user,$id);
 $after=Store::table('task')->where('id',$id)->find();check($after['delete_time']>0,'soft deletion recorded');
 foreach(['result_text','status','billing_state','idempotency_key','prompt_snapshot','model_snapshot','consumption_id','app_task_id'] as $field)check($after[$field]===$before[$field],'evidence changed: '.$field);
 check(Tasks::lists($tenant,$user)['count']===0,'deleted history hidden');
 check(Store::owned('task',$tenant,$id)['id']==$id,'admin evidence retained');
 check(Store::table('audit')->where(['tenant_id'=>$tenant,'target_id'=>$id,'action'=>'user_delete'])->count()===1,'delete audit is idempotent');
 try{Tasks::detail($tenant,$user,$id);throw new RuntimeException('deleted detail visible');}catch(InvalidArgumentException $e){}
 try{Tasks::create($tenant,$user,['idempotency_key'=>$key]);throw new RuntimeException('deleted request replayed');}catch(InvalidArgumentException $e){check($e->getMessage()==='任务已删除，请重新创作','deleted replay reason');}
 foreach(['pending','running'] as $status){
  Store::table('task')->where('id',$id)->update(['status'=>$status,'delete_time'=>0]);
  try{Tasks::delete($tenant,$user,$id);throw new RuntimeException('active task deleted');}catch(InvalidArgumentException $e){}
  check((int)Store::table('task')->where('id',$id)->value('delete_time')===0,'active task unchanged');
 }
 foreach(['failed','canceled'] as $status){
  Store::table('task')->where('id',$id)->update(['status'=>$status,'delete_time'=>0]);
  Tasks::delete($tenant,$user,$id);check(Store::table('task')->where('id',$id)->value('delete_time')>0,'terminal task deletable');
 }
 echo "copywriting deletion PASS (rolled back)\n";
} finally {Db::rollback();}
