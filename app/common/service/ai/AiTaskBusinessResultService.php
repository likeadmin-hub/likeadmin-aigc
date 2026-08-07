<?php

namespace app\common\service\ai;

use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_product_promo_video\AigcProductPromoVideoService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_video\AigcVideoService;
use RuntimeException;
use think\facade\Db;

class AiTaskBusinessResultService
{
    /**
     * Reconcile a result that is already terminal in local storage. This must
     * not query a provider, so list/detail APIs can safely recover a delayed
     * business write without turning reads into upstream polling requests.
     */
    public static function syncTerminalByConsumptionId(int $consumptionId): bool
    {
        $context = self::context($consumptionId);
        if ($context === null || !self::terminal($context['consumption'])) {
            return false;
        }

        $assets = AiTaskResultAssetService::recordConsumptionAssets(
            $consumptionId,
            self::requiresForcedTransfer($consumptionId)
        );
        if ((string)($context['consumption']['run_status'] ?? '') === 'success'
            && self::requiresForcedTransfer($consumptionId)
            && self::expectsResultAssets($context['consumption'])
            && !self::assetsPersisted($assets)) {
            return false;
        }
        if (!self::hasBusinessAdapter((string)$context['business_table'], (int)$context['business_id'])) {
            return false;
        }
        self::syncByConsumptionId($consumptionId);
        return true;
    }

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
            'aigc_image_task' => AigcImageService::refreshAsyncTaskResult((int)$consumption['tenant_id'], $businessId, (int)$consumption['user_id']),
            'aigc_video_task' => self::syncVideoTask($consumption, $businessId),
            'aigc_short_drama_script_task' => AigcShortDramaService::refreshScriptTask($businessId),
            'aigc_short_drama_generation_task' => AigcShortDramaService::refreshMarketGenerationTask($businessId),
            'aigc_canvas_run' => null,
            default => self::assertOptionalBusinessAdapter($consumption, $businessTable),
        };
    }

    public static function requiresForcedTransfer(int $consumptionId): bool
    {
        $context = self::context($consumptionId);
        if ($context === null) {
            return false;
        }
        return (string)($context['consumption']['provider'] ?? '') === 'power_market'
            || (string)$context['app_code'] === 'aigc_short_drama'
            || (string)$context['business_table'] === 'aigc_short_drama_generation_task';
    }

    private static function assertOptionalBusinessAdapter(array $consumption, string $businessTable): void
    {
        if (self::terminal($consumption)) {
            throw new RuntimeException('未注册的关联业务结果处理器: ' . $businessTable);
        }
    }

    private static function syncVideoTask(array $consumption, int $businessId): void
    {
        AigcVideoService::refreshMarketTask((int)$consumption['tenant_id'], $businessId, (int)$consumption['user_id']);
        AigcProductPromoVideoService::syncMarketVideoTask((int)$consumption['tenant_id'], $businessId);
    }

    private static function hasBusinessAdapter(string $businessTable, int $businessId): bool
    {
        return $businessId > 0 && in_array($businessTable, [
            'aigc_image_task',
            'aigc_video_task',
            'aigc_short_drama_script_task',
            'aigc_short_drama_generation_task',
            'aigc_canvas_run',
        ], true);
    }

    private static function terminal(array $consumption): bool
    {
        return in_array((string)($consumption['run_status'] ?? ''), ['success', 'failed', 'canceled', 'cancelled'], true)
            || in_array((string)($consumption['billing_status'] ?? ''), ['settled', 'refunded'], true);
    }

    private static function assetsPersisted(array $assets): bool
    {
        if ($assets === []) {
            return false;
        }
        foreach ($assets as $asset) {
            if ((string)($asset['transfer_status'] ?? '') !== 'stored'
                || trim((string)($asset['local_uri'] ?? '')) === ''
                || trim((string)($asset['storage_scope'] ?? '')) === ''
                || trim((string)($asset['storage_engine'] ?? '')) === '') {
                return false;
            }
        }
        return true;
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
