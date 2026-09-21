<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/** Bounded tenant-scoped durable scan. A supervisor can resume with cursor,
 * resetting to zero after reaching the end; no request-owned Provider class.
 * Production registration awaits the billing/safety adapter acceptance gate.
 */
final class ConversationQueue
{
    public static function tick(int $tenant,ConversationProviderInterface $provider,int $after=0,int $limit=20): array
    {
        if ($tenant<=0 || $after<0 || $limit<1 || $limit>100) throw new RuntimeException('INVALID_QUEUE_SCAN');
        $now=time();
        $rows=Db::name(ConversationStore::PREFIX.'outbox')->where('tenant_id',$tenant)->where('id','>',$after)
            ->whereRaw('((state = ? AND available_at <= ?) OR (state IN (?, ?) AND lease_until <= ?))',['pending',$now,'processing','submitting',$now])
            ->order('id')->limit($limit)->select()->toArray();
        $result=['cursor'=>$after,'scanned'=>count($rows),'items'=>[]];
        foreach ($rows as $row) {
            $result['cursor']=(int)$row['id'];$run=(int)$row['run_id'];$user=(int)$row['user_id'];
            try {
                if ($row['state']==='pending') {
                    $state=FeatureGate::enabled($tenant)?ConversationWorker::process($tenant,$user,$run,$provider):'deferred_disabled';
                } else {
                    // Never dispatch an expired/uncertain submission again.
                    $state=ConversationExecution::expire($tenant,$user,$run)?'needs_reconciliation':'unchanged';
                }
            } catch (\Throwable $error) {
                // Do not rewrite uncertain state or expose raw SQL/provider
                // diagnostics. Other owned jobs in the batch may still progress.
                $state='dispatch_error';
            }
            $result['items'][]=['run_id'=>$run,'state'=>$state];
        }
        return $result;
    }
}
