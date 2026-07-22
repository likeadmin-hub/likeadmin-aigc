<?php

namespace app\common\service\ai;

use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_video\AigcVideoService;
use RuntimeException;
use think\facade\Db;

class AiTaskBusinessResultService
{
    public static function syncByConsumptionId(int $consumptionId): void
    {
        $context = self::context($consumptionId);
        if ($context === null) {
            return;
        }

        $consumption = $context['consumption'];
        $businessTable = (string)$context['business_table'];
        $businessId = (int)$context['business_id'];
        if ($businessTable === '' || $businessId <= 0) {
            return;
        }

        match ($businessTable) {
            'aigc_image_task' => AigcImageService::refreshRuntimeTask((int)$consumption['tenant_id'], $businessId, (int)$consumption['user_id']),
            'aigc_video_task' => AigcVideoService::refreshMarketTask((int)$consumption['tenant_id'], $businessId, (int)$consumption['user_id']),
            'aigc_short_drama_script_task' => AigcShortDramaService::refreshScriptTask($businessId),
            'aigc_short_drama_generation_task' => AigcShortDramaService::refreshMarketGenerationTask($businessId),
            default => self::assertOptionalBusinessAdapter($consumption, $businessTable),
        };
    }

    public static function requiresForcedTransfer(int $consumptionId): bool
    {
        $context = self::context($consumptionId);
        if ($context === null) {
            return false;
        }
        return ((string)$context['app_code'] === AigcShortDramaService::APP_CODE
            || (string)$context['business_table'] === 'aigc_short_drama_generation_task')
            && AigcShortDramaService::resultTransferEnabled((int)$context['consumption']['tenant_id']);
    }

    private static function assertOptionalBusinessAdapter(array $consumption, string $businessTable): void
    {
        if (self::terminal($consumption)) {
            throw new RuntimeException('未注册的关联业务结果处理器: ' . $businessTable);
        }
    }

    private static function terminal(array $consumption): bool
    {
        return in_array((string)($consumption['run_status'] ?? ''), ['success', 'failed', 'canceled', 'cancelled'], true)
            || in_array((string)($consumption['billing_status'] ?? ''), ['settled', 'refunded'], true);
    }

    /** @return array<string,mixed>|null */
    private static function context(int $consumptionId): ?array
    {
        if ($consumptionId <= 0) {
            return null;
        }
        $row = Db::name('ai_consumption_log')->alias('c')
            ->leftJoin('ai_app_task t', 't.id = c.app_task_id')
            ->where('c.id', $consumptionId)
            ->field('c.*,t.app_code as linked_app_code,t.business_table,t.business_id')
            ->find();
        if (!$row) {
            return null;
        }
        return [
            'consumption' => $row,
            'app_code' => (string)($row['linked_app_code'] ?? $row['app_code'] ?? ''),
            'business_table' => (string)($row['business_table'] ?? ''),
            'business_id' => (int)($row['business_id'] ?? 0),
        ];
    }
}
