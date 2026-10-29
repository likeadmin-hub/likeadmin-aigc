<?php
declare(strict_types=1);
namespace app\common\service\auth;

use app\common\model\tenant\Tenant;
use app\common\service\open_platform\OpenStore;

/** Public menu projection; platform grants supplement existing role permissions. */
final class TenantFeatureMenuService
{
    public static function capabilities(int $tenantId): array
    {
        $tenant = Tenant::withoutGlobalScope()->where('id', $tenantId)->field('id,disable,oem_sales_enabled')->findOrEmpty();
        if ($tenant->isEmpty() || (int)$tenant['disable'] !== 0) return ['oem' => false, 'api' => false];
        return [
            'oem' => (int)$tenant['oem_sales_enabled'] === 1,
            // A tenant that pauses API usage retains its grant and must still
            // be able to open management to resume it. Global maintenance is
            // likewise separate from platform authorization of this tenant.
            'api' => (bool)OpenStore::settings($tenantId)['authorized'],
        ];
    }

    /** Filter only rows already granted by the role; never create or enable menus. */
    public static function filter(array $menus, array $capabilities): array
    {
        $blocked = [];
        foreach ($menus as $menu) {
            $path = trim((string)($menu['paths'] ?? ''), '/');
            $component = trim((string)($menu['component'] ?? ''), '/');
            $source = (string)($menu['source_menu_key'] ?? '');
            $oem = $path === 'brand' || str_starts_with($path, 'brand/')
                || str_starts_with($component, 'brand/') || $source === 'core_tenant_brand';
            $api = ($menu['app_code'] ?? '') === 'open_platform'
                || $path === 'open-platform' || str_starts_with($path, 'open-platform/')
                || str_starts_with($component, 'apps/open_platform/') || $source === 'open_platform_tenant';
            if (($oem && empty($capabilities['oem'])) || ($api && empty($capabilities['api']))) {
                $blocked[(string)$menu['id']] = true;
            }
        }
        // Descendants may be custom or carry no app metadata. Remove the whole
        // denied subtree regardless of input order, including nested groups.
        do {
            $changed = false;
            foreach ($menus as $menu) {
                $id = (string)$menu['id'];
                if (!isset($blocked[$id]) && isset($blocked[(string)($menu['pid'] ?? '')])) {
                    $blocked[$id] = true;
                    $changed = true;
                }
            }
        } while ($changed);
        return array_values(array_filter($menus, static fn(array $menu): bool => !isset($blocked[(string)$menu['id']])));
    }
}
