<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use app\common\service\ai\ReferenceMaterialSlot;
use InvalidArgumentException;
final class ProductVideoMaterials
{
    public static function normalize(int $tenant,int $user,array $body): array
    {
        $extras=[];if(count($body['generation_assets'])>20)throw new InvalidArgumentException('参考视频和音频总数最多 20 个');
        foreach($body['generation_assets'] as $asset){
            $type=$asset['type']??'';if(!in_array($type,['video','audio'],true))throw new InvalidArgumentException('参考媒体类型无效');
            $canonical=$type==='video'?MicroVideoMedia::validateVideo($tenant,$user,(string)($asset['uri']??'')):MicroVideoMedia::validateAudio($tenant,$user,(string)($asset['uri']??''));
            $extras[]=$canonical+['type'=>$type,'role'=>'reference_'.$type,'reference_slot_id'=>ReferenceMaterialSlot::id($asset),'mention_name'=>ProductVideoPolicy::text($asset['mention_name']??'',100)];
        }
        $body['generation_assets']=$extras;$used=[];$names=[];$mentions=[];$numbers=['image'=>0,'video'=>0,'audio'=>0];$labels=['image'=>'图片','video'=>'视频','audio'=>'音频'];
        foreach(['images','generation_assets'] as $collection)foreach($body[$collection] as $index=>&$asset){
            $kind=$collection==='images'?'image':$asset['type'];$numbers[$kind]++;
            $slot=ReferenceMaterialSlot::id($asset);if($slot==='')$slot='pv_'.substr(hash('sha256',$asset['uri'].'|'.$kind.'|'.$index),0,24);
            if(isset($used[$slot]))throw new InvalidArgumentException('素材槽位重复，请重新选择素材');$used[$slot]=true;$asset['reference_slot_id']=$slot;
            $name=ProductVideoPolicy::text($asset['mention_name']??'',100)?:$labels[$kind].$numbers[$kind];if(isset($names[$name]))throw new InvalidArgumentException('素材引用名称重复，请重新命名');$names[$name]=true;$asset['mention_name']=$name;
            $mentions[]=['name'=>$name,'type'=>$kind,'reference_slot_id'=>$slot,'uri'=>$asset['uri']];
        }unset($asset);
        foreach($body['selected_mentions'] as $old)if(is_array($old)&&!isset($used[ReferenceMaterialSlot::id($old)]))$mentions[]=['name'=>ProductVideoPolicy::text($old['name']??'',100),'type'=>ProductVideoPolicy::text($old['type']??'',20),'reference_slot_id'=>ReferenceMaterialSlot::id($old)];
        $body['selected_mentions']=$mentions;return $body;
    }
    public static function assertMentions(array $body,array $assets): void
    {
        $slots=array_column($assets,'reference_slot_id');$prompt=$body['mode']==='advanced'?($body['plan']['data']['video_prompt']??$body['prompt']):$body['prompt'];
        preg_match_all('/@(已移除图片[0-9]+|图片[0-9]+)(?![0-9])/u',$prompt,$tokens);$names=array_column($assets,'mention_name');
        foreach($tokens[1] as $name)if(!in_array($name,$names,true))throw new InvalidArgumentException('描述引用的 '.$name.' 不存在，请按当前素材重新选择引用');
        foreach($body['selected_mentions'] as $mention)if(($mention['name']??'')!==''&&str_contains($prompt,'@'.$mention['name'])&&!in_array($mention['reference_slot_id']??'',$slots,true))throw new InvalidArgumentException('描述引用的 '.$mention['name'].' 已移除，请更新描述或重新添加素材');
    }
}
