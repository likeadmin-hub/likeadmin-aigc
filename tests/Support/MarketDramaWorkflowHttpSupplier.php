<?php
/** Only loaded inside the owner-marked loopback supplier process. */
function marketDramaWorkflowReply(string $dir): string
{
    $unit=think\facade\Db::name('aigc_short_drama_planning_unit')->where('status','running')->order('id','desc')->find();
    $key=(string)($unit['unit_key']??'intake');
    file_put_contents($dir.'/workflow-supplier.jsonl',json_encode(['unit'=>$key])."\n",FILE_APPEND|LOCK_EX);
    $base=['title'=>'Synthetic workflow 色块小剧','type_judgement'=>'生活短剧','core_theme'=>'有序完成动作',
        'story_outline'=>'小方在白色摄影棚走到标记，再向镜头挥手。','script_lines'=>['小方走到标记，站定后挥手。'],
        'subjects'=>[['id'=>'person','name'=>'小方','category'=>'character','description'=>'穿纯红色上衣的成年虚构人物，黑色短发。','role'=>'主角','arc'=>'从走动到站定']],
        'locations'=>[['id'=>'studio','name'=>'白色摄影棚','description'=>'白墙、白地面和地面黑色站位标记。','interior_exterior'=>'interior']],
        'art_style'=>['style'=>'电影写实','color'=>'红白黑'],
        'series_bible'=>['audience'=>'成人','core_hook'=>'清晰动作','logline'=>'小方完成两个简单动作','relationships'=>['小方是唯一人物'],'world_rules'=>['现实摄影棚'],'series_arc'=>'开始走动，完成站位，挥手收束。']];
    if($key==='intake')$reply=['reply_markdown'=>'请核对制作设定后开始。','intake'=>['candidates'=>[],'questions'=>[]]];
    elseif(preg_match('/^story(?:_repair)?$/',$key))$reply=$base+['episodes'=>[],'storyboard'=>[]];
    elseif(str_starts_with($key,'outline_')){
        $reply=['episodes'=>[]];foreach([1,2]as$n)$reply['episodes'][]=['episode_number'=>$n,'title'=>'色块动作'.$n,'story_outline'=>$n===1?'小方在摄影棚走到标记，再向镜头挥手。':'小方回到摄影棚站位，再向镜头挥手告别。','conflict_point'=>'准确找到站位','ending_hook'=>'挥手完成动作','target_duration_seconds'=>10];
    } elseif(str_contains($key,'timed_skeleton'))$reply=$base+['scene_beats'=>[['scene_ref_id'=>'studio','scene_name'=>'白色摄影棚','goal'=>'站定后挥手','entry'=>'小方开始走动','exit'=>'小方完成挥手','key_events'=>['走到标记','站定后挥手'],'duration_seconds'=>10,'shot_durations'=>[5,5],'scene_script'=>['action'=>['小方走到标记。','小方站定后挥手。'],'dialogue'=>[]]]]];
    elseif(str_contains($key,'timed_scene')){
        $reply=['storyboard'=>[]];foreach([1,2]as$n)$reply['storyboard'][]=['shot_id'=>'s1_'.$n,'scene_ref_id'=>'studio','scene_name'=>'白色摄影棚','subject_ref_ids'=>['person'],'interior_exterior'=>'interior','visual_description'=>$n===1?'小方在白色摄影棚走到黑色站位标记。':'小方在白色摄影棚站定，向镜头挥手。','dialogue'=>'','recommended_duration_seconds'=>5,'camera'=>'固定中景','action'=>$n===1?'走到标记':'站定挥手'];
    } elseif(str_contains($key,'continuity'))$reply=['summary'=>'小方走到标记并挥手。','changes'=>[],'hooks'=>[],'warnings'=>[]];
    else throw new RuntimeException('Unexpected private workflow unit '.$key);
    return json_encode($reply,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
