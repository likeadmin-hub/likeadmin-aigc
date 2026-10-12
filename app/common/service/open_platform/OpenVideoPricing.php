<?php
namespace app\common\service\open_platform;
/** An accepted native video call owns its negotiated parameters and market price. */
final class OpenVideoPricing
{
    public static function budget($request,array $params): array
    {
        $tenant=(int)$request->tenantId;$user=(int)$request->userId;
        OpenToolAdapter::authorize($tenant,$user,'aigc_video');
        $captured=\app\common\service\app\aigc_video\AigcVideoService::openPlatformAdmission($tenant,$params);
        $budget=OpenToolAdapter::estimateBudget($captured['quote']);
        if($budget===null)throw new OpenException('pricing_unavailable','视频生成缺少可靠的计费额度边界',503);
        $budget['snapshot']['native_video_blob']=OpenVault::seal(OpenStore::encode(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_video']+$captured));
        return $budget;
    }
    public static function forRequest(int $tenant,int $user): ?array
    {
        $id=OpenCallContext::id();if($id==='')return null;
        $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$id,'app_code'=>'aigc_video','kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/aigc_video/tasks'])->find();
        if(!$row)return null;
        $blob=OpenStore::json($row['snapshot'])['native_video_blob']??'';
        if($blob==='')throw new OpenException('pricing_snapshot_invalid','视频请求缺少受理快照',409);
        $stored=OpenStore::json(OpenVault::open($blob));
        if(($stored['app_code']??'')!=='aigc_video'||(int)($stored['tenant_id']??0)!==$tenant||(int)($stored['user_id']??0)!==$user||!isset($stored['market']['product'],$stored['market']['sku'],$stored['market']['tenant_price'],$stored['selection'],$stored['params'],$stored['quote']))throw new OpenException('pricing_snapshot_invalid','视频受理快照不完整',409);
        return $stored;
    }
    public static function market(int $tenant,array $selection): ?array
    {
        $frozen=self::forRequest($tenant,(int)(request()->userId??0));if(!$frozen)return null;
        foreach(['model_id','channel','resource_type','resolution','quality','ratio','duration'] as $field)if(isset($selection[$field])&&$selection[$field]!==''&&isset($frozen['selection'][$field])&&(string)$selection[$field]!== (string)$frozen['selection'][$field])throw new OpenException('pricing_snapshot_conflict','视频规格与受理快照不一致',409);
        foreach(['market_sku_id','sku_id'] as $field)if(!empty($selection[$field])&&(int)preg_replace('/^market_sku:/','',(string)$selection[$field])!== (int)$frozen['market']['sku']['id'])throw new OpenException('pricing_snapshot_conflict','视频 SKU 与受理快照不一致',409);
        if(!empty($selection['market_product_id'])&&(int)$selection['market_product_id']!== (int)$frozen['market']['product']['id'])throw new OpenException('pricing_snapshot_conflict','视频商品与受理快照不一致',409);
        return $frozen['market'];
    }
}
