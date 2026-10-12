<?php
namespace app\common\service\open_platform;
/** Explicit module permissions; unknown/missing menu registrations never grant access. */
final class OpenAdminPermission
{
    public static function assert(string $scene,array $admin,string $action): void
    {
        if(!in_array($scene,['platform_admin','tenant_admin'],true)||(int)($admin['admin_id']??0)<=0)throw new OpenException('permission_denied','需要开放平台后台权限',403);
        $action=self::normalize($action);$terminal=$scene==='platform_admin'?'platform':'tenant';
        $permissions=OpenStore::json((string)file_get_contents(base_path().'apps/open_platform/permissions/'.$terminal.'.json'));
        $uri='app.open_platform.management/'.$action;
        if(!array_filter($permissions,static fn($permission)=>self::normalize($permission['api_path']??'')===self::normalize($uri)))throw new OpenException('permission_denied','该操作未注册后台权限',403);
        if((int)($admin['root']??0)===1)return;
        $cache=$scene==='platform_admin'?new \app\common\cache\AdminAuthCache($admin['admin_id']):new \app\common\cache\TenantAdminAuthCache($admin['admin_id']);
        if(!self::granted($action,(array)$cache->getAdminUri()))throw new OpenException('permission_denied','没有该开放平台操作权限',403);
    }
    private static function normalize(string $uri): string {return strtolower(\think\helper\Str::camel($uri));}
    private static function granted(string $action,array $uris): bool
    {
        return in_array(self::normalize('app.open_platform.management/'.$action),array_map(self::normalize(...),$uris),true);
    }
}
