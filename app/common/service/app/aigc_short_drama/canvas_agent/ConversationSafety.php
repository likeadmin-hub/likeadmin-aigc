<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use think\facade\Db;

/**
 * App-scoped text safety boundary for the canvas Agent.
 *
 * The audit stores only a digest, length and decision. Tenant rules never
 * return to a browser and are never shared across tenants.
 */
final class ConversationSafety
{
    public const TABLE=ConversationStore::PREFIX.'safety_audit';
    public const POLICY_VERSION='canvas-agent-default-v1';

    public static function assertInput(int $tenant,int $user,int $canvas,int $thread,string $requestKey,string $content): void
    {
        $decision=self::decision($tenant,$content);
        self::audit($tenant,$user,$canvas,$thread,0,$requestKey,'input',$content,$decision,false);
        if ($decision['state']==='blocked') throw new ConversationSafetyViolation();
    }

    /** @throws ConversationSafetyViolation */
    public static function assertOutput(int $tenant,int $user,int $canvas,int $thread,int $run,string $content): void
    {
        $decision=self::decision($tenant,$content);
        self::audit($tenant,$user,$canvas,$thread,$run,'','output',$content,$decision,true);
        if ($decision['state']==='blocked') throw new ConversationSafetyViolation();
    }

    /** Bounded cleanup is safe to call from the long-running Agent worker. */
    public static function purgeExpired(int $tenant,int $limit=100): int
    {
        if ($tenant<=0) return 0;
        $rows=Db::name(self::TABLE)->where(['tenant_id'=>$tenant])->where('expires_at','>',0)->where('expires_at','<=',time())->order('id')->limit(max(1,min(1000,$limit)))->column('id');
        if (!$rows) return 0;
        return Db::name(self::TABLE)->whereIn('id',$rows)->delete();
    }

    /** @return array{state:string,reason:string,policy:array<string,mixed>} */
    private static function decision(int $tenant,string $content): array
    {
        $policy=FeatureGate::safetyPolicy($tenant);
        foreach ((array)($policy['blocked_terms']??[]) as $term) {
            $term=trim((string)$term);
            if ($term!=='' && mb_stripos($content,$term,0,'UTF-8')!==false) {
                return ['state'=>'blocked','reason'=>'tenant_rule','policy'=>$policy];
            }
        }
        return ['state'=>'passed','reason'=>'','policy'=>$policy];
    }

    /** @param array{state:string,reason:string,policy:array<string,mixed>} $decision */
    private static function audit(int $tenant,int $user,int $canvas,int $thread,int $run,string $requestKey,string $direction,string $content,array $decision,bool $providerSubmitted): void
    {
        $policy=$decision['policy'];
        $record=[
            'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'thread_id'=>$thread,'run_id'=>$run,
            'app_code'=>'aigc_short_drama','request_key'=>$requestKey,'direction'=>$direction,
            'policy_version'=>(string)($policy['version']??self::POLICY_VERSION),'decision'=>$decision['state'],
            'reason_code'=>$decision['reason'],'content_sha256'=>hash('sha256',$content),'content_length'=>mb_strlen($content,'UTF-8'),
            'provider_submitted'=>$providerSubmitted?1:0,'expires_at'=>time()+((int)$policy['audit_retention_days']*86400),'create_time'=>time(),
        ];
        $where=['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'thread_id'=>$thread,'run_id'=>$run,'direction'=>$direction,'request_key'=>$requestKey];
        if (Db::name(self::TABLE)->where($where)->find()) return;
        try { Db::name(self::TABLE)->insert($record); }
        catch (\Throwable $error) {
            // Concurrent retries may race after the first existence read. The
            // unique key is the authority; only suppress that already-owned
            // audit, never a different database failure.
            if (!str_contains($error->getMessage(),'Duplicate entry')) throw $error;
        }
    }
}
