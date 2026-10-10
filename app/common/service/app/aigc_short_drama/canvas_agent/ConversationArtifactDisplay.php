<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Read-only presentation; never modifies canonical prompts or workflow memory. */
final class ConversationArtifactDisplay
{
    public static function item(array $item,string $stage=''): array
    {
        $type=(string)($item['artifact']??'');
        $content=(string)($item['content']??$item['prompt']??'');
        $stage=(string)($item['stage']??$stage);
        $result=['stage'=>$stage,'artifact'=>$type,'key'=>(string)($item['key']??''),'title'=>(string)($item['title']??''),'content'=>$content];
        if (str_ends_with($type,'_prompt') || in_array($stage,['assets','storyboard','video_plan','video_nodes','audio_plan'],true)) {
            $result['content']='';
            return $result;
        }
        $data=json_decode($content,true);
        if (is_array($data) && isset($data['art_style']) && (isset($data['subjects']) || isset($data['locations']))) {
            $result['content']=ConversationScriptDetails::art($data);
        }
        return $result;
    }
}
