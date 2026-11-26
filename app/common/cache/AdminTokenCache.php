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


namespace app\common\cache;

use app\common\model\auth\Admin;
use app\common\model\auth\AdminSession;
use app\common\model\auth\SystemRole;

/**
 * 管理员token缓存
 * Class AdminTokenCache
 * @package app\common\cache
 */
class AdminTokenCache extends BaseCache
{

    private $prefix = 'token_admin_';

    /**
     * @notes 通过token获取缓存管理员信息
     * @param $token
     * @return false|mixed
     * @author 令狐冲
     * @date 2021/6/30 16:57
     */
    public function getAdminInfo($token)
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $session = AdminSession::findActive($token);
        if (!$session) {
            return false;
        }
        $key = $this->prefix . $token;
        $adminInfo = $this->get($key);
        if ($adminInfo) {
            $adminInfo['expire_time'] = (int)$session['expire_time'];
            $adminInfo['login_ip'] = $session['login_ip'] ?? $adminInfo['login_ip'];
            return $adminInfo;
        }

        $admin = Admin::where('id', $session['admin_id'])
            ->append(['role_id'])
            ->find();
        if (!$admin || (int)$admin['disable'] === 1) {
            return false;
        }

        $roleName = '';
        $roleLists = SystemRole::column('name', 'id');
        if ($admin['root'] == 1) {
            $roleName = '系统管理员';
        } else {
            foreach ($admin['role_id'] as $roleId) {
                $roleName .= $roleLists[$roleId] ?? '';
                $roleName .= '/';
            }
            $roleName = trim($roleName, '/');
        }

        $adminInfo = [
            'admin_id' => $admin->id,
            'root' => $admin->root,
            'name' => $admin->name,
            'account' => $admin->account,
            'role_name' => $roleName,
            'role_id' => $admin->role_id,
            'token' => $token,
            'terminal' => $session['terminal'],
            'expire_time' => (int)$session['expire_time'],
            'login_ip' => $session['login_ip'] ?? (string)$admin['login_ip'],
        ];
        $this->set($key, $adminInfo, max(1, $adminInfo['expire_time'] - time()));
        return $adminInfo;
    }

    /**
     * @notes 删除缓存
     * @param $token
     * @return bool
     * @author 令狐冲
     * @date 2021/7/3 16:57
     */
    public function deleteAdminInfo($token)
    {
        return $this->delete($this->prefix . $token);
    }


}
