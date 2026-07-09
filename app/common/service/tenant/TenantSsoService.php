<?php

namespace app\common\service\tenant;

use app\common\cache\TenantAdminTokenCache;
use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantSsoTicket;
use app\common\service\FileService;
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

        $tenantData = $tenant->toArray();
        $tenantAdmin = Db::name(self::tenantTable('tenant_admin', $tenantData))
            ->where(['tenant_id' => $tenantId, 'root' => 1, 'disable' => 0])
            ->whereRaw('(`delete_time` IS NULL OR `delete_time` = 0)')
            ->order('id asc')
            ->find();
        if (empty($tenantAdmin)) {
            throw new Exception('租户未配置可登录的超级管理员');
        }

        $ticket = create_token($tenantId . $platformAdminId . time());
        $time = time();
        TenantSsoTicket::create([
            'ticket' => $ticket,
            'tenant_id' => $tenantId,
            'tenant_admin_id' => $tenantAdmin['id'],
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

            $tenantData = $tenant->toArray();
            $admin = Db::name(self::tenantTable('tenant_admin', $tenantData))
                ->where(['id' => $row->tenant_admin_id, 'tenant_id' => $tenantId, 'disable' => 0])
                ->whereRaw('(`delete_time` IS NULL OR `delete_time` = 0)')
                ->find();
            if (empty($admin)) {
                throw new Exception('租户管理员不存在或已停用');
            }

            $row->used_time = $time;
            $row->update_time = $time;
            $row->save();

            Db::name(self::tenantTable('tenant_admin', $tenantData))
                ->where('id', $admin['id'])
                ->update([
                    'login_time' => $time,
                    'login_ip' => request()->ip(),
                    'update_time' => $time,
                ]);

            $adminInfo = self::setTenantToken($tenantData, $admin, $terminal);
            $avatar = $admin['avatar'] ?: Config::get('project.default_image.admin_avatar');

            return [
                'name' => $adminInfo['name'],
                'avatar' => FileService::getFileUrl($avatar),
                'role_name' => $adminInfo['role_name'],
                'token' => $adminInfo['token'],
                'redirect' => (string)$row->redirect,
            ];
        });
    }

    private static function setTenantToken(array $tenant, array $admin, int $terminal): array
    {
        $time = time();
        $expireTime = $time + (int)Config::get('project.tenant_token.expire_duration');
        $sessionTable = self::tenantTable('tenant_admin_session', $tenant);
        $session = Db::name($sessionTable)
            ->where(['admin_id' => $admin['id'], 'terminal' => $terminal])
            ->find();

        if ($session) {
            $token = (string)$session['token'];
            if ((int)$session['expire_time'] < $time || (int)$admin['multipoint_login'] === 0) {
                (new TenantAdminTokenCache())->deleteAdminInfo($token);
                $token = create_token($admin['id']);
            }
            Db::name($sessionTable)
                ->where('id', $session['id'])
                ->update([
                    'token' => $token,
                    'expire_time' => $expireTime,
                    'update_time' => $time,
                ]);
        } else {
            $token = create_token($admin['id']);
            Db::name($sessionTable)->insert([
                'admin_id' => $admin['id'],
                'terminal' => $terminal,
                'token' => $token,
                'expire_time' => $expireTime,
                'update_time' => $time,
            ]);
        }

        $roleId = [];
        $roleName = '';
        if ((int)$admin['root'] === 1) {
            $roleName = '系统管理员';
        } else {
            $roleId = Db::name(self::tenantTable('tenant_admin_role', $tenant))
                ->where('admin_id', $admin['id'])
                ->column('role_id');
            $roleLists = Db::name(self::tenantTable('tenant_system_role', $tenant))
                ->column('name', 'id');
            foreach ($roleId as $id) {
                $roleName .= $roleLists[$id] ?? '';
                $roleName .= '/';
            }
            $roleName = trim($roleName, '/');
        }

        $adminInfo = [
            'admin_id' => (int)$admin['id'],
            'tenant_id' => (int)$admin['tenant_id'],
            'root' => (int)$admin['root'],
            'name' => (string)$admin['name'],
            'account' => (string)$admin['account'],
            'role_name' => $roleName,
            'role_id' => $roleId,
            'token' => $token,
            'terminal' => $terminal,
            'expire_time' => $expireTime,
            'login_ip' => request()->ip(),
        ];
        (new TenantAdminTokenCache())->set(
            'token_tenant_' . $token,
            $adminInfo,
            new \DateTime(date('Y-m-d H:i:s', $expireTime))
        );

        return $adminInfo;
    }

    private static function tenantTable(string $baseTable, array $tenant): string
    {
        if ((int)($tenant['tactics'] ?? 0) !== 1) {
            return $baseTable;
        }
        $sn = (string)($tenant['sn'] ?? '');
        if ($sn === '' || !preg_match('/^[A-Za-z0-9_]+$/', $sn)) {
            throw new Exception('租户标识异常');
        }
        return $baseTable . '_' . $sn;
    }

    private static function rootUrl(): string
    {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = Config::get('project.http_host') ?: request()->host();
        return $scheme . '://' . trim($host, '/');
    }
}
