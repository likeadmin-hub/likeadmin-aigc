<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Deterministic projection of the same validated screenplay shown outside canvas. */
final class ConversationSeriesVisuals
{
    public static function key(string $kind,string $identity): string { return $kind.'_'.substr(hash('sha256',$identity),0,20); }

    public static function proposals(array $plan,string $stage,int $episode,callable $render,array $sceneReferences=[],array $workflow=[]): array
    {
        $shots=array_values((array)($plan['storyboard']??[]));
        if (!$shots) throw new RuntimeException('WORKFLOW_EPISODE_SCRIPT_MISSING');
        $subjects=[];$scenes=[];
        foreach ($shots as $shot) {
            foreach ((array)($shot['subject_ref_ids']??[]) as $id) $subjects[(string)$id]=true;
            $scenes[(string)($shot['scene_ref_id']??'')]=true;
        }
        $node=static fn($type,$artifact,$title,$prompt,$key)=>['type'=>$type,'artifact'=>$artifact,'title'=>mb_substr($title,0,80),'prompt'=>$prompt,'key'=>$key];
        if ($stage==='art') return [$node('text','art_bible','本集美术设定',json_encode([
            'art_style'=>$plan['art_style']??[], 'subjects'=>$plan['subjects']??[], 'locations'=>$plan['locations']??[]
        ],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),'episode_art')];
        $result=[];
        $threeView=($workflow['asset_step']??'')==='three_view';
        if ($stage==='assets') {
            foreach ((array)($plan['subjects']??[]) as $item) {
                if (!isset($subjects[(string)$item['id']])) continue;
                $key=self::key('subject',(string)$item['id']);
                if (!$threeView) $result[]=$node('image','subject',(string)$item['name'],$render('subject',$item),$key);
                elseif (in_array($item['category']??'character',['character','animal'],true)) {
                    $viewKey=self::key('view',(string)$item['id']);
                    $existing=false;
                    foreach ((array)($workflow['artifact_memory']??[]) as $artifact) if (($artifact['key']??'')===$viewKey && !empty($artifact['node_id'])) $existing=true;
                    if (!$existing) $result[]=$node('image','three_view',$item['name'].'三视图',$render('three_view',$item),$viewKey)+['reference_keys'=>['assets:'.$key]];
                }
            }
        }
        if ($stage==='assets' && $threeView) return $result;
        if (in_array($stage,['assets','storyboard'],true)) {
            foreach ((array)($plan['locations']??[]) as $item) if (isset($scenes[(string)$item['id']])) {
                if ($stage==='assets' || !isset($sceneReferences[(string)$item['id']])) $result[]=$node('image','scene',(string)$item['name'],$render('scene',$item),self::key('scene',(string)$item['id']));
                unset($scenes[(string)$item['id']]);
            }
            if ($scenes) throw new RuntimeException('WORKFLOW_SCENE_REFERENCE_MISSING');
            if ($stage==='assets') return $result;
        }
        foreach ($shots as $index=>$shot) {
            $number=$index+1;
            $shotKey='ep'.$episode.'_shot'.$number;
            if ($stage==='video_nodes' && ConversationStepFlow::enabled($workflow) && !in_array($shotKey,(array)($workflow['video_selection']??[]),true)) continue;
            if ($stage==='storyboard') {
                $refs=[];
                foreach ((array)($shot['subject_ref_ids']??[]) as $id) $refs[]='assets:'.self::key('subject',(string)$id);
                $sceneReference=$sceneReferences[(string)$shot['scene_ref_id']]??'';
                if ($sceneReference!=='') $refs[]=$sceneReference;
                if (count($refs)>6) throw new RuntimeException('WORKFLOW_REFERENCE_LIMIT');
                $proposal=$node('image','storyboard','分镜'.$number,$render('storyboard',$shot),$shotKey);
                if ($sceneReference==='') $proposal['depends_on']=[self::key('scene',(string)$shot['scene_ref_id'])];
                if ($refs) $proposal['reference_keys']=$refs;
                $result[]=$proposal;
            } elseif (in_array($stage,['video_plan','video_nodes'],true)) {
                $duration=(float)($shot['recommended_duration_seconds']??$shot['duration_seconds']??0);
                if ($duration<=0 || $duration>60) throw new RuntimeException('WORKFLOW_SHOT_DURATION_INVALID');
                $prompt='分镜'.$number.'：0-'.$duration.'秒'."\n".$render('storyboard_video',$shot);
                $proposal=$node($stage==='video_plan'?'text':'video',$stage==='video_plan'?'video_prompt_plan':'storyboard_video','分镜'.$number.'视频',$prompt,$shotKey);
                if ($stage==='video_nodes') {
                    $refs=['storyboard:'.$shotKey];
                    if (ConversationStepFlow::enabled($workflow)) {
                        $refs=[];
                        foreach ((array)($shot['subject_ref_ids']??[]) as $id) $refs[]='assets:'.self::key('subject',(string)$id);
                        $refs[]='assets:'.self::key('scene',(string)$shot['scene_ref_id']);
                        foreach ((array)($workflow['artifact_memory']??[]) as $artifact) if (($artifact['stage']??'')==='video_plan' && ($artifact['key']??'')===$shotKey) $proposal['prompt']=(string)$artifact['content'];
                    }
                    $proposal+=['reference_keys'=>$refs,'duration_seconds'=>$duration];
                }
                $result[]=$proposal;
            }
        }
        if ($stage==='audio_plan') {
            $prompt=trim((string)($plan['background_music_prompt']??$plan['music_prompt']??''));
            if ($prompt==='') $prompt='为本集制作纯背景音乐，不要歌词和人声。剧情：'.(string)($plan['story_outline']??'');
            return [$node('audio','audio_plan','本集背景音乐（暂未开放）',$prompt,'ep'.$episode.'_music')];
        }
        return $result;
    }
}
