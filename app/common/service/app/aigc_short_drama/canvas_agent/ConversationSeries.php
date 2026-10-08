<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Versioned series policy. Existing conversations retain their frozen flow. */
final class ConversationSeries
{
    public const VERSION = '2026-10-08.1';
    public static function enabled(array $state): bool
    {
        return !empty($state['workflow_snapshot']['series_core']);
    }
    public static function maximum(array $state,string $stage): int
    {
        return self::enabled($state) ? (['story'=>64,'outline'=>64,'episode'=>64,'art'=>64,'assets'=>64,'storyboard'=>100,'video_plan'=>60,'video_nodes'=>60,'audio_plan'=>1][$stage]??4)
            : ConversationActionPlan::maximumNodesForStage($stage,ConversationWorkflow::compactOutput($state));
    }
    public static function isScriptStage(string $stage): bool { return in_array($stage,['story','outline','episode'],true); }
    public static function count(array $state): int
    {
        preg_match('/\d+/',(string)($state['slot_values']['episode_count']??'1'),$match);
        return max(1,min(500,(int)($match[0]??1)));
    }
    public static function episode(array $state): int { return max(1,(int)($state['series']['episode_number']??1)); }
    public static function next(string $stage): string { return ['story'=>'outline','outline'=>'episode','episode'=>'art'][$stage]??''; }
    public static function taskKey(array $state): string
    {
        $stage=(string)$state['stage_state']['key'];
        return $stage==='episode'?'episode_'.self::episode($state):$stage;
    }
    public static function requiresConfirmation(array $state): bool
    {
        return self::enabled($state) && in_array($state['stage_state']['key']??'', ['story','outline'],true);
    }
    public static function assertEpisodeMedia(array $state,array $nodes): void
    {
        $boards=[];$videos=[];
        foreach ((array)$state['artifact_memory'] as $item) {
            if (($item['artifact']??'')==='storyboard') $boards[]=$item;
            if (($item['artifact']??'')==='storyboard_video') $videos[]=$item;
        }
        if (!$boards || count($boards)!==count($videos)) throw new RuntimeException('WORKFLOW_EPISODE_MEDIA_INCOMPLETE');
        $byId=[];foreach($nodes as $node) $byId[(string)$node['id']]=$node;
        foreach ($videos as $item) {
            $meta=(array)($byId[(string)$item['node_id']]['metadata']??[]);
            if (($meta['status']??'')!=='success' || empty($meta['video_url']) || (int)($meta['asset_id']??0)<=0) throw new RuntimeException('WORKFLOW_EPISODE_MEDIA_INCOMPLETE');
        }
    }
    public static function nextEpisode(array $state): array
    {
        if (!self::enabled($state) || ($state['stage_state']['key']??'')!=='complete'
            || self::episode($state)>=self::count($state)) throw new RuntimeException('WORKFLOW_NEXT_EPISODE_UNAVAILABLE');
        $episode=self::episode($state);
        $state['series']['completed_episodes']=array_values(array_unique(array_merge((array)($state['series']['completed_episodes']??[]),[$episode])));
        $state['series']['episode_number']=$episode+1;
        // Keep canonical series text and reusable visual assets; the completed
        // episode's nodes remain in the graph, not in every later model input.
        $state['artifact_memory']=array_values(array_filter((array)$state['artifact_memory'],static fn($item)=>
            in_array($item['stage']??'',['story','outline'],true)));
        $state['stage_state']=['key'=>'episode','status'=>'ready','completed'=>['intake','story','outline']];
        $state['stage_plan']=[];$state['image_plan']=[];$state['plan_hash']='';$state['plan_confirmation']=['status'=>'not_required'];
        $state['state_revision']++;
        return $state;
    }
    /** Project production data to readable text. Never truncate a source silently. */
    public static function text(array $plan,string $stage,int $episode): string
    {
        if ($stage==='outline') {
            $lines=['# 分集大纲'];
            foreach ((array)($plan['episodes']??[]) as $item) {
                $lines[]='## 第'.($item['episode_number']??'').'集：'.($item['title']??'');
                foreach (['story_outline','conflict_point','ending_hook','source_content'] as $field)
                    if (!empty($item[$field])) $lines[]=(string)$item[$field];
            }
            if (count($lines)===1) $lines[]=(string)($plan['story_outline']??'');
            return implode("\n\n",$lines);
        }
        $labels=['title'=>'剧名','type_judgement'=>'类型','core_theme'=>'主题','story_outline'=>'剧情','series_bible'=>'全剧设定','subjects'=>'主体','locations'=>'场景'];
        if ($stage==='episode') $labels+=['script_lines'=>'剧本正文','storyboard'=>'分镜脚本'];
        $lines=[$stage==='episode'?'# 第'.$episode.'集剧本':'# 故事设定'];
        foreach ($labels as $field=>$label) if (!empty($plan[$field])) {
            $value=$plan[$field];
            if (is_array($value)) $value=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
            $lines[]='## '.$label."\n".$value;
        }
        return implode("\n\n",$lines);
    }
    public static function proposals(array $plan,string $stage,int $episode): array
    {
        $text=self::text($plan,$stage,$episode);
        // Split display artifacts only. The full structured result is durable
        // in the same production task used by conventional short-drama pages.
        $parts=mb_str_split($text,18000,'UTF-8');
        if (count($parts)>64) throw new RuntimeException('CANVAS_CAPACITY_EXCEEDED');
        return array_map(static fn($part,$i)=>['type'=>'text','artifact'=>['story'=>'story_setting','outline'=>'series_outline','episode'=>'episode_script'][$stage],
            'title'=>mb_substr((string)($plan['title']??'短剧'),0,40).' · '.(['story'=>'故事设定','outline'=>'分集大纲','episode'=>'第'.$episode.'集剧本'][$stage]).(count($parts)>1?'（'.($i+1).'）':''),
            'prompt'=>$part,'key'=>$stage.'_'.($stage==='episode'?$episode.'_':'').($i+1)],$parts,array_keys($parts));
    }
}
