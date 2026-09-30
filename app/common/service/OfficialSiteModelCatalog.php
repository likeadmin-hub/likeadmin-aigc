<?php
namespace app\common\service;

use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;
use app\common\service\power\PowerMarketService;
use think\facade\Cache;
use think\facade\Log;

/** Tenant-separated, public allowlist. Never expose runtime pricing or credentials. */
class OfficialSiteModelCatalog
{
    public static function get(int $tenantId): array
    {
        if ($tenantId <= 0) return [];
        try {
            return Cache::remember('official_site_models_v4_' . $tenantId, static function () use ($tenantId) {
                return array_merge(
                    self::present(MarketImageModelRuntimeService::options($tenantId), 'image'),
                    self::present(MarketVideoRuntimeService::options($tenantId, PowerMarketService::TYPE_MODEL), 'video')
                );
            }, 60);
        } catch (\Throwable $e) {
            Log::warning('Official site model catalog unavailable: ' . get_class($e));
            return [];
        }
    }

    public static function present(array $options, string $type): array
    {
        $result = [];
        foreach ($options as $option) {
            if (empty($option['available']) || empty($option['enabled'])) continue;
            $id = (string)($option['value'] ?? $option['id'] ?? '');
            if ($id === '') continue;
            $icon = (string)($option['display_icon'] ?? '');
            $result[] = [
                'id' => $id, 'type' => $type,
                'title' => (string)($option['name'] ?? ''),
                'description' => mb_substr(strip_tags((string)($option['description'] ?? '')), 0, 300),
                'icon_url' => $icon === '' ? '' : FileService::getFileUrl($icon),
                'link' => '/ai/create?type=' . $type . '&channel=' . rawurlencode($id),
            ];
        }
        return $result;
    }
}
