<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\ai\StudioVideoCatalogService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\power\{MarketVideoRuntimeService,MarketCatalogReadScope};
use InvalidArgumentException;
final class ProductVideoCatalog
{
    public static function models(int $tenant,string $id=''): array
    {
        return MarketCatalogReadScope::run(static fn(): array => self::buildModels($tenant,$id));
    }
    private static function buildModels(int $tenant,string $id): array
    {
        $rows=StudioVideoCatalogService::models($tenant,false,'reference',$id!==''?[$id]:null,$id==='');
        $native=AigcVideoService::marketOptions($tenant)['channels']??[];$byId=[];foreach($native as $m)$byId[(string)$m['id']]=$m;
        foreach($rows as &$m){$source=$byId[(string)$m['id']]??[];$m['max_reference_videos']=(int)($source['max_reference_videos']??0);$m['max_reference_audios']=(int)($source['max_reference_audios']??0);
            if($id!==''){
                $m['material_modes']=$source['material_modes']??[];$m['max_reference_images']=min(15,max($m['max_reference_images'],(int)($source['max_reference_images']??0)));
                try{$m['supports_generate_audio']=MarketVideoRuntimeService::supportsGenerateAudio($tenant,['model_id'=>$id]+($m['specs'][0]??[]));}catch(\Throwable){$m['supports_generate_audio']=false;}
            }
        }unset($m);return $rows;
    }
    public static function model(int $tenant,array $body): array
    {
        $id=(string)($body['video_selection']['model_id']??'');foreach(self::models($tenant,$id) as $m)if((string)$m['id']===$id)return $m;
        throw new InvalidArgumentException('视频模型暂不可用，请重新选择');
    }
    public static function spec(array $m,array $body): array
    {
        $s=$body['video_selection'];$mode=($body['mode']==='normal'&&($body['reference_mode']??'reference')==='start_end')?'start_end':'reference';
        $spec=StudioVideoCatalogService::spec($m,(string)($s['quality']??''),(string)($s['ratio']??''),(int)($s['duration']??0),$mode);
        $count=count($body['images']);if($mode==='start_end'){ProductVideoPolicy::frames($body['images']);if($body['generation_assets'])throw new InvalidArgumentException('首尾帧模式不支持额外的视频或音频，请调整素材');if($count===1){$spec['generation_method']='image_to_video';unset($spec['market_sku_id']);foreach($m['material_modes']??[] as $native)if($native['method']==='image_to_video'){$spec['material_mode']=$native['key'];break;}}return $spec;}
        if(empty($m['material_modes'])){if($count>(int)$m['max_reference_images']||$body['generation_assets'])throw new InvalidArgumentException('所选模型不支持当前参考素材');return $spec;}
        $counts=['image'=>$count,'video'=>0,'audio'=>0];foreach($body['generation_assets'] as $asset)$counts[$asset['type']]++;
        $candidates=[];
        foreach($m['material_modes'] as $native){
            if(!in_array($native['method'],['image_reference','image_to_video','omni_reference'],true))continue;
            $limits=['image'=>0,'video'=>0,'audio'=>0];$minimum=$limits;
            foreach($native['fields'] as $f){if(!array_key_exists($f['kind'],$limits))continue;$limits[$f['kind']]+=(int)($f['maximum']??$f['minimum']??0);$minimum[$f['kind']]+=max((int)($f['minimum']??0),empty($f['required'])?0:1);}
            foreach($counts as $kind=>$n)if($n>$limits[$kind]||$n<$minimum[$kind])continue 2;
            $candidates[]=$native;
        }
        if(!$candidates)throw new InvalidArgumentException('模型不支持当前图片、视频或音频组合，请调整素材');
        usort($candidates,static fn($a,$b)=>(($a['method']===$spec['generation_method'])?0:1)<=> (($b['method']===$spec['generation_method'])?0:1));
        if($spec['generation_method']!==$candidates[0]['method'])unset($spec['market_sku_id']);
        $spec['generation_method']=$candidates[0]['method'];$spec['material_mode']=$candidates[0]['key'];return $spec;
    }
}
