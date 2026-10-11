<?php
namespace app\common\service\open_platform;
use think\facade\Db;
/** A durable batch boundary; recovery observes created children and never resubmits them. */
final class OpenImageBatchDispatch
{
    public const APPS=['aigc_fitting','aigc_product_multi_angle'];
    public static function supports(array $row): bool {return in_array($row['app_code']??'',self::APPS,true)&&($row['method']??'')==='POST'&&($row['path']??'')==='/api/v1/tools/'.$row['app_code'].'/tasks';}
    public static function start(int $tenant,int $user,int $expected): void
    {
        $id=OpenCallContext::id();if($id==='')return;
        Db::transaction(static function()use($id,$tenant,$user,$expected){
            $row=self::owned($id,$tenant,$user)->lock(true)->find();if(!$row)return;
            if(OpenCallContext::leaseToken($id)===''&&(string)$row['lease_token']!==''&&(int)$row['lease_expire']>time())throw new OpenException('recovery_lease_lost','图片批次请求已由恢复流程接管',409);
            OpenCallContext::assertLease($row);
            $snapshot=OpenStore::json($row['snapshot']);
            // Gateway claims before marking dispatch; the native service reuses
            // that exact live owner before materializing its sealed recipe.
            $existing=$snapshot['fitting_dispatch']??[];
            if((int)$row['active']&&$row['tool_task_id']===''&&($existing['state']??'')==='running'&&OpenCallContext::leaseToken($id)!==''&&hash_equals((string)$existing['owner'],OpenCallContext::leaseToken($id)))return;
            if(!(int)$row['active']||$row['tool_task_id']!==''||isset($snapshot['fitting_dispatch']))throw new OpenException('task_context_invalid','图片批次已受理，不能再次提交',409);
            $token=OpenCallContext::leaseToken($id)?:bin2hex(random_bytes(16));
            // Preserve the existing durable snapshot key for accepted fitting receipts.
            $snapshot['fitting_dispatch']=['state'=>'running','expected'=>$expected,'owner'=>$token,'started_at'=>time()];
            OpenStore::table('request')->where('id',$row['id'])->update(['snapshot'=>OpenStore::encode($snapshot),'lease_token'=>$token,'lease_expire'=>time()+600,'update_time'=>time()]);
            OpenCallContext::enter($id,$token);
        });
    }
    public static function heartbeat(int $tenant,int $user): void
    {
        $id=OpenCallContext::id();if($id==='')return;
        Db::transaction(static function()use($id,$tenant,$user){$row=self::owned($id,$tenant,$user)->lock(true)->find();if(!$row)return;OpenCallContext::assertLease($row);
            $dispatch=OpenStore::json($row['snapshot'])['fitting_dispatch']??[];
            if(($dispatch['state']??'')!=='running'||!hash_equals((string)($dispatch['owner']??''),OpenCallContext::leaseToken($id)))throw new OpenException('recovery_lease_lost','图片批次已由恢复流程接管',409);
            OpenAdmission::finish($id,['lease_expire'=>time()+600]);
        });
    }
    public static function complete(int $tenant,int $user,bool $aborted=false): void
    {
        $id=OpenCallContext::id();if($id==='')return;
        Db::transaction(static function()use($id,$tenant,$user,$aborted){$row=self::owned($id,$tenant,$user)->lock(true)->find();if(!$row)return;OpenCallContext::assertLease($row);$snapshot=OpenStore::json($row['snapshot']);
            if(!isset($snapshot['fitting_dispatch']))return;$snapshot['fitting_dispatch']['state']=$aborted?'interrupted':'completed';$snapshot['fitting_dispatch']['finished_at']=time();OpenAdmission::finish($id,['snapshot'=>OpenStore::encode($snapshot)]);
        });
    }
    /** Called after a worker lease claim, or after the HTTP owner has released it. */
    public static function recover(array $row): void
    {
        if(!self::supports($row))return;
        Db::transaction(static function()use($row){$current=self::owned($row['request_id'],(int)$row['tenant_id'],(int)$row['user_id'])->lock(true)->find();if(!$current)return;OpenCallContext::assertLease($current);$snapshot=OpenStore::json($current['snapshot']);$dispatch=$snapshot['fitting_dispatch']??[];
            if(!$dispatch&&($snapshot['_execution_version']??0)===2&&isset($snapshot['native_prepared_blob'])&&$current['tool_task_id']===''&&!OpenStore::json($current['consumption_ids'])&&(float)$current['actual_user']===0.&&(float)$current['actual_tenant']===0.&&OpenCallContext::leaseToken($current['request_id'])!==''){
                // A claimed pre-batch receipt cannot have reached transport:
                // native handoff and consumption association precede supplier I/O.
                $snapshot['fitting_dispatch']=['state'=>'interrupted','expected'=>0,'owner'=>OpenCallContext::leaseToken($current['request_id']),'finished_at'=>time()];
                OpenAdmission::finish($current['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);return;
            }
            if(($dispatch['state']??'')!=='running')return;
            // An active submitter must not be classified as a crashed batch.
            if((int)$current['lease_expire']>time()&&hash_equals((string)$dispatch['owner'],(string)$current['lease_token']))return;
            $snapshot['fitting_dispatch']['state']='interrupted';$snapshot['fitting_dispatch']['finished_at']=time();OpenAdmission::finish($current['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);
        });
    }
    public static function interrupted(int $tenant,int $user,int $parent,string $app='aigc_fitting'): bool
    {
        $row=OpenStore::owner('request',$tenant,$user)->where(['app_code'=>$app,'kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks','tool_task_id'=>(string)$parent])->find();
        return $row&&(OpenStore::json($row['snapshot'])['fitting_dispatch']['state']??'')==='interrupted';
    }
    public static function release(string $id): void
    {
        $token=OpenCallContext::leaseToken($id);if($token==='')return;
        self::owned($id)->where('lease_token',$token)->update(['lease_token'=>'','lease_expire'=>0,'update_time'=>time()]);
    }
    private static function owned(string $id,?int $tenant=null,?int $user=null)
    {
        return ($tenant===null?OpenStore::table('request'):OpenStore::owner('request',$tenant,$user))->where(['request_id'=>$id,'kind'=>'tool','method'=>'POST'])->whereIn('app_code',self::APPS)->whereIn('path',array_map(static fn($app)=>'/api/v1/tools/'.$app.'/tasks',self::APPS));
    }
}
