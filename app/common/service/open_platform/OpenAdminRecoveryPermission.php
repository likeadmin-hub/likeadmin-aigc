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
        $uris=array_map(static fn($uri)=>strtolower(\think\helper\Str::camel((string)$uri)),(array)$cache->getAdminUri());
        if(!in_array('app.open_platform.management/resumetracking',$uris,true))throw new OpenException('permission_denied','没有恢复原任务跟踪权限',403);
    }
}
