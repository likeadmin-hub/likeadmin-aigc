<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/** Durable execution boundary; no Provider dependency and no automatic retry.
 * A production dispatcher must claim BEFORE provider I/O and pass only a
 * validated assistant reply here. Tool execution is not part of this class.
 */
final class ConversationExecution
{
    public static function claim(int $tenant,int $user,int $runId,int $seconds=180): ?array
    {
        return Db::transaction(function () use ($tenant,$user,$runId,$seconds): ?array {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);
            FeatureGate::assertEnabled($tenant);
            if ($run['status']!=='queued' || $outbox['state']!=='pending' || (int)$outbox['available_at']>time()) return null;
            if ((int)$thread['active_run_id']!==$runId) throw new RuntimeException('RUN_SUPERSEDED');
            $claim=['token'=>bin2hex(random_bytes(24)),'fence'=>(int)$outbox['fencing_version']+1,'lease_until'=>time()+max(1,min(3600,$seconds))];
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'processing','lease_token'=>$claim['token'],'fencing_version'=>$claim['fence'],'lease_until'=>$claim['lease_until'],'attempts'=>(int)$outbox['attempts']+1,'update_time'=>time()]);
            self::state($run,'running');self::event($run,'run.running',['status'=>'running']);
            return $claim+['run_id'=>$runId,'thread_id'=>(int)$thread['id'],'canvas_id'=>(int)$run['canvas_id'],'context'=>json_decode($run['context_snapshot'],true,512,JSON_THROW_ON_ERROR),'settings'=>json_decode($run['settings_snapshot'],true,512,JSON_THROW_ON_ERROR),'skill'=>json_decode($run['skill_snapshot'],true,512,JSON_THROW_ON_ERROR)];
        });
    }

    /** Return false when a valid but late reply is retained for reconciliation. */
    /**
     * Complete a reply and its bounded graph proposal atomically.  The action
     * is a server-validated proposal, not an arbitrary provider tool call.
     */
    public static function complete(int $tenant,int $user,int $runId,string $token,int $fence,string $text,array $proposals=[]): bool
    {
        if (trim($text)==='' || mb_strlen($text)>100000) throw new RuntimeException('INVALID_ASSISTANT_REPLY');
        return Db::transaction(function () use ($tenant,$user,$runId,$token,$fence,$text,$proposals): bool {
            [$run,$thread,$outbox,$document]=self::locked($tenant,$user,$runId);
            self::identity($outbox,$token,$fence);
            $hash=hash('sha256',$text);
            if ($run['status']==='success') {
                $prior=Db::name(ConversationStore::PREFIX.'message')->where(['run_id'=>$runId,'role'=>'assistant'])->lock(true)->value('content_json');
                if ($prior!==self::json(['text'=>$text])) throw new RuntimeException('REPLY_CONFLICT');
                return true;
            }
            if (!in_array($run['status'],['running','needs_reconciliation'],true)) throw new RuntimeException('INVALID_RUN_STATE');
            if ($run['status']==='needs_reconciliation' || (int)$outbox['lease_until']<=time()) {
                $prior=Db::name(ConversationStore::PREFIX.'event')->where(['run_id'=>$runId,'kind'=>'run.late_reply'])->lock(true)->find();
                if ($prior) {
                    if ((json_decode($prior['payload_json'],true)['reply_hash']??'')!==$hash) throw new RuntimeException('REPLY_CONFLICT');
                } else {
                    // Evidence is retained; no successful message or billing
                    // settlement is fabricated for an expired worker.
                    self::event($run,'run.late_reply',['reply_hash'=>$hash,'text'=>$text]);
                }
                self::uncertain($run,$outbox,'LATE_REPLY_RETAINED');
                return false;
            }
            if ((int)$thread['active_run_id']!==$runId) throw new RuntimeException('RUN_SUPERSEDED');
            $effects=[];
            if ($proposals) {
                $context=json_decode($run['context_snapshot'],true,512,JSON_THROW_ON_ERROR);
                $settings=json_decode($run['settings_snapshot'],true,512,JSON_THROW_ON_ERROR);
                $sourceIds=[];
                foreach ((array)($context['selected_nodes']??[]) as $node) if (is_array($node) && is_scalar($node['id']??null)) $sourceIds[]=(string)$node['id'];
                $effects=GraphService::appendAgentNodesLocked($document,$proposals,$sourceIds,($settings['generation_mode']??'manual')==='auto',$settings);
                $effects['mode']=($settings['generation_mode']??'manual')==='auto'?'auto':'manual';
            }
            $sequence=(int)$thread['next_message_sequence'];
            $content=['text'=>$text]; if ($effects) $content['canvas_actions']=$effects;
            Db::name(ConversationStore::PREFIX.'message')->insert(self::scope($run)+['thread_id'=>$run['thread_id'],'run_id'=>$runId,'sequence'=>$sequence,'role'=>'assistant','content_json'=>self::json($content),'attachments_json'=>'[]','create_time'=>time()]);
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread['id'])->update(['active_run_id'=>0,'next_message_sequence'=>$sequence+1,'update_time'=>time()]);
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'done','lease_until'=>0,'update_time'=>time()]);
            self::state($run,'success');self::event($run,'run.succeeded',['status'=>'success','message_sequence'=>$sequence]+($effects?['canvas_actions'=>$effects]:[]));
            return true;
        });
    }

    /** Only a server-owned no-cost preflight may use this before generate(). */
    public static function rejectBeforeSubmit(int $tenant,int $user,int $runId,string $token,int $fence): string
    {
        return Db::transaction(function () use ($tenant,$user,$runId,$token,$fence): string {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);self::identity($outbox,$token,$fence);
            if ($run['status']==='canceled') return 'canceled';
            if ($run['status']==='failed' && $run['error_code']==='PRECHECK_FAILED') return 'failed';
            if ($run['status']==='needs_reconciliation') return 'needs_reconciliation';
            if ($run['status']!=='running' || $outbox['state']!=='processing') throw new RuntimeException('STALE_WORKER');
            if ((int)$outbox['lease_until']<=time()) {
                self::uncertain($run,$outbox,'WORKER_LEASE_EXPIRED');return 'needs_reconciliation';
            }
            if ((int)$thread['active_run_id']!==$runId) throw new RuntimeException('RUN_SUPERSEDED');
            self::state($run,'failed','PRECHECK_FAILED');
            self::event($run,'run.failed',['status'=>'failed','code'=>'PRECHECK_FAILED']);
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'failed','lease_until'=>0,'update_time'=>time()]);
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread['id'])->update(['active_run_id'=>0,'update_time'=>time()]);
            return 'failed';
        });
    }

    /** The Provider request may already exist, but output was withheld before
     * publication. The Provider adapter releases any still-reserved usage
     * before this terminal conversation transition. */
    public static function rejectAfterSubmit(int $tenant,int $user,int $runId,string $token,int $fence): string
    {
        return Db::transaction(function () use ($tenant,$user,$runId,$token,$fence): string {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);self::identity($outbox,$token,$fence);
            if ($run['status']==='failed' && $run['error_code']==='SAFETY_OUTPUT_BLOCKED') return 'failed';
            if ($run['status']==='needs_reconciliation') return 'needs_reconciliation';
            if ($run['status']!=='running' || $outbox['state']!=='submitting') throw new RuntimeException('STALE_WORKER');
            if ((int)$outbox['lease_until']<=time()) { self::uncertain($run,$outbox,'WORKER_LEASE_EXPIRED');return 'needs_reconciliation'; }
            self::state($run,'failed','SAFETY_OUTPUT_BLOCKED');
            self::event($run,'run.failed',['status'=>'failed','code'=>'SAFETY_OUTPUT_BLOCKED']);
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'failed','lease_until'=>0,'update_time'=>time()]);
            if ((int)$thread['active_run_id']===$runId) Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread['id'])->update(['active_run_id'=>0,'update_time'=>time()]);
            return 'failed';
        });
    }

    /** A response was received, but it violates the P2 text-only contract.
     * This is a known terminal result, unlike a transport timeout: retain no
     * reply and never retry it. Billing adapters remain responsible for their
     * own already-settled ledger records. */
    public static function rejectInvalidResponse(int $tenant,int $user,int $runId,string $token,int $fence): string
    {
        return Db::transaction(function () use ($tenant,$user,$runId,$token,$fence): string {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);self::identity($outbox,$token,$fence);
            if ($run['status']==='failed' && $run['error_code']==='UNSUPPORTED_MODEL_RESPONSE') return 'failed';
            if ($run['status']==='needs_reconciliation') return 'needs_reconciliation';
            if ($run['status']!=='running' || $outbox['state']!=='submitting') throw new RuntimeException('STALE_WORKER');
            if ((int)$outbox['lease_until']<=time()) { self::uncertain($run,$outbox,'WORKER_LEASE_EXPIRED');return 'needs_reconciliation'; }
            self::state($run,'failed','UNSUPPORTED_MODEL_RESPONSE');
            self::event($run,'run.failed',['status'=>'failed','code'=>'UNSUPPORTED_MODEL_RESPONSE']);
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'failed','lease_until'=>0,'update_time'=>time()]);
            if ((int)$thread['active_run_id']===$runId) Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread['id'])->update(['active_run_id'=>0,'update_time'=>time()]);
            return 'failed';
        });
    }

    /** Durable one-time handoff immediately before provider I/O. No locks are
     * retained during HTTP; disabling after this handoff cannot cancel an
     * already submitted request and must use reconciliation, never resubmit.
     */
    public static function authorizeSubmission(int $tenant,int $user,int $runId,string $token,int $fence,int $timeoutSeconds=120): string
    {
        if ($timeoutSeconds<1 || $timeoutSeconds>3600) throw new RuntimeException('INVALID_PROVIDER_TIMEOUT');
        return Db::transaction(function () use ($tenant,$user,$runId,$token,$fence,$timeoutSeconds): string {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);self::identity($outbox,$token,$fence);
            if ($run['status']==='needs_reconciliation') return 'needs_reconciliation';
            if ($run['status']!=='running' || $outbox['state']!=='processing') return 'not_claimed';
            if ((int)$thread['active_run_id']!==$runId) throw new RuntimeException('RUN_SUPERSEDED');
            if ((int)$outbox['lease_until']-time()<$timeoutSeconds+5) {
                self::uncertain($run,$outbox,'INSUFFICIENT_SUBMISSION_LEASE');return 'needs_reconciliation';
            }
            if (!FeatureGate::enabled($tenant)) {
                return self::rejectBeforeSubmit($tenant,$user,$runId,$token,$fence);
            }
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'submitting','update_time'=>time()]);
            self::event($run,'run.submitting',['status'=>'running']);
            return 'authorized';
        });
    }

    /** Unknown provider outcome is NOT a retryable failure and does not refund. */
    public static function unknown(int $tenant,int $user,int $runId,string $token,int $fence): void
    {
        Db::transaction(function () use ($tenant,$user,$runId,$token,$fence): void {
            [$run,,$outbox]=self::locked($tenant,$user,$runId);self::identity($outbox,$token,$fence);
            if ($run['status']==='needs_reconciliation') return;
            if ($run['status']!=='running') throw new RuntimeException('INVALID_RUN_STATE');
            self::uncertain($run,$outbox,'PROVIDER_OUTCOME_UNKNOWN');
        });
    }

    public static function expire(int $tenant,int $user,int $runId): bool
    {
        return Db::transaction(function () use ($tenant,$user,$runId): bool {
            [$run,,$outbox]=self::locked($tenant,$user,$runId);
            if ($run['status']!=='running' || !in_array($outbox['state'],['processing','submitting'],true) || (int)$outbox['lease_until']>time()) return false;
            self::uncertain($run,$outbox,'WORKER_LEASE_EXPIRED');return true;
        });
    }

    /** Stop is allowed even after the Agent feature is switched off. Identity
     * still comes from the authenticated actor, never from the request body.
     * The durable submitting boundary separates local cancellation from an
     * upstream outcome we cannot promise to cancel or refund.
     */
    public static function stop(int $tenant,int $user,int $canvas,int $threadId,int $runId): array
    {
        return Db::transaction(function () use ($tenant,$user,$canvas,$threadId,$runId): array {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);
            if ((int)$run['canvas_id']!==$canvas || (int)$run['thread_id']!==$threadId) throw new RuntimeException('RUN_NOT_FOUND');
            $status=$run['status'];
            if (in_array($status,['success','failed','canceled'],true)) return ['run_id'=>$runId,'status'=>$status,'cancellation_confirmed'=>$status==='canceled'];
            if (($status==='queued' && $outbox['state']==='pending') || ($status==='running' && $outbox['state']==='processing')) {
                self::state($run,'canceled','USER_STOPPED_BEFORE_SUBMIT');
                self::event($run,'run.canceled',['status'=>'canceled','code'=>'USER_STOPPED_BEFORE_SUBMIT']);
                Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'canceled','lease_until'=>0,'update_time'=>time()]);
                if ((int)$thread['active_run_id']===$runId) Db::name(ConversationStore::PREFIX.'thread')->where('id',$threadId)->update(['active_run_id'=>0,'update_time'=>time()]);
                return ['run_id'=>$runId,'status'=>'canceled','cancellation_confirmed'=>true];
            }
            if (($status==='running' && $outbox['state']==='submitting') || $status==='needs_reconciliation') {
                if (!Db::name(ConversationStore::PREFIX.'event')->where(['run_id'=>$runId,'kind'=>'run.stop_requested'])->lock(true)->find()) self::event($run,'run.stop_requested',['status'=>'needs_reconciliation','cancellation_confirmed'=>false]);
                self::uncertain($run,$outbox,'USER_STOP_REQUESTED');
                return ['run_id'=>$runId,'status'=>'needs_reconciliation','cancellation_confirmed'=>false];
            }
            throw new RuntimeException('INVALID_RUN_STATE');
        });
    }

    private static function locked(int $tenant,int $user,int $runId): array
    {
        $identity=Db::name(ConversationStore::PREFIX.'run')->where(['id'=>$runId,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find();
        if (!$identity) throw new RuntimeException('RUN_NOT_FOUND');
        $canvas=Db::name(GraphService::TABLE)->where(['id'=>$identity['canvas_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
        if (!$canvas) throw new RuntimeException('CANVAS_NOT_FOUND');
        $thread=Db::name(ConversationStore::PREFIX.'thread')->where(self::scope($identity)+['id'=>$identity['thread_id'],'delete_time'=>0])->lock(true)->find();
        if (!$thread) throw new RuntimeException('THREAD_NOT_FOUND');
        $run=Db::name(ConversationStore::PREFIX.'run')->where('id',$runId)->lock(true)->find();
        $outbox=Db::name(ConversationStore::PREFIX.'outbox')->where(self::scope($identity)+['run_id'=>$runId])->lock(true)->find();
        if (!$outbox) throw new RuntimeException('OUTBOX_NOT_FOUND');
        return [$run,$thread,$outbox,$canvas];
    }
    private static function identity(array $outbox,string $token,int $fence): void {
        if ($token==='' || !hash_equals($outbox['lease_token'],$token) || (int)$outbox['fencing_version']!==$fence) throw new RuntimeException('STALE_WORKER');
    }
    private static function uncertain(array $run,array $outbox,string $code): void {
        if ($run['status']!=='needs_reconciliation') {
            self::state($run,'needs_reconciliation',$code);
            self::event($run,'run.needs_reconciliation',['status'=>'needs_reconciliation','code'=>$code]);
        }
        Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'needs_reconciliation','lease_until'=>0,'update_time'=>time()]);
    }
    private static function state(array $run,string $status,string $error=''): void {
        Db::name(ConversationStore::PREFIX.'run')->where('id',$run['id'])->update(['status'=>$status,'version'=>(int)$run['version']+1,'error_code'=>$error,'update_time'=>time()]);
    }
    private static function event(array $run,string $kind,array $payload): void {
        // The identity lookup can establish an older REPEATABLE READ snapshot
        // before we wait for the canvas lock. Use a current read under the run
        // lock, not a snapshot MAX(), to include the preceding owner's events.
        $last=Db::name(ConversationStore::PREFIX.'event')->where('run_id',$run['id'])->order('sequence','desc')->lock(true)->find();
        $sequence=(int)($last['sequence']??0)+1;
        Db::name(ConversationStore::PREFIX.'event')->insert(self::scope($run)+['thread_id'=>$run['thread_id'],'run_id'=>$run['id'],'sequence'=>$sequence,'kind'=>$kind,'payload_json'=>self::json($payload),'create_time'=>time()]);
    }
    private static function scope(array $run): array {return ['tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id'],'canvas_id'=>$run['canvas_id']];}
    private static function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
