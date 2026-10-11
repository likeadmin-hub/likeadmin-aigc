<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenCallContext
{
    private static string $requestId='';
    public static function enter(string $id): void { self::$requestId=$id; }
    public static function leave(): void { self::$requestId=''; }
    public static function id(): string { return self::$requestId; }
    public static function linkNativeTask($model): void
    {
        if(self::$requestId==='')return;$parts=explode('\\',get_class($model));$app=$parts[count($parts)-2]??'';$tool=OpenToolRegistry::all()[$app]??null;
        if(!$tool||($tool['task_model']??'')!==get_class($model))return;
        Db::transaction(function()use($model,$app){$row=OpenStore::owner('request',(int)$model['tenant_id'],(int)$model['user_id'])->where(['request_id'=>self::$requestId,'app_code'=>$app,'kind'=>'tool'])->lock(true)->find();if(!$row||$row['tool_task_id']!=='')return;OpenAdmission::finish(self::$requestId,['tool_task_id'=>(string)$model['id']]);});
    }
    public static function linkConsumption(int $id): void
    {
        if(self::$requestId==='')return;
        $row=OpenStore::table('request')->where('request_id',self::$requestId)->lock(true)->find();if(!$row)return;
        $ids=OpenStore::json($row['consumption_ids']);$ids[]=$id;OpenStore::table('request')->where('id',$row['id'])->update(['consumption_ids'=>OpenStore::encode(array_values(array_unique($ids)))]);
    }
    public static function observePoints(int $tenantId,int $userId,float $tenant,float $user,string $source,array $extra,bool $refund): void
    {
        $requestId=self::$requestId;
        if($requestId==='' && !empty($extra['consumption_id'])) { $consumption=\app\common\model\ai\AiConsumptionLog::findOrEmpty((int)$extra['consumption_id']);if(!$consumption->isEmpty())$requestId=(string)(OpenStore::json($consumption['price_snapshot'])['_open_platform_request_id']??''); }
        if($requestId===''&&!empty($extra['_open_platform_request_id']))$requestId=(string)$extra['_open_platform_request_id'];
        if($requestId==='')return;
        $row=OpenStore::owner('request',$tenantId,$userId)->where('request_id',$requestId)->lock(true)->find();if(!$row)throw new \RuntimeException('开放请求账务关联不存在');
        $snapshot=OpenStore::json($row['snapshot']);$event=($refund?'refund:':'consume:').$source;
        if(isset($snapshot['point_events'][$event]))return;
        $snapshot['point_events'][$event]=['user'=>$user,'tenant'=>$tenant];
        OpenStore::table('request')->where('id',$row['id'])->update(['snapshot'=>OpenStore::encode($snapshot),'actual_user'=>max(0,(float)$row['actual_user']+($refund?-$user:$user)),'actual_tenant'=>max(0,(float)$row['actual_tenant']+($refund?-$tenant:$tenant)),'budget_user'=>max(0,(float)$row['budget_user']-($refund?0:$user)),'budget_tenant'=>max(0,(float)$row['budget_tenant']-($refund?0:$tenant)),'update_time'=>time()]);
    }
}
