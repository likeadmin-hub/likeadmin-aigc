<?php
namespace app\common\service\power;

use app\common\service\app\aigc_image\AigcImageAssetService;
use InvalidArgumentException;

/** Declared output slots and server image measurements, shared by image consumers. */
final class MarketPixelImageResult
{
    public static function persist(array $snapshot,array $response,int $tenantId,int $userId,string $status,string $taskId): array
    {
        $meter=(array)($snapshot['billing_meter']??[]);
        if(!MarketGenerationPixelMeter::applies($meter))throw new InvalidArgumentException('缺少冻结像素计量合同');
        if(in_array($status,['failed','error','canceled','cancelled'],true))return [];
        try{$urls=MarketGenerationPixelMeter::urls($meter,$response);}
        catch(InvalidArgumentException $e){
            if(in_array($status,['pending','queued','processing','running','submitted','created'],true)||($status===''&&$taskId!==''))return [];
            throw $e;
        }
        $reserved=(int)($snapshot['reserved_image_quantity']??0);MarketGenerationPixelMeter::upperBound($meter,$reserved);
        if(count($urls)>$reserved)throw new InvalidArgumentException('实际图片数量超过预占数量或缺失');
        $images=[];$stored=[];
        foreach($urls as$url){
            $asset=$stored[$url]??=AigcImageAssetService::persistGeneratedImage($url,$tenantId,$userId,true,$meter['maximum_pixels_per_image']);
            $images[]=['image_uri'=>(string)$asset['uri'],'width'=>(int)($asset['width']??0),'height'=>(int)($asset['height']??0),
                'storage_scope'=>(string)($asset['storage_scope']??'tenant'),'storage_engine'=>(string)($asset['storage_engine']??'local'),'storage_domain'=>(string)($asset['storage_domain']??'')];
        }
        MarketGenerationPixelMeter::measured($meter,$images,$reserved);
        return $images;
    }
}
