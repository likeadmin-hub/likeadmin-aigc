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
        $messageKeys=['request_key','content','selected_node_ids','base_revision'];
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
        if (!ConversationStore::hasRunRequestKey($tenant,$user,$canvas,$thread,$key)) {
            ConversationSafety::assertInput($tenant,$user,$canvas,$thread,$key,$content);
        }
        return ConversationStore::enqueue($tenant,$user,$canvas,$thread,array_intersect_key($request,array_flip($messageKeys)),static function () use ($tenant,$preferences,$skillId,$skillVersion): array {
            $settings=ConversationSettings::resolve($tenant,$preferences);
            $skill=[];
            if ($skillId>0) {
                try {
                    $skill=ShortDramaSkillService::resolveForTask($tenant,['skill_id'=>$skillId,'skill_version'=>$skillVersion,'skill_source'=>'manual']);
                    ConversationSkillPolicy::assertSafe($skill);
                }
                catch (\Throwable $error) {throw new RuntimeException('SKILL_UNAVAILABLE',0,$error);}
            }
            return ['settings'=>$settings,'skill'=>$skill];
        },['preferences'=>$preferences,'skill_id'=>$skillId,'skill_version'=>$skillVersion]);
    }
}
