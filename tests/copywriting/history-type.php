<?php
// Local DB fixtures only; the transaction always rolls back and never calls a model.
require __DIR__.'/contract.php';
use think\facade\Db;
use app\common\service\app\aigc_copywriting\CopywritingStore as Store;
use app\common\service\app\aigc_copywriting\CopywritingTaskService as Tasks;
$app=new think\App();$app->initialize();
check(in_array(config('database.connections.mysql.hostname'),['127.0.0.1','localhost'],true),'Local database required');
$version=Store::table('version')->order('id')->find();check((bool)$version,'Published version required');
$tenant=(int)$version['tenant_id'];$user=987654329;
Db::startTrans();
try {
 foreach([[101,21,$user,$tenant,0],[102,3,$user,$tenant,0],[101,2,$user+1,$tenant,0],[101,2,$user,$tenant+99999,0],[101,2,$user,$tenant,123]] as [$tool,$count,$owner,$scope,$deleted])for($i=0;$i<$count;$i++)Store::insert('task',['tenant_id'=>$scope,'user_id'=>$owner,'tool_id'=>$tool,'version_id'=>$version['id'],'idempotency_key'=>'type-history-'.bin2hex(random_bytes(12)),'request_hash'=>Tasks::requestHash([]),'input_snapshot'=>'{}','prompt_snapshot'=>'{}','model_snapshot'=>'{}','execution_fingerprint'=>str_repeat('a',64),'status'=>'success','billing_state'=>'settled','result_text'=>'分类作品回归正文','delete_time'=>$deleted]);
 $first=Tasks::lists($tenant,$user,1,false,101);$second=Tasks::lists($tenant,$user,2,false,101);
 check($first['count']===21 && count($first['lists'])===20,'Filtered first page and count');
 check($second['count']===21 && count($second['lists'])===1,'Filtered second page');
 check(Tasks::lists($tenant,$user,1,false,102)['count']===3,'Different tool has independent count');
 check(Tasks::lists($tenant,$user)['count']===24,'Unfiltered caller remains compatible');
 foreach($first['lists'] as $row)check((int)$row['tool_id']===101,'No other type leaks');
 echo "copywriting history type filtering PASS\n";
} finally { Db::rollback(); }
