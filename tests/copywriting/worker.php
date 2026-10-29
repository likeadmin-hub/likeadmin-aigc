<?php
require __DIR__.'/runtime.php';
use app\common\service\app\aigc_copywriting\CopywritingWorkerService as Worker;
use app\common\service\app\aigc_copywriting\CopywritingStore as Store;
use think\facade\Db;
Db::startTrans();try{
 $tenant=(int)Db::name('tenant')->order('id')->value('id');$tool=Store::table('tool')->where('tenant_id',$tenant)->find();$page=Store::table('page')->where('tool_id',$tool['id'])->find();
 $data=['tenant_id'=>$tenant,'user_id'=>999999,'tool_id'=>$tool['id'],'version_id'=>$page['published_version_id'],'idempotency_key'=>'test_'.bin2hex(random_bytes(8)),'request_hash'=>str_repeat('a',64),'input_snapshot'=>'{}','prompt_snapshot'=>'{}','model_snapshot'=>'{}','execution_fingerprint'=>str_repeat('b',64),'status'=>'running','submission_state'=>'not_sent','lease_owner'=>'old','lease_until'=>time()-1];
 $id=Store::insert('task',$data);$jobs=Worker::claim('test-worker',90,1);check(count(array_filter($jobs,fn($r)=>$r['id']==$id))===1,'expired unsent task must be reclaimed');
 $seenBeforeDone=false;$calls=0;$worker=new Worker(function($task,$onEvent)use(&$seenBeforeDone,&$calls,$id){$calls++;$onEvent('delta',['delta'=>'第一段']);$seenBeforeDone=Store::table('task')->where('id',$id)->value('result_text')==='第一段';usleep(350000);$onEvent('delta',['delta'=>'第二段']);return ['content'=>'第一段第二段','billing'=>['billing_status'=>'deducted']];});$worker->run($id,'test-worker');check($seenBeforeDone,'first delta visible before completion');check(Store::table('task')->where('id',$id)->value('result_text')==='第一段第二段','all deltas retained');$worker->run($id,'test-worker');check($calls===1,'terminal task not regenerated');
 $sent=Store::insert('task',array_replace($data,['idempotency_key'=>'sent_'.bin2hex(random_bytes(8)),'submission_state'=>'sending']));Worker::claim('new-worker',90,1);check(Store::table('task')->where('id',$sent)->value('submission_state')==='uncertain','sent request becomes uncertain and is not resent');
 echo "copywriting worker PASS (rolled back; no upstream calls)\n";
}finally{Db::rollback();}
