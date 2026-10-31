<?php
namespace app\common\service\open_platform;

use app\common\service\power\MarketGenerationPixelMeter as Pixel;
use app\common\service\app\aigc_image\AigcImageAssetService as Assets;

/** In-process server evidence. Never read dimensions from the supplier response. */
final class OpenNativePixelMeter
{
    public static function measure(array $native,array $result): ?array
    {
        $snapshot=(array)($native['market_snapshot']??[]);$meter=(array)($snapshot['billing_meter']??[]);
        if(!Pixel::applies($meter))return null;
        try{$urls=Pixel::urls($meter,$result);}catch(\InvalidArgumentException){return null;}
        $reserved=(int)($snapshot['reserved_image_quantity']??0);Pixel::upperBound($meter,$reserved);
        if(count($urls)>$reserved)throw new OpenException('usage_boundary_exceeded','图片输出数量超过冻结合同上限',502);
        $images=[];$cache=[];
        foreach($urls as$url)$images[]=$cache[$url]??=Assets::measureGeneratedImage($url,$meter['maximum_pixels_per_image']);
        Pixel::measured($meter,$images,$reserved);
        return ['native_hash'=>OpenPolicy::fingerprint($native),'result_hash'=>OpenPolicy::fingerprint($result),'images'=>$images];
    }
}
