<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use InvalidArgumentException;

final class ProductVideoPolicy
{
    public const APP = 'aigc_product_video';
    public const ACTIONS = ['product','reference','plan','storyboard','video','chapters'];
    public static function json($value): array {return is_array($value)?$value:(json_decode((string)$value,true)?:[]);}
    public static function encode(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function hash(array $value): string
    {
        $sort=static function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map($sort,$v);};
        return hash('sha256',self::encode($sort($value)));
    }
    public static function text($value,int $max): string
    {if(!is_string($value)&&!is_numeric($value))throw new InvalidArgumentException('内容格式不正确');$text=trim((string)$value);if(mb_strlen($text)>$max)throw new InvalidArgumentException('内容超过长度限制');return $text;}
    public static function key(string $value): string
    {if(!preg_match('/^[a-zA-Z0-9_-]{16,100}$/D',$value))throw new InvalidArgumentException('请刷新后重新提交');return $value;}
    public static function body(array $body): array
    {
        $mode=$body['mode']??'normal';if(!in_array($mode,['normal','advanced'],true))throw new InvalidArgumentException('创作模式无效');
        $images=$body['images']??[];if(!is_array($images)||count($images)>15)throw new InvalidArgumentException('商品图片最多 15 张');
        $out=['mode'=>$mode,'images'=>$images,'reference_video'=>$body['reference_video']??null,
            'product_info'=>self::text($body['product_info']??'',3000),'prompt'=>self::text($body['prompt']??'',12000),
            'requirements'=>self::text($body['requirements']??'',2000),'template_code'=>self::text($body['template_code']??'detail',50),
            'language'=>self::text($body['language']??'中文',60),'plan'=>$body['plan']??null,'reference_analysis'=>$body['reference_analysis']??null,
            'storyboard'=>$body['storyboard']??null,'analysis_model_id'=>self::text($body['analysis_model_id']??'',100),
            'image_selection'=>(array)($body['image_selection']??[]),'video_selection'=>(array)($body['video_selection']??[])];
        if(strlen(self::encode($out))>160000)throw new InvalidArgumentException('创作资料过大');return $out;
    }
    /** All plan-dependent inputs, including ratio and model/SKU identity, participate. */
    public static function planKey(array $body): string
    {
        $inputs=array_intersect_key($body,array_flip(['product_info','requirements','template_code','language','video_selection']));
        $inputs['images']=array_map(static fn($i)=>array_intersect_key($i,array_flip(['uri','role'])),$body['images']??[]);
        $inputs['reference_video']=$body['reference_video']['uri']??'';
        $inputs['reference_analysis']=$body['reference_analysis']??null;return self::hash($inputs);
    }
    public static function requestKey(array $request): string
    {
        $stable=static function($v)use(&$stable){if(!is_array($v))return $v;foreach(['url','video_url','image_url'] as $k)unset($v[$k]);foreach($v as $k=>$x)if(is_array($x))$v[$k]=$stable($x);return $v;};
        return self::hash($stable($request));
    }
    public static function plan(array $plan,int $duration): array
    {
        $shots=$plan['shots']??null;if(!is_array($shots)||count($shots)<2||count($shots)>16)throw new InvalidArgumentException('分镜需要 2～16 个镜头');
        $end=0.;$out=[];
        foreach($shots as $index=>$shot){
            if(!is_array($shot)||!is_numeric($shot['start']??null)||!is_numeric($shot['end']??null))throw new InvalidArgumentException('分镜时间格式不正确');
            $start=(float)$shot['start'];$next=(float)$shot['end'];
            if(!is_finite($start)||!is_finite($next)||abs($start-$end)>0.01||$next<=$start||$next>$duration+0.01)throw new InvalidArgumentException('分镜时间需要从 0 秒连续覆盖视频时长');
            $description=self::text($shot['description']??'',1800);if($description==='')throw new InvalidArgumentException('请补充分镜画面');
            $out[]=['id'=>'shot_'.($index+1),'start'=>$start,'end'=>$next,'description'=>$description];$end=$next;
        }
        if(abs($end-$duration)>0.01)throw new InvalidArgumentException('分镜总时长与生成时长不一致');
        return ['summary'=>self::text($plan['summary']??'',1000),'shots'=>$out,'duration'=>$duration];
    }
    public static function prompt(array $body): string
    {
        $base='以用户商品图片和已确认资料为准，保持商品轮廓、材质、颜色与标识一致，不添加未确认的价格、功效、认证、字幕或水印。';
        $base.='商品资料：'.$body['product_info'].'。表达语言：'.$body['language'].'。';
        if($body['mode']==='normal')return $base.$body['prompt'];
        $duration=(int)($body['video_selection']['duration']??0);$plan=self::plan((array)($body['plan']['data']??[]),$duration);
        if(($body['plan']['input_key']??'')!==self::planKey($body))throw new InvalidArgumentException('商品资料或视频规格已变化，请更新分镜');
        $lines=[];foreach($plan['shots'] as $shot)$lines[]=$shot['start'].'-'.$shot['end'].'秒：'.$shot['description'];
        return $base.$plan['summary']."\n".implode("\n",$lines);
    }
}
