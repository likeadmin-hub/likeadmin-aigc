<?php
namespace app\common\service\open_platform;
use think\facade\Db;
/** A confirmed preview only schedules tracking of an already admitted task. */
final class OpenRecoveryAction
{
    public static function offer(array $preview,array $snapshot): array
    {
        $row=$preview['preview'];
        $eligible=$preview['tracking']['active']&&$preview['tracking']['query_available']&&!$preview['tracking']['lease_active']
            &&($snapshot['_execution_version']??0)===2&&$row['method']==='POST'
            &&!in_array($row['billing_status'],['settled','refunded','none'],true);
        $expiry=time()+300;
        return ['available'=>$eligible,'expires_at'=>$eligible?$expiry:0,'preview_token'=>$eligible?OpenVault::seal(OpenStore::encode([
            'purpose'=>'resume_existing_tracking','tenant_id'=>(int)$row['tenant_id'],'request_id'=>$row['request_id'],
            'expires_at'=>$expiry,'fingerprint'=>self::fingerprint($row),'revision'=>hash('sha256',OpenStore::encode($snapshot)),
        ])):''];
    }
    public static function resume(int $tenant,string $id,string $token,string $scene,int $adminId): array
    {
        if(!in_array($scene,['platform_admin','tenant_admin'],true)||$adminId<=0||$tenant<0)throw new OpenException('permission_denied','恢复操作需要明确的后台身份和租户',403);
        try{$proof=OpenStore::json(OpenVault::open($token));}catch(\Throwable){throw new OpenException('preview_invalid','请重新预览恢复影响',409);}
        if(($proof['purpose']??'')!=='resume_existing_tracking'||(int)($proof['tenant_id']??-1)!==$tenant||($proof['request_id']??'')!==$id||(int)($proof['expires_at']??0)<=time())throw new OpenException('preview_expired','恢复预览已失效，请重新预览',409);
        return Db::transaction(static function()use($tenant,$id,$proof,$scene,$adminId){
            $row=OpenStore::table('request')->where(['tenant_id'=>$tenant,'request_id'=>$id])->lock(true)->find();
            if(!$row)throw new OpenException('not_found','请求不存在',404);
            $preview=OpenManagement::recoveryPreview($tenant,$id);
            if(!$preview['resume']['available']||!hash_equals(self::fingerprint($preview['preview']),(string)($proof['fingerprint']??''))||!hash_equals(hash('sha256',OpenStore::encode(OpenStore::json($row['snapshot']))),(string)($proof['revision']??'')))throw new OpenException('preview_stale','任务状态或费用影响已变化，请重新预览',409);
            $snapshot=OpenStore::json($row['snapshot']);$snapshot['manual_resume']=['scene'=>$scene,'admin_id'=>$adminId,'confirmed_at'=>time(),'attempt'=>(int)($snapshot['manual_resume']['attempt']??0)+1];
            $snapshot['manual_resume_events'][]=$snapshot['manual_resume'];
            OpenStore::table('request')->where('id',$row['id'])->update(['snapshot'=>OpenStore::encode($snapshot),'next_run_time'=>time(),'lease_token'=>'','lease_expire'=>0,'update_time'=>time()]);
            return ['request_id'=>$id,'tenant_id'=>$tenant,'status'=>'tracking_scheduled','new_submission'=>false,'charged_now'=>0,'historical_backcharge_allowed'=>false];
        });
    }
    private static function fingerprint(array $row): string
    {
        $fields=['request_id','tenant_id','user_id','kind','app_code','method','path','tool_task_id','upstream_task_id','status','billing_status','active','actual_user','actual_tenant','budget_user','budget_tenant','lease_expire','next_run_time','update_time'];
        return hash('sha256',OpenStore::encode(array_intersect_key($row,array_flip($fields))));
    }
}
