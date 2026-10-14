<?php

namespace app\common\service\ai;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiTaskResultAsset;
use app\common\service\storage\StorageConfigService;
use think\facade\Db;

/** Read-only market task reconciliation for rollout gates and incident triage. */
class MarketTaskReconciliationService
{
    public static function audit(int $tenantId = 0, string $appCode = '', int $limit = 500, string $businessTable = '', int $userId = 0): array
    {
        $query = AiConsumptionLog::where('provider', 'power_market')->order('id', 'desc')->limit(max(1, min(5000, $limit)));
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($appCode !== '') {
            $query->where('app_code', $appCode);
        }
        if($userId>0)$query->where('user_id',$userId);
        if($businessTable!=='')$query->whereIn('app_task_id',Db::raw(AiAppTask::where('business_table',$businessTable)->field('id')->buildSql()));
        $issues = [];
        $checked = 0;
        foreach ($query->select() as $consumption) {
            $checked++;
            $issues = array_merge($issues, self::issues($consumption->toArray()));
        }
        return ['checked' => $checked, 'issue_count' => count($issues), 'issues' => $issues];
    }

    public static function repairAssets(int $tenantId = 0, string $appCode = '', int $limit = 500, bool $apply = false): array
    {
        $query = AiConsumptionLog::where('provider', 'power_market')
            ->where('run_status', 'success')
            ->where('billing_status', 'settled')
            ->order('id', 'asc')
            ->limit(max(1, min(5000, $limit)));
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($appCode !== '') {
            $query->where('app_code', $appCode);
        }
        $candidates = 0;
        $repaired = 0;
        $queued = 0;
        foreach ($query->select() as $consumption) {
            $row = $consumption->toArray();
            if (!self::expectsResultAssets($row)) {
                continue;
            }
            $assets = AiTaskResultAsset::where('consumption_id', (int)$row['id'])->select()->toArray();
            $needsRepair = $assets === [];
            foreach ($assets as $asset) {
                if ((string)($asset['transfer_status'] ?? '') !== 'stored'
                    || trim((string)($asset['local_uri'] ?? '')) === '') {
                    $needsRepair = true;
                    break;
                }
                if (trim((string)($asset['storage_scope'] ?? '')) === ''
                    || trim((string)($asset['storage_engine'] ?? '')) === '') {
                    $needsRepair = true;
                    break;
                }
            }
            if (!$needsRepair) {
                continue;
            }
            $candidates++;
            if (!$apply) {
                continue;
            }
            foreach ($assets as $asset) {
                if ((string)($asset['transfer_status'] ?? '') !== 'stored'
                    || trim((string)($asset['local_uri'] ?? '')) === '') {
                    continue;
                }
                $scope = trim((string)($asset['storage_scope'] ?? ''));
                $engine = trim((string)($asset['storage_engine'] ?? ''));
                if ($scope === '' || $engine === '') {
                    $storage = StorageConfigService::getEffectiveConfig((int)$row['tenant_id']);
                    $updates = [];
                    if ($scope === '') $updates['storage_scope'] = (string)($storage['scope'] ?? 'tenant');
                    if ($engine === '') $updates['storage_engine'] = (string)($storage['default'] ?? 'local');
                    if ($updates !== []) {
                        $updates['update_time'] = time();
                        AiTaskResultAsset::where('id', (int)$asset['id'])->update($updates);
                        $repaired++;
                    }
                }
            }
            $before = count($assets);
            $after = AiTaskResultAssetService::recordConsumptionAssets((int)$row['id'], true);
            $repaired += max(0, count($after) - $before);
            foreach ($after as $asset) {
                if ((string)($asset['transfer_status'] ?? '') !== 'stored'
                    || trim((string)($asset['local_uri'] ?? '')) === '') {
                    continue;
                }
                $scope = trim((string)($asset['storage_scope'] ?? ''));
                $engine = trim((string)($asset['storage_engine'] ?? ''));
                if ($scope === '' || $engine === '') {
                    $storage = StorageConfigService::getEffectiveConfig((int)$row['tenant_id']);
                    $updates = [];
                    if ($scope === '') $updates['storage_scope'] = (string)($storage['scope'] ?? 'tenant');
                    if ($engine === '') $updates['storage_engine'] = (string)($storage['default'] ?? 'local');
                    if ($updates !== []) {
                        $updates['update_time'] = time();
                        AiTaskResultAsset::where('id', (int)$asset['id'])->update($updates);
                        $repaired++;
                    }
                }
            }
            foreach ($after as $asset) {
                if ((string)($asset['transfer_status'] ?? '') !== 'stored') {
                    $queued++;
                }
            }
        }
        return [
            'dry_run' => !$apply,
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'candidates' => $candidates,
            'repaired' => $repaired,
            'transfer_jobs_queued' => $queued,
        ];
    }

