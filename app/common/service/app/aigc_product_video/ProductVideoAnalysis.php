<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;

use InvalidArgumentException;

/** Model-facing structure is converted to the existing two editable text fields. */
final class ProductVideoAnalysis
{
    public const FACT_FIELDS = [
        'product_name'=>'商品名称', 'category'=>'商品类别', 'brand'=>'可辨认品牌',
        'product_type'=>'商品类型', 'primary_features'=>'已确认的展示重点',
        'colors'=>'配色', 'materials'=>'材质与表面质感', 'structure'=>'外形与结构',
        'visible_text'=>'清晰可读文字', 'logo'=>'标识特征',
        'front'=>'正面细节', 'sides'=>'侧面细节', 'back'=>'背面细节', 'interior'=>'内部细节',
        'dimensions'=>'已确认规格', 'usage'=>'已确认用途', 'packaging'=>'包装信息',
        'craftsmanship'=>'可见工艺细节', 'visible_scene'=>'图片中的环境', 'person'=>'图片中的人物',
        'product_anchor'=>'商品一致性要点', 'source_notes'=>'依据说明',
    ];
    private const IDEA_FIELDS = ['audience'=>'适合受众', 'scenes'=>'拍摄场景', 'style'=>'视觉风格'];
    private const SHOT_FIELDS = [
        'action'=>'画面', 'framing'=>'景别与视角', 'camera'=>'运镜',
        'scene'=>'场景', 'lighting'=>'光线', 'focus'=>'对焦与质感', 'sound'=>'声音',
    ];

    /** Ignore unknown keys and empty facts; never stringify arbitrary model objects. */
    private static function value($value,int $limit=400): string
    {
        if($value===null)return '';
        if(is_array($value)){
            if(!array_is_list($value))throw new InvalidArgumentException('分析字段格式不正确');
            $parts=[];
            foreach($value as $item){
                if(!is_scalar($item)&&$item!==null)throw new InvalidArgumentException('分析字段格式不正确');
                $part=self::value($item,$limit);if($part!=='')$parts[]=$part;
            }
            $value=implode('；',array_unique($parts));
        }
        if(!is_string($value)&&!is_numeric($value))throw new InvalidArgumentException('分析字段格式不正确');
        $value=trim(preg_replace('/\s+/u',' ',(string)$value)??'');
        if(in_array(mb_strtolower($value),['','未知','未提供','无法确认','无法识别','不确定','不适用','unknown','n/a','null'],true))return '';
        return mb_strlen($value)>$limit?mb_substr($value,0,$limit-1).'…':$value;
    }

    public static function productInfo(array $data): string
    {
        if(!array_key_exists('product_profile',$data))return ProductVideoPolicy::text($data['product_info']??'',3000);
        $profile=$data['product_profile'];
        if(!is_array($profile)||($profile!==[]&&array_is_list($profile)))throw new InvalidArgumentException('商品分析结构不正确');
        $facts=[];
        foreach(self::FACT_FIELDS as $key=>$label){
            $value=self::value($profile[$key]??null);
            if($value!=='')$facts[$key]='['.$label.']：'.$value;
        }
        // A provenance note or a creative suggestion alone is not a recognized product.
        if(!array_diff_key($facts,['source_notes'=>true]))return '';
        $ideas=$data['product_ideas']??[];
        if(!is_array($ideas)||($ideas!==[]&&array_is_list($ideas)))throw new InvalidArgumentException('商品创意建议格式不正确');

        // Give identity, markings and consistency priority over long secondary details.
        $priority=['product_name','category','brand','product_type','primary_features','colors','materials','structure','visible_text','logo','product_anchor'];
        $selected=[];$remaining=3000-mb_strlen("【商品事实】\n");
        foreach(array_unique(array_merge($priority,array_keys(self::FACT_FIELDS))) as $key){
            if(!isset($facts[$key]))continue;
            $cost=mb_strlen($facts[$key])+1;
            if($cost<=$remaining){$selected[$key]=$facts[$key];$remaining-=$cost;}
        }
        $lines=['【商品事实】'];
        foreach(self::FACT_FIELDS as $key=>$label)if(isset($selected[$key]))$lines[]=$selected[$key];
        $suggestions=[];
        foreach(self::IDEA_FIELDS as $key=>$label){$value=self::value($ideas[$key]??null,180);if($value!=='')$suggestions[]='['.$label.']：'.$value;}
        if($suggestions){
            $heading='【创意建议，非商品事实】';
            foreach($suggestions as $line){
                $cost=mb_strlen($line)+1+($heading!==''?mb_strlen($heading)+1:0);
                if($cost>$remaining)continue;
                if($heading!==''){$lines[]=$heading;$heading='';}
                $lines[]=$line;$remaining-=$cost;
            }
        }
        return ProductVideoPolicy::text(implode("\n",$lines),3000);
    }

    public static function structuredPlan(array $plan): bool
    {
        if(array_key_exists('creative_brief',$plan))return true;
        foreach((array)($plan['shots']??[]) as $shot)if(is_array($shot)&&array_key_exists('action',$shot))return true;
        return false;
    }

    public static function shotDescription(array $shot): string
    {
        if(!array_key_exists('action',$shot))return ProductVideoPolicy::text($shot['description']??'',1800);
        $action=self::value($shot['action']);
        if($action==='')throw new InvalidArgumentException('请补充分镜主体与动作');
        $lines=[];
        foreach(self::SHOT_FIELDS as $key=>$label){
            $value=$key==='action'?$action:self::value($shot[$key]??null,200);
            if($value!=='')$lines[]=$label.'：'.$value;
        }
        return ProductVideoPolicy::text(implode('；',$lines),1800);
    }

    /** The visible description and timed shots share one source of truth. */
    public static function videoDescription(array $plan,array $brief,array $context): string
    {
        $lines=['【整体设置】','总时长：'.$plan['duration'].'秒'];
        $ratio=ProductVideoPolicy::text($context['video_selection']['ratio']??'',30);
        if($ratio!=='')$lines[]='画面比例：'.$ratio;
        foreach(['subject'=>'展示主体','style'=>'视觉风格'] as $key=>$label){$value=self::value($brief[$key]??null,400);if($value!=='')$lines[]=$label.'：'.$value;}
        $lines[]='商品一致性：以商品图片和已确认资料为准，保持轮廓、配色、材质与可见文字标识一致；不补造未展示的背面、内部或包装。';
        $audio=($context['audio']??null)===false?'无声，不生成配乐、人声或音效':self::value($brief['audio']??null,400);
        $lines[]='声音：'.($audio!==''?$audio:'沿用模型默认设置，不额外指定口播');
        $text=self::value($brief['text']??null,400);
        $lines[]='画面文字：'.($text!==''?$text:'不叠加额外字幕、文字或水印');
        foreach($plan['shots'] as $index=>$shot)$lines[]="\n【镜头".($index+1).'｜'.$shot['start'].'-'.$shot['end']."秒】\n".$shot['description'];
        return ProductVideoPolicy::text(implode("\n",$lines),12000);
    }
}
