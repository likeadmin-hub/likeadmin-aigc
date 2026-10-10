<?php
namespace app\common\service\app\aigc_short_drama;

/** Schema projection only: preserve source fields, prose, prompts and timing. */
final class ShortDramaResultProjection
{
    public static function normalize(array $plan): array
    {
        // Once projected, an intentional editor deletion must stay deleted.
        $legacy=empty($plan['_result_projection_version']);
        $aliases=['subjects'=>['character_profiles','characters','roles'],
            'locations'=>['scenes','scene_script'], 'storyboard'=>['storyboard_script','shots','shot_list']];
        foreach($aliases as $key=>$sources) {
            if ($legacy && empty($plan[$key])) foreach($sources as $source) {
                $items=self::items($plan[$source]??null);
                if($items) {$plan[$key]=$items;break;}
            }
            if(isset($plan[$key]) && is_array($plan[$key])) $plan[$key]=self::items($plan[$key]);
        }
        foreach((array)($plan['subjects']??[]) as $i=>$item) {
            $plan['subjects'][$i]=self::fields($item,['id'=>['character_id','subject_id'],'name'=>['character_name','role_name'],
                'visual_prompt'=>['visual_features','appearance']]);
        }
        foreach((array)($plan['locations']??[]) as $i=>$item) {
            $plan['locations'][$i]=self::fields($item,['id'=>['scene_id','location_id'],'name'=>['location','scene_name'],
                'description'=>['atmosphere','setting'],'time_of_day'=>['time']]);
        }
        foreach((array)($plan['storyboard']??[]) as $i=>$item) {
            $plan['storyboard'][$i]=self::fields($item,['shot_id'=>['id'],'scene_ref_id'=>['scene_id'],
                'subject_ref_ids'=>['character_ids'],'visual_description'=>['description'],
                'recommended_duration_seconds'=>['duration_seconds'],'dialogue'=>['dialogue_text']]);
        }
        $plan=self::fields($plan,['title'=>['project_title'],'story_outline'=>['logline'],'core_theme'=>['theme']]);
        if($legacy && empty($plan['script_lines'])) {
            $lines=[];
            foreach(self::items($plan['scene_script']??null) as $scene) {
                foreach((array)($scene['dialogue']??[]) as $line) {
                    if(is_string($line)) {$lines[]=$line;continue;}
                    if(!is_array($line) || !is_string($line['line']??null)) continue;
                    $prefix=implode(' ',array_filter([(string)($line['timestamp']??''),(string)($line['character']??'')]));
                    $lines[]=($prefix!==''?$prefix.'：':'').$line['line'];
                }
            }
            if($lines) $plan['script_lines']=$lines;
        }
        $plan['_result_projection_version']=1;
        return $plan;
    }
    private static function items(mixed $value): array
    {
        if(!is_array($value) || !$value) return [];
        if(!array_is_list($value)) {
            if(array_intersect(['id','scene_id','shot_id','character_id','name','location'],array_keys($value))) return [$value];
            // Do not infer identities from arbitrary object keys.
        }
        return array_values(array_filter($value,'is_array'));
    }
    private static function fields(array $item,array $aliases): array
    {
        foreach($aliases as $key=>$sources) {
            if(isset($item[$key]) && $item[$key]!=='' && $item[$key]!==[]) continue;
            foreach($sources as $source) if(isset($item[$source]) && $item[$source]!=='' && $item[$source]!==[]) {
                $item[$key]=$item[$source];break;
            }
        }
        return $item;
    }
}
