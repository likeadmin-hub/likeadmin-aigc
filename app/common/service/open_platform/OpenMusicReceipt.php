<?php
namespace app\common\service\open_platform;
use app\common\model\app\aigc_music\AigcMusicTask;
use think\facade\Db;
/** Preserve a known music submission before polling, storage or native task writes. */
final class OpenMusicReceipt
{
    public static function capture(int $tenant,int $user,int $task,string $providerId,array $payload): void
    {
        $rid=OpenCallContext::id();if($rid==='')return;
        if($providerId==='')throw new OpenException('upstream_uncertain','音乐任务受理状态待确认',504);
        Db::transaction(function()use($tenant,$user,$task,$providerId,$payload,$rid){
            $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$rid,'app_code'=>'aigc_music','kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/aigc_music/tasks','tool_task_id'=>(string)$task])->lock(true)->find();
            if(!$row)throw new OpenException('receipt_identity_mismatch','音乐回执与受理任务不一致',409);OpenCallContext::assertLease($row);
            $snapshot=OpenStore::json($row['snapshot']);
            $old=$snapshot['native_music_receipt_blob']??'';
            if($old!==''){if(OpenStore::json(OpenVault::open($old))['provider_task_id']!==$providerId)throw new OpenException('receipt_identity_mismatch','音乐上游任务关联发生变化',409);return;}
            $snapshot['native_music_receipt_blob']=OpenVault::seal(OpenStore::encode(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,'provider_task_id'=>$providerId,'submit'=>$payload]));
            OpenAdmission::finish($rid,['snapshot'=>OpenStore::encode($snapshot),'upstream_task_id'=>$providerId,'status'=>'running','active'=>1,'next_run_time'=>time()+60]);
        });
    }
    /** Repair only an owned blank native ID. This never submits or charges a task. */
    public static function restore(int $tenant,int $user,int $task): bool
    {
        return Db::transaction(function()use($tenant,$user,$task){
            $native=AigcMusicTask::where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$task,'delete_time'=>0])->lock(true)->findOrEmpty();
            if($native->isEmpty()||(string)$native['provider_task_id']!==''||(string)$native['status']!=='running')return false;
            $row=OpenStore::owner('request',$tenant,$user)->where(['app_code'=>'aigc_music','kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/aigc_music/tasks','tool_task_id'=>(string)$task])->order('id','asc')->find();
            if(!$row)return false;$blob=OpenStore::json($row['snapshot'])['native_music_receipt_blob']??'';if($blob==='')return false;
            OpenCallContext::assertLease($row);$receipt=OpenStore::json(OpenVault::open($blob));
            if((int)($receipt['tenant_id']??0)!==$tenant||(int)($receipt['user_id']??0)!==$user||(int)($receipt['task_id']??0)!==$task||empty($receipt['provider_task_id']))throw new OpenException('receipt_identity_mismatch','音乐回执归属无效，等待核对',409);
            $native->save(['provider_task_id'=>$receipt['provider_task_id'],'provider_payload'=>['submit'=>$receipt['submit']??[]],'update_time'=>time()]);return true;
        });
    }
}
