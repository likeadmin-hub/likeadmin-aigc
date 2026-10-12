<?php
namespace app\common\service\open_platform;
/** Fail closed even before the new menu permission has been synchronized. */
final class OpenAdminRecoveryPermission
{
    public static function assert(string $scene,array $admin): void
    {
        if(!in_array($scene,['platform_admin','tenant_admin'],true)||(int)($admin['admin_id']??0)<=0)throw new OpenException('permission_denied','恢复操作需要后台权限',403);
        if((int)($admin['root']??0)===1)return;
        $cache=$scene==='platform_admin'?new \app\common\cache\AdminAuthCache($admin['admin_id']):new \app\common\cache\TenantAdminAuthCache($admin['admin_id']);
        if(!self::granted((array)$cache->getAdminUri()))throw new OpenException('permission_denied','没有恢复原任务跟踪权限',403);
    }
    private static function granted(array $uris): bool
    {
        $normalize=static fn($uri)=>strtolower(\think\helper\Str::camel((string)$uri));
        return in_array($normalize('app.open_platform.management/resumeTracking'),array_map($normalize,$uris),true);
    }
}
