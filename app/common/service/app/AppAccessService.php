<?php

namespace app\common\service\app;

use app\common\model\app\App;
use app\common\model\app\TenantApp;
use app\common\service\JsonService;
use app\common\service\membership\MembershipService;
use think\Response;

class AppAccessService
{
    public const DEFAULT_AIGC_APP_CODES = ['aigc_image', 'aigc_video', 'aigc_digital_human', 'aigc_canvas', 'aigc_llm', 'image_human', 'smart_clip', 'aigc_hairstyle', 'aigc_fitting', 'aigc_product_image', 'aigc_style_transfer'];
    public const BUY_PAID = 'paid';
    public const BUY_TRIAL = 'trial';
    public const SHELF_ON = 'on';
    public const ENABLED = 'enabled';

    public static function isDefaultAigcApp(string $appCode): bool
    {
        return in_array($appCode, self::DEFAULT_AIGC_APP_CODES, true);
    }

    public static function enabledTenantAppCodes(int $tenantId): array
    {
        return self::tenantAppCodes($tenantId, true);
    }

    public static function purchasedTenantAppCodes(int $tenantId): array
    {
        return self::tenantAppCodes($tenantId, false);
    }

    public static function tenantCanManage(int $tenantId, string $appCode): bool
    {
        if (self::isDefaultAigcApp($appCode) && self::isInstalled($appCode)) {
            DefaultAppService::ensureTenantDefaultApp($tenantId, $appCode);
            return true;
        }
        return in_array($appCode, self::purchasedTenantAppCodes($tenantId), true);
    }

    public static function tenantCanUse(int $tenantId, string $appCode): bool
    {
        if (self::isDefaultAigcApp($appCode) && self::isInstalled($appCode)) {
            DefaultAppService::ensureTenantDefaultApp($tenantId, $appCode);
            return true;
        }
        return in_array($appCode, self::enabledTenantAppCodes($tenantId), true);
    }

    public static function assertTenantCanManage(int $tenantId, string $appCode): ?Response
    {
        if (!self::tenantCanManage($tenantId, $appCode)) {
            return JsonService::fail('应用未购买、已禁用或已过期', [], 0, 1);
        }
        return null;
    }

    public static function assertTenantCanUse(int $tenantId, string $appCode, int $userId = 0): ?Response
    {
        if (!self::tenantCanUse($tenantId, $appCode)) {
            return JsonService::fail('应用未购买、未上架或已过期', [], 0, 1);
        }
        if (!MembershipService::userCanUseApp($tenantId, $userId, $appCode)) {
            return JsonService::fail('该应用需开通会员后使用', [
                'need_membership' => 1,
                'app_code' => $appCode,
            ], 0, 1);
        }
        return null;
    }

    private static function tenantAppCodes(int $tenantId, bool $requireShelf): array
    {
        if ($tenantId <= 0) {
            return [];
        }
        $installedApps = App::where('status', AppRegistryService::STATUS_INSTALLED)->column('*', 'code');
        $installed = array_keys($installedApps);
        if (empty($installed)) {
            return [];
        }
        $query = TenantApp::where('tenant_id', $tenantId)
            ->whereIn('app_code', $installed)
            ->whereIn('buy_status', [self::BUY_PAID, self::BUY_TRIAL])
            ->where('enable_status', self::ENABLED);
        if ($requireShelf) {
            $query->where('shelf_status', self::SHELF_ON);
        }
        $rows = $query->select()->toArray();
        $codes = [];
        foreach ($rows as $row) {
            if (empty($row['expire_time']) || (int)$row['expire_time'] > time()) {
                $codes[] = $row['app_code'];
                continue;
            }
            $app = $installedApps[$row['app_code']] ?? [];
            if (($app['expire_policy'] ?? AppPlanService::EXPIRE_BLOCK) === AppPlanService::EXPIRE_ALLOW) {
                $codes[] = $row['app_code'];
            }
        }
        return array_values(array_unique(array_merge($codes, self::installedDefaultAigcAppCodes())));
    }

    private static function isInstalled(string $appCode): bool
    {
        return App::where(['code' => $appCode, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
    }

    private static function installedDefaultAigcAppCodes(): array
    {
        return App::where('status', AppRegistryService::STATUS_INSTALLED)
            ->whereIn('code', self::DEFAULT_AIGC_APP_CODES)
            ->column('code');
    }
}
