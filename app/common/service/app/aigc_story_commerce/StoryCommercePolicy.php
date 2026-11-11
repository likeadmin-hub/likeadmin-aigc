<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_story_commerce;
use InvalidArgumentException;
final class StoryCommercePolicy
{
    public const APP='aigc_story_commerce';
    public const NO_STYLE='__none__';
    public static function json($v): array { return is_array($v)?$v:(json_decode((string)$v,true)?:[]); }
    public static function encode(array $v): string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function text($v,int $max=2000): string {if(!is_string($v)&&!is_numeric($v))throw new InvalidArgumentException('文字格式不正确');$v=trim((string)$v);if(mb_strlen($v)>$max)throw new InvalidArgumentException('文字超过长度限制');return $v;}
    public static function hash(array $v): string { $sort=static function($x)use(&$sort){if(!is_array($x))return $x;if(!array_is_list($x))ksort($x);return array_map($sort,$x);};return hash('sha256',self::encode($sort($v))); }
    public static function body(array $p): array {
        $images=$p['images']??[];if(!is_array($images)||count($images)>15)throw new InvalidArgumentException('商品图片数量不正确');
        return ['images'=>$images,'product_info'=>self::text($p['product_info']??'',3000),'requirements'=>self::text($p['requirements']??'',2000),
            'story_type'=>self::text($p['story_type']??'comedy',80),'custom_story'=>self::text($p['custom_story']??'',1000),
            'style_id'=>self::text($p['style_id']??'',80),'custom_style'=>($p['style_id']??'')===self::NO_STYLE?'':self::text($p['custom_style']??'',500),
            'language'=>self::text($p['language']??'中文',60),'market_region'=>self::text($p['market_region']??'',100),
            'audio'=>isset($p['audio'])?filter_var($p['audio'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE):null,
            'video_selection'=>(array)($p['video_selection']??[]),'script'=>is_string($p['script']??null)?self::text($p['script'],6000):(array)($p['script']??[]),
            'script_input_key'=>self::text($p['script_input_key']??'',64),'genre_snapshot'=>(array)($p['genre_snapshot']??[]),'style_snapshot'=>(array)($p['style_snapshot']??[])];
    }
    public static function inputKey(array $b): string {
        $v=array_intersect_key($b,array_flip(['product_info','requirements','story_type','custom_story','style_id','custom_style','genre_snapshot','style_snapshot','language','market_region','audio','video_selection']));
        $v['images']=array_map(static fn($i)=>array_intersect_key($i,array_flip(['uri','reference_slot_id','role'])), $b['images']);return self::hash($v);
    }
    public static function script(array $p,int $duration,array $body): array {
        $shots=$p['shots']??[];if(!$shots||count($shots)>24)throw new InvalidArgumentException('请添加有效的剧情镜头');
        $out=[];$last=0.;$slots=array_column($body['images'],'reference_slot_id');
        foreach($shots as $i=>$s){
            if(!is_array($s)||!is_numeric($s['start']??null)||!is_numeric($s['end']??null))throw new InvalidArgumentException('请填写镜头时间');
            $a=(float)$s['start'];$z=(float)$s['end'];
            if(!is_finite($a)||!is_finite($z)||abs($a-$last)>.01||$z<=$a||$z>$duration+.01)throw new InvalidArgumentException('镜头须从 0 秒连续覆盖所选时长');
            $action=self::text($s['action']??'',1800);if($action==='')throw new InvalidArgumentException('请填写镜头画面和动作');
            $refs=array_values((array)($s['source_slots']??[]));if(array_diff($refs,$slots))throw new InvalidArgumentException('脚本引用的商品素材已移除，请更新脚本');
            foreach(['action','dialogue','product_point','camera','sound'] as $field){
                $value=(string)($s[$field]??'');
                if(str_contains($value,'@已移除图片'))throw new InvalidArgumentException('脚本引用的商品素材已移除，请更新脚本');
                preg_match_all('/@图片(\d+)(?!\d)/u',$value,$mentions);
                foreach($mentions[1] as $number){$at=(int)$number-1;if(!isset($slots[$at]))throw new InvalidArgumentException('脚本引用的商品图片不存在');$refs[]=$slots[$at];}
            }
            $refs=array_values(array_unique($refs));
            $dialogue=self::text($s['dialogue']??'',600);if(($body['audio']??null)===false&&$dialogue!=='')throw new InvalidArgumentException('静音设置与台词冲突，请调整声音或台词');
            $out[]=['id'=>'shot_'.($i+1),'start'=>$a,'end'=>$z,'action'=>$action,'dialogue'=>$dialogue,'product_point'=>self::text($s['product_point']??'',400),
                'camera'=>self::text($s['camera']??'',400),'sound'=>($body['audio']??null)===false?'无声':self::text($s['sound']??'',400),'source_slots'=>$refs];$last=$z;
        }
        if(abs($last-$duration)>.01)throw new InvalidArgumentException('剧情脚本总时长与视频规格不一致');
        $characters=[];foreach(array_slice((array)($p['characters']??[]),0,4) as $i=>$c)$characters[]=['id'=>'character_'.($i+1),'name'=>self::text($c['name']??'',80),'appearance'=>self::text($c['appearance']??'',600)];
        return ['summary'=>self::text($p['summary']??'',1000),'characters'=>$characters,'shots'=>$out,'ending'=>self::text($p['ending']??'',600)];
    }
    /** PC accepts an editable text script; existing structured scripts remain valid. */
    public static function scriptValue($value,int $duration,array $body): array|string
    {
        if(!is_string($value))return self::script((array)$value,$duration,$body);
        $text=self::text($value,6000);
        if($text==='')throw new InvalidArgumentException('请填写剧情脚本');
        if(str_contains($text,'@已移除图片'))throw new InvalidArgumentException('脚本引用的商品素材已移除，请更新脚本');
        preg_match_all('/@图片(\d+)(?!\d)/u',$text,$matches);
        foreach($matches[1] as $n)if(!isset($body['images'][(int)$n-1]))throw new InvalidArgumentException('脚本引用的商品图片不存在');
        return $text;
    }
    public static function prompt(array $b): string {
        if(($b['script_input_key']??'')!==self::inputKey($b))throw new InvalidArgumentException('创作条件已变化，请重新分析或确认已修改的脚本');
        $s=self::scriptValue($b['script'],(int)($b['video_selection']['duration']??0),$b);
        $lines=['根据已确认商品素材创作剧情带货短片。商品外观、结构、配色、标识、用途与已确认事实保持一致；不编造功效、价格、认证和未展示的结构。',
            '商品事实：'.$b['product_info'],'剧情类型：'.($b['genre_snapshot']['name']??''),'剧情规则：'.($b['genre_snapshot']['instruction']??''),
            ($b['style_id']??'')===self::NO_STYLE
                ?'画面风格：未指定预设。遵循已确认脚本和用户补充要求中的风格描述；未描述时，根据剧情与商品自行设计连贯一致的视觉风格。'
                :'统一画面风格：'.($b['style_snapshot']['name']??'').'；'.($b['style_snapshot']['prompt']??''),
            '用户补充要求：'.$b['requirements'],'语言：'.$b['language'].'；地区：'.$b['market_region'],
            '总时长：'.($b['video_selection']['duration']??'').'秒；比例：'.($b['video_selection']['ratio']??''),'剧情：'.(is_string($s)?$s:$s['summary'])];
        foreach(is_array($s)?$s['characters']:[] as $c)$lines[]=$c['name'].'：'.$c['appearance'];
        foreach($b['images'] as $i=>$image)$lines[]='@图片'.($i+1).' 是商品参考图，保持该商品一致。';
        foreach(is_array($s)?$s['shots']:[] as $i=>$shot)$lines[]='【镜头'.($i+1).'｜'.$shot['start'].'-'.$shot['end'].'秒】画面：'.$shot['action'].'；台词：'.$shot['dialogue'].'；商品展示：'.$shot['product_point'].'；商品参考：'.implode('、',array_map(static fn($slot)=>'@图片'.(array_search($slot,array_column($b['images'],'reference_slot_id'),true)+1),$shot['source_slots'])).'；镜头：'.$shot['camera'].'；声音：'.$shot['sound'];
        if(is_array($s))$lines[]='结尾：'.$s['ending'];$lines[]=($b['audio']??null)===false?'无声，不生成任何人声、配乐或音效。':'声音遵守当前模型原生能力；不额外叠加未经要求的字幕、水印和价格。';
        return self::text(implode("\n",$lines),12000);
    }
}
