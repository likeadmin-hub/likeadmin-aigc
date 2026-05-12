<?php

namespace app\common\service\tenant;

use app\common\model\auth\TenantAdmin;
use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantSsoTicket;
use app\common\service\FileService;
use app\tenantapi\service\TenantTokenService;
use Exception;
use think\facade\Config;
use think\facade\Db;

class TenantSsoService
{
    private const TTL = 60;

    public static function createTicket(int $tenantId, int $platformAdminId, string $target = 'admin', string $redirect = ''): array
    {
        if ($target !== 'admin') {
            throw new Exception('暂只支持进入租户后台');
        }

        $tenant = Tenant::withoutGlobalScope()->where('id', $tenantId)->findOrEmpty();
        if ($tenant->isEmpty()) {
            throw new Exception('租户不存在');
        }
        if ((int)$tenant->disable !== 0) {
            throw new Exception('租户已停用');
        }

        $tenantAdmin = TenantAdmin::withoutGlobalScope()
            ->where(['tenant_id' => $tenantId, 'root' => 1, 'disable' => 0])
            ->order('id asc')
            ->findOrEmpty();
        if ($tenantAdmin->isEmpty()) {
            throw new Exception('租户未配置可登录的超级管理员');
        }

        $ticket = create_token($tenantId . $platformAdminId . time());
        $time = time();
        TenantSsoTicket::create([
            'ticket' => $ticket,
            'tenant_id' => $tenantId,
            'tenant_admin_id' => $tenantAdmin->id,
            'platform_admin_id' => $platformAdminId,
            'target' => $target,
            'redirect' => $redirect,
            'ip' => request()->ip(),
            'user_agent' => substr((string)request()->header('user-agent'), 0, 500),
            'expire_time' => $time + self::TTL,
            'create_time' => $time,
            'update_time' => $time,
        ]);

        $baseUrl = self::rootUrl();
        $query = http_build_query(array_filter([
            'tenant_id' => $tenantId,
            'sso_ticket' => $ticket,
            'redirect' => $redirect,
        ], fn($value) => $value !== ''));

        return [
            'ticket' => $ticket,
            'expire_time' => $time + self::TTL,
            'url' => $baseUrl . '/admin/?' . $query,
            'path_url' => $baseUrl . '/t/' . $tenantId . '/admin/?' . http_build_query(array_filter([
                'sso_ticket' => $ticket,
                'redirect' => $redirect,
            ], fn($value) => $value !== '')),
        ];
    }

    public static function consume(string $ticket, int $tenantId, int $terminal): array
    {
        $time = time();
        return Db::transaction(function () use ($ticket, $tenantId, $terminal, $time) {
            $row = TenantSsoTicket::where('ticket', $ticket)->lock(true)->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('免登录票据不存在');
            }
            if ((int)$row->tenant_id !== $tenantId) {
                throw new Exception('免登录票据与当前租户不匹配');
            }
            if ((int)$row->used_time > 0) {
                throw new Exception('免登录票据已使用');
            }
            if ((int)$row->expire_time < $time) {
                throw new Exception('免登录票据已过期');
            }

            $tenant = Tenant::withoutGlobalScope()->where('id', $tenantId)->findOrEmpty();
            if ($tenant->isEmpty() || (int)$tenant->disable !== 0) {
                throw new Exception('租户不存在或已停用');
            }

            $admin = TenantAdmin::withoutGlobalScope()
                ->where(['id' => $row->tenant_admin_id, 'tenant_id' => $tenantId, 'disable' => 0])
                ->findOrEmpty();
            if ($admin->isEmpty()) {
                throw new Exception('租户管理员不存在或已停用');
            }

            $row->used_time = $time;
            $row->update_time = $time;
            $row->save();

            $admin->login_time = $time;
            $admin->login_ip = request()->ip();
            $admin->save();

            $adminInfo = TenantTokenService::setToken($admin->id, $terminal, $admin->multipoint_login);
            $avatar = $admin->avatar ?: Config::get('project.default_image.admin_avatar');

            return [
                'name' => $adminInfo['name'],
                'avatar' => FileService::getFileUrl($avatar),
                'role_name' => $adminInfo['role_name'],
                'token' => $adminInfo['token'],
                'redirect' => (string)$row->redirect,
            ];
        });
    }

    private static function rootUrl(): string
    {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = Config::get('project.http_host') ?: request()->host();
        return $scheme . '://' . trim($host, '/');
    }
}