    private static function issues(array $consumption): array
    {
        $issues = [];
        $id = (int)$consumption['id'];
        $context = ['consumption_id' => $id, 'tenant_id' => (int)$consumption['tenant_id'], 'app_code' => (string)$consumption['app_code'], 'app_task_id' => (int)$consumption['app_task_id']];
        $appTask = (int)$consumption['app_task_id'] > 0 ? AiAppTask::findOrEmpty((int)$consumption['app_task_id']) : null;
        if ($appTask === null || $appTask->isEmpty()) {
            $issues[] = array_merge($context, ['code' => 'missing_app_task']);
        }
        $runStatus = (string)($consumption['run_status'] ?? '');
        $billingStatus = (string)($consumption['billing_status'] ?? '');
        $context['user_id']=(int)$consumption['user_id'];
        if($appTask&&!$appTask->isEmpty()){
            $context['business_table']=(string)$appTask['business_table'];$context['business_id']=(int)$appTask['business_id'];
            if((int)$appTask['tenant_id']!==(int)$consumption['tenant_id']||(int)$appTask['user_id']!==(int)$consumption['user_id'])$issues[]=array_merge($context,['code'=>'task_owner_mismatch']);
        }
        $prices=$consumption['price_snapshot']??[];if(is_string($prices))$prices=json_decode($prices,true)?:[];
        $text=isset($prices['input'],$prices['output'])&&($consumption['usage_unit']??'')==='token';
        if($runStatus==='success'&&$billingStatus==='pending_usage')$issues[]=array_merge($context,['code'=>$text?'text_usage_pending':'actual_usage_pending','has_upstream_reference'=>trim((string)($consumption['upstream_request_id']??''))!==''||trim((string)($consumption['upstream_task_id']??''))!=='']);
        if($billingStatus==='settlement_failed')$issues[]=array_merge($context,['code'=>'settlement_failed']);
        // Text calls have no media assets. They still need exactly one traceable deduction per payer.
        if($text&&$billingStatus==='settled'){
            foreach(['tenant'=>['tenant_point_log','tenant_id','actual_tenant_cost','tenant_point_sn'],'user'=>['user_account_log','user_id','actual_user_price','user_point_sn']] as $side=>$fields){
                [$table,$owner,$amount,$reference]=$fields;if((float)$consumption[$amount]<=0)continue;
                $sn=trim((string)($consumption[$reference]??''));
                if($sn===''||Db::name($table)->where([$owner=>(int)$consumption[$owner],'source_sn'=>$sn])->count()!==1)$issues[]=array_merge($context,['code'=>$side.'_billing_log_not_idempotent']);
            }
        }
        if (in_array($runStatus, ['failed', 'canceled', 'cancelled'], true) && !in_array($billingStatus, ['refunded', 'none'], true)) {
            $issues[] = array_merge($context, ['code' => 'reservation_not_released']);
        }
        if(in_array($runStatus,['failed','canceled','cancelled'],true)&&$billingStatus==='refunded'){
            foreach(['tenant'=>['tenant_point_log','tenant_id','reserved_tenant_cost'],'user'=>['user_account_log','user_id','reserved_user_price']] as $side=>$fields){
                [$table,$owner,$amount]=$fields;if((float)($consumption[$amount]??0)<=0)continue;
                if(Db::name($table)->where([$owner=>(int)$consumption[$owner],'source_sn'=>(string)$consumption['consume_no'].'-release'])->count()!==1)$issues[]=array_merge($context,['code'=>$side.'_refund_log_not_idempotent']);
            }
        }
        if ($runStatus === 'success' && $billingStatus === 'settled' && self::expectsResultAssets($consumption)) {
            $assets = AiTaskResultAsset::where('consumption_id', $id)->select()->toArray();
            if ($assets === []) {
                $issues[] = array_merge($context, ['code' => 'missing_result_asset']);
            }
            foreach ($assets as $asset) {
                if ((string)($asset['transfer_status'] ?? '') !== 'stored'
                    || trim((string)($asset['local_uri'] ?? '')) === ''
                    || trim((string)($asset['storage_scope'] ?? '')) === ''
                    || trim((string)($asset['storage_engine'] ?? '')) === '') {
                    $issues[] = array_merge($context, ['code' => 'asset_not_persisted', 'asset_id' => (int)($asset['id'] ?? 0)]);
                }
            }
            if ((float)($consumption['reserved_tenant_cost'] ?? 0) > 0 || (float)($consumption['reserved_user_price'] ?? 0) > 0) {
                $sourceSn = (string)($consumption['consume_no'] ?? '') . '-reserve';
                if ((float)($consumption['reserved_tenant_cost']??0)>0 && Db::name('tenant_point_log')->where(['tenant_id' => (int)$consumption['tenant_id'], 'source_sn' => $sourceSn])->count() !== 1) {
                    $issues[] = array_merge($context, ['code' => 'tenant_billing_log_not_idempotent']);
                }
                if ((float)($consumption['reserved_user_price']??0)>0 && Db::name('user_account_log')->where(['user_id' => (int)$consumption['user_id'], 'source_sn' => $sourceSn])->count() !== 1) {
                    $issues[] = array_merge($context, ['code' => 'user_billing_log_not_idempotent']);
                }
            }
        }
        return $issues;
    }

    private static function expectsResultAssets(array $consumption): bool
    {
        $summary = $consumption['response_summary'] ?? [];
        if (is_string($summary)) {
            $summary = json_decode($summary, true) ?: [];
        }
        if (!is_array($summary)) {
            return false;
        }
        foreach (['images', 'videos', 'items', 'audio', 'assets'] as $key) {
            if (is_array($summary[$key] ?? null) && $summary[$key] !== []) {
                return true;
            }
        }
        foreach (['image_count', 'video_count', 'audio_count', 'asset_count'] as $key) {
            if ((int)($summary[$key] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }
}
