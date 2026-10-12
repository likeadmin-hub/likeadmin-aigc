<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenWebhooks
{
    public static function enqueue(array $request,array $data): void
    {
        $snapshot=OpenStore::json($request['snapshot']);$callback=$snapshot['customer_callback']??null;if(!$callback)return;
        $event='evt_'.substr(hash('sha256',$request['request_id'].' terminal'),0,48);
        Db::transaction(function()use($request,$data,$callback,$event){
            // Serialize terminal notification creation with the durable parent request.
            $owner=OpenStore::owner('request',(int)$request['tenant_id'],(int)$request['user_id'])->where('request_id',$request['request_id'])->lock(true)->find();
            if(!$owner)return;OpenCallContext::assertLease($owner);if(OpenStore::table('webhook')->where('event_id',$event)->find())return;
            if($owner['kind']==='market'&&(OpenStore::json($owner['snapshot'])['contract']['response_adapter']??'')===OpenMarketResponse::ADAPTER)$data=OpenStore::json(OpenMarketResponse::project(OpenStore::encode($data),OpenMarketResponse::amounts($owner)));
            OpenStore::table('webhook')->insert(['tenant_id'=>$request['tenant_id'],'user_id'=>$request['user_id'],'request_id'=>$request['request_id'],'event_id'=>$event,'url'=>$callback['url'],'secret_blob'=>$callback['secret_blob'],'payload_blob'=>OpenVault::seal(OpenStore::encode(['event_id'=>$event,'request_id'=>$request['request_id'],'type'=>'task.completed','data'=>OpenPolicy::publicData($data)])),'status'=>'pending','attempts'=>0,'next_run_time'=>time()]+OpenStore::timestamp());
        });
    }
    public static function deliver(array $row): void
    {
        $time=time();$body=OpenVault::open($row['payload_blob']);$secret=OpenVault::open($row['secret_blob']);$signature=hash_hmac('sha256',$time.'.'.$body,$secret);$status=0;$error='';
        try{$status=OpenSafeHttp::post($row['url'],$body,['Content-Type: application/json','X-Open-Event-Id: '.$row['event_id'],'X-Open-Timestamp: '.$time,'X-Open-Signature: '.$signature]);if($status<200||$status>=300)$error='接收端返回非成功状态';}catch(\Throwable $e){$error='通知投递失败';}
        $attempt=(int)$row['attempts']+1;$delays=[60,300,900,3600,21600,86400];$done=$status>=200&&$status<300;
        Db::transaction(function()use($row,$time,$attempt,$delays,$done,$status,$error){
            $current=OpenStore::table('webhook')->where(['id'=>$row['id'],'lease_token'=>$row['lease_token'],'status'=>'pending'])->lock(true)->find();
            if(!$current)return; // A superseded worker cannot record or change a newer delivery attempt.
            OpenStore::table('webhook_delivery')->insert(['webhook_id'=>$row['id'],'event_id'=>$row['event_id'],'attempt'=>$attempt,'http_code'=>$status,'error_message'=>$error,'create_time'=>$time]);OpenStore::table('webhook')->where(['id'=>$row['id'],'lease_token'=>$row['lease_token']])->update(['attempts'=>$attempt,'status'=>$done?'delivered':($attempt>count($delays)?'failed':'pending'),'http_code'=>$status,'last_error'=>$error,'next_run_time'=>$done?0:($delays[$attempt-1]??0)+time(),'lease_token'=>'','lease_expire'=>0,'update_time'=>time()]);});
    }
    public static function replay(int $tenantId,int $userId,int $id): void
    {
        $q=OpenStore::table('webhook')->where('id',$id);if($tenantId>=0)$q->where('tenant_id',$tenantId);if($userId>0)$q->where('user_id',$userId);$row=$q->find();if(!$row||$row['status']==='pending')throw new OpenException('not_found','通知不存在或正在投递',404);$q->update(['status'=>'pending','attempts'=>0,'next_run_time'=>time(),'lease_token'=>'','lease_expire'=>0,'update_time'=>time()]);
    }
}
