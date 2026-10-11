<?php
namespace app\common\service\open_platform;
use app\common\service\app\aigc_image\AigcImageChannelService as Channels;
use app\common\service\power\MarketImageModelRuntimeService as Models;
use app\common\service\power\MarketNanoBananaAppRuntimeService as Nano;
/** Freeze the native image selection and supplier SKU before accepting a paid call. */
final class OpenImagePricing
{
    public static function budget($request,array $params): array
    {
        $tenant=(int)$request->tenantId;$user=(int)$request->userId;
        OpenToolAdapter::authorize($tenant,$user,'aigc_image');
        $selection=Channels::resolveSelection($tenant,$params);$spec=$selection['spec'];$channel=$selection['channel'];
        $quantity=Channels::normalizeQuantity($params['quantity']??1);Channels::assertChannelQuantity($channel,$quantity);
        $references=array_filter(array_map('strval',(array)($params['reference_images']??[])),static fn($value)=>trim($value)!=='');
        if(count($references)>(int)$channel['max_reference_images'])throw new OpenException('invalid_request','参考图数量超出限制');
        $runtime=match($channel['provider']){'power_market_model'=>'model','power_market_app_api'=>'nano',default=>throw new OpenException('capability_unavailable','图片通道尚未接入算力市场',503)};
        $resolved=array_merge($params,['channel'=>$channel['code'],'model_id'=>$channel['code'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'quantity'=>$quantity,
            'variant'=>$spec['variant']??$params['variant']??'','market_product_id'=>(int)($spec['market_product_id']??0),'market_sku_id'=>(int)($spec['market_sku_id']??$params['market_sku_id']??$params['sku_id']??0)]);
        $capture=($runtime==='model'?Models::class:Nano::class)::openPlatformAdmissionQuote($tenant,$resolved,$quantity);
        $quote=$capture['quote'];$budget=OpenToolAdapter::estimateBudget($quote);
        if($budget===null)throw new OpenException('pricing_unavailable','图片生成缺少可靠的计费额度边界',503);
        $selection['_open_image']=['runtime'=>$runtime,'market'=>$capture['market'],'quantity'=>$quantity];
        $budget['snapshot']['native_selection_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>'aigc_image','tenant_id'=>$tenant,'user_id'=>$user,'selection'=>$selection]));
        return $budget;
    }
    /** Only an owned accepted native image request may replace a live market lookup. */
    public static function assertQuantity(int $tenant,int $quantity): void
    {
        $frozen=OpenToolPricing::forRequest('aigc_image',$tenant,(int)(request()->userId??0));
        if($frozen&&(int)($frozen['selection']['_open_image']['quantity']??0)!==max(1,$quantity))throw new OpenException('pricing_snapshot_conflict','图片数量与受理快照不一致',409);
    }
    public static function market(int $tenant,array $params,string $runtime): ?array
    {
        $frozen=OpenToolPricing::forRequest('aigc_image',$tenant,(int)(request()->userId??0));if(!$frozen)return null;
        $selection=$frozen['selection'];$image=$selection['_open_image']??null;
        if(!is_array($image)||($image['runtime']??'')!==$runtime||!isset($image['market']['product'],$image['market']['sku'],$image['market']['tenant_price']))throw new OpenException('pricing_snapshot_invalid','图片价格快照不完整',409);
        foreach(['channel','model_id'] as $field)if(isset($params[$field])&&$params[$field]!==''&&(string)$params[$field]!==$selection['channel']['code'])throw new OpenException('pricing_snapshot_conflict','图片通道与受理快照不一致',409);
        foreach(['quality','ratio'] as $field)if(isset($params[$field])&&$params[$field]!==''&&(string)$params[$field]!==$selection['spec'][$field])throw new OpenException('pricing_snapshot_conflict','图片规格与受理快照不一致',409);
        foreach(['market_sku_id','sku_id'] as $field)if(!empty($params[$field])&&(int)$params[$field]!== (int)$image['market']['sku']['id'])throw new OpenException('pricing_snapshot_conflict','图片 SKU 与受理快照不一致',409);
        if(!empty($params['market_product_id'])&&(int)$params['market_product_id']!==(int)$image['market']['product']['id'])throw new OpenException('pricing_snapshot_conflict','图片商品与受理快照不一致',409);
        if(isset($params['quantity'])&&Channels::normalizeQuantity($params['quantity'])!==(int)$image['quantity'])throw new OpenException('pricing_snapshot_conflict','图片数量与受理快照不一致',409);
        return $image['market'];
    }
}
