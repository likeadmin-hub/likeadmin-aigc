<?php
declare(strict_types=1);
namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionEvent;
use think\facade\Db;
use RuntimeException;

/** Explicit operator decisions for uncharged retired text calls. Never submits or debits. */
final class RetiredTextConsumptionReconciliationService
{
    public static function fingerprint(array $row): string
    {
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function process(array $plan, bool $apply = false, string $actor = '', string $reason = '', bool $writeoff = false): array
    {
        if (($plan['app_code'] ?? '') !== 'aigc_micro_video' || ($plan['database'] ?? '') !== config('database.connections.mysql.database')
            || !in_array(config('database.connections.mysql.hostname'), ['127.0.0.1', 'localhost'], true)
            || empty($plan['records']) || !preg_match('/^[a-f0-9]{64}$/D', $plan['evidence_sha256'] ?? '')) {
            throw new RuntimeException('需要本地数据库的完整核对清单与证据摘要');
        }
        if ($apply && (trim($actor) === '' || mb_strlen(trim($reason)) < 8)) throw new RuntimeException('执行需要明确操作人和处理原因');
        if ($apply) \app\common\service\app\RetiredAppWorkerGuard::assertStopped(root_path());
        $planId = self::fingerprint($plan);
        return Db::transaction(static function () use ($plan, $planId, $apply, $actor, $reason, $writeoff): array {
            $seen = []; $result = [];
            foreach ($plan['records'] as $item) {
                $id = (int)($item['id'] ?? 0);
                if ($id <= 0 || isset($seen[$id])) throw new RuntimeException('清单包含无效或重复记录');
                $seen[$id] = true;
                $row = Db::name('ai_consumption_log')->where('id', $id)->lock(true)->find();
                if (!$row || $row['app_code'] !== 'aigc_micro_video' || $row['usage_unit'] !== 'token' || $row['resource_type'] !== 'model') throw new RuntimeException('记录不属于已退役文本调用');
                $task = Db::name('ai_app_task')->where('id', $row['app_task_id'])->lock(true)->find();
                if (!$task || $task['app_code'] !== $row['app_code'] || (int)$task['tenant_id'] !== (int)$row['tenant_id'] || (int)$task['user_id'] !== (int)$row['user_id']) throw new RuntimeException('任务归属不匹配');
                $old = AiConsumptionEvent::where(['consumption_id'=>$id, 'event_type'=>'admin_reconciliation'])->lock(true)->select();
                foreach ($old as $event) {
                    if (($event['payload_summary']['plan_id'] ?? '') !== $planId) throw new RuntimeException('已有其他核对决定，请重新审核');
                    if (!hash_equals($event['payload_summary']['closed_fingerprint'] ?? '', self::fingerprint($row))) throw new RuntimeException('核对后记录已变化');
                }
                if (count($old)) { $result[] = ['id'=>$id, 'status'=>'already_reconciled']; continue; }
                if (!hash_equals((string)$item['local_fingerprint'], self::fingerprint($row))) throw new RuntimeException('记录已变化，请重新核对');
                foreach (['reserved_tenant_cost','reserved_user_price','actual_tenant_cost','actual_user_price'] as $field) {
                    if ((float)$row[$field] !== 0.0) throw new RuntimeException('有预扣或实际资金记录，禁止零金额收尾');
                }
                $sources = array_map(static fn($suffix) => $row['consume_no'].$suffix, ['', '-reserve', '-extra', '-refund', '-release']);
                foreach (['tenant_point_log','user_account_log'] as $table) {
                    if (Db::name($table)->whereIn('source_sn', $sources)->lock(true)->find()) throw new RuntimeException('存在资金流水，需要单独账务核对');
                }
                if (!in_array($row['billing_status'], ['pending_usage','settlement_failed'], true)
                    || !in_array($row['run_status'], ['failed','pending_confirmation'], true)
                    || !in_array($task['status'], ['running','failed'], true)) throw new RuntimeException('不是待核对的失败文本调用');
                $receipt = $item['receipt'] ?? [];
                if (empty($receipt['identity_verified']) || empty($receipt['request_verified']) || empty($receipt['task_id'])
                    || !in_array($receipt['status'] ?? '', ['success','failed'], true) || !is_numeric($receipt['actual_points'] ?? null)) throw new RuntimeException('缺少独立上游终态及归属证据');
                $rejected = $receipt['status'] === 'failed' && (float)$receipt['actual_points'] === 0.0;
                if (!$rejected && !($writeoff || !$apply)) throw new RuntimeException('历史费用需要管理员明确批准平台核销；不会自动补扣');
                $decision = $rejected ? 'provider_rejected_zero_charge' : 'platform_writeoff';
                if ($apply) {
                    // Keep all original usage, pricing, provider errors, receipts and business history byte-for-byte.
                    $now = time(); $changes = ['run_status'=>'failed','billing_status'=>$rejected ? 'refunded' : 'settled',
                        'finish_time'=>(int)$row['finish_time'] ?: $now, 'update_time'=>$now];
                    Db::name('ai_consumption_log')->where('id', $id)->update($changes);
                    if ($task['status'] === 'running') Db::name('ai_app_task')->where('id', $task['id'])->update(['status'=>'failed','finish_time'=>$now,'update_time'=>$now]);
                    AiConsumptionEvent::create(['consumption_id'=>$id,'event_type'=>'admin_reconciliation','event_status'=>'success','attempt_no'=>1,
                        'payload_summary'=>['plan_id'=>$planId,'decision'=>$decision,'actor'=>$actor,'reason'=>$reason,'evidence_sha256'=>$plan['evidence_sha256'],
                            'original_fingerprint'=>$item['local_fingerprint'],'closed_fingerprint'=>self::fingerprint(array_replace($row,$changes)),
                            'receipt'=>$receipt,'tenant_debit_points'=>0,'user_debit_points'=>0,'refund_points'=>0], 'create_time'=>$now]);
                }
                $result[] = ['id'=>$id,'status'=>$apply ? 'reconciled' : 'reviewed','decision'=>$decision,'upstream_points'=>$receipt['actual_points'], 'local_debit_points'=>0];
            }
            return ['mode'=>$apply ? 'applied' : 'audit','plan_id'=>$planId,'records'=>$result];
        });
    }
}
