<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_store_visit;
use app\common\service\ai\StudioVideoCatalogService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\power\{MarketCatalogReadScope,MarketVideoRuntimeService};
use InvalidArgumentException;
final class StoreVisitCatalog
{
    public static function styles(int $tenant): array {return array_merge([
        ['id'=>StoreVisitPolicy::NO_STYLE,'name'=>'无风格','description'=>'','image'=>''],
    ],AigcShortDramaService::configuredVisualStyles($tenant));}
    public static function style(int $tenant,array $b): array {
        if($b['style_id']===StoreVisitPolicy::NO_STYLE)return ['id'=>StoreVisitPolicy::NO_STYLE,'name'=>'无风格','prompt'=>'','mode'=>'auto'];
        if($b['style_id']===''){if($b['custom_style']==='')throw new InvalidArgumentException('请选择画面风格或填写自定义画面风格');return ['id'=>'','name'=>'自定义','prompt'=>$b['custom_style']];}
        foreach(self::styles($tenant) as $row)if((string)$row['id']===$b['style_id'])return ['id'=>(string)$row['id'],'name'=>$row['name'],'prompt'=>$row['description']];
        throw new InvalidArgumentException('所选画面风格已停用或不可用，请重新选择');
    }
    /** Image-only creation may use a multi-reference mode even when single-image discovery chose a first frame. */
    public static function imageLimit(array $model): int {
        $modes=$model['material_modes']??[];
        if(!$modes)return min(15,max(0,(int)($model['max_reference_images']??0)));
        $limit=0;
        foreach($modes as $mode){
            if(!in_array($mode['method']??'', ['image_reference','image_to_video','omni_reference'],true))continue;
            $max=0;$min=0;$other=false;
            foreach($mode['fields']??[] as $field){
                if(($field['kind']??'')==='image'){$max+=(int)($field['maximum']??$field['minimum']??0);$min+=max((int)($field['minimum']??0),empty($field['required'])?0:1);}
                elseif(!empty($field['required'])||(int)($field['minimum']??0)>0)$other=true;
            }
            if(!$other&&$max>=max(1,$min))$limit=max($limit,$max);
        }
        return min(15,$limit);
    }
    public static function models(int $tenant,string $id=''): array {
        return MarketCatalogReadScope::run(static function()use($tenant,$id){
            $rows=StudioVideoCatalogService::models($tenant,false,'reference',$id!==''?[$id]:null,$id==='');
            if($id==='')return $rows;
            $native=AigcVideoService::marketOptions($tenant)['channels']??[];$by=[];foreach($native as $m)$by[(string)$m['id']]=$m;
            foreach($rows as &$m){$m['material_modes']=$by[(string)$m['id']]['material_modes']??[];
                $m['max_reference_images']=self::imageLimit($m);
                try{$m['supports_generate_audio']=MarketVideoRuntimeService::supportsGenerateAudio($tenant,['model_id'=>$m['id']]+($m['specs'][0]??[]));}catch(\Throwable){$m['supports_generate_audio']=false;}}
            return $rows;
        });
    }
    public static function selection(int $tenant,array $b): array {
        $s=$b['video_selection'];$model=null;foreach(self::models($tenant,(string)($s['model_id']??'')) as $m)if((string)$m['id']===(string)($s['model_id']??''))$model=$m;
        if(!$model)throw new InvalidArgumentException('所选视频模型不可用，不会自动替换');
        $spec=StudioVideoCatalogService::spec($model,(string)($s['quality']??''),(string)($s['ratio']??''),(int)($s['duration']??0),'reference');
        $n=count($b['images']);if(!$n||$n>(int)$model['max_reference_images'])throw new InvalidArgumentException('门店图片数量超出当前模型支持范围');
        if(!empty($model['material_modes'])){
            $chosen=null;foreach($model['material_modes'] as $mode){
                if(!in_array($mode['method'],['image_reference','image_to_video','omni_reference'],true))continue;
                $max=0;$min=0;$other=false;
                foreach($mode['fields'] as $f){if($f['kind']==='image'){$max+=(int)($f['maximum']??$f['minimum']??0);$min+=max((int)($f['minimum']??0),empty($f['required'])?0:1);}elseif(!empty($f['required'])||(int)($f['minimum']??0)>0)$other=true;}
                if(!$other&&$n>=$min&&$n<=$max){if($mode['method']===$spec['generation_method']){$chosen=$mode;break;}$chosen??=$mode;}
            }
            if(!$chosen)throw new InvalidArgumentException('该模型不支持当前门店图片组合');
            if($chosen['method']!==$spec['generation_method'])unset($spec['market_sku_id']);
            $spec['generation_method']=$chosen['method'];$spec['material_mode']=$chosen['key'];
        }
        if($b['audio']!==null){if(empty($model['supports_generate_audio']))throw new InvalidArgumentException('该模型未提供声音开关，请使用自动声音');$spec['generate_audio']=$b['audio'];}
        return $spec+['model_id'=>$model['id'],'channel'=>$model['id'],'resolution'=>$spec['quality']];
    }
}
