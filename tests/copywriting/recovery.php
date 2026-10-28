<?php
require __DIR__.'/lifecycle.php';
use think\facade\Db;
use app\common\service\app\aigc_copywriting\CopywritingStore as Store;
use app\common\service\app\aigc_copywriting\CopywritingReceipt as Receipt;
use app\common\service\app\aigc_copywriting\CopywritingRecovery as Recovery;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
Db::startTrans();$receiptPath='';
try {
    $tool=Store::table('tool')->where('tenant_id',$tenant)->find();$page=Store::table('page')->where('tool_id',$tool['id'])->find();
    $user=(int)Db::name('user')->where('tenant_id',$tenant)->value('id');
    $model=Runtime::resolveModel($tenant,'175');
    $id=Store::insert('task',['tenant_id'=>$tenant,'user_id'=>$user,'tool_id'=>$tool['id'],'version_id'=>$page['published_version_id'],'idempotency_key'=>'recovery_'.bin2hex(random_bytes(8)),'request_hash'=>str_repeat('a',64),'input_snapshot'=>'{}','prompt_snapshot'=>'{}','model_snapshot'=>'{}','execution_fingerprint'=>str_repeat('b',64),'status'=>'running','submission_state'=>'uncertain']);
    $make=new ReflectionMethod(Runtime::class,'createConsumption');$make->setAccessible(true);
    $ctx=$make->invoke(null,$tenant,$user,$model,'copywriting_generate',[],['tenant_cost_points'=>0,'user_charge_points'=>0],0,'aigc_copywriting','aigc_copywriting_task',$id);
    $snapshot=(array)$ctx['consumption']['price_snapshot'];foreach(['input','output']as $direction){$snapshot[$direction]['platform_price']=0;$snapshot[$direction]['tenant_price']=0;}$ctx['consumption']->save(['price_snapshot'=>$snapshot]);
    $cid=(int)$ctx['consumption']['id'];Store::table('task')->where('id',$id)->update(['consumption_id'=>$cid,'app_task_id'=>$ctx['appTask']['id']]);
    $before=Db::name('user')->where('id',$user)->value('user_money');
    Receipt::record($ctx['consumption']->toArray(),$ctx['appTask']->toArray(),['content'=>'可信回执的完整正文','provider_request_id'=>'test-request','usage'=>['prompt_tokens'=>10,'completion_tokens'=>10,'total_tokens'=>20]],true);
    $receiptPath=runtime_path().'copywriting-receipts/'.$cid.'.receipt';
    $recovered=Recovery::reconcile($tenant,$id);check($recovered['status']==='success','complete receipt closes interrupted task');check($recovered['result_text']==='可信回执的完整正文','receipt restores unflushed final text');check($recovered['billing_state']==='settled','actual usage settled from pinned snapshot');
    Recovery::reconcile($tenant,$id);check(Db::name('user')->where('id',$user)->value('user_money')===$before,'recovery is idempotent; zero-cost fixture does not debit');
    $menus=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_copywriting/menus/tenant.json'),true);$perms=[];$walk=function($nodes)use(&$walk,&$perms){foreach($nodes as $n){$perms[]=$n['perms']??'';$walk($n['children']??[]);}};$walk($menus);
    foreach(json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_copywriting/permissions/tenant.json'),true)as $r)check(in_array($r['api_path'],$perms,true),'action must register in tenant authorization: '.$r['api_path']);
    echo "copywriting receipt recovery and permission registry PASS (rolled back; no provider calls)\n";
} finally {Db::rollback();if($receiptPath&&is_file($receiptPath))unlink($receiptPath);}
