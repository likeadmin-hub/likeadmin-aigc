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
        if (preg_match('/以上|以下|左右|到|至|[-~]/u',$value)) return 0;
        if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(秒|分钟|分|min|s)\s*$/iu',$value,$match)) return 0;
        return min(7200,(int)round((float)$match[1]*(in_array($match[2],['分钟','分','min'],true)?60:1)));
    }
    public static function instruction(): string
    {
        return '自然回应用户，不重复确认已明确选择的制作模式。选择完整制作只表示进入方案讨论，不表示用户已确认方案。'
            .'正式生成前先给一套可编辑方案，包含具体故事方向、集数、每集明确秒数、视觉风格和画面比例；统一确认，不逐项问八次。'
            .'用户明确要求放在 intake.candidates，缺失项提出建议并标 source=suggestion，不能把建议冒充用户要求。'
            .'reply_markdown 与 candidates 必须一致，不另说一套剧情或集数；提醒用户在方案卡确认。历史用户创作要求必须保留，不能把“确认”当成故事。'
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
