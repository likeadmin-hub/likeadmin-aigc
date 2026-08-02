<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketMusicAppRuntimeService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;
use RuntimeException;

class AiMarketTaskRuntimeService
{
    public static function refresh(int $consumptionId): void
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty()) {
            return;
        }
        if (self::terminal($consumption->toArray())) {
            self::syncTerminalBusinessResult($consumptionId);
            return;
        }

        $protocol = (string)$consumption['protocol'];
        $provider = (string)$consumption['provider'];
        $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
        $upstreamApp = (string)($snapshot['app_code'] ?? '');

        // Application APIs own their submit/query protocol even when the
        // consuming business app is AIGC image.
        if ($provider === 'power_market' && $protocol === 'application_api' && $upstreamApp === 'nano_banana') {
            MarketNanoBananaAppRuntimeService::refresh($consumptionId);
            return;
        }

        // AIGC image model APIs use local provider runtimes. Market rows only
        // control availability and pricing, never the submit/query executor.
        if ((string)$consumption['app_code'] === 'aigc_image') {
            AiTaskBusinessResultService::syncByConsumptionId($consumptionId);
            return;
        }

        // Standalone video records retain the exact runtime selection made at
        // submission. Some older market records do not have protocol/app-code
        // metadata, so routing them by those mutable fields leaves the result
        // worker without a handler even though the business task can refresh.
        if ((string)$consumption['app_code'] === 'aigc_video') {
            AiTaskBusinessResultService::syncByConsumptionId($consumptionId);
            return;
        }

        if ($provider !== 'power_market') {
            AiTaskBusinessResultService::syncByConsumptionId($consumptionId);
            return;
        }

        if ($protocol === 'image_generate') {
            MarketImageModelRuntimeService::refresh($consumptionId);
            self::syncTerminalBusinessResult($consumptionId);
            return;
        }
        if ($protocol === 'video_generate' || self::isVideoApp($upstreamApp)) {
            MarketVideoRuntimeService::refresh($consumptionId);
            self::syncTerminalBusinessResult($consumptionId);
            return;
        }
        if ($protocol === 'application_api') {
            if ($upstreamApp === 'music_generation') {
                MarketMusicAppRuntimeService::refresh($consumptionId);
                self::syncTerminalBusinessResult($consumptionId);
                return;
            }
        }

        AiTaskBusinessResultService::syncByConsumptionId($consumptionId);
        $latest = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($latest->isEmpty() || !self::terminal($latest->toArray())) {
            throw new RuntimeException('未注册的市场上游任务处理器: ' . $protocol . '/' . $upstreamApp);
        }
    }

    private static function terminal(array $consumption): bool
    {
        return in_array((string)($consumption['run_status'] ?? ''), ['success', 'failed', 'canceled', 'cancelled'], true)
            || in_array((string)($consumption['billing_status'] ?? ''), ['settled', 'refunded'], true);
    }

    /**
     * Provider runtimes settle the shared consumption before the linked
     * business record is hydrated. Reconcile that handoff here so a late
     * callback or a restarted worker cannot leave the user-facing task active.
     */
    private static function syncTerminalBusinessResult(int $consumptionId): void
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty() || !self::terminal($consumption->toArray())) {
            return;
        }
        AiTaskBusinessResultService::syncTerminalByConsumptionId($consumptionId);
    }

    private static function isVideoApp(string $appCode): bool
    {
        return in_array($appCode, ['wan', 'seedance', 'happy_horse'], true);
    }

    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
