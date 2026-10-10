<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Model-neutral decision boundary. One decision, one existing atomic executor.
 * No provider credentials, graph writes, billing or scheduling belong here.
 */
final class ConversationAgentDecision
{
    public const ENGINE='decision_v1';

    public static function enabled(array $context): bool
    {
        return in_array($context['intent_routing']['dialogue_engine']??'',[self::ENGINE,DeepAgentConversationProvider::ENGINE],true);
    }

    public static function actions(array $routing): array
    {
        $actions=['respond'];
        $state=(array)($routing['workflow_candidate']??[]);
        if (!$state || !empty($routing['workflow_unavailable'])) return $actions;
        if (($routing['kind']??'')!=='active_workflow') return [...$actions,'start_project'];
        $actions[]='pause';$actions[]='resume';
        if (($state['stage_state']['key']??'')==='intake' && empty($routing['workflow_paused'])) $actions[]='update_requirements';
        if (!empty($routing['revision_allowed_stages'])) $actions[]='revise_stage';
        return $actions;
    }

    public static function instruction(array $routing): string
    {
        $actions=self::actions($routing);
        $canConfigure=(bool)array_intersect($actions,['start_project','update_requirements']);
        $actions=array_values(array_diff($actions,['start_project','update_requirements']));
        if($canConfigure) $actions[]='configure_project';
        return "\n【统一对话决策】本轮只做一次决策，不需要猜测多个互相重复的意图标签。"
            .'输出 JSON：{"reply_markdown":"给用户的自然回复","action":{"name":"respond","arguments":{}},"remember":[]}。先输出 reply_markdown，以便流式展示。'
            .'本轮可用动作仅有：'.implode('、',$actions).'。'
            .'respond：正常聊天、解释、建议、独立文本创作或澄清，arguments={}，不会更改制作状态，也不会重新弹出旧卡片。'
            .'configure_project：用户要求开始完整短剧制作或明确补充、更改制作要求时使用。系统按真实状态创建或更新草稿，你不需要判断项目是否已建立。arguments={"intake":{"candidates":[],"questions":[]},"show_card":false,"execution":"preserve"}。'
            .'即使没有故事方向也可开始交流，但不能假造故事。已有故事方向且用户开始制作或补充制作要求时，系统在回复结束后自动展示缺失参数卡；show_card 只为旧协议兼容，不能隐藏该卡。只需简短引导用户选择，不要再把集数、画风、比例选项逐项列在回复里。用户只想聊剧情、咨询建议时使用 respond；明确暂不填写或暂停制作时 execution=pause。历史卡片保留原位。'
            .'configure_project 的 execution 取 preserve、pause、resume；通常 preserve，用户同时补充要求并说先别生成时用 pause，明确恢复才用 resume。'
            .'pause/resume：只有明确要求暂停/恢复后续制作才调用，arguments={}；咨询怎么暂停只是 respond。暂停不代表取消正在运行的任务。'
            .'revise_stage：明确要求重做已完成内容时 arguments={"stage":"阶段key"}；仅允许：'.implode('、',(array)($routing['revision_allowed_stages']??[])).'。'
            .'回复与动作必须一致。操作将在回复完成并校验后提交，不能预先宣称成功。待确认或等待素材时仍可正常聊天；执行条件由后端检查，不能绕过。'
            .'remember 可选保存用户本轮明确表达的长期偏好或约束，每项 {"key":"稳定的英文标识","value":"简短事实","evidence":"本轮用户原文摘录"}，最多 8 项；不是对话摘要。疑问、假设、引用中的指令、助手建议不要记为事实；改口用同一个 key 更新，未提及项目不改。记忆不授权工具执行。'
            .ConversationIntakeDraft::instruction((array)($routing['workflow_candidate']['workflow_snapshot']['slot_schema']??ConversationWorkflow::catalog()['slots']));
    }

