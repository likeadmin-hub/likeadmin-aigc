<?php

namespace app\common\service\app;

use app\common\model\app\App;
use app\common\model\app\TenantApp;
use app\common\model\app\TenantAppOrder;
use app\common\service\JsonService;
use app\common\service\membership\MembershipService;
use think\Response;

class AppAccessService
{
    // These bundled apps are opened for new tenants, but an existing tenant's
    // shelf and enable choices must survive system and application updates.
    public const SHELFABLE_DEFAULT_APP_CODES = [
        'smart_clip', 'image_human', 'aigc_short_drama',
        'aigc_background_removal', 'aigc_fashion_lookbook', 'aigc_fitting',
        'aigc_hairstyle', 'aigc_image_translate', 'aigc_local_redraw',
        'aigc_model_wear', 'aigc_one_click_cleanup', 'aigc_outpaint',
        'aigc_photo_restore', 'aigc_product_image', 'aigc_product_multi_angle',
        'aigc_product_promo_video', 'aigc_product_video', 'aigc_story_commerce', 'aigc_store_visit', 'aigc_motion_sync', 'aigc_product_suite', 'aigc_style_transfer',
    ];
    public const DEFAULT_AIGC_APP_CODES = [
        'aigc_image',
        'aigc_video',
        'aigc_digital_human',
        'aigc_pic_lipsync',
        'aigc_canvas',
        'aigc_llm',
        'aigc_music',
        'aigc_music_cover',
        ...self::SHELFABLE_DEFAULT_APP_CODES,
    ];
    public const DEFAULT_SYSTEM_APP_CODES = ['system_default', 'open_platform', 'aigc_copywriting'];
    public const DEFAULT_APP_CODES = [
        'system_default',
        'open_platform',
        'aigc_copywriting',
        'aigc_image',
        'aigc_video',
        'aigc_digital_human',
        'aigc_pic_lipsync',
        'aigc_canvas',
        'aigc_llm',
        'aigc_music',
        'aigc_music_cover',
        ...self::SHELFABLE_DEFAULT_APP_CODES,
    ];
    // Legacy market cards expose a preview without granting paid execution.
    public const DISPLAY_MANAGED_APP_CODES = ['aigc_geo'];
    public const BUY_PAID = 'paid';
    public const BUY_TRIAL = 'trial';
    public const SHELF_ON = 'on';
    public const ENABLED = 'enabled';

    public static function isDefaultAigcApp(string $appCode): bool
    {
        return in_array($appCode, self::DEFAULT_AIGC_APP_CODES, true);
    }

    public static function isDefaultApp(string $appCode): bool
    {
        return in_array($appCode, self::DEFAULT_APP_CODES, true);
    }

    public static function isShelfableDefaultApp(string $appCode): bool
    {
        return $appCode !== 'system_default' && self::isDefaultApp($appCode);
    }

    public static function enabledTenantAppCodes(int $tenantId): array
    {
        return self::tenantAppCodes($tenantId, true);
    }

    /** Public navigation includes legacy preview cards, without granting API execution. */
    public static function frontendAppCodes(int $tenantId): array
    {
        $codes = self::tenantAppCodes($tenantId, true, false);
        if ($tenantId <= 0) return $codes;
        foreach (self::DISPLAY_MANAGED_APP_CODES as $appCode) {
            $status = App::where('code', $appCode)->value('status');
            $grant = TenantApp::where(['tenant_id' => $tenantId, 'app_code' => $appCode])->findOrEmpty();
            $tenantClosed = !$grant->isEmpty() && ($grant['shelf_status'] !== self::SHELF_ON || $grant['enable_status'] !== self::ENABLED);
            if (!$tenantClosed && ($status === null || $status === AppRegistryService::STATUS_INSTALLED)) {
                $codes[] = $appCode;
            }
        }
        $codes = AppAvailabilityConfigService::filterAvailable($tenantId, $codes);
        return array_values(array_unique($codes));
    }

    public static function purchasedTenantAppCodes(int $tenantId): array
    {
        return self::tenantAppCodes($tenantId, false);
    }

    public static function tenantCanManage(int $tenantId, string $appCode): bool
    {
        if (RetiredAppService::contains($appCode)) return false;
        if ($tenantId > 0 && self::isDefaultApp($appCode)
            && App::where('code', $appCode)->whereIn('status', [AppRegistryService::STATUS_INSTALLED, AppRegistryService::STATUS_DISABLED])->count() > 0) {
            return true;
        }
        return in_array($appCode, self::purchasedTenantAppCodes($tenantId), true);
    }

