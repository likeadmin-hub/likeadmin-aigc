<?php
namespace app\common\service\app\aigc_micro_video;

use InvalidArgumentException;

/** Pure plan, retry and public presentation rules; no model calls or writes. */
final class MicroVideoPolicy
{
    public static function allowedModel(string $id,array $allowed): void
    {
        if($id===''||!in_array($id,$allowed,true))throw new InvalidArgumentException('请选择应用支持的视频模型');
    }

    public static function durations(int $target,array $supported,int $max): array
    {
        $supported=array_values(array_unique(array_filter(array_map('intval',$supported),fn($s)=>$s>0&&$s<=120)));
        if($target<1||$target>600||$max<1||!$supported)throw new InvalidArgumentException('目标时长或模型时长配置无效');
        sort($supported);$paths=[0=>[]];
        for($i=0;$i<$max;$i++){
            foreach($paths as $sum=>$parts){
                if(count($parts)!==$i)continue;
                foreach($supported as $seconds){$next=$sum+$seconds;if($next>$target+max($supported))continue;$candidate=[...$parts,$seconds];if(!isset($paths[$next])||count($candidate)<count($paths[$next]))$paths[$next]=$candidate;}
            }
        }
        unset($paths[0]);if(!$paths)throw new InvalidArgumentException('无法按当前模型规划视频时长');
        $keys=array_keys($paths);usort($keys,fn($a,$b)=>[abs($a-$target),$a<$target?1:0,count($paths[$a])]<=>[abs($b-$target),$b<$target?1:0,count($paths[$b])]);
        $best=$keys[0];if(abs($best-$target)>=min($supported))throw new InvalidArgumentException('目标时长超出当前模型与最大分段数的能力范围');
        return $paths[$best];
    }

    public static function segments(array $segments,array $durations,int $imageCount,int $referenceLimit,int $max,bool $requireTimeline=false): array
    {
        if(!$segments||count($segments)>$max)throw new InvalidArgumentException('分段数量超出配置范围');
        $out=[];
        foreach(array_values($segments) as $index=>$s){
            if(!is_array($s)||!(is_int($s['duration']??null)||(is_string($s['duration']??null)&&ctype_digit($s['duration'])))||!in_array((int)$s['duration'],$durations,true))throw new InvalidArgumentException('分段时长不受当前模型支持');
            $row=['sort'=>$index+1,'duration'=>(int)$s['duration']];
            foreach(['title','prompt','start_state','end_state','continuity'] as $field){$value=$s[$field]??'';if(!is_string($value))throw new InvalidArgumentException('分段文本字段格式无效，请重新规划');$text=trim($value);if($text===''||mb_strlen($text)>($field==='prompt'?12000:($field==='title'?200:2000)))throw new InvalidArgumentException('请完整填写分段提示词和衔接信息，标题限200字');$row[$field]=$text;}
            if($requireTimeline)MicroVideoTimeline::validate($row['prompt'],$row['duration']);
            $indices=$s['image_indices']??[];
            if(!is_array($indices)||!$indices||count($indices)>$referenceLimit)throw new InvalidArgumentException('分段引用图片数量超出模型限制');
            foreach($indices as $value)if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))throw new InvalidArgumentException('图片引用无效');
            $indices=array_values(array_unique(array_map('intval',$indices)));
            foreach($indices as $value)if($value<0||$value>=$imageCount)throw new InvalidArgumentException('分段引用图片不存在');
            $row['image_indices']=$indices;$out[]=$row;
        }
        return $out;
    }

    public static function retryIds(array $rows): array
    {
        return array_values(array_map(fn($r)=>(int)$r['id'],array_filter($rows,fn($r)=>($r['status']??'')==='failed')));
    }

    public static function publicState(string $status): array
    {
        $labels=['planning'=>'正在构思','awaiting_confirmation'=>'待确认分段','running'=>'正在生成视频','waiting_continue'=>'待继续','merging'=>'正在整理成片','success'=>'已完成','failed'=>'需要调整创作信息','canceled'=>'已取消'];
        return ['status'=>$status,'status_text'=>$labels[$status]??'待继续','can_continue'=>$status==='waiting_continue','can_show_error'=>false];
    }

    public static function fingerprint(array $data): string
    {
        $sort=function($value)use(&$sort){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);return array_map($sort,$value);};
        return hash('sha256',json_encode($sort($data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function stableParams(array $params): array
    {
        foreach($params['images']??[] as $i=>$image){unset($image['url']);$params['images'][$i]=$image;}return $params;
    }
}
