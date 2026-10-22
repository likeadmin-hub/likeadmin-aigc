<?php
namespace app\common\service\workbench;

/** Reuse the existing workbench permission without introducing unregistered fail-open APIs. */
final class OperationsAccess
{
    public static function allowed(array $admin, bool $platform): bool
    {
        if (empty($admin['admin_id'])) return false;
        if ((int)($admin['root'] ?? 0) === 1) return true;
        $class = $platform ? \app\common\cache\AdminAuthCache::class : \app\common\cache\TenantAdminAuthCache::class;
        $uris = (new $class($admin['admin_id']))->getAdminUri() ?? [];
        return in_array('workbench/index', array_map('strtolower', $uris), true);
    }
}
