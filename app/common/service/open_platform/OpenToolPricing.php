<?php
namespace app\common\service\open_platform;
use app\common\service\app\aigc_music\AigcMusicChannelService;
/** Server-owned pricing selections; no client field can override a snapshot. */
final class OpenToolPricing
{
    public static function musicBudget($request,array $params): array
    {
        OpenToolAdapter::authorize((int)$request->tenantId,(int)$request->userId,'aigc_music');
        return self::applicationMusicBudget((int)$request->tenantId,(int)$request->userId,$params);
    }
    public static function applicationMusicBudget(int $tenant,int $user,array $params): array
    {
        $request=(object)['tenantId'=>$tenant,'userId'=>$user];
        $denied=\app\common\service\app\AppAccessService::assertTenantCanUse($tenant,'aigc_music',$user,true,false);
        if($denied)throw new OpenException('permission_denied','音乐工具不可用',403);
        $selection=AigcMusicChannelService::resolveSelection((int)$request->tenantId,$params);
        $selection['resource_bindings']=\app\common\service\app\aigc_music\AigcMusicService::referenceBindings((int)$request->tenantId,(int)$request->userId,$params);
        if(!in_array(strtolower(trim($selection['channel']['provider'])),['xhadmin','xhadmin_aigc_music','music_generation'],true))throw new OpenException('capability_unavailable','当前音乐通道尚未接入真实生成能力',503);
        $quote=AigcMusicChannelService::estimateSelection($selection,$params);
        $budget=OpenToolAdapter::estimateBudget($quote);
        if($budget===null)throw new OpenException('pricing_unavailable','音乐生成未提供可靠的计费额度边界',503);
        $budget['snapshot']['native_selection_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>'aigc_music','tenant_id'=>(int)$request->tenantId,'user_id'=>(int)$request->userId,'selection'=>$selection]));
        return $budget;
    }
    public static function forRequest(string $app,int $tenant,int $user): ?array
    {
        $id=OpenCallContext::id();if($id==='')return null;
        $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$id,'app_code'=>$app,'kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks'])->find();
        return $row?self::decode($row,$app,$tenant,$user):null;
    }
    public static function forTask(string $app,int $tenant,int $user,int|string $task): ?array
    {
        $row=OpenStore::owner('request',$tenant,$user)->where(['app_code'=>$app,'kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks','tool_task_id'=>(string)$task])->order('id','asc')->find();
        return $row?self::decode($row,$app,$tenant,$user):null;
    }
    private static function decode(array $row,string $app,int $tenant,int $user): ?array
    {
        $snapshot=OpenStore::json($row['snapshot']);$blob=$snapshot['native_selection_blob']??'';
        if($blob==='')return null; // Legacy tasks keep their existing lifecycle.
        $stored=OpenStore::json(OpenVault::open($blob));
        if(($stored['app_code']??'')!==$app||(int)($stored['tenant_id']??0)!==$tenant||(int)($stored['user_id']??0)!==$user||!isset($stored['selection']['channel'],$stored['selection']['spec'],$snapshot['quote']))throw new OpenException('pricing_snapshot_invalid','任务价格快照不完整，等待核对',409);
        return ['selection'=>$stored['selection'],'quote'=>$snapshot['quote'],'request_id'=>$row['request_id']];
    }
}
