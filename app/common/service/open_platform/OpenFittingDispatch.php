<?php
namespace app\common\service\open_platform;
use think\facade\Db;
/** A durable batch boundary; recovery observes created children and never resubmits them. */
final class OpenFittingDispatch
{
    public static function start(int $tenant,int $user,int $expected): void
    {
        $id=OpenCallContext::id();if($id==='')return;
        Db::transaction(static function()use($id,$tenant,$user,$expected){
            $row=self::owned($id,$tenant,$user)->lock(true)->find();if(!$row)return;
            if(OpenCallContext::leaseToken($id)===''&&(string)$row['lease_token']!==''&&(int)$row['lease_expire']>time())throw new OpenException('recovery_lease_lost','试衣请求已由恢复流程接管',409);
            OpenCallContext::assertLease($row);
            $snapshot=OpenStore::json($row['snapshot']);
            if(!(int)$row['active']||$row['tool_task_id']!==''||isset($snapshot['fitting_dispatch']))throw new OpenException('task_context_invalid','试衣批次已受理，不能再次提交',409);
            $token=OpenCallContext::leaseToken($id)?:bin2hex(random_bytes(16));
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
            if(($dispatch['state']??'')!=='running'||!hash_equals((string)($dispatch['owner']??''),OpenCallContext::leaseToken($id)))throw new OpenException('recovery_lease_lost','试衣批次已由恢复流程接管',409);
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
        if($row['app_code']!=='aigc_fitting'||$row['method']!=='POST'||$row['path']!=='/api/v1/tools/aigc_fitting/tasks')return;
        Db::transaction(static function()use($row){$current=self::owned($row['request_id'],(int)$row['tenant_id'],(int)$row['user_id'])->lock(true)->find();if(!$current)return;OpenCallContext::assertLease($current);$snapshot=OpenStore::json($current['snapshot']);$dispatch=$snapshot['fitting_dispatch']??[];
            if(($dispatch['state']??'')!=='running')return;
            // An active submitter must not be classified as a crashed batch.
            if((int)$current['lease_expire']>time()&&hash_equals((string)$dispatch['owner'],(string)$current['lease_token']))return;
            $snapshot['fitting_dispatch']['state']='interrupted';$snapshot['fitting_dispatch']['finished_at']=time();OpenAdmission::finish($current['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);
        });
    }
    public static function interrupted(int $tenant,int $user,int $parent): bool
    {
        $row=OpenStore::owner('request',$tenant,$user)->where(['app_code'=>'aigc_fitting','kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/aigc_fitting/tasks','tool_task_id'=>(string)$parent])->find();
        return $row&&(OpenStore::json($row['snapshot'])['fitting_dispatch']['state']??'')==='interrupted';
    }
    public static function release(string $id): void
    {
        $token=OpenCallContext::leaseToken($id);if($token==='')return;
        OpenStore::table('request')->where(['request_id'=>$id,'app_code'=>'aigc_fitting','method'=>'POST','path'=>'/api/v1/tools/aigc_fitting/tasks','lease_token'=>$token])->update(['lease_token'=>'','lease_expire'=>0,'update_time'=>time()]);
    }
    private static function owned(string $id,int $tenant,int $user)
    {
        return OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$id,'app_code'=>'aigc_fitting','kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/aigc_fitting/tasks']);
    }
}
