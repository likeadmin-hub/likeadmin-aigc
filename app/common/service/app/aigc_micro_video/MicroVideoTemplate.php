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
        if(!$segments||array_sum(array_column($segments,'duration'))!==$body['default_duration'])$body['requires_sync']=true;
        foreach($segments as $segment){$timeline=self::shots((string)($segment['prompt']??''));if(!$timeline||(float)end($timeline)['end']!==(float)($segment['duration']??0))$body['requires_sync']=true;}
        return $body;
    }
    public static function shots(string $text): array
    {
        preg_match_all('/^\h*(?:[-*#]+\h*)?[\[【]?(-?\d+(?:\.\d+)?)\h*(?:秒|s)?\h*[-–—~～至]\h*(-?\d+(?:\.\d+)?)\h*(?:秒|s)[\]】]?\h*[:：]\h*([^\r\n]+)$/imu',$text,$rows,PREG_SET_ORDER);
        preg_match_all('/^\h*(?:[-*#]+\h*)?[\[【]?\d+(?:\.\d+)?\h*(?:秒|s)?\h*[-–—~～至]\h*\d/imu',$text,$candidates);
        if(count($candidates[0])!==count($rows))return [];
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
    public static function values(array $body): array
    {
        $values=(array)($body['template_values']??[]);$product=$body['product_snapshot']??null;$data=(array)($product['body']??[]);
        if($product)$values=array_replace($values,['商品名称'=>(string)($product['title']??''),'已确认商品事实'=>implode('；',array_filter([$data['features']??'',$data['selling_points']??'',$data['facts']['description']??''])),'目标人群'=>$data['audience']??'','必须表达'=>$data['must_say']??'','禁止表述'=>$data['forbidden']??'']);
        if(empty($values['目标人群']))$values['目标人群']=$body['brief']['audience']??'';
        $values['使用场景']??='';$values['商品']=$values['商品名称']??'';
        return array_map(static fn($v)=>is_scalar($v)?mb_substr((string)$v,0,6000):'',$values);
    }
    public static function render(string $text,array $values): string
    {
        return preg_replace_callback('/〔([^〔〕]+)〕|\{\{\h*([^{}]+?)\h*\}\}/u',static function($m)use($values){$key=$m[1]?:trim($m[2]);$value=trim((string)($values[$key]??''));return $value!==''?$value:$m[0];},$text);
    }
    public static function bind(array $body): array
    {
        $source=$body['template_snapshot']['body']??null;if(!$source)return $body;
        $values=self::values($body);$previous=(array)($body['template_applied_values']??[]);
        $bind=static fn($text,$original)=>(string)$text===self::render((string)$original,$previous)?self::render((string)$original,$values):self::render((string)$text,$values);
        $body['script']['text']=$bind($body['script']['text']??'',$source['script']??'');
        foreach($body['script']['segments']??[] as $i=>$segment)foreach(['prompt','start_state','end_state','continuity'] as $key)$body['script']['segments'][$i][$key]=$bind($segment[$key]??'',$source['segments'][$i][$key]??'');
        foreach($body['roles']??[] as $i=>$role)$body['roles'][$i]['description']=$bind($role['description']??'',$source['roles'][$i]['description']??'');
        $body['template_values']=$values;$body['template_applied_values']=$values;return $body;
    }
    public static function unresolved(array $body): array
    {
        $text=implode("\n",array_merge([(string)($body['script']['text']??'')],array_column((array)($body['script']['segments']??[]),'prompt')));
        preg_match_all('/〔([^〔〕]+)〕|\{\{\h*([^{}]+?)\h*\}\}/u',$text,$matches,PREG_SET_ORDER);
        return array_values(array_unique(array_map(static fn($m)=>$m[1]?:trim($m[2]),$matches)));
    }
}
