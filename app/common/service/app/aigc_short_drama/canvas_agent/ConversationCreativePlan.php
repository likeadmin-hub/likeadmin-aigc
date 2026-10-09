<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** One owner-reviewed plan is the authority for all production parameters. */
final class ConversationCreativePlan
{
    public const REQUIRED = ['story_direction','episode_count','episode_duration','visual_style','aspect_ratio'];

    public static function enabled(array $state): bool
    {
        return ($state['workflow_snapshot']['creative_policy_version'] ?? '') === '2026-10-10.2';
    }

    public static function brief(array $messages): string
    {
        $parts=[];
        foreach ($messages as $message) {
            if (($message['role']??'')!=='user') continue;
            $text=trim((string)($message['content']??''));
            if ($text==='' || preg_match('/^(?:确认|好的?|可以|继续|[abAB]|选[Bb]|完整短剧制作)[。！!\s]*$/u',$text)) continue;
            $parts[]=$text;
        }
        return implode("\n用户补充：",$parts);
    }

    public static function draft(array $state,array $draft): array
    {
        $candidates=$draft['candidates'];
        $brief=trim((string)($state['creative_brief']??''));
        $ratios=[];
        foreach ($state['workflow_snapshot']['slot_schema'] as $slot) if ($slot['key']==='aspect_ratio') $ratios=$slot['options'];
        $defaults=['story_direction'=>mb_substr($brief,0,240), 'episode_count'=>'1集',
            'episode_duration'=>'90秒','visual_style'=>'电影写实',
            'aspect_ratio'=>in_array('9:16',$ratios,true)?'9:16':($ratios[0]??'')];
        foreach ($defaults as $key=>$value) {
            if (isset($candidates[$key])) continue;
            $candidates[$key]=['value'=>(string)($state['slot_values'][$key]??$value),
                'source'=>'suggestion','evidence'=>'建议值，确认后才用于制作'];
        }
        // Extracted values are not executable settings until this entire card is accepted.
        $state['intake_candidates']=$candidates;
        $state['intake_questions']=[];
        $state['creative_plan_confirmed']=false;
        $state['stage_state']=['key'=>'intake','status'=>'reviewing_intake','completed'=>[]];
        // Only explicit delegation of the whole plan AND immediate execution skips the card.
        if (preg_match('/(?:全部|所有|都).{0,8}(?:你决定|你来定|自行决定)/u',$brief)
            && preg_match('/(?:直接|立即|马上)(?:开始)?生成/u',$brief)
            && !preg_match('/不要|不必|先别|暂不/u',$brief)) {
            $state=self::confirm($state,array_map(static fn($item)=>(string)$item['value'],$candidates),'delegated');
        }
        return $state;
    }

    public static function confirm(array $state,array $values,string $source='confirmed'): array
    {
        foreach (self::REQUIRED as $key) if (!is_string($values[$key]??null) || trim($values[$key])==='') throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
        foreach ($values as $key=>$value) {
            if (!isset($state['intake_candidates'][$key]) || !is_string($value) || mb_strlen($value)>240) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
            if (trim($value)!=='') $state['slot_values'][$key]=trim($value);
        }
        $count=ConversationCreativePolicy::episodeCount($state['slot_values']['episode_count']);
        if ($count===null || ConversationCreativePolicy::duration($state)<=0) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
        $state['slot_values']['episode_count']=$count.'集';
        $ratio=str_replace('：',':',$state['slot_values']['aspect_ratio']);
        $ratios=[];
        foreach ($state['workflow_snapshot']['slot_schema'] as $slot) if ($slot['key']==='aspect_ratio') $ratios=$slot['options'];
        if (!in_array($ratio,$ratios,true)) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
        $state['slot_values']['aspect_ratio']=$ratio;
        $state['creative_settings']['aspect_ratio']=$ratio;
        foreach ($state['slot_values'] as $key=>$value) $state['slot_sources'][$key]=['value'=>$value,'source'=>$source,'evidence'=>'整套创作方案已确认'];
        $state['creative_plan_confirmed']=true;
        $state['intake_candidates']=[];
        $state['stage_state']=['key'=>ConversationCreativePolicy::firstStage($state),'status'=>'ready','completed'=>['intake']];
        return $state;
    }

    public static function assertConfirmed(array $state): void
    {
        if (self::enabled($state) && empty($state['creative_plan_confirmed'])) throw new RuntimeException('WORKFLOW_INTAKE_REVIEW_REQUIRED');
    }
}
