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

namespace app\common\model\auth;

use app\common\model\BaseModel;
use app\common\model\auth\Admin;
use think\facade\Db;

class AdminSession extends BaseModel
{
    public const TABLE = 'platform_admin_session';
    public const LEGACY_TABLE = 'admin_session';

    public static function findActive(string $token): ?array
    {
        foreach ([self::TABLE, self::LEGACY_TABLE] as $table) {
            $session = Db::name($table)->where('token', $token)->where('expire_time', '>', time())->find();
            if ($session) {
                return $session + ['table' => $table];
            }
        }
        return null;
    }

    /**
     * @notes 关联管理员表
     * @return \think\model\relation\HasOne
     * @author 令狐冲
     * @date 2021/7/5 14:39
     */
    public function admin()
    {
        return $this->hasOne(Admin::class, 'id', 'admin_id')
            ->field('id,multipoint_login');
    }
}
