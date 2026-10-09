<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Frozen, opt-in interaction policy. Assumptions never become user facts. */
final class ConversationCreativePolicy
{
    public const VERSION = '2026-10-10.1';
    public static function enabled(array $state): bool
    {
        return ($state['workflow_snapshot']['creative_policy_version']??'')===self::VERSION;
    }
    public static function firstStage(array $state): string
    {
        return self::enabled($state) && ConversationSeries::count($state)===1 ? 'episode' : 'story';
    }
    public static function adopt(array $state,array $draft): array
    {
        foreach ($draft['candidates'] as $key=>$candidate) {
            if (array_key_exists($key,$state['slot_values'])) continue;
            // Only server-supported ratios can be used by production.
            if ($key==='aspect_ratio') {
                $options=[];
                foreach ($state['workflow_snapshot']['slot_schema'] as $slot) if ($slot['key']===$key) $options=$slot['options'];
                if (!in_array(str_replace('：',':',$candidate['value']),$options,true)) continue;
                $candidate['value']=str_replace('：',':',$candidate['value']);
                $state['creative_settings']['aspect_ratio']=$candidate['value'];
            }
            $state['slot_values'][$key]=$candidate['value'];
            $state['slot_sources'][$key]=$candidate;
        }
        if (!isset($state['slot_values']['episode_count']) && preg_match('/多集|连载|系列/u',(string)($state['creative_brief']??''))) {
            $state['intake_candidates']=[];$state['intake_questions']=$draft['questions'];
            $state['stage_state']=['key'=>'intake','status'=>'collecting','completed'=>[]];
            return $state;
        }
        if (!isset($state['slot_values']['episode_count'])) {
            $state['slot_values']['episode_count']='1集（暂定草稿）';
            $state['slot_sources']['episode_count']=['source'=>'assumption','value'=>'1集','evidence'=>'未指定规模，先起草一集；可在采用前调整'];
        }
        $state['intake_candidates']=[];$state['intake_questions']=[];
        $state['stage_state']=['key'=>self::firstStage($state),'status'=>'ready','completed'=>['intake']];
        return $state;
    }
    public static function duration(array $state): int
    {
        $value=(string)($state['slot_values']['episode_duration']??'');
        if (preg_match('/以上|以下|左右|到|至|[-~]/u',$value)) return 0;
        if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(秒|分钟|分|min|s)\s*$/iu',$value,$match)) return 0;
        return min(7200,(int)round((float)$match[1]*(in_array($match[2],['分钟','分','min'],true)?60:1)));
    }
    public static function instruction(): string
    {
        return '先回应本轮实际创作要求，语言自然简洁。灵感足够时给一个具体开场或创作方向，不先填八项表单；最多问两个真正影响本轮的缺口。'
            .'未指定的受众、结局、画风允许暂不决定；未指定规模先起草一集并明确这只是暂定。上传完整剧本时保留原剧情和人物，提取已有内容，不另编故事。'
            .'用户明确要求优先于风格默认；推测必须标为草案。解释问题只解释，不能自动开始制作；“你来定”只授权创作选择，不授权图片或视频费用。';
    }
    public static function missingVisualSlot(array $state): ?array
    {
        foreach ((array)($state['workflow_snapshot']['slot_schema']??[]) as $slot) {
            if (in_array($slot['key'],['visual_style','aspect_ratio'],true) && empty($state['slot_values'][$slot['key']])) return $slot;
        }
        return null;
    }
}
