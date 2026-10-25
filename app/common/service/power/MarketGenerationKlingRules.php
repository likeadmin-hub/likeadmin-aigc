<?php
namespace app\common\service\power;

/** Pure validation matching the upstream Kling 4 material contract; media facts remain provider-validated. */
final class MarketGenerationKlingRules
{
    public static function valid(string $check,array $p):bool
    {
        if($check==='frames')return (int)!empty($p['first_frame_url'])+count($p['keyframe_urls']??[])+(int)!empty($p['last_frame_url'])<=min((int)($p['duration']??5)+1,10);
        if($check==='quota'){
            $videos=count(array_filter($p['elements']??[],static fn($e)=>!empty($e['video_url'])));
            return $videos<=3&&$videos+count($p['video_urls']??[])<=5&&array_sum(array_map(static fn($k)=>count($p[$k]??[]),['image_urls','video_urls','elements','voices']))<=15;
        }
        if($check==='subjects'){
            foreach($p['elements']??[] as $e){
                $images=$e['image_urls']??[];$video=!empty($e['video_url']);
                if(array_diff(array_keys($e),['name','image_urls','video_url','tag','description','voice_url']))return false;
                if($video===($images!==[]))return false;
                if(!$video&&(count($images)<2||count($images)>4))return false;
                if(($video||!empty($e['voice_url']))&&($e['tag']??'character')!=='character')return false;
            }return true;
        }
        if($check==='names'){
            $names=[];foreach($p['voices']??[] as $voice)if(array_diff(array_keys($voice),['name','audio_url']))return false;
            foreach(array_merge($p['elements']??[],$p['voices']??[]) as $e){
                $name=(string)($e['name']??'');$lower=mb_strtolower($name);
                if(!preg_match('/^[一-龥A-Za-z0-9_]{1,20}$/uD',$name)||preg_match('/^(?:first_frame|last_frame|(?:image|video|keyframe|object|audio)_\d+)$/iD',$name)||isset($names[$lower]))return false;
                $names[$lower]=true;
            }return true;
        }
        if($check==='prompt'){
            $prompt=(string)($p['prompt']??'');if(trim($prompt)==='')return false;$names=[];
            foreach(['first_frame_url'=>'first_frame','last_frame_url'=>'last_frame'] as $f=>$n)if(!empty($p[$f]))$names[]=$n;
            foreach(['image_urls'=>'image','video_urls'=>'video','keyframe_urls'=>'keyframe'] as $f=>$prefix)foreach($p[$f]??[] as $i=>$v)$names[]=$prefix.'_'.($i+1);
            foreach(array_merge($p['elements']??[],$p['voices']??[]) as $e)if(isset($e['name']))$names[]=$e['name'];
            usort($names,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
            foreach($names as $name){$boundary=preg_match('/[A-Za-z0-9_]$/D',$name)?'(?![A-Za-z0-9_])':'';$prompt=preg_replace('/@'.preg_quote($name,'/').$boundary.'/u','',$prompt);}
            return !str_contains($prompt,'@');
        }
        return true; // Measured-media rules are enforced before freezing by validateMetadata/facts.
    }
}
