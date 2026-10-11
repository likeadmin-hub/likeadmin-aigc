<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenCallContext
{
    private static string $requestId='';
    private static string $leaseToken='';
    public static function enter(string $id,string $leaseToken=''): void { self::$requestId=$id;self::$leaseToken=$leaseToken; }
    public static function leave(): void { self::$requestId='';self::$leaseToken=''; }
    public static function leaseToken(string $id): string {return $id===self::$requestId?self::$leaseToken:'';}
    public static function assertLease(array $row): void
    {
        $token=self::leaseToken((string)$row['request_id']);if($token!==''&&(!hash_equals($token,(string)$row['lease_token'])||(int)$row['lease_expire']<=time()))throw new OpenException('recovery_lease_lost','任务已由其他恢复实例接管',409);
    }
    public static function id(): string { return self::$requestId; }
    public static function textModel(): ?array
    {
        if(self::$requestId==='')return null;$row=OpenStore::table('request')->where(['request_id'=>self::$requestId,'app_code'=>'aigc_llm','kind'=>'tool'])->find();
        return $row?(OpenStore::json($row['snapshot'])['native_text_model']??null):null;
    }
    public static function providerFailure(int $httpStatus,string $body,bool $hasOutput): void
    {
        if(self::$requestId==='')return;
        Db::transaction(function()use($httpStatus,$body,$hasOutput){$row=OpenStore::table('request')->where('request_id',self::$requestId)->lock(true)->find();if(!$row)return;self::assertLease($row);
            $snapshot=OpenStore::json($row['snapshot']);$payload=OpenStore::json($body);
            $message=(string)($payload['msg']??$payload['message']??$payload['error']['message']??'');
            $ambiguous=(bool)preg_match('/timeout|timed out|connection|curl|超时|连接|网络|格式|响应|未返回|内部异常/iu',$message);
            $snapshot['provider_rejection_confirmed']=!$hasOutput&&!$ambiguous&&($httpStatus>=400&&$httpStatus<500&&!in_array($httpStatus,[408,425],true)||$httpStatus<500&&array_key_exists('code',$payload)&&in_array($payload['code'],[0,'0'],true));
            // Error bodies can quote private input. Retain encrypted evidence,
            // never expose it through user records or print it in request logs.
            $snapshot['provider_error_blob']=OpenVault::seal(OpenStore::encode(['http_status'=>$httpStatus,'body'=>substr($body,0,16384)]));
            OpenAdmission::finish(self::$requestId,['snapshot'=>OpenStore::encode($snapshot)]);
        });
    }
    public static function linkNativeTask($model): void
    {
        if(self::$requestId==='')return;$parts=explode('\\',get_class($model));$app=$parts[count($parts)-2]??'';$tool=OpenToolRegistry::all()[$app]??null;
        if(!$tool||($tool['task_model']??'')!==get_class($model))return;
        Db::transaction(function()use($model,$app){$row=OpenStore::owner('request',(int)$model['tenant_id'],(int)$model['user_id'])->where(['request_id'=>self::$requestId,'app_code'=>$app,'kind'=>'tool'])->lock(true)->find();if(!$row)return;self::assertLease($row);if($row['tool_task_id']!=='')return;OpenAdmission::finish(self::$requestId,['tool_task_id'=>(string)$model['id']]);});
    }
    public static function linkConsumption(int $id): void
    {
        if(self::$requestId==='')return;
        $row=OpenStore::table('request')->where('request_id',self::$requestId)->lock(true)->find();if(!$row)return;self::assertLease($row);
        $ids=OpenStore::json($row['consumption_ids']);$ids[]=$id;OpenStore::table('request')->where('id',$row['id'])->update(['consumption_ids'=>OpenStore::encode(array_values(array_unique($ids)))]);
    }
    public static function observePoints(int $tenantId,int $userId,float $tenant,float $user,string $source,array $extra,bool $refund): void
    {
        $tenant=round(max(0,$tenant),2);$user=round(max(0,$user),2);
        // A query may complete an older task. Its durable billing identity takes
        // precedence over the current (free) query's request context.
        $requestId=(string)($extra['_open_platform_request_id']??'');
        if($requestId===''&&!empty($extra['consumption_id'])) { $consumption=\app\common\model\ai\AiConsumptionLog::findOrEmpty((int)$extra['consumption_id']);if(!$consumption->isEmpty())$requestId=(string)(OpenStore::json($consumption['price_snapshot'])['_open_platform_request_id']??''); }
        if($requestId==='')$requestId=self::$requestId;
        if($requestId==='')return;
        $row=OpenStore::owner('request',$tenantId,$userId)->where('request_id',$requestId)->lock(true)->find();if(!$row)throw new \RuntimeException('开放请求账务关联不存在');self::assertLease($row);
        $snapshot=OpenStore::json($row['snapshot']);$event=($refund?'refund:':'consume:').$source;
        if(isset($snapshot['point_events'][$event]))return;
        if(!$refund&&($user>(float)$row['budget_user']+0.000001||$tenant>(float)$row['budget_tenant']+0.000001))throw new OpenException('settlement_boundary_exceeded','实际费用超过受理额度，等待人工核对',402);
        $snapshot['point_events'][$event]=['user'=>$user,'tenant'=>$tenant];
        OpenStore::table('request')->where('id',$row['id'])->update(['snapshot'=>OpenStore::encode($snapshot),'actual_user'=>max(0,(float)$row['actual_user']+($refund?-$user:$user)),'actual_tenant'=>max(0,(float)$row['actual_tenant']+($refund?-$tenant:$tenant)),'budget_user'=>max(0,(float)$row['budget_user']-($refund?0:$user)),'budget_tenant'=>max(0,(float)$row['budget_tenant']-($refund?0:$tenant)),'update_time'=>time()]);
    }
}
