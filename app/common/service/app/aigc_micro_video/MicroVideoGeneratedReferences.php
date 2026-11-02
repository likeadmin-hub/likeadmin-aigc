<?php
namespace app\common\service\app\aigc_micro_video;

/** Generated authoring references only. Execution assets retain their separate ownership authority. */
final class MicroVideoGeneratedReferences
{
    public static function contract(array $snapshot): array
    {
        $count=count((array)($snapshot['images']??[]));
        return ['image_count'=>$count,'allowed_image_indices'=>$count?range(0,$count-1):[],
            'requirements'=>'image_indices只引用project.images的零起始索引，不是角色、商品或分镜图片的索引。仅使用allowed_image_indices中确实需要的图片；没有全局图片或暂未绑定时必须输出空数组[]，禁止虚构图片绑定。'];
    }

    /** Drop invented out-of-range bindings; never fill an empty binding or borrow role/product images. */
    public static function script(array $script,array $snapshot,?array $currentImages=null): array
    {
        $images=array_values((array)($snapshot['images']??[]));
        foreach($script['segments']??[] as $n=>$segment){
            // Malformed schemas remain errors in the existing strict validator.
            if(!is_array($segment)||!is_array($segment['image_indices']??null))continue;
            $script['segments'][$n]['image_indices']=array_values(array_filter($segment['image_indices'],static function($index)use($images,$currentImages){
                if(!is_int($index))return true;
                if($index<0||!array_key_exists($index,$images))return false;
                if($currentImages===null)return true;
                // Adoption must not retarget a frozen binding after global images were replaced/reordered.
                $prior=(array)$images[$index];$current=(array)($currentImages[$index]??[]);
                if(empty($prior['uri'])||($prior['uri']??null)!==($current['uri']??null))return false;
                foreach(['storage_scope','storage_engine','storage_domain'] as $field)if(($prior[$field]??'')!==($current[$field]??''))return false;
                return true;
            }));
        }
        return $script;
    }
}
