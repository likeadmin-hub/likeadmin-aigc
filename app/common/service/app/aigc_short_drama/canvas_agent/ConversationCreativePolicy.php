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
    public static function episodeCount(string $value): ?int
    {
        if (!preg_match('/^\s*([0-9零〇一二两三四五六七八九十百]+)\s*(?:集|$)/u',$value,$match)) return null;
        $raw=$match[1];$count=0;$digit=0;
        if (ctype_digit($raw)) $count=(int)$raw;
        else {
            $digits=['零'=>0,'〇'=>0,'一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];
            foreach (preg_split('//u',$raw,-1,PREG_SPLIT_NO_EMPTY) as $char) {
                if ($char==='十' || $char==='百') { $count+=max(1,$digit)*($char==='十'?10:100);$digit=0; }
                else $digit=$digits[$char]??0;
            }
            $count+=$digit;
        }
        return $count>=1 && $count<=500 ? $count : null;
    }
    public static function firstStage(array $state): string
    {
        return self::enabled($state) && ConversationSeries::count($state)===1 ? 'episode' : 'story';
    }
    public static function adopt(array $state,array $draft): array
    {
        foreach ($draft['candidates'] as $key=>$candidate) {
            if (array_key_exists($key,$state['slot_values'])) continue;
            if ($key==='episode_count') {
                $count=self::episodeCount($candidate['value']);
                if ($count===null) throw new \RuntimeException('INVALID_AGENT_INTAKE');
                $candidate['value']=$count.'集';
            }
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
        $brief=(string)($state['creative_brief']??'');
        $series=preg_match('/多集|连载|系列/u',$brief)===1;
        $delegated=preg_match('/你来定|你决定|自行决定|自动安排|按默认/u',$brief)===1;
        if (!isset($state['slot_values']['episode_count']) && $series && !$delegated) {
            $state['intake_candidates']=[];$state['intake_questions']=$draft['questions'];
            $state['stage_state']=['key'=>'intake','status'=>'collecting','completed'=>[]];
            return $state;
        }
        if (!isset($state['slot_values']['episode_count'])) {
            $count=$series?3:1;
            $state['slot_values']['episode_count']=$count.'集（暂定草稿）';
            $state['slot_sources']['episode_count']=['source'=>'assumption','value'=>$count.'集','evidence'=>$series?'用户授权暂定规模，先规划三集':'未指定规模，先起草一集'];
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
            .'未指定的受众、结局、画风允许暂不决定；未指定规模先起草一集；明确要求多集且授权你决定规模时先按三集规划，并说明集数暂定。上传完整剧本时保留原剧情和人物，提取已有内容，不另编故事。'
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
