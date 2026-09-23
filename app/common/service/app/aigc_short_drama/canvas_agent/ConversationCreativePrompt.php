<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog;
use app\common\service\app\aigc_short_drama\ShortDramaPromptDocuments;

/** Content rules come from the original short-drama prompt workspace.
 * Agent JSON/graph instructions remain separate transport rules. */
final class ConversationCreativePrompt
{
    public static function forStage(array $workflow): string
    {
        $snapshot=(array)($workflow['workflow_snapshot']['creative_prompt_snapshot']??[]);
        if (!$snapshot) return ''; // Older frozen workflows keep their original contract.
        $stage=(string)($workflow['stage_state']['key']??'');
        $count=(string)($workflow['slot_values']['episode_count']??'');
        $multi=preg_match('/^\s*([2-9]|[1-9][0-9]+)/u',$count)===1;
        $base=['multi'=>$multi,'stage'=>$multi?'story':'production','first_frame'=>true,'subject_count'=>1];
        $documents=match ($stage) {
            'script'=>['script','storyboard'],
            'art'=>['script','storyboard'],
            'assets'=>['subject_image','subject_views'],
            'storyboard'=>['scene_image','shot_image','storyboard'],
            'video_plan','video_nodes'=>['shot_video'],
            default=>[],
        };
        if (!$documents) return '';
        $parts=[];
        foreach ($documents as $id) {
            $context=$base;
            if ($id==='shot_image' || $id==='shot_video') $context['empty']=false;
            $text=trim(ShortDramaPromptDocuments::renderSnapshot($snapshot,$id,$context));
            if ($text!=='') $parts[]='【原短剧流程：'.ShortDramaPromptDocuments::definition()['documents'][$id]['label']."】\n".$text;
        }
        if (!$parts) return '';
        return "\n【共用创作提示词】以下内容直接来自本工作流开始时冻结的原短剧创作配置；只用于内容创作，不改变上面的 JSON 输出、画布操作、计费和安全边界。空镜/多人/尾帧等具体镜头条件仍由节点内容和实际素材决定。\n"
            .implode("\n\n",$parts)."\n".ShortDramaPromptCatalog::priority();
    }
}