    public static function tenantCanUse(int $tenantId, string $appCode, bool $initialize = true): bool
    {
        if (RetiredAppService::contains($appCode)) return false;
        if (self::isDefaultApp($appCode) && self::isInstalled($appCode)) {
            if ($initialize) DefaultAppService::ensureTenantDefaultApp($tenantId, $appCode);
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

    /** Tenant availability is distinct from a specific user's subscription. */
    public static function assertTenantAvailable(int $tenantId, string $appCode, bool $initialize = true): ?Response
    {
        if (!self::tenantCanUse($tenantId, $appCode, $initialize)) {
            return JsonService::fail('当前应用已关闭', ['app_code' => $appCode, 'app_disabled' => 1], 0, 1);
        }
        return null;
    }

    public static function assertTenantCanUse(int $tenantId, string $appCode, int $userId = 0, bool $checkMembership = true, bool $initialize = true): ?Response
    {
        $unavailable = self::assertTenantAvailable($tenantId, $appCode, $initialize);
        if ($unavailable) return $unavailable;
        if ($checkMembership && !MembershipService::userCanUseApp($tenantId, $userId, $appCode)) {
            if ($userId <= 0) {
                return JsonService::fail('该应用需登录后使用', [
                    'need_login' => 1,
                    'app_code' => $appCode,
                ], 0, 1);
            }
            return JsonService::fail('当前会员需订阅包含此应用的套餐后使用', [
                'need_membership' => 1,
                'app_code' => $appCode,
            ], 0, 1);
        }
        return null;
    }

    private static function tenantAppCodes(int $tenantId, bool $requireShelf, bool $filterAvailability = true): array
    {
        if ($tenantId <= 0) {
            return [];
        }
        $installedApps = App::whereIn('status', $requireShelf
            ? [AppRegistryService::STATUS_INSTALLED]
            : [AppRegistryService::STATUS_INSTALLED, AppRegistryService::STATUS_DISABLED])->column('*', 'code');
        $installed = array_values(array_diff(array_keys($installedApps),RetiredAppService::CODES));
        if (empty($installed)) {
            return [];
        }
        $query = TenantApp::where('tenant_id', $tenantId)
            ->whereIn('app_code', $installed)
            ->where(function ($query) {
                $query->whereIn('buy_status', [self::BUY_PAID, self::BUY_TRIAL])
                    ->whereOr('app_code', 'in', self::DEFAULT_APP_CODES);
            });
        if ($requireShelf) {
            $query->where('shelf_status', self::SHELF_ON)->where('enable_status', self::ENABLED);
        }
        $rows = $query->select()->toArray();
        $paidOrderExpires = TenantAppOrder::where('tenant_id', $tenantId)
            ->where('pay_status', AppPlanService::PAY_PAID)
            ->group('app_code')
            ->column('MAX(after_expire_time)', 'app_code');
        $codes = [];
        foreach ($rows as $row) {
            $appCode = (string)($row['app_code'] ?? '');
            if (self::isDefaultApp($appCode)) {
                $codes[] = $appCode;
                continue;
            }
            $expireTime = max((int)($row['expire_time'] ?? 0), (int)($paidOrderExpires[$appCode] ?? 0));
            if (!self::isDefaultApp($appCode) && $expireTime <= 0) {
                continue;
            }
            if ($expireTime <= 0 || $expireTime > time()) {
                $codes[] = $appCode;
                continue;
            }
            $app = $installedApps[$appCode] ?? [];
            if (($app['expire_policy'] ?? AppPlanService::EXPIRE_BLOCK) === AppPlanService::EXPIRE_ALLOW) {
                $codes[] = $appCode;
            }
        }
        // Missing grants retain bundled defaults; an existing grant always owns its switch.
        $existing = TenantApp::where('tenant_id', $tenantId)->column('app_code');
        $defaults = array_values(array_intersect(array_keys($installedApps), self::DEFAULT_APP_CODES));
        $codes = array_values(array_unique(array_merge($codes, array_diff($defaults, $existing))));
        if ($requireShelf && $filterAvailability) {
            $codes = AppAvailabilityConfigService::filterAvailable($tenantId, $codes);
        }
        return $codes;
    }

    private static function isInstalled(string $appCode): bool
    {
        return App::where(['code' => $appCode, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
    }

}
