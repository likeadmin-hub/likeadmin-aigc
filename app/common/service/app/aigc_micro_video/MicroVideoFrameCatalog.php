<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\power\MarketImageModelRuntimeService as R;
use InvalidArgumentException;
final class MicroVideoFrameCatalog
{
    public static function models(int $tenant): array
    {
        $out=[];
        foreach(R::options($tenant) as $m){
            if(empty($m['available'])||empty($m['supports_reference_images'])||(int)$m['max_reference_images']<2)continue;
            $specs=[];
            foreach($m['skus'] as $s){
                if(($s['settlement_mode']??'')!=='reserved')continue;
                foreach($s['ratio_options'] as $ratio){
                    if(!is_string($ratio)||$ratio===''||in_array(strtolower($ratio),['adaptive','auto','original'],true))continue;
                    // The shared catalogue batches live SKU availability and tenant retail prices.
                    // This is advisory display data; exact quote/reserve still validate live selections.
                    $specs[]=['quality'=>$s['quality'],'ratio'=>$ratio,'market_sku_id'=>(int)$s['market_sku_id'],'user_charge_points'=>round((float)$s['tenant_unit_price'],4)];
                }
            }
            if($specs)$out[]=['channel_source'=>(string)($m['channel_source']??'unknown'),'channel_source_label'=>(string)($m['channel_source_label']??'渠道未标注'),'id'=>$m['id'],'label'=>$m['name'],'max_reference_images'=>(int)$m['max_reference_images'],'specs'=>$specs];
        }
        return $out;
    }
    public static function model(int $tenant,string $id): array{foreach(self::models($tenant) as $m)if($m['id']===$id)return $m;throw new InvalidArgumentException('首尾帧图片模型暂不可用');}
    public static function selection(array $model,string $quality,string $ratio): array{foreach($model['specs'] as $s)if($s['quality']===$quality&&$s['ratio']===$ratio)return ['model_id'=>$model['id'],'market_sku_id'=>$s['market_sku_id'],'quality'=>$quality,'ratio'=>$ratio];throw new InvalidArgumentException('图片模型不支持当前画质或视频比例');}
    public static function quote(int $tenant,array $selection): array{$q=R::quote($tenant,$selection,1);if(($q['settlement_mode']??'reserved')!=='reserved')throw new InvalidArgumentException('图片规格不支持预报价');return ['selection'=>$selection,'market_sku_id'=>(int)$selection['market_sku_id'],'tenant_cost_points'=>(float)$q['tenant_cost_points'],'user_charge_points'=>(float)$q['user_charge_points']];}
}
