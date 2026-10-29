<?php
namespace app\common\service\power;

/** Extract encoded media slots without probing, deduplicating, trusting duration hints or following links. */
final class MarketGenerationMediaInputs
{
    public static function extract(array $native): array
    {
        $result=['image'=>[],'video'=>[],'audio'=>[]];
        $walk=static function($value,string $kind='')use(&$walk,&$result):void{
            if(is_array($value)){
                if(is_string($value['type']??null))$kind=match(strtolower($value['type'])){
                    'image','image_url','input_image','reference_image','first_frame','last_frame'=>'image',
                    'video','video_url','input_video','reference_video'=>'video',
                    'audio','audio_url','input_audio','reference_audio'=>'audio',default=>'',
                };
                foreach($value as$key=>$child){
                    if(is_string($key)&&in_array($key,['prompt','text','negative_prompt','description','callback_url','webhook','model','channel','role','type','name'],true))continue;
                    $next=is_int($key)?$kind:(preg_match('/video|clip/i',(string)$key)?'video':(preg_match('/audio|voice/i',(string)$key)?'audio':(preg_match('/image|frame/i',(string)$key)?'image':$kind)));
                    $walk($child,$next);
                }return;
            }
            if(isset($result[$kind])&&is_string($value)&&preg_match('~^(?:https?://|data:|asset://)~i',$value))$result[$kind][]=$value;
        };
        $walk($native);return $result;
    }
    /** Ambiguous visual slots are measured from bytes, never from a URL suffix or browser type. */
    public static function visualUrls(array $native): array
    {
        $urls=[];
        $walk=static function(array $value)use(&$walk,&$urls):void{
            foreach($value as$key=>$child){
                if(in_array($key,['prompt','text','negative_prompt','description','callback_url','webhook','name'],true))continue;
                if($key==='element_input_urls'){
                    foreach((array)$child as$url)if(is_string($url)&&$url!=='')$urls[]=$url;
                }elseif(is_array($child))$walk($child);
            }
        };
        $walk($native);return $urls;
    }

}
