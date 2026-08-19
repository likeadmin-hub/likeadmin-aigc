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
    public static function audit(int $tenantId = 0, string $appCode = '', int $limit = 500): array
    {
        $query = AiConsumptionLog::where('provider', 'power_market')->order('id', 'desc')->limit(max(1, min(5000, $limit)));
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($appCode !== '') {
            $query->where('app_code', $appCode);
        }
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
        if (in_array($runStatus, ['failed', 'canceled', 'cancelled'], true) && !in_array($billingStatus, ['refunded', 'none'], true)) {
            $issues[] = array_merge($context, ['code' => 'reservation_not_released']);
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
                if (Db::name('tenant_point_log')->where(['tenant_id' => (int)$consumption['tenant_id'], 'source_sn' => $sourceSn])->count() !== 1) {
                    $issues[] = array_merge($context, ['code' => 'tenant_billing_log_not_idempotent']);
                }
                if (Db::name('user_account_log')->where(['user_id' => (int)$consumption['user_id'], 'source_sn' => $sourceSn])->count() !== 1) {
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
