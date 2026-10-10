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

    /** Upgrade only drafts which have never bound a production task. */
    public static function upgradePending(array $state): array
    {
        if (self::enabled($state) && empty($state['intake_collection_version'])
            && empty($state['creative_plan_confirmed']) && empty($state['series']['tasks'])
            && ($state['stage_state']['key']??'')==='intake') {
            $state['creative_brief']=self::brief(array_map(static fn($text)=>['role'=>'user','content'=>$text],
                explode("\n用户补充：",(string)($state['creative_brief']??''))));
            return self::draft($state,['candidates'=>[],'questions'=>[]]);
        }
        if (($state['workflow_snapshot']['creative_policy_version']??'')!=='2026-10-10.1'
            || !empty($state['series']['tasks'])
            || !in_array($state['stage_state']['key']??'',['intake','story','episode'],true)
            || !in_array($state['stage_state']['status']??'',['collecting','reviewing_intake','ready'],true)) return $state;
        $state['workflow_snapshot']['creative_policy_version']=ConversationCreativePolicy::VERSION;
        $keys=array_column($state['workflow_snapshot']['slot_schema']??[],'key');
        if (!in_array('story_direction',$keys,true)) array_unshift($state['workflow_snapshot']['slot_schema'],
            ['key'=>'story_direction','label'=>'故事方向','ask'=>'请确认核心剧情与发展方向。','options'=>[]]);
        if (($state['stage_state']['status']??'')==='collecting') return $state;
        return self::draft($state,['candidates'=>$state['intake_candidates']??[],'questions'=>[]]);
    }

    public static function brief(array $messages): string
    {
        $parts=[];
        foreach ($messages as $message) {
            if (($message['role']??'')!=='user') continue;
            $text=trim((string)($message['content']??''));
            if ($text==='' || preg_match('/^(?:你好|您好|嗨|hello|hi|谢谢|确认|好的?|可以|继续|[abAB]|选[Bb]|完整短剧制作|单独写一份剧本[\/／]文案|(?:重新)?(?:给我|帮我)?生成一部完整短剧)[。！!\s]*$/iu',$text)) continue;
            if (in_array($text,$parts,true)) continue;
            $parts[]=$text;
        }
        return implode("\n用户补充：",$parts);
    }

    public static function draft(array $state,array $draft): array
    {
        $candidates=array_replace($state['intake_candidates']??[],$draft['candidates']);
        foreach ($candidates as $key=>$candidate) {
            if (($candidate['source']??'')!=='message') continue;
            $state['slot_values'][$key]=$candidate['value'];
            $state['slot_sources'][$key]=$candidate;
            unset($candidates[$key]);
        }
        $brief=trim((string)($state['creative_brief']??''));
        $delegated=preg_match('/(?:全部|所有|都).{0,8}(?:你决定|你来定|自行决定)/u',$brief)
            && !preg_match('/不要|不必|先别|暂不/u',$brief);
        // A user's own brief is the creation request, not an invitation to
        // replace it with a model-designed plot or a concatenated greeting.
        if ($brief!=='' && empty($state['slot_values']['story_direction'])) {
            $state['slot_values']['story_direction']=mb_substr($brief,0,240);
            $state['slot_sources']['story_direction']=['value'=>$state['slot_values']['story_direction'],
                'source'=>'message','evidence'=>mb_substr($brief,0,160)];
        }
        $defaults=array_fill_keys(self::REQUIRED,'');
        foreach ($defaults as $key=>$value) {
            if (!empty($state['slot_values'][$key]) && in_array($state['slot_sources'][$key]['source']??'', ['message','confirmed','delegated'],true)) {
                unset($candidates[$key]);
                continue;
            }
            if (isset($candidates[$key]) && (($candidates[$key]['source']??'')!=='suggestion' || $delegated)) continue;
            $candidates[$key]=['value'=>'','source'=>'suggestion','evidence'=>'尚未提供，请选择或填写'];
        }
        // Extracted values are not executable settings until this entire card is accepted.
        $state['intake_candidates']=$candidates;
        $state['intake_questions']=[];
        $state['creative_plan_confirmed']=false;
        $state['intake_collection_version']=1;
        $state['stage_state']=['key'=>'intake','status'=>'reviewing_intake','completed'=>[]];
        if (!$candidates) return self::confirm($state,[]);
        // Only explicit delegation of the whole plan AND immediate execution skips the card.
        if ($delegated && !array_filter($candidates,static fn($item)=>trim((string)$item['value'])==='')
            && preg_match('/(?:直接|立即|马上)(?:开始)?生成/u',$brief)
            && !preg_match('/不要|不必|先别|暂不/u',$brief)) {
            $state=self::confirm($state,array_map(static fn($item)=>(string)$item['value'],$candidates),'delegated');
        }
        return $state;
    }

    public static function confirm(array $state,array $values,string $source='confirmed'): array
    {
        $merged=array_replace($state['slot_values']??[],$values);
        foreach (self::REQUIRED as $key) if (!is_string($merged[$key]??null) || trim($merged[$key])==='') throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
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
        foreach (array_keys($values) as $key) $state['slot_sources'][$key]=['value'=>$state['slot_values'][$key],'source'=>$source,'evidence'=>'本项创作设定已确认'];
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
