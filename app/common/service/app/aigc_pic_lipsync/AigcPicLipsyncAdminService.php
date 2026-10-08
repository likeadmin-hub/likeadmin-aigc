<?php

namespace app\common\service\app\aigc_pic_lipsync;

use app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncTask;
use app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncResult;
use app\common\model\app\aigc_music\AigcMusicAsset;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use think\facade\Db;
use Exception;

class AigcPicLipsyncAdminService
{
    public static function filterTasks($query, array $params): void
    {
        foreach (['status', 'quality', 'drive_mode'] as $key) {
            if (trim((string)($params[$key] ?? '')) !== '') $query->where($key, (string)$params[$key]);
        }
        foreach (['user_id', 'id'] as $key) {
            if ((int)($params[$key] ?? 0) > 0) $query->where($key, (int)$params[$key]);
        }
        if (trim((string)($params['keyword'] ?? '')) !== '') $query->where('title', 'like', '%' . trim((string)$params['keyword']) . '%');
        foreach (['start_time' => '>=', 'end_time' => '<='] as $key => $operator) {
            if ((int)($params[$key] ?? 0) > 0) $query->where('create_time', $operator, (int)$params[$key]);
        }
    }

    public static function stat(int $tenantId): array
    {
        $tasks = AigcPicLipsyncTask::where('id', '>', 0);
        if ($tenantId > 0) $tasks->where('tenant_id', $tenantId);
        // Successful task snapshots include soft-deleted tasks; removing a record must not hide settled consumption.
        $settled = (clone $tasks)->where('status', 'success')->field('SUM(actual_tenant_cost) tenant_cost_points, SUM(actual_user_price) user_charge_points, SUM(input_seconds) input_seconds')->find();
        $visible = (clone $tasks)->where('delete_time', 0);
        $counts = (clone $visible)->group('status')->column('COUNT(*)', 'status');
        $result = AigcPicLipsyncResult::where('delete_time', 0);
        $audio = AigcMusicAsset::where(['asset_type' => 'pic_lipsync_audio', 'delete_time' => 0]);
        if ($tenantId > 0) { $result->where('tenant_id', $tenantId); $audio->where('tenant_id', $tenantId); }
        return [
            'task_total' => (int)(clone $visible)->count(),
            'task_success' => (int)($counts['success'] ?? 0),
            'task_failed' => (int)($counts['failed'] ?? 0),
            'task_running' => (int)($counts['running'] ?? 0) + (int)($counts['pending'] ?? 0),
            'task_canceled' => (int)($counts['canceled'] ?? 0),
            'result_total' => (int)$result->count(),
            'audio_total' => (int)$audio->count(),
            'tenant_cost_points' => (float)($settled['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($settled['user_charge_points'] ?? 0),
            'input_seconds' => (float)($settled['input_seconds'] ?? 0),
            'billing_note' => '成功任务的实际结算快照，包含已删除任务；不包含未同步的任务和其他业务。',
        ];
    }

    public static function tenantUsage(array $params): array
    {
        $query = Db::name('tenant_app')->alias('a')->leftJoin('tenant t', 't.id = a.tenant_id')
            ->where('a.app_code', AigcPicLipsyncService::APP_CODE)
            ->field('a.tenant_id,t.name tenant_name,a.buy_status,a.enable_status,a.shelf_status,a.expire_time');
        if ((int)($params['tenant_id'] ?? 0) > 0) $query->where('a.tenant_id', (int)$params['tenant_id']);
        $page = max(1, (int)($params['page_no'] ?? 1));
        $size = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->order('a.tenant_id', 'desc')->page($page, $size)->select()->toArray();
        foreach ($rows as &$row) $row['usage'] = self::stat((int)$row['tenant_id']);
        return ['lists' => $rows, 'count' => $count, 'page_no' => $page, 'page_size' => $size];
    }

    public static function audioLists(int $tenantId, array $params): array
    {
        $query = AigcMusicAsset::where(['tenant_id' => $tenantId, 'asset_type' => 'pic_lipsync_audio', 'delete_time' => 0]);
        if ((int)($params['user_id'] ?? 0) > 0) $query->where('user_id', (int)$params['user_id']);
        if (trim((string)($params['keyword'] ?? '')) !== '') $query->where('name', 'like', '%' . trim((string)$params['keyword']) . '%');
        $page = max(1, (int)($params['page_no'] ?? 1));
        $size = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $size)->select()->toArray();
        foreach ($rows as &$row) $row['audio_url'] = AigcMusicAssetService::assetUrl($row);
        return ['lists' => $rows, 'count' => $count, 'page_no' => $page, 'page_size' => $size];
    }

    public static function deleteAudio(int $tenantId, int $id): void
    {
        $asset = AigcMusicAsset::where(['tenant_id' => $tenantId, 'asset_type' => 'pic_lipsync_audio', 'id' => $id, 'delete_time' => 0])->findOrEmpty();
        if ($asset->isEmpty()) throw new Exception('驱动音频不存在');
        $asset->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function clearData(): void
    {
        // Shared avatar/voice libraries and immutable billing ledgers belong to other modules.
        AigcPicLipsyncResult::where('id', '>', 0)->delete();
        AigcPicLipsyncTask::where('id', '>', 0)->delete();
        \app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncConfig::where('id', '>', 0)->delete();
        AigcMusicAsset::where('asset_type', 'pic_lipsync_audio')->delete();
    }
}
