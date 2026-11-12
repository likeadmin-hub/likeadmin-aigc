<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use InvalidArgumentException;

final class ProductVideoPolicy
{
    public const APP = 'aigc_product_video';
    public const ACTIONS = ['product','reference','plan','analysis','write','optimize','video','chapters'];
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
    {if(str_starts_with($value,'pipeline_')||!preg_match('/^[a-zA-Z0-9_-]{16,100}$/D',$value))throw new InvalidArgumentException('请刷新后重新提交');return $value;}
    public static function body(array $body): array
    {
        $mode=$body['mode']??'normal';if(!in_array($mode,['normal','advanced'],true))throw new InvalidArgumentException('创作模式无效');
        $images=$body['images']??[];if(!is_array($images)||count($images)>15)throw new InvalidArgumentException('商品图片最多 15 张');
        $normalSource=$body['normal_source']??'prompt';if(!in_array($normalSource,['prompt','template'],true))throw new InvalidArgumentException('视频描述来源无效');
        $referenceMode=$body['reference_mode']??'reference';if(!in_array($referenceMode,['reference','start_end'],true))throw new InvalidArgumentException('参考素材方式无效');
        $templateCode=self::text($body['template_code']??'detail',50);$template=null;foreach(ProductVideoPrompts::templates() as $item)if($item['code']===$templateCode)$template=$item;if(!$template)throw new InvalidArgumentException('创作模板不存在，请重新选择');
        $productUrl='';if(trim((string)($body['product_url']??''))!==''){try{$productUrl=ProductVideoAmazon::target((string)$body['product_url'])['url'];}catch(InvalidArgumentException){}}
        $out=['product_url'=>$productUrl,'template_version'=>$template['version'],'normal_source'=>$normalSource,'reference_mode'=>$referenceMode,'market_region'=>self::text($body['market_region']??'',100),'audio'=>isset($body['audio'])?filter_var($body['audio'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE):null,
            'analyze_chapters'=>!empty($body['analyze_chapters']),'prompt_review_images'=>array_values((array)($body['prompt_review_images']??[])),'generation_assets'=>(array)($body['generation_assets']??[]),'selected_mentions'=>(array)($body['selected_mentions']??[]),
            'mode'=>$mode,'images'=>$images,'reference_video'=>$body['reference_video']??null,
            'product_info'=>self::text($body['product_info']??'',3000),'prompt'=>self::text($body['prompt']??'',12000),
            'requirements'=>self::text($body['requirements']??'',2000),'template_code'=>$templateCode,
            'language'=>self::text($body['language']??'中文',60),'plan'=>$body['plan']??null,'reference_analysis'=>$body['reference_analysis']??null,
            'analysis_model_id'=>self::text($body['analysis_model_id']??'',100),'planning_model_id'=>self::text($body['planning_model_id']??'',100),
            'video_selection'=>(array)($body['video_selection']??[])];
        if(strlen(self::encode($out))>160000)throw new InvalidArgumentException('创作资料过大');return $out;
    }
    /** Preserve explicit roles: removing a first frame must never promote a tail frame. */
    public static function frames(array $images): array
    {
        if(count($images)<1||count($images)>2)throw new InvalidArgumentException('首尾帧模式需要首帧，尾帧可选，最多两张图片');
        $explicit=array_filter($images,static fn($i)=>in_array($i['role']??'',['first_frame_image','last_frame_image'],true));
        if(!$explicit)return $images; // Legacy drafts used ordered image slots.
        $slots=[];foreach($images as $image){$role=$image['role']??'';
            if(!in_array($role,['first_frame_image','last_frame_image'],true)||isset($slots[$role]))throw new InvalidArgumentException('请重新确认首帧与尾帧图片');
            $slots[$role]=$image;
        }
        if(!isset($slots['first_frame_image']))throw new InvalidArgumentException('请上传首帧图片');
        return array_values(array_intersect_key(array_replace(['first_frame_image'=>null,'last_frame_image'=>null],$slots),$slots));
    }
    /** All plan-dependent inputs, including ratio and model/SKU identity, participate. */
    public static function planKey(array $body): string
    {
        $inputs=array_intersect_key($body,array_flip(['product_info','requirements','template_code','template_version','language','market_region','audio','reference_mode','video_selection']));
        $inputs['images']=array_map(static fn($i)=>array_intersect_key($i,array_flip(['uri','role','reference_slot_id'])),$body['images']??[]);
        $inputs['generation_assets']=array_map(static fn($i)=>array_intersect_key($i,array_flip(['uri','type','role','reference_slot_id'])),$body['generation_assets']??[]);
        $inputs['reference_video']=$body['reference_video']['uri']??'';
        $inputs['reference_analysis']=$body['reference_analysis']??null;return self::hash($inputs);
    }
    public static function requestKey(array $request): string
    {
        $stable=static function($v)use(&$stable){if(!is_array($v))return $v;foreach(['url','video_url','image_url'] as $k)unset($v[$k]);foreach($v as $k=>$x)if(is_array($x))$v[$k]=$stable($x);return $v;};
        return self::hash($stable($request));
    }
    public static function plan(array $plan,int $duration,array $context=[]): array
    {
        $shots=$plan['shots']??null;if(!is_array($shots)||count($shots)<2||count($shots)>16)throw new InvalidArgumentException('分镜需要 2～16 个镜头');
        $end=0.;$out=[];
        foreach($shots as $index=>$shot){
            if(!is_array($shot)||!is_numeric($shot['start']??null)||!is_numeric($shot['end']??null))throw new InvalidArgumentException('分镜时间格式不正确');
            $start=(float)$shot['start'];$next=(float)$shot['end'];
            if(!is_finite($start)||!is_finite($next)||abs($start-$end)>0.01||$next<=$start||$next>$duration+0.01)throw new InvalidArgumentException('分镜时间需要从 0 秒连续覆盖视频时长');
            if(array_key_exists('action',$shot)&&($context['audio']??null)===false)$shot['sound']='无声';
            $description=ProductVideoAnalysis::shotDescription($shot);if($description==='')throw new InvalidArgumentException('请补充分镜画面');
            $out[]=['id'=>'shot_'.($index+1),'start'=>$start,'end'=>$next,'description'=>$description];$end=$next;
        }
        if(abs($end-$duration)>0.01)throw new InvalidArgumentException('分镜总时长与生成时长不一致');
        $points=[];
        foreach(array_slice((array)($plan['selling_points']??[]),0,12) as $index=>$point){
            if(!is_array($point))continue;$label=self::text($point['label']??'',300);if($label==='')continue;
            $points[]=['id'=>'point_'.($index+1),'label'=>$label,'source_slots'=>array_values(array_filter(array_map(static fn($v)=>self::text($v,128),(array)($point['source_slots']??[]))))];
        }
        $result=['summary'=>self::text($plan['summary']??'',1000),'shots'=>$out,'duration'=>$duration];
        if(isset($plan['product_info'])||array_key_exists('product_profile',$plan))$result['product_info']=ProductVideoAnalysis::productInfo($plan);
        if(ProductVideoAnalysis::structuredPlan($plan)){
            $brief=$plan['creative_brief']??[];
            if(!is_array($brief)||($brief!==[]&&array_is_list($brief)))throw new InvalidArgumentException('视频整体描述格式不正确');
            $result['video_prompt']=ProductVideoAnalysis::videoDescription($result,$brief,$context);
        }elseif(isset($plan['video_prompt']))$result['video_prompt']=self::text($plan['video_prompt'],12000);
        if($points)$result['selling_points']=$points;
        return $result;
    }
    /** Keep older text plans usable in the description field as well as generation. */
    public static function planDescription(array $plan): string
    {
        $prompt=self::text($plan['video_prompt']??'',12000);
        if($prompt!=='')return $prompt;
        $lines=[self::text($plan['summary']??'',1000)];
        foreach($plan['shots']??[] as $shot)$lines[]=$shot['start'].'-'.$shot['end'].'秒：'.$shot['description'];
        return self::text(implode("\n",array_filter($lines,static fn($line)=>$line!=='')),12000);
    }
    public static function prompt(array $body): string
    {
        $base='以用户商品图片和已确认资料为准，保持商品轮廓、材质、颜色与标识一致，不添加未确认的价格、功效、认证、字幕或水印。';
        $base.='商品资料：'.$body['product_info'].'。表达语言：'.$body['language'].'。销售地区：'.($body['market_region']??'').'。';
        if(($body['audio']??null)===false)$base.='无声音，不生成配乐或人声。';
        if($body['mode']==='normal'){
            if(($body['normal_source']??'prompt')==='template'){
                foreach(ProductVideoPrompts::templates() as $t)if($t['code']===$body['template_code'])return $base.$t['instruction'].($body['requirements']?:'');
                throw new InvalidArgumentException('请选择可用的商品展示模板');
            }
            if($body['prompt']!==''&&($body['prompt_review_images']??[])!==array_column($body['images'],'uri'))throw new InvalidArgumentException('商品已变化，请检查并确认视频描述');
            return $base.$body['prompt'];
        }
        if(empty($body['plan'])&&$body['prompt']!==''){if(($body['prompt_review_images']??[])!==array_column($body['images'],'uri'))throw new InvalidArgumentException('商品已变化，请检查并确认视频描述');return $base.$body['prompt'];}
        $duration=(int)($body['video_selection']['duration']??0);$plan=self::plan((array)($body['plan']['data']??[]),$duration);
        if(($body['plan']['input_key']??'')!==self::planKey($body))throw new InvalidArgumentException('商品资料或视频规格已变化，请更新分镜');
        return $base.self::planDescription($plan);
    }
}
