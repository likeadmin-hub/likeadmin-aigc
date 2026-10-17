<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use InvalidArgumentException;
use think\facade\Cache;

final class MicroVideoCatalog
{
    private static array $cache=[];
    public static function models(int $tenant,bool $legacy=false): array
    {
        $cacheKey=$tenant.':'.(int)$legacy;if(isset(self::$cache[$cacheKey]))return self::$cache[$cacheKey];
        $sharedKey='micro_video_callable_models_v2:'.$tenant;if(!$legacy){try{$cached=Cache::get($sharedKey);if(is_array($cached))return self::$cache[$cacheKey]=$cached;}catch(\Throwable){}}
        $result=[];$options=AigcVideoService::marketOptions($tenant);
        foreach($options['channels']??[] as $model){
            if((int)($model['status']??1)!==1||(int)($model['max_reference_images']??0)<1)continue;
            $modes=(array)($model['generation_modes']??$model['input_modes']??[]);$method='';
            foreach(['image_reference','omni_reference','image_to_video'] as $m)if(in_array($m,$modes,true)){$method=$m;break;}
            if($method===''){if($legacy)$method='image_reference';else continue;}
            $hasFrames=!empty($model['supports_first_last_frame'])&&in_array('start_end',$modes,true);if(!$legacy&&!$hasFrames)continue;
            $specs=[];$frameSpecs=[];$validatedPairs=[];
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
                        if($legacy){$specs[]=$entry;if($hasFrames&&($input===''||in_array($input,['start_end','image_reference'],true)))$frameSpecs[]=array_replace($entry,['generation_method'=>'start_end']);continue;}
                        // A pricing SKU may be labeled text_to_video while the shared
                        // adapter permits image reference and start/end requests. Let
                        // the real read-only admission validator decide each exact
                        // mode/spec pair, including ratio and duration constraints.
                        $pairKey=implode('|',[$s['market_sku_id'],$entry['quality'],$ratio]);
                        if(!array_key_exists($pairKey,$validatedPairs)){
                            try{$validatedPairs[$pairKey]=['duration'=>$duration,'reference'=>self::quote($tenant,$entry,(string)$model['id']),'frames'=>self::quote($tenant,array_replace($entry,['generation_method'=>'start_end']),(string)$model['id'])];}catch(\Throwable){$validatedPairs[$pairKey]=false;}
                        }
                        $pair=$validatedPairs[$pairKey];if($pair===false)continue;$reference=$pair['reference'];$frames=$pair['frames'];
                        // Durations are the shared runtime's advertised options for
                        // this same SKU. Validate mode/quality/ratio once, then scale
                        // reserved second-based prices; exact admissions quote again.
                        $scale=in_array($unit,['input_second','output_second','second'],true)?$duration/$pair['duration']:1;
                        $refEntry=array_replace($entry,['market_sku_id'=>$reference['market_sku_id'],'user_charge_points'=>round($reference['user_charge_points']*$scale,4)]);
                        $frameEntry=array_replace($entry,['generation_method'=>'start_end','market_sku_id'=>$frames['market_sku_id'],'user_charge_points'=>round($frames['user_charge_points']*$scale,4)]);
                        // App adapters can normalize text-priced aliases to the same
                        // reference SKU. Display and freeze that authoritative SKU.
                        $key=implode('|',[$entry['quality'],$ratio,$duration,$reference['market_sku_id'],$frames['market_sku_id']]);$specs[$key]=$refEntry;$frameSpecs[$key]=$frameEntry;
                    }
                }
            }
            if(!$legacy){$specs=array_values($specs);$frameSpecs=array_values($frameSpecs);}
            if(!$specs||(!$legacy&&!$frameSpecs))continue;
            $result[]=['id'=>(string)$model['id'],'label'=>(string)$model['name'],'description'=>(string)($model['description']??''),'display_icon'=>(string)($model['display_icon']??''),'max_reference_images'=>$method==='image_to_video'?1:min(15,(int)$model['max_reference_images']),'specs'=>$specs,'start_end_specs'=>$frameSpecs,'generation_modes'=>$hasFrames&&$frameSpecs?['reference','start_end']:['reference'],'supports_first_last_frame'=>(bool)($hasFrames&&$frameSpecs)];
        }
        // Catalog discovery is advisory; exact quote/reserve always validates live.
        // A short shared cache avoids repeating market reads for simultaneous
        // PC/mobile/admin config loads and never authorizes a paid request.
        if(!$legacy)try{Cache::set($sharedKey,$result,15);}catch(\Throwable){}
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
        $selection['market_sku_id']=(int)$q['market_sku_id'];
        return ['selection'=>$selection,'tenant_cost_points'=>(float)$q['tenant_cost_points'],'user_charge_points'=>(float)$q['user_charge_points'],'market_sku_id'=>(int)$q['market_sku_id']];
    }
}
