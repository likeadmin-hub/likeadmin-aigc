<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use app\common\service\app\aigc_short_drama\ShortDramaSkillService;

/** App-scoped acceptance facade. Still unrouted until complete P2 API tests. */
final class ConversationService
{
    public static function send(int $tenant,int $user,int $canvas,int $thread,array $request): array
    {
        $messageKeys=['request_key','content','selected_node_ids','base_revision','attachments'];
        if (array_diff(array_keys($request),array_merge($messageKeys,['preferences','skill_id','skill_version']))) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
        $preferences=$request['preferences']??[];
        if (!is_array($preferences) || strlen(json_encode($preferences,JSON_THROW_ON_ERROR))>4096) throw new RuntimeException('INVALID_AGENT_PREFERENCES');
        $skillId=$request['skill_id']??0;$skillVersion=$request['skill_version']??0;
        if (!is_int($skillId) || !is_int($skillVersion) || $skillId<0 || $skillVersion<0 || ($skillId===0 && $skillVersion!==0) || ($skillId>0 && $skillVersion===0)) throw new RuntimeException('INVALID_SKILL_SELECTION');
        $key=$request['request_key']??null;$content=$request['content']??null;
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)) throw new RuntimeException('INVALID_REQUEST_KEY');
        if (!is_string($content) || trim($content)==='' || mb_strlen($content)>20000) throw new RuntimeException('INVALID_MESSAGE');
        // Audit only after ownership is known. A blocked input never creates
        // a run/outbox and therefore can never reach a billable Provider.
        ConversationStore::assertThreadAccess($tenant,$user,$canvas,$thread);
        $attachments=ConversationAttachments::normalize($request['attachments']??[]);
        if (!ConversationStore::hasRunRequestKey($tenant,$user,$canvas,$thread,$key)) {
            ConversationSafety::assertInput($tenant,$user,$canvas,$thread,$key,$content.($attachments ? "\n".json_encode($attachments,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) : ''));
        }
        $selectedIds=(array)($request['selected_node_ids']??[]);
        return ConversationStore::enqueue($tenant,$user,$canvas,$thread,array_intersect_key($request,array_flip($messageKeys)),static function (array $conversation) use ($tenant,$preferences,$skillId,$skillVersion,$key,$content,$selectedIds,$attachments): array {
            $settings=ConversationSettings::resolve($tenant,$preferences);
            $skill=[];
            if ($skillId>0) {
                try {
                    $skill=ShortDramaSkillService::resolveForTask($tenant,['skill_id'=>$skillId,'skill_version'=>$skillVersion,'skill_source'=>'manual']);
                    ConversationSkillPolicy::assertSafe($skill);
                }
                catch (\Throwable $error) {throw new RuntimeException('SKILL_UNAVAILABLE',0,$error);}
            } elseif (($skillKey=self::explicitSkillKey($content))!=='' && !ConversationWorkflow::isManualAlias($content)) {
                try {
                    $skill=ShortDramaSkillService::resolveForTaskByKey($tenant,$skillKey);
                    if ($skill) ConversationSkillPolicy::assertSafe($skill);
                }
                catch (\Throwable $error) {throw new RuntimeException('SKILL_UNAVAILABLE',0,$error);}
            }
            // Freeze server-resolved model identities, not mutable browser
            // preference tokens.  The workflow snapshot contains no Provider
            // credential, but it does make a later plan confirmation bound to
            // the exact tenant-authorized model selection used for this run.
            $workflowPreferences=array_replace($preferences,array_intersect_key($settings,array_flip(['generation_mode','reasoning_model','image_model','video_model'])));
            $current=ConversationWorkflow::currentState($conversation);
            if ($current!==[] && FeatureGate::workflowEnabled($tenant,ConversationWorkflow::KEY)
                && !ConversationWorkflow::isManualAlias($content)) {
                // A different explicit Skill has priority over the current
                // workflow. Neither the selected Skill nor unrelated chat may
                // mutate its frozen stage while this run is being classified.
                if ($skill!==[]) return ['settings'=>$settings,'skill'=>$skill];
                $candidate=$current;
                $status=(string)($current['stage_state']['status']??'');
                if (!in_array($status,['awaiting_plan_confirmation','awaiting_stage_confirmation','reviewing_intake'],true)) {
                    $candidate=ConversationWorkflow::prepare($tenant,$conversation,$content,$selectedIds,$attachments,$workflowPreferences)['workflow'];
                }
                $routing=ConversationIntentRouter::snapshot($tenant);
                $routing['kind']='active_workflow';
                $routing['workflow_candidate']=$candidate;
                $routing['base_workflow_revision']=(int)$current['state_revision'];
                $routing['workflow_paused']=in_array($status,['awaiting_plan_confirmation','awaiting_stage_confirmation','reviewing_intake'],true);
                return ['settings'=>$settings,'skill'=>[],'intent_routing'=>$routing];
            }
            $workflow=ConversationWorkflow::prepare($tenant,$conversation,$content,$selectedIds,$attachments,$workflowPreferences);
            $intentRouting=ConversationIntentRouter::shouldClassify($tenant,$content,$skill,$workflow['workflow'])
                ? ConversationIntentRouter::snapshot($tenant) : [];
            if ($intentRouting) {
                // Freeze the same authorized workflow/Skill/model versions as a
                // direct route before the text Provider crosses its I/O
                // boundary. The classifier can only activate this snapshot.
                try {
                    $candidate=ConversationWorkflow::prepare($tenant,$conversation,'/short-drama',$selectedIds,$attachments,$workflowPreferences)['workflow'];
                    $candidate['workflow_snapshot']['route']='semantic';
                    $intentRouting['workflow_candidate']=$candidate;
                } catch (\Throwable $error) {
                    // A broken stage Skill must not prevent unrelated chat.
                    $intentRouting['workflow_unavailable']=true;
                }
            }
            return ['settings'=>$settings,'skill'=>$skill,'workflow'=>$workflow['workflow'],
                'thread_settings'=>$workflow['thread_settings'],'intent_routing'=>$intentRouting];
        },['preferences'=>$preferences,'skill_id'=>$skillId,'skill_version'=>$skillVersion]);
    }

    /** `/skill_key` is a convenience selector for a published, tenant-visible
     * Skill. It never accepts an ID, version or any policy from the browser. */
    private static function explicitSkillKey(string $content): string
    {
        if (!preg_match('/^\/([a-z][a-z0-9_]{1,79})(?:\s|$)/i',trim($content),$matches)) return '';
        return strtolower($matches[1]);
    }
}
