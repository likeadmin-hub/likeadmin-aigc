<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** One paid text turn chooses whether to resume a frozen workflow. The model
 * cannot advance a stage merely by naming it: its payload is independently
 * validated by the existing intake/action-plan contracts. */
final class ConversationWorkflowTurn
{
    private const INTENTS=['continue','chat','creative_plan','image','video','short_drama','uncertain'];

    /** Safe shape-only diagnostics for a rejected Provider answer. Never
     * persist the answer itself or arbitrary exception text. */
    public static function failureCategory(string $response,array $routing): string
    {
        try { $value=json_decode(trim($response),true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { return 'intent_not_json'; }
        $modern=(int)($routing['version']??2)>=3;
        $fields=['intent','confidence','skill_key','reply_markdown','workflow_output'];
        if ($modern) $fields=array_merge($fields,['speech_act','deliverable','scope']);
        if (!is_array($value) || count($value)!==count($fields)
            || array_diff($fields,array_keys($value)) || array_diff(array_keys($value),$fields)) return 'intent_shape';
        $intent=$value['intent'];$confidence=$value['confidence'];
        if (!is_string($intent) || !in_array($intent,self::INTENTS,true)
            || (!is_int($confidence) && !is_float($confidence)) || $confidence<0 || $confidence>1
            || !is_string($value['skill_key']) || !is_string($value['reply_markdown'])) return 'intent_value';
        if ($modern && !ConversationIntentRouter::validAxes($value)) return 'intent_value';
        $allowed=[];
        foreach ((array)($routing['skill_candidates']??[]) as $item) if (is_array($item) && is_string($item['key']??null)) $allowed[$item['key']]=true;
        if ($value['skill_key']!=='' && !isset($allowed[$value['skill_key']])) return 'intent_skill';
        $resume=$intent==='continue' && $confidence>=($modern?0.8:0.7) && empty($routing['workflow_paused'])
            && (!$modern || (($value['scope']??'')==='workflow' && in_array($value['speech_act']??'',['answer','request','confirm'],true)));
        if (!$resume) return $value['workflow_output']!==null ? 'intent_unexpected_output' : 'intent_noncontinue';
        if (trim($value['reply_markdown'])!=='') return 'intent_continue_reply';
        if (!is_array($value['workflow_output'])) return 'intent_continue_output';
        $workflow=(array)($routing['workflow_candidate']??[]);
        $stage=(string)($workflow['stage_state']['key']??'');
        try {
            $payload=json_encode($value['workflow_output'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if ($stage==='intake') ConversationIntakeDraft::parseDirect($payload,(array)($workflow['workflow_snapshot']['slot_schema']??[]),['message']);
            else ConversationActionPlan::parse($payload,$stage,ConversationWorkflow::compactOutput($workflow));
        } catch (\Throwable $error) {
            $output=$value['workflow_output'];
            if (array_keys($output)!==['reply_markdown','canvas_actions'] || !is_string($output['reply_markdown']??null)) return 'intent_stage_keys';
            $actions=$output['canvas_actions']??null;
            if (!is_array($actions) || array_keys($actions)!==['nodes'] || !is_array($actions['nodes']) || !array_is_list($actions['nodes']) || !$actions['nodes']) return 'intent_stage_nodes';
            $max=match ($stage) { 'video_nodes'=>60,'audio_plan'=>1,'script'=>ConversationWorkflow::compactOutput($workflow)?2:3,'assets','storyboard'=>4,default=>8 };
            if (count($actions['nodes'])>$max) return 'intent_stage_node_count';
            $types=match ($stage) { 'script','art','video_plan'=>['text'], 'assets','storyboard'=>['image'], 'video_nodes'=>['video'], 'audio_plan'=>['audio'], default=>[] };
            $artifacts=match ($stage) {
                'script'=>ConversationWorkflow::compactOutput($workflow)?['story_setting','episode_script']:['story_setting','episode_outline','storyboard_script'],
                'art'=>['art_bible','character_asset_spec','scene_asset_spec','prop_asset_spec','subject_image_prompt','three_view_prompt','scene_image_prompt','storyboard_image_prompt'],
                'assets'=>['subject','three_view'], 'storyboard'=>ConversationWorkflow::compactOutput($workflow)?['scene','storyboard']:['scene','prop','storyboard'], 'video_plan'=>['video_prompt_plan'],
                'video_nodes'=>['storyboard_video'], 'audio_plan'=>['audio_plan'], default=>[],
            };
            $seenKeys=[];
            foreach ($actions['nodes'] as $node) {
                if (!is_array($node) || array_diff(array_keys($node),['type','artifact','title','prompt','key','depends_on','reference_keys'])) return 'intent_stage_node_fields';
                if (!is_string($node['type']??null) || !is_string($node['artifact']??null) || !is_string($node['title']??null) || !is_string($node['prompt']??null) || trim($node['prompt'])==='') return 'intent_stage_node_values';
                if ($types && !in_array($node['type'],$types,true)) return 'intent_stage_node_type';
                if ($artifacts && !in_array($node['artifact'],$artifacts,true)) return 'intent_stage_node_artifact';
                if (isset($node['key']) && (!is_string($node['key']) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$node['key']))) return 'intent_stage_node_key';
                if (mb_strlen($node['title'])>80 || mb_strlen($node['prompt'])>20000) return 'intent_stage_node_length';
                if (isset($node['key'])) {
                    if (isset($seenKeys[$node['key']])) return 'intent_stage_duplicate_key';
                    $seenKeys[$node['key']]=true;
                }
                if ((isset($node['depends_on']) && !is_array($node['depends_on'])) || (isset($node['reference_keys']) && !is_array($node['reference_keys']))) return 'intent_stage_node_links';
            }
            return 'intent_stage_contract';
        }
        return 'intent_consistency';
    }

    public static function instruction(array $routing,string $mode): string
    {
        $workflow=(array)($routing['workflow_candidate']??[]);
        $stage=(string)($workflow['stage_state']['key']??'');
        $paused=!empty($routing['workflow_paused']);
        $skills=[];
        foreach ((array)($routing['skill_candidates']??[]) as $item) {
            if (!is_array($item)) continue;
            $skills[]=(string)($item['key']??'').'：'.(string)($item['name']??'').' '.(string)($item['description']??'');
        }
        $stageInstruction=$stage==='intake'
            ? 'workflow_output 必须是 {"reply_markdown":"简短核对提示","intake":{"candidates":[],"questions":[]}}。'.ConversationIntakeDraft::instruction((array)($workflow['workflow_snapshot']['slot_schema']??[]))
            : 'workflow_output 必须是 {"reply_markdown":"真实阶段回复","canvas_actions":{"nodes":[...]}}。以下阶段说明仅约束 workflow_output 子对象：'.ConversationActionPlan::nestedInstruction($mode,$stage,ConversationWorkflow::compactOutput($workflow),ConversationWorkflow::usesStageGenerationPrompts($workflow));
        $modern=(int)($routing['version']??2)>=3;
        return "\n【逐轮意图判断】当前已有短剧工作流，但历史阶段不是本轮用户的新指令。先只根据本轮请求、已确认的上下文和引用素材判断意图。"
            .($modern
                ? '只输出一个 JSON 对象，字段恰好为 intent、confidence、skill_key、reply_markdown、workflow_output、speech_act、deliverable、scope。speech_act 只能是 question、request、answer、confirm、chat；deliverable 只能是 none、text、image、video、full_drama；scope 只能是 conversation、standalone、workflow、uncertain。只有本轮明确回答当前问题卡、修改当前阶段或要求继续时才用 scope=workflow；单独索取一份脚本、图片或视频时用 standalone；咨询能力或概念用 conversation。'
                : '只输出一个 JSON 对象，字段恰好为 intent、confidence、skill_key、reply_markdown、workflow_output。')
            .'intent 只能是 continue、chat、creative_plan、image、video、short_drama、uncertain；continue 表示本轮明确在回答、修改或推进当前短剧工作流。'
            .'普通问候、解释性提问、与当前短剧无关的内容选 chat；单独作图或视频选 image/video；新的短剧项目选 short_drama，不要偷偷替换现有工作流。'
            .'不能判断时选 uncertain，reply_markdown 只问一个澄清问题。confidence 是 0 到 1 的数字。'
            .'skill_key 只能是下面已授权候选的 key 或空字符串；它仅作推荐，绝不能自动执行 Skill。'
            .'若 intent 不是 continue，workflow_output 必须为 null，reply_markdown 给出自然回复，不得声称已创建节点、提交任务或推进阶段。'
            .($modern?'如果本轮是独立的文本创作且信息足够，直接在 reply_markdown 交付真实文本；若只是能力提问，只回答并询问必要信息，不推进当前工作流。':'')
            .($paused
                ? '当前工作流有待用户确认的卡片。即使本轮意图是 continue，workflow_output 也必须为 null；不能绕过确认卡。'
                : '只有明确属于当前阶段且 confidence 不低于 '.($modern?'0.8':'0.7').' 时才能选 continue。'.($modern?'continue 还必须是 scope=workflow 且 speech_act 为 answer、request 或 confirm。':'').'选 continue 时 reply_markdown 为空字符串，'. $stageInstruction)
            .'已授权技能：'.implode('；',$skills);
    }

    /** @return array{intent:string,confidence:float,skill_key:string,reply_markdown:string,workflow_output:mixed,text:string,nodes:array,intake:array,continue:bool} */
    public static function parse(string $response,array $routing,array $availableSources=['message']): array
    {
        try { $value=json_decode(trim($response),true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new RuntimeException('INVALID_AGENT_INTENT',0,$error); }
        $modern=(int)($routing['version']??2)>=3;
        $fields=['intent','confidence','skill_key','reply_markdown','workflow_output'];
        if ($modern) $fields=array_merge($fields,['speech_act','deliverable','scope']);
        if (!is_array($value) || count($value)!==count($fields)
            || array_diff($fields,array_keys($value)) || array_diff(array_keys($value),$fields)) throw new RuntimeException('INVALID_AGENT_INTENT');
        $intent=$value['intent'];$confidence=$value['confidence'];$skillKey=$value['skill_key'];$reply=$value['reply_markdown'];
        $replyLimit=$modern && ($value['deliverable']??'')==='text' ? 10000 : 4000;
        if (!is_string($intent) || !in_array($intent,self::INTENTS,true)
            || (!is_int($confidence) && !is_float($confidence)) || $confidence<0 || $confidence>1
            || !is_string($skillKey) || !is_string($reply) || mb_strlen($reply)>$replyLimit
            || str_contains($reply,'<canvas-actions>')) throw new RuntimeException('INVALID_AGENT_INTENT');
        if ($modern && !ConversationIntentRouter::validAxes($value)) throw new RuntimeException('INVALID_AGENT_INTENT');
        $allowed=[];
        foreach ((array)($routing['skill_candidates']??[]) as $item) if (is_array($item) && is_string($item['key']??null)) $allowed[$item['key']]=true;
        if ($skillKey!=='' && !isset($allowed[$skillKey])) throw new RuntimeException('INVALID_AGENT_INTENT');
        $workflow=(array)($routing['workflow_candidate']??[]);
        if (($routing['kind']??'')!=='active_workflow' || ($workflow['workflow_snapshot']['key']??'')!==ConversationWorkflow::KEY) throw new RuntimeException('INVALID_AGENT_INTENT');
        $resume=$intent==='continue' && $confidence>=($modern?0.8:0.7) && empty($routing['workflow_paused'])
            && (!$modern || (($value['scope']??'')==='workflow' && in_array($value['speech_act']??'',['answer','request','confirm'],true)));
        if (!$resume) {
            if ($value['workflow_output']!==null) throw new RuntimeException('INVALID_AGENT_INTENT');
            if ($intent==='continue' && !empty($routing['workflow_paused'])) {
                $reply='当前短剧工作流有待确认的阶段卡片，请先确认或修改；你的这条消息没有改动工作流。';
            } elseif ($modern && ($value['scope']??'')==='uncertain') {
                $reply=trim($reply);
            } elseif ($intent==='continue' || (!$modern && $confidence<0.7 && $intent!=='chat')) {
                $reply='这条消息是要继续当前短剧工作流，还是开始另一项创作？请说明后我再继续。';
            } elseif ($intent==='short_drama' && (!$modern || ($value['scope']??'')==='workflow')) {
                $reply=trim($reply)."\n\n当前短剧工作流仍保留；如需开始另一部短剧，请新建聊天。";
            } elseif ($skillKey!=='' && in_array($intent,['creative_plan','image','video'],true)
                && (!$modern || in_array($value['deliverable']??'',['image','video'],true))) {
                $reply=trim($reply)."\n\n可选技能：/".$skillKey.'（单独发送以使用）。';
            }
            if (trim($reply)==='') throw new RuntimeException('INVALID_AGENT_INTENT');
            return $value+['text'=>trim($reply),'nodes'=>[],'intake'=>[],'continue'=>false];
        }
        // A tenant-authorized Skill recommendation may accompany a workflow
        // continuation. It is advisory only: the frozen stage Skills, not this
        // model field, control execution. Rejecting it made a valid paid stage
        // response disappear after the upstream call had already completed.
        if (trim($reply)!=='' || !is_array($value['workflow_output'])) throw new RuntimeException('INVALID_AGENT_INTENT');
        $stage=(string)($workflow['stage_state']['key']??'');
        $payload=json_encode($value['workflow_output'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if ($stage==='intake') {
            $draft=ConversationIntakeDraft::parseDirect($payload,(array)($workflow['workflow_snapshot']['slot_schema']??[]),$availableSources);
            return $value+['text'=>$draft['reply_markdown'],'nodes'=>[],'intake'=>$draft['intake'],'continue'=>true];
        }
        $plan=ConversationActionPlan::parse($payload,$stage,ConversationWorkflow::compactOutput($workflow));
        if (!$plan['nodes']) throw new RuntimeException('INVALID_AGENT_ACTION');
        return $value+['text'=>$plan['text'],'nodes'=>$plan['nodes'],'intake'=>[],'continue'=>true];
    }
}
