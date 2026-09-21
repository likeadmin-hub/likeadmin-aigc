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
            return $claim+['run_id'=>$runId,'thread_id'=>(int)$thread['id'],'context'=>json_decode($run['context_snapshot'],true,512,JSON_THROW_ON_ERROR),'settings'=>json_decode($run['settings_snapshot'],true,512,JSON_THROW_ON_ERROR),'skill'=>json_decode($run['skill_snapshot'],true,512,JSON_THROW_ON_ERROR)];
        });
    }

    /** Return false when a valid but late reply is retained for reconciliation. */
    public static function complete(int $tenant,int $user,int $runId,string $token,int $fence,string $text): bool
    {
        if (trim($text)==='' || mb_strlen($text)>100000) throw new RuntimeException('INVALID_ASSISTANT_REPLY');
        return Db::transaction(function () use ($tenant,$user,$runId,$token,$fence,$text): bool {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);
            self::identity($outbox,$token,$fence);
            $hash=hash('sha256',$text);
            if ($run['status']==='success') {
                $prior=Db::name(ConversationStore::PREFIX.'message')->where(['run_id'=>$runId,'role'=>'assistant'])->value('content_json');
                if ($prior!==self::json(['text'=>$text])) throw new RuntimeException('REPLY_CONFLICT');
                return true;
            }
            if (!in_array($run['status'],['running','needs_reconciliation'],true)) throw new RuntimeException('INVALID_RUN_STATE');
            if ($run['status']==='needs_reconciliation' || (int)$outbox['lease_until']<=time()) {
                $prior=Db::name(ConversationStore::PREFIX.'event')->where(['run_id'=>$runId,'kind'=>'run.late_reply'])->find();
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
            $sequence=(int)$thread['next_message_sequence'];
            Db::name(ConversationStore::PREFIX.'message')->insert(self::scope($run)+['thread_id'=>$run['thread_id'],'run_id'=>$runId,'sequence'=>$sequence,'role'=>'assistant','content_json'=>self::json(['text'=>$text]),'attachments_json'=>'[]','create_time'=>time()]);
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread['id'])->update(['active_run_id'=>0,'next_message_sequence'=>$sequence+1,'update_time'=>time()]);
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'done','lease_until'=>0,'update_time'=>time()]);
            self::state($run,'success');self::event($run,'run.succeeded',['status'=>'success','message_sequence'=>$sequence]);
            return true;
        });
    }

    /** Only a server-owned no-cost preflight may use this before generate(). */
    public static function rejectBeforeSubmit(int $tenant,int $user,int $runId,string $token,int $fence): void
    {
        Db::transaction(function () use ($tenant,$user,$runId,$token,$fence): void {
            [$run,$thread,$outbox]=self::locked($tenant,$user,$runId);self::identity($outbox,$token,$fence);
            if ($run['status']==='failed' && $run['error_code']==='PRECHECK_FAILED') return;
            if ($run['status']!=='running' || $outbox['state']!=='processing' || (int)$outbox['lease_until']<=time()) throw new RuntimeException('STALE_WORKER');
            if ((int)$thread['active_run_id']!==$runId) throw new RuntimeException('RUN_SUPERSEDED');
            self::state($run,'failed','PRECHECK_FAILED');
            self::event($run,'run.failed',['status'=>'failed','code'=>'PRECHECK_FAILED']);
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update(['state'=>'failed','lease_until'=>0,'update_time'=>time()]);
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread['id'])->update(['active_run_id'=>0,'update_time'=>time()]);
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
            if ($run['status']!=='running' || $outbox['state']!=='processing' || (int)$outbox['lease_until']>time()) return false;
            self::uncertain($run,$outbox,'WORKER_LEASE_EXPIRED');return true;
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
        return [$run,$thread,$outbox];
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
        $sequence=(int)Db::name(ConversationStore::PREFIX.'event')->where('run_id',$run['id'])->max('sequence')+1;
        Db::name(ConversationStore::PREFIX.'event')->insert(self::scope($run)+['thread_id'=>$run['thread_id'],'run_id'=>$run['id'],'sequence'=>$sequence,'kind'=>$kind,'payload_json'=>self::json($payload),'create_time'=>time()]);
    }
    private static function scope(array $run): array {return ['tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id'],'canvas_id'=>$run['canvas_id']];}
    private static function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
