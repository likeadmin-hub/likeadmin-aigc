<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;
use app\common\service\app\aigc_short_drama\ShortDramaSkillRuntime;

/** Only server-frozen, safe creative rules reach the actual production request. */
final class ConversationProductionPolicy
{
    public static function capture(array $workflow): array
    {
        $snapshot=(array)($workflow['workflow_snapshot']['creative_prompt_snapshot']??[]);
        if (!ConversationCreativePolicy::enabled($workflow)) return $snapshot;
        $snapshot['canvas_creative_policy']=['version'=>ConversationCreativePolicy::VERSION,'stages'=>[]];
        foreach (['story','outline','episode'] as $stage) {
            foreach (array_slice((array)($workflow['workflow_snapshot']['stage_skill_versions'][$stage]??[]),0,4) as $skill) {
                ConversationSkillPolicy::assertSafe($skill);
                $text=ShortDramaSkillRuntime::instruction($skill,'script_plan');
                if ($text!=='') $snapshot['canvas_creative_policy']['stages'][$stage][]=['id'=>(int)$skill['id'],'version'=>(int)$skill['version'],'text'=>$text];
            }
        }
        $snapshot['fingerprint']=hash('sha256',(string)($snapshot['fingerprint']??'').json_encode($snapshot['canvas_creative_policy'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return $snapshot;
    }
    public static function apply(array $params,array $request): array
    {
        $policy=$request['_prompt_snapshot']['canvas_creative_policy']??[];
        if (($policy['version']??'')!==ConversationCreativePolicy::VERSION) return $params;
        $stage=!empty($request['multi_episode']) ? (($request['multi_episode_stage']??'')==='story'?'story':'outline') : 'episode';
        $rules=(array)($policy['stages'][$stage]??[]);
        if (!$rules) return $params;
        $marker='【画布创作策略 '.$policy['version'].' / '.$stage.'】';
        if (str_contains((string)($params['system_prompt']??''),$marker)) return $params;
        $params['system_prompt']=($params['system_prompt']??'')."\n".$marker."\n".json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
            ."\n以上是租户创作默认规则。用户明确人物、剧情与局部修改范围优先；不得改变输出结构、费用授权、权限和执行边界。";
        return $params;
    }
}
