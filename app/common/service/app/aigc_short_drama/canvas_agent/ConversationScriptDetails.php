<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Same public fields as the conventional script detail page, without node prompts. */
final class ConversationScriptDetails
{
    public static function text(array $plan,string $stage,int $episode): string
    {
        $lines=[$stage==='episode'?'# 第'.$episode.'集剧本':($stage==='outline'?'# 分集大纲':'# 故事设定')];
        self::fields($lines,$plan,['title'=>'剧名']);
        if($stage==='outline') {
            foreach((array)($plan['episodes']??[]) as $item) {
                if(!is_array($item)) continue;
                $lines[]='## 第'.($item['episode_number']??'').'集：'.($item['title']??'');
                self::fields($lines,$item,['story_outline'=>'剧情大纲','conflict_point'=>'核心冲突','ending_hook'=>'结尾悬念','source_content'=>'本集内容']);
            }
            if(empty($plan['episodes'])) self::fields($lines,$plan,['story_outline'=>'剧情大纲']);
            return implode("\n\n",$lines);
        }
        self::fields($lines,$plan,['type_judgement'=>'类型判断','core_theme'=>'核心主题']);
        $names=array_filter(array_map(static fn($item)=>is_array($item)?self::value($item['name']??''):'',(array)($plan['subjects']??[])));
        if($names) $lines[]='主要角色：'.implode('、',$names);
        if($stage==='episode') {
            $script=self::value($plan['script_lines']??'');
            if($script==='') $script=self::value($plan['story_outline']??'');
            if($script!=='') $lines[]="## 剧本内容\n\n".$script;
        } else {
            self::fields($lines,$plan,['story_outline'=>'故事梗概']);
            $bible=is_array($plan['series_bible']??null)?$plan['series_bible']:$plan;
            self::fields($lines,$bible,['audience'=>'目标受众','core_hook'=>'核心梗','logline'=>'一句话故事','relationships'=>'人物关系','world_rules'=>'世界规则','series_arc'=>'全剧主线与阶段反转']);
        }
        $art=[];
        self::fields($art,(array)($plan['art_style']??[]),['base_style'=>'基础风格','visual_description'=>'视觉描述','color_tone'=>'色彩基调','lighting_design'=>'光影设计','camera_texture'=>'镜头质感','atmosphere_keywords'=>'氛围关键词']);
        if($art) $lines[]="## 画风设定\n\n".implode("\n\n",$art);
        foreach(['subjects'=>'主体角色','locations'=>'场景列表'] as $key=>$title) {
            $items=[];
            foreach((array)($plan[$key]??[]) as $item) {
                if(!is_array($item)) continue;
                $detail=[];$name=self::value($item['name']??'');
                if($name!=='') $detail[]='### '.$name;
                if($key==='locations') self::fields($detail,$item,['story_phase'=>'剧情阶段']);
                $description=self::value($item['description']??'');
                if($description!=='') $detail[]=$description;
                if($detail) $items[]=implode("\n\n",$detail);
            }
            if($items) $lines[]='## '.$title."\n\n".implode("\n\n",$items);
        }
        return implode("\n\n",$lines);
    }
    private static function fields(array &$lines,array $source,array $labels): void
    {
        foreach($labels as $key=>$label) {
            $text=self::value($source[$key]??'');
            if($text!=='') $lines[]=$label.'：'.$text;
        }
    }
    private static function value(mixed $value): string
    {
        if(is_string($value) || is_numeric($value)) return trim((string)$value);
        if(!is_array($value) || !array_is_list($value)) return '';
        return implode("\n\n",array_filter(array_map(static fn($item)=>is_scalar($item)?trim((string)$item):'', $value),static fn($text)=>$text!==''));
    }
}
