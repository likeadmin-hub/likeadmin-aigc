<?php
namespace app\common\service\app\aigc_micro_video;

/** Pure compatibility and synchronization for versioned template/project JSON. */
final class MicroVideoTemplate
{
    public static function normalize(array $body): array
    {
        $segments=(array)($body['segments']??[]);$duration=array_sum(array_column($segments,'duration'));
        $shots=self::shots((string)($body['script']??''));
        $body['default_duration']=(int)($body['default_duration']??($duration?:($shots?end($shots)['end']:30)));
        if(!$segments&&$shots){$segments=[['sort'=>1,'title'=>'剧情片段','duration'=>(int)end($shots)['end'],'prompt'=>$body['script'],'start_state'=>$shots[0]['description'],'end_state'=>end($shots)['description'],'continuity'=>$body['reference_rules']??'保持人物、商品、场景与光线一致。','image_indices'=>[]]];}
        $body['segments']=$segments;$body['durations']=[$body['default_duration']];$body['roles']??=[];$body['variables']??=[];$body['structure_version']=2;
        return $body;
    }
    public static function shots(string $text): array
    {
        preg_match_all('/^\h*(?:[-*#]+\h*)?[\[【]?(-?\d+(?:\.\d+)?)\h*(?:秒|s)?\h*[-–—~～至]\h*(-?\d+(?:\.\d+)?)\h*(?:秒|s)[\]】]?\h*[:：]\h*([^\r\n]+)$/imu',$text,$rows,PREG_SET_ORDER);
        $shots=[];$end=0;
        foreach($rows as $r){if(abs((float)$r[1]-$end)>0.00001||(float)$r[2]<=(float)$r[1])return [];$end=(float)$r[2];$shots[]=['start'=>(float)$r[1],'end'=>$end,'description'=>trim($r[3])];}
        return count($shots)>=2&&floor($end)===$end?$shots:[];
    }
    public static function synchronize(array $body,array $old): array
    {
        $body=MicroVideoWorkbenchPolicy::normalizeValues($body);$old=MicroVideoWorkbenchPolicy::normalizeValues($old);
        $script=(array)($body['script']??[]);$before=(array)($old['script']??[]);
        $text=(string)($script['text']??'');$segments=(array)($script['segments']??[]);
        $changedText=$text!==(string)($before['text']??'');
        $content=static fn($rows)=>array_map(static fn($s)=>array_intersect_key($s,array_flip(['sort','duration','prompt'])),(array)$rows);
        $changedSegments=MicroVideoWorkbenchPolicy::hash($content($segments))!==MicroVideoWorkbenchPolicy::hash($content($before['segments']??[]));
        if($old&&$changedText&&!$changedSegments&&$segments)$script['requires_sync']=trim($text)!==trim(self::text($segments));
        elseif($changedSegments){
            if(!$changedText&&!empty($before['requires_sync']))$script['requires_sync']=true;
            else {if(!$changedText)$script['text']=self::text($segments);$script['requires_sync']=(bool)($script['requires_sync']??false);}
        }
        else $script['requires_sync']=(bool)($before['requires_sync']??$script['requires_sync']??false);
        if(empty($script['requires_sync']))unset($script['requires_sync']);
        $body['script']=$script;return $body;
    }
    public static function text(array $segments): string
    {
        $offset=0;$parts=[];
        foreach($segments as $segment){
            $parts[]=preg_replace_callback('/^(\h*(?:[-*#]+\h*)?[\[【]?)(-?\d+(?:\.\d+)?)\h*(?:秒|s)?\h*[-–—~～至]\h*(-?\d+(?:\.\d+)?)\h*(?:秒|s)[\]】]?\h*[:：]\h*([^\r\n]+)$/imu',static fn($m)=>((float)$m[2]+$offset).'-'.((float)$m[3]+$offset).'秒：'.$m[4],(string)($segment['prompt']??''));
            $offset+=(int)($segment['duration']??0);
        }
        return implode("\n",$parts);
    }
}