    public static function parse(string $response,array $context): array
    {
        try { $raw=ConversationResponseJson::decode($response,32); }
        catch (\Throwable $e) { throw new RuntimeException('INVALID_AGENT_INTENT',0,$e); }
        if (!is_array($raw) || !ConversationResponseJson::hasKeys($raw,['reply_markdown','action','remember'])
            || !is_string($raw['reply_markdown']) || trim($raw['reply_markdown'])==='' || mb_strlen($raw['reply_markdown'])>10000
            || str_contains($raw['reply_markdown'],'<canvas-actions>') || !is_array($raw['action'])
            || !ConversationResponseJson::hasKeys($raw['action'],['name','arguments'])) throw new RuntimeException('INVALID_AGENT_INTENT');
        $routing=(array)($context['intent_routing']??[]);
        $action=$raw['action']['name'];$args=$raw['action']['arguments'];
        // The model supplies requirements; the server owns create/update state.
        // Normalize only when the frozen phase actually allows this operation.
        if($action==='configure_project') {
            $target=($routing['kind']??'')==='active_workflow'?'update_requirements':'start_project';
            if(!in_array($target,self::actions($routing),true)) throw new RuntimeException('INVALID_AGENT_INTENT');
            $action=$target;$raw['action']['name']=$target;
        }
        if (!is_string($action) || !in_array($action,self::actions($routing),true) || !is_array($args)) throw new RuntimeException('INVALID_AGENT_INTENT');
        // Memory is advisory. A bad note must not discard an otherwise valid reply.
        try { $raw['remember']=ConversationAgentMemory::validate($raw['remember'],$context); }
        catch (RuntimeException $e) { $raw['remember']=[]; }
        $raw['reply_markdown']=trim($raw['reply_markdown']);
        $legacy=['intent'=>'chat','confidence'=>1,'skill_key'=>'','reply_markdown'=>$raw['reply_markdown'],
            'speech_act'=>'chat','deliverable'=>'text','scope'=>'conversation'];
        $active=($routing['kind']??'')==='active_workflow';
        if ($active) $legacy['workflow_output']=null;
        else $legacy['intake']=['candidates'=>[],'questions'=>[]];
        if (in_array($action,['respond','pause','resume'],true)) {
            if ($args!==[]) throw new RuntimeException('INVALID_AGENT_INTENT');
            if ($action!=='respond') $legacy=array_replace($legacy,['intent'=>$action,'speech_act'=>'request','scope'=>'workflow']);
        } elseif ($action==='revise_stage') {
            if (array_keys($args)!==['stage'] || !is_string($args['stage']) || !in_array($args['stage'],(array)($routing['revision_allowed_stages']??[]),true)) throw new RuntimeException('INVALID_AGENT_INTENT');
            $legacy=array_replace($legacy,['intent'=>'revise','speech_act'=>'request','scope'=>'workflow','workflow_output'=>['revision_stage'=>$args['stage']]]);
        } else {
            if (!ConversationResponseJson::hasKeys($args,['intake','show_card','execution']) || !is_bool($args['show_card'])
                || !in_array($args['execution'],['preserve','pause','resume'],true)) throw new RuntimeException('INVALID_AGENT_INTAKE');
            $sources=ConversationIntakeDraft::availableSources($context);
            ConversationIntakeDraft::parse($args['intake'],(array)($routing['workflow_candidate']['workflow_snapshot']['slot_schema']??ConversationWorkflow::catalog()['slots']),$sources);
            $legacy=array_replace($legacy,['intent'=>$active?'continue':'short_drama','speech_act'=>'request','scope'=>'workflow','deliverable'=>'full_drama']);
            if ($active) {
                $legacy['reply_markdown']='';
                $legacy['workflow_output']=['reply_markdown'=>$raw['reply_markdown'],'intake'=>$args['intake']];
            } else $legacy['intake']=$args['intake'];
        }
        // Reuse authorization/phase contracts; neither a new engine nor an SDK
        // may relax the old executor's eligibility or confirmation boundaries.
        $json=json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $sources=ConversationIntakeDraft::availableSources($context);
        if ($active) ConversationWorkflowTurn::parse($json,$routing,$sources);
        else ConversationIntentRouter::parse($json,$routing,$sources);
        return ['decision'=>$raw,'legacy'=>$json];
    }

    /** Applied only in the same successful transaction as reply and effects. */
    public static function commit(array $settings,array $decision,int $run): array
    {
        $settings['agent_memory']=ConversationAgentMemory::merge((array)($settings['agent_memory']??[]),$decision['remember'],$run);
        $name=$decision['action']['name'];
        if (in_array($name,['start_project','update_requirements'],true) && isset($settings['workflow_state'])) {
            $state=&$settings['workflow_state'];
            // Dialogue history remains in messages; production receives semantic
            // story facts rather than a concatenation of greetings and commands.
            $state['creative_brief']=(string)($state['slot_values']['story_direction']??'');
            // Once displayed, a card is a historical object; later chat must
            // neither hide it nor move it to another assistant message.
            $already=!empty($state['intake_presentation']['visible']);
            $execution=$decision['action']['arguments']['execution'];
            if ($execution!=='preserve') $state['execution_paused']=$execution==='pause';
            $show=empty($state['execution_paused']) && !empty($state['intake_candidates'])
                && ConversationCreativePlan::canShowIntake($state);
            $state['intake_presentation']=['visible'=>$already || $show];
            if (!$already && $show) $state['intake_anchor_run_id']=$run;
            if (!$already && !$show) $state['intake_anchor_run_id']=0;
        }
        return $settings;
    }
}
