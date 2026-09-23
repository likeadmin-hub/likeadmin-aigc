<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** A public, bounded narration of a validated workflow stage, never its full artifact body. */
final class ConversationStageReply
{
    public static function present(array $workflow, string $reply, array $nodes, bool $written): string
    {
        if (($workflow['workflow_snapshot']['key'] ?? '') !== ConversationWorkflow::KEY) return $reply;
        $stage=(string)($workflow['stage_state']['key'] ?? '');
        if (!in_array($stage, ['script','art','assets','storyboard','video_plan','video_nodes','audio_plan'], true) || !$nodes) return $reply;

        $reply=trim(preg_replace('/\s+/u', ' ', $reply) ?? $reply);
        $looksLikeArtifact=mb_strlen($reply)>260
            || preg_match('/(?:[`#*_{}\[\]]|<canvas-actions>|\|\s*[-:]|(?:^|\s)(?:project_title|logline|world_setting|character_profiles|episode_outline|scene_script|storyboard_script|image_prompt|video_prompt|reference_keys|shot_number|asset_references)(?:\s|[:：])|(?:生成|正面|负面)提示词[:：])/iu', $reply)
            || (!$written && preg_match('/已(?:写入|插入|创建)(?:到|在)?画布|(?:画布)?(?:故事设定|剧本|文本|图片|视频|音频|分镜|主体|场景|节点)(?:节点)?已(?:更新|重写|替换)|图片已生成|视频已生成/u', $reply));
        if (mb_strlen($reply)>=20 && !$looksLikeArtifact) {
            if (!preg_match('/请确认|接下来|下一步|继续|逐个/u',$reply)) $reply.=' '.self::nextStep($stage);
            return $reply;
        }

        $titles=[];
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $title=trim(preg_replace('/[\r\n`#*_{}\[\]]+/u', ' ', (string)($node['title'] ?? '')) ?? '');
            if ($title!=='' && !in_array($title,$titles,true)) $titles[]=$title;
        }
        $names=implode('、',array_slice($titles,0,3));
        if (count($titles)>3) $names.='等';
        $detail=$names!=='' ? '，包括'.$names : '';
        return match ($stage) {
            'script'=>'已整理故事设定和单集剧本'.$detail.'。请确认故事与剧本方向；确认后继续画风和美术规划。',
            'art'=>'已完成画风与美术规划'.$detail.'。请确认主体、场景和风格方向；确认后继续准备图片节点。',
            'assets'=>'已准备'.count($nodes).'个主体相关图片节点'.$detail.'。请核对图片计划与预估积分；确认后再插入画布并按模式生成。',
            'storyboard'=>'已准备'.count($nodes).'个场景或分镜图片节点'.$detail.'。请核对所用素材与预估积分；确认后再生成图片。',
            'video_plan'=>'已整理分镜视频规划'.$detail.'。请确认镜头内容；确认后插入待生成的视频节点。',
            'video_nodes'=>'已'.($written?'插入':'准备').count($nodes).'个分镜视频节点'.$detail.'。视频不会自动提交，请在画布上逐个确认生成。',
            'audio_plan'=>'已'.($written?'插入':'准备').'音频规划'.$detail.'。音频生成暂未开放。',
            default=>$reply,
        };
    }

    private static function nextStep(string $stage): string
    {
        return match ($stage) {
            'script'=>'请确认故事和单集剧本，随后继续美术规划。',
            'art'=>'请确认美术方向，随后准备主体图片。',
            'assets','storyboard'=>'请核对图片计划与预计积分，再决定是否生成。',
            'video_plan'=>'请确认镜头规划，随后插入待生成的视频节点。',
            'video_nodes'=>'视频节点需在画布上逐个确认生成。',
            'audio_plan'=>'音频暂未开放生成。',
            default=>'',
        };
    }
}
