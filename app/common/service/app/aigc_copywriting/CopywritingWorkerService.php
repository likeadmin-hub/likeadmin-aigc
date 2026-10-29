<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;
use think\facade\Db;
use app\common\service\power\TextSettlementPending;
final class CopywritingWorkerService
{
    private $generate;
    public function __construct(?callable $generate=null){$this->generate=$generate??[CopywritingTextRuntime::class,'generate'];}
    public static function claim(string $owner,int $leaseSeconds=90,int $limit=1): array
    {
        // A sent request is never reclaimed: expired leases become explicitly uncertain.
        CopywritingStore::table('task')->where(['status'=>'running','submission_state'=>'not_sent'])->where('lease_until','<',time())->where('lease_until','>',0)->update(['status'=>'pending','lease_owner'=>'','lease_until'=>0]);
        CopywritingStore::table('task')->where('status','running')->where('submission_state','<>','not_sent')->where('lease_until','<',time())->where('lease_until','>',0)->update(['submission_state'=>'uncertain','lease_until'=>0,'error'=>'执行连接中断，正在核对原任务，请勿重复提交']);
        return Db::transaction(function()use($owner,$leaseSeconds,$limit){$rows=CopywritingStore::table('task')->where(['status'=>'pending','submission_state'=>'not_sent'])->where('cancel_requested',0)->order('id')->limit(1)->lock(true)->select()->toArray();foreach($rows as &$row){$changes=['status'=>'running','lease_owner'=>$owner,'lease_until'=>time()+$leaseSeconds,'started_at'=>time()];CopywritingStore::table('task')->where('id',$row['id'])->update($changes);$row=array_replace($row,$changes);}return $rows;});
    }
    public function run(int $id,string $owner): void
    {
        $task=CopywritingStore::table('task')->where(['id'=>$id,'lease_owner'=>$owner,'status'=>'running'])->find();if(!$task||$task['submission_state']!=='not_sent')return;
        $buffer='';$lastFlush=0.0;$lastHeartbeat=0.0;
        $flush=function(bool $force=false)use($id,&$buffer,&$lastFlush){if($force||microtime(true)-$lastFlush>=0.3){CopywritingEventService::save($id,['result_text'=>$buffer]);$lastFlush=microtime(true);}};
        try{
            $tool=CopywritingStore::owned('tool',(int)$task['tenant_id'],(int)$task['tool_id']);if(!$tool['status'])throw new \InvalidArgumentException('功能已下架');
            CopywritingStore::table('task')->where('id',$id)->update(['submission_state'=>'sending']);
            $result=($this->generate)($task,function($event,$data)use($id,&$buffer,&$lastFlush,&$lastHeartbeat,$flush){
                if(microtime(true)-$lastHeartbeat>1){$row=CopywritingStore::table('task')->where('id',$id)->field('cancel_requested')->find();if(!empty($row['cancel_requested']))throw new \RuntimeException('任务已取消');CopywritingStore::table('task')->where('id',$id)->update(['lease_until'=>time()+90]);$lastHeartbeat=microtime(true);}
                if($event==='app_task')CopywritingStore::table('task')->where('id',$id)->update(['app_task_id'=>(int)($data['app_task_id']??0),'consumption_id'=>(int)($data['consumption_id']??0),'submission_state'=>'accepted']);
                if($event==='provider_request')CopywritingStore::table('task')->where('id',$id)->update(['provider_request_id'=>(string)($data['provider_request_id']??'')]);
                if($event==='delta'){$first=$buffer==='';$buffer.=(string)($data['delta']??'');$flush($first||mb_strlen($buffer)%512<32);}elseif($event==='heartbeat')$flush();
            });
            $buffer=(string)($result['content']??$result['text']??$buffer);$flush(true);
            CopywritingEventService::save($id,['status'=>'success','submission_state'=>'finished','billing_state'=>in_array($result['billing']['billing_status']??'',['settled','deducted'],true)?'settled':'pending_usage','result_text'=>$buffer,'finished_at'=>time(),'lease_until'=>0]);
        }catch(TextSettlementPending $e){$isFinal=($e->result['content_source']??'content')==='content';if($isFinal)$buffer=(string)($e->result['content']??$buffer);$flush(true);$cancel=(bool)CopywritingStore::table('task')->where('id',$id)->value('cancel_requested');CopywritingEventService::save($id,['result_text'=>$buffer,'status'=>$cancel?'canceled':($isFinal&&!empty($e->result['finish_reason'])&&$e->result['finish_reason']==='stop'?'success':'failed'),'billing_state'=>'pending_usage','submission_state'=>'finished','error'=>$e->getMessage(),'finished_at'=>time(),'lease_until'=>0]);
        }catch(\Throwable $e){$flush(true);$row=CopywritingStore::table('task')->where('id',$id)->find();$ambiguous=$row['consumption_id']>0&&preg_match('/timeout|超时|连接|cURL|connection|任务已取消/i',$e->getMessage());$cancel=!empty($row['cancel_requested']);CopywritingEventService::save($id,['status'=>$cancel?'canceled':($ambiguous?'running':'failed'),'submission_state'=>$ambiguous?'uncertain':($row['consumption_id']?'finished':'not_sent'),'billing_state'=>$row['consumption_id']?'pending_usage':'none','error'=>$e->getMessage(),'lease_until'=>0,'finished_at'=>$ambiguous?0:time()]);}
    }
}
