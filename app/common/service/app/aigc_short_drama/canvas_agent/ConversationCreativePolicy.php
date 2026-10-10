<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Frozen, opt-in interaction policy. Assumptions never become user facts. */
final class ConversationCreativePolicy
{
    public const VERSION = '2026-10-10.2';
    public static function enabled(array $state): bool
    {
        return in_array($state['workflow_snapshot']['creative_policy_version']??'', ['2026-10-10.1', self::VERSION], true);
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
        if (ConversationCreativePlan::enabled($state)) return ConversationCreativePlan::draft($state,$draft);
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
        if (preg_match('/以上|以下|到|至|[-~～]/u',$value)) return 0;
        // Approximation describes the requested target, not a shot constraint.
        $value=preg_replace('/^(?:大约|约为|大概|约)\s*|\s*(?:左右|上下)$/u','',trim($value));
        if (!preg_match('/^([0-9]+(?:\.[0-9]+)?|[零〇一二两三四五六七八九十百]+|半)\s*(秒|分钟|分|min|s)(半)?$/iu',$value,$match)) return 0;
        $raw=$match[1];$amount=is_numeric($raw)?(float)$raw:($raw==='半'?0.5:(float)(self::episodeCount($raw.'集')??0));
        $minutes=in_array(mb_strtolower($match[2]),['分钟','分','min'],true);
        if (!empty($match[3])) {if (!$minutes) return 0;$amount+=0.5;}
        return min(7200,(int)round($amount*($minutes?60:1)));
    }
    public static function instruction(): string
    {
        return '以下采集规则只适用于本轮明确提供制作设定或要求启动完整制作；普通聊天、剧情讨论和询问建议应先回答用户，不触发采集或执行。自然回应用户，不重复确认已明确选择的制作模式。识别到完整短剧制作意图后，先收集制作要求，再按确认后的要求创作。'
            .'在信息收集阶段，reply_markdown 只简短承接用户题材并说明需要补齐设定，不要提前设计故事发展、角色、结局或列出一套制作方案。'
            .'用户没有明确授权你决定时，禁止自行确定集数、每集时长、视觉风格、画面比例；用户主动询问建议时可以给建议，但不得作为已采纳的制作设定；先判断是否已有真实故事方向：没有时只通过对话帮助用户明确方向，不催填制作参数；已有时缺失参数由信息卡收集，回复应说明同一个下一步，不要同时说以后再定和要求现在填写。'
            .'对话中已明确或已确认的设定直接沿用，不重复询问；只收集缺失项。用户明确纠正已有设定时提取新值。'
            .'用户明确要求放在 intake.candidates，source=message；助手历史生成的剧本和建议不代表用户已确认，问候、确认用语与模式切换指令不是故事内容。'
            .'只有用户明确委托你决定的项目才可提供 source=suggestion 的值；reply_markdown 与 candidates 必须一致。用户完成信息卡确认后，再将已确认设定提交给创作阶段模型。'
            .'仅明确授权全部参数由你决定且要求直接生成时可跳过方案确认；仅“你来定集数”不足以跳过整套确认。媒体费用仍需原有确认。';
    }
    public static function missingVisualSlot(array $state): ?array
    {
        foreach ((array)($state['workflow_snapshot']['slot_schema']??[]) as $slot) {
            if (in_array($slot['key'],['visual_style','aspect_ratio'],true) && empty($state['slot_values'][$slot['key']])) return $slot;
        }
        return null;
    }
}
