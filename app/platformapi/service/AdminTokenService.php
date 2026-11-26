<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------


namespace app\platformapi\service;

use app\common\cache\AdminTokenCache;
use app\common\model\auth\Admin;
use app\common\model\auth\AdminSession;
use think\facade\Config;
use think\facade\Db;

class AdminTokenService
{
    public static function setToken(int $adminId, int $terminal): array
    {
        $token = Db::transaction(static function () use ($adminId, $terminal): string {
            $admin = Admin::where('id', $adminId)->lock(true)->findOrEmpty();
            if ($admin->isEmpty() || (int)$admin->disable === 1) {
                throw new \RuntimeException('管理员账号已停用');
            }

            $time = time();
            $loginIp = request()->ip();
            Db::name(AdminSession::TABLE)->where('admin_id', $adminId)->where('expire_time', '<=', $time)->delete();
            if ((int)$admin->multipoint_login === 0) {
                self::expireAdminSessions($adminId, $terminal);
            }

            $token = bin2hex(random_bytes(16));
            Db::name(AdminSession::TABLE)->insert([
                'admin_id' => $adminId,
                'terminal' => $terminal,
                'token' => $token,
                'login_ip' => $loginIp,
                'expire_time' => $time + Config::get('project.admin_token.expire_duration'),
                'update_time' => $time,
            ]);
            $admin->save(['login_time' => $time, 'login_ip' => $loginIp]);
            return $token;
        });

        $adminInfo = (new AdminTokenCache())->getAdminInfo($token);
        if (!$adminInfo) {
            throw new \RuntimeException('登录会话已失效，请重新登录');
        }
        return $adminInfo;
    }

    public static function overtimeToken(string $token): array|false
    {
        $session = AdminSession::findActive($token);
        $cache = new AdminTokenCache();
        $adminInfo = $session ? $cache->getAdminInfo($token) : false;
        if (!$adminInfo || $adminInfo['login_ip'] !== request()->ip()) {
            return false;
        }

        $time = time();
        Db::name($session['table'])->where('token', $token)->where('expire_time', '>', $time)->update([
            'expire_time' => $time + Config::get('project.admin_token.expire_duration'),
            'update_time' => $time,
        ]);
        return $cache->getAdminInfo($token);
    }

    public static function expireToken(string $token): bool
    {
        $session = AdminSession::findActive($token);
        if (!$session) {
            return false;
        }

        // A legacy token may still be shared. Preserve its existing logout behavior.
        if ($session['table'] === AdminSession::LEGACY_TABLE
            && (int)Db::name('admin')->where('id', $session['admin_id'])->value('multipoint_login') === 1) {
            return false;
        }

        $time = time();
        Db::name($session['table'])->where('token', $token)->update(['expire_time' => $time, 'update_time' => $time]);
        (new AdminTokenCache())->deleteAdminInfo($token);
        return true;
    }

    public static function expireAdminSessions(int $adminId, ?int $terminal = null): void
    {
        $time = time();
        $cache = new AdminTokenCache();
        foreach ([AdminSession::TABLE, AdminSession::LEGACY_TABLE] as $table) {
            $sessions = Db::name($table)->where('admin_id', $adminId)->where('expire_time', '>', $time);
            if ($terminal !== null) {
                $sessions->where('terminal', $terminal);
            }
            $tokens = (clone $sessions)->column('token');
            $sessions->update(['expire_time' => $time, 'update_time' => $time]);
            foreach ($tokens as $token) {
                $cache->deleteAdminInfo($token);
            }
        }
    }
}
