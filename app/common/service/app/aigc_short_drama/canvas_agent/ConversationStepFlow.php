<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** User-controlled production boundaries; no generation or billing in this policy. */
final class ConversationStepFlow
{
    public static function enabled(array $state): bool { return ConversationSeries::enabled($state); }

    public static function remainingVideos(array $state): array
    {
        $created=[];$items=[];
        foreach ((array)($state['artifact_memory']??[]) as $item) {
            if (($item['artifact']??'')==='storyboard_video' && !empty($item['node_id'])) $created[(string)$item['key']]=true;
        }
        foreach ((array)($state['artifact_memory']??[]) as $item) {
            if (($item['stage']??'')==='video_plan' && !isset($created[(string)$item['key']]))
                $items[]=['key'=>(string)$item['key'],'title'=>(string)$item['title'],'content'=>(string)$item['content']];
        }
        return $items;
    }

    public static function canPrepareViews(array $state): bool
    {
        $subjects=[];$views=[];$characters=null;
        foreach ((array)($state['artifact_memory']??[]) as $item) if (($item['artifact']??'')==='art_bible') {
            $plan=json_decode((string)($item['content']??''),true);
            if (is_array($plan['subjects']??null)) {
                $characters=[];
                foreach ($plan['subjects'] as $subject) if (in_array($subject['category']??'character',['character','animal'],true)) $characters[]=substr(ConversationSeriesVisuals::key('subject',(string)$subject['id']),8);
            }
        }
        foreach ((array)($state['artifact_memory']??[]) as $item) {
            if (($item['stage']??'')!=='assets' || empty($item['node_id'])) continue;
            if (($item['artifact']??'')==='subject') $subjects[]=substr((string)$item['key'],8);
            if (($item['artifact']??'')==='three_view') $views[]=substr((string)$item['key'],5);
        }
        if ($characters!==null) $subjects=array_intersect($subjects,$characters);
        return (bool)array_diff($subjects,$views);
    }

    public static function advance(array &$state,string $stage,array $completed): array
    {
        unset($state['asset_step']);
        $next=match($stage) {
            'assets'=>'video_plan',
            'video_plan'=>'storyboard',
            'storyboard'=>'video_nodes',
            'video_nodes'=>self::remainingVideos($state)?'video_nodes':'complete',
            default=>'',
        };
        if ($next==='') return [];
        $state['image_plan']=[];$state['plan_hash']='';$state['plan_confirmation']=['status'=>'not_required'];
        unset($state['video_selection']);
        return ['key'=>$next,'status'=>in_array($next,['complete','storyboard'],true)?'ready':'awaiting_stage_start','completed'=>$completed];
    }

    public static function answer(array &$state,string $value): void
    {
        $stage=(string)$state['stage_state']['key'];
        if (($state['stage_state']['status']??'')!=='awaiting_stage_start') throw new RuntimeException('WORKFLOW_STAGE_PLAN_STALE');
        if ($value==='three_view' && in_array($stage,['storyboard','video_plan'],true)) {
            if (!self::canPrepareViews($state)) throw new RuntimeException('WORKFLOW_STAGE_PLAN_STALE');
            $state['asset_step']='three_view';
            $state['stage_state']['key']='assets';
        } elseif ($stage==='video_nodes') {
            $available=array_column(self::remainingVideos($state),'key');
            $selected=explode(',',$value);
            if (!$selected || count($selected)!==count(array_unique($selected)) || array_diff($selected,$available)) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
            $state['video_selection']=$selected;
        } elseif ($value==='continue' && in_array($stage,['storyboard','video_plan','audio_plan'],true)) {
            // Old conversations waiting for storyboard images can use the new text-first flow.
            if ($stage==='storyboard') $state['stage_state']['key']='video_plan';
        } else throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
        $state['stage_state']['status']='ready';
    }

    public static function storyboardNodes(array $nodes): array
    {
        $parts=mb_str_split(implode("\n\n",array_column($nodes,'prompt')),18000,'UTF-8');
        return array_map(static fn($part,$index)=>['type'=>'text','artifact'=>'video_prompt_plan','key'=>'storyboard_table_'.($index+1),'title'=>'分镜表'.(count($parts)>1?'（'.($index+1).'）':''),'prompt'=>$part],$parts,array_keys($parts));
    }

    public static function card(array $state): array
    {
        $stage=(string)$state['stage_state']['key'];
        if ($stage==='video_nodes') return ['type'=>'next_stage','body'=>'分镜已插入画布，请自行生成分镜图片。需要制作视频时，再选择本次要准备的片段；视频仍由你在节点上确认生成。',
            'action_label'=>'准备所选视频片段','video_options'=>self::remainingVideos($state)];
        return ['type'=>'next_stage','body'=>'主体、场景和道具记录已保留。可按需准备角色三视图，或继续查看分镜表。',
            'action_label'=>'生成分镜表']+(self::canPrepareViews($state)?['optional_action'=>['value'=>'three_view','label'=>'准备角色三视图']]:[]);
    }
}
