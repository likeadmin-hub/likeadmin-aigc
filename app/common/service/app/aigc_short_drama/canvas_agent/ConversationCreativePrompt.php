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
        $documents=match ($stage) {
            'script'=>['script','storyboard'],
            'art'=>['subject_image','subject_views','scene_image','shot_image'],
            'assets'=>['subject_image','subject_views'],
            'storyboard'=>['scene_image','shot_image','storyboard'],
            'video_plan','video_nodes'=>['shot_video'],
            default=>[],
        };
        if (!$documents) return '';
        $parts=[];
        foreach ($documents as $id) {
            $conditions=match ($id) {
                'script'=>$multi ? ['multi','story','episodes','production'] : ['single'],
                'storyboard'=>$multi ? ['production','missing'] : ['single','missing'],
                'subject_image','subject_views'=>['character','prop','missing_character','missing_prop'],
                'scene_image'=>['missing'],
                'shot_image'=>['has_subject','empty'],
                'shot_video'=>['has_subject','empty','multi_subject','first_character','first_empty','last','missing','missing_subject','missing_empty','character'],
                default=>[],
            };
            $text=trim(ShortDramaPromptDocuments::renderSnapshotGuidance($snapshot,$id,$conditions));
            if ($text!=='') $parts[]='【原短剧流程：'.ShortDramaPromptDocuments::definition()['documents'][$id]['label']."】\n".$text;
        }
        if (!$parts) return '';
        return "\n【共用创作提示词】仅当本轮意图已判定为继续当前短剧阶段时使用；普通聊天或其他意图不得引用这些规则推进工作流。以下内容直接来自本工作流开始时冻结的原短剧创作配置；只用于内容创作，不改变上面的 JSON 输出、画布操作、计费和安全边界。带有“仅适用”标签的段落只用于符合该条件的主体或镜头；不能把人物、物品、空镜、多人、首帧、尾帧和内容缺失规则混用于同一个不符合条件的节点。条件以实际素材和节点内容为准，未确定时不要擅自假定。\n"
            .implode("\n\n",$parts)."\n".ShortDramaPromptCatalog::priority();
    }
}
