<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use InvalidArgumentException;

final class MicroVideoCatalog
{
    private static array $cache=[];
    public static function models(int $tenant,bool $legacy=false): array
    {
        $cacheKey=$tenant.':'.(int)$legacy;if(isset(self::$cache[$cacheKey]))return self::$cache[$cacheKey];
        $result=[];$options=AigcVideoService::marketOptions($tenant);
        foreach($options['channels']??[] as $model){
            if((int)($model['status']??1)!==1||(int)($model['max_reference_images']??0)<1)continue;
            $modes=(array)($model['generation_modes']??$model['input_modes']??[]);$method='';
            foreach(['image_reference','omni_reference','image_to_video'] as $m)if(in_array($m,$modes,true)){$method=$m;break;}
            if($method===''){if($legacy)$method='image_reference';else continue;}
            $hasFrames=!empty($model['supports_first_last_frame'])&&in_array('start_end',$modes,true);if(!$legacy&&!$hasFrames)continue;
            $specs=[];$frameSpecs=[];
            foreach($model['specs']??[] as $s){
                if(($s['settlement_mode']??'reserved')!=='reserved'||in_array($s['input_mode']??'', ['video_edit','audio_reference'],true))continue;
                $durations=$s['duration_options']?:((int)$s['duration']>0?[(int)$s['duration']]:($model['duration_options']??[]));
                $ratios=$s['ratio_options']?:($s['ratio']!==''?[$s['ratio']]:($model['ratio_options']??[]));
                if(!$ratios)continue;
                foreach($ratios as $ratio){$ratio=is_array($ratio)?($ratio['value']??$ratio['ratio']??''):$ratio;if($ratio===''||$ratio==='adaptive')continue;
                    foreach($durations as $duration){$duration=(int)$duration;if($duration<1||$duration>60)continue;
                        $unit=$s['usage_unit']??'';$quantity=in_array($unit,['input_second','output_second','second'],true)?$duration:1;
                        $entry=['quality'=>(string)$s['quality'],'ratio'=>$ratio,'duration'=>$duration,'generation_method'=>$method,'market_product_id'=>(int)$model['market_product_id'],'market_sku_id'=>(int)$s['market_sku_id'],'resource_type'=>$model['resource_type'],'user_charge_points'=>round((float)$s['tenant_unit_price']*$quantity/max(1,(float)($s['usage_unit_size']??1)),4)];
                        $input=(string)($s['input_mode']??'');
                        if($legacy||$input===''||in_array($input,[$method,'image_reference','omni_reference','image_to_video'],true))$specs[]=$entry;
                        if($hasFrames&&($input===''||in_array($input,['start_end','image_reference'],true)))$frameSpecs[]=array_replace($entry,['generation_method'=>'start_end']);
                    }
                }
            }
            if(!$legacy)$specs=array_values(array_filter($specs,fn($r)=>count(array_filter($frameSpecs,fn($f)=>$f['quality']===$r['quality']&&$f['ratio']===$r['ratio']&&$f['duration']===$r['duration']))>0));
            if(!$specs||(!$legacy&&!$frameSpecs))continue;
            $result[]=['id'=>(string)$model['id'],'label'=>(string)$model['name'],'description'=>(string)($model['description']??''),'display_icon'=>(string)($model['display_icon']??''),'max_reference_images'=>$method==='image_to_video'?1:min(15,(int)$model['max_reference_images']),'specs'=>$specs,'start_end_specs'=>$frameSpecs,'generation_modes'=>$hasFrames&&$frameSpecs?['reference','start_end']:['reference'],'supports_first_last_frame'=>(bool)($hasFrames&&$frameSpecs)];
        }
        return self::$cache[$cacheKey]=$result;
    }
    public static function model(int $tenant,string $id,bool $legacy=false): array
    {
        foreach(self::models($tenant,$legacy) as $m)if($m['id']===$id)return $m;throw new InvalidArgumentException('视频模型暂不可用，请重新选择');
    }
    public static function specs(array $model,string $quality,string $ratio): array
    {
        $rows=array_values(array_filter($model['specs'],fn($s)=>$s['quality']===$quality&&$s['ratio']===$ratio));
        if(!$rows)throw new InvalidArgumentException('当前模型不支持所选画质与比例');return $rows;
    }
    public static function spec(array $model,string $quality,string $ratio,int $duration,string $mode='reference'): array
    {
        if($mode==='start_end')$model['specs']=$model['start_end_specs']??[];
        foreach(self::specs($model,$quality,$ratio) as $s)if($s['duration']===$duration)return $s;throw new InvalidArgumentException('当前模型不支持此分段时长');
    }
    public static function runtime(array $spec): string{return ($spec['resource_type']??'model')==='app_api'?MarketVideoAppRuntimeService::class:MarketVideoModelRuntimeService::class;}
    public static function quote(int $tenant,array $spec,string $model): array
    {
        $selection=$spec+['model_id'=>$model,'channel'=>$model,'resolution'=>$spec['quality']];
        // Read-only quotes validate the generation mode's reference shape. This
        // application admits owned images separately; never send this pricing-only
        // placeholder to a provider or persist it in the executable selection.
        $asset=['type'=>'image','uri'=>'uploads/aigc_micro_video/pricing-only.png','role'=>$selection['generation_method']==='image_to_video'?'first_frame_image':'reference_image'];
        $assets=[$asset];if($selection['generation_method']==='start_end'){$assets=[array_replace($asset,['role'=>'first_frame_image']),array_replace($asset,['role'=>'last_frame_image'])];}
        $q=self::runtime($spec)::quote($tenant,$selection+['reference_images'=>array_column($assets,'uri'),'reference_assets'=>$assets]);
        if(($q['settlement_mode']??'reserved')!=='reserved')throw new InvalidArgumentException('此规格按实际用量结算，暂不支持分段预报价');
        return ['selection'=>$selection,'tenant_cost_points'=>(float)$q['tenant_cost_points'],'user_charge_points'=>(float)$q['user_charge_points'],'market_sku_id'=>(int)$q['market_sku_id']];
    }
}
