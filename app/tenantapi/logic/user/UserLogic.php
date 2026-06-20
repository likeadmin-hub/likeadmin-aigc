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
namespace app\tenantapi\logic\user;

use app\common\enum\user\AccountLogEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\logic\AccountLogLogic;
use app\common\logic\BaseLogic;
use app\common\model\user\User;
use app\common\model\user\UserAccountLog;
use app\common\service\JsonService;
use think\facade\Db;

/**
 * 用户逻辑层
 * Class TenantLogic
 * @package app\tenantapi\logic\user
 */
class UserLogic extends BaseLogic
{

    /**
     * @notes 用户详情
     * @param int $userId
     * @param int|null $tenantId
     * @return array
     * @author 段誉
     * @date 2022/9/22 16:32
     */
    public static function detail(int $userId, ?int $tenantId = null): array
    {
        $field = [
            'id', 'sn', 'account', 'nickname', 'avatar', 'real_name',
            'sex', 'mobile', 'create_time', 'login_time', 'channel',
            'user_money', 'total_recharge_amount', 'is_disable'
        ];

        $query = User::where(['id' => $userId]);
        if (!empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        }

        $user = $query->field($field)
            ->findOrEmpty();
        if ($user->isEmpty()) {
            JsonService::throw('用户不存在！');
        }

        $user['channel'] = UserTerminalEnum::getTermInalDesc($user['channel']);
        $user->sexCode = $user->getData('sex');
        $detail = $user->toArray();
        $detail['user_money'] = self::formatPoints($detail['user_money'] ?? 0);
        $detail['total_recharge_amount'] = self::formatPoints($detail['total_recharge_amount'] ?? 0);
        $detail['total_used_amount'] = self::formatPoints(self::getUsedAmount($userId, $tenantId));
        return $detail;
    }


    /**
     * @notes 更新用户信息
     * @param array $params
     * @return User
     * @author 段誉
     * @date 2022/9/22 16:38
     */
    public static function setUserInfo(array $params)
    {
        return User::update([
            $params['field'] => $params['value']
        ], ['id' => $params['id']]);
    }


    /**
     * @notes 调整用户点数
     * @param array $params
     * @return bool|string
     * @author 段誉
     * @date 2023/2/23 14:25
     */
    public static function adjustUserMoney(array $params)
    {
        Db::startTrans();
        try {
            $user = User::find($params['user_id']);
            if (AccountLogEnum::INC == $params['action']) {
                //调整可用点数
                $user->user_money += $params['num'];
                $user->save();
                //记录日志
                AccountLogLogic::add(
                    $user->id,
                    AccountLogEnum::UM_INC_ADMIN,
                    AccountLogEnum::INC,
                    $params['num'],
                    '',
                    $params['remark'] ?? ''
                );
            } else {
                $user->user_money -= $params['num'];
                $user->save();
                //记录日志
                AccountLogLogic::add(
                    $user->id,
                    AccountLogEnum::UM_DEC_ADMIN,
                    AccountLogEnum::DEC,
                    $params['num'],
                    '',
                    $params['remark'] ?? ''
                );
            }

            Db::commit();
            return true;

        } catch (\Exception $e) {
            Db::rollback();
            return $e->getMessage();
        }
    }


    /**
     * @notes 获取用户累计消费点数
     * @param int $userId
     * @param int|null $tenantId
     * @return float
     */
    private static function getUsedAmount(int $userId, ?int $tenantId = null): float
    {
        $query = UserAccountLog::where('user_id', $userId);
        if (!empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        }

        return (float)$query
            ->where('change_type', AccountLogEnum::UM_DEC_APP_CONSUME)
            ->where('action', AccountLogEnum::DEC)
            ->sum('change_amount');
    }


    /**
     * @notes 格式化点数
     * @param mixed $value
     * @return string
     */
    private static function formatPoints(mixed $value): string
    {
        $number = round((float)$value, 2);
        return number_format($number, floor($number) == $number ? 0 : 2, '.', '');
    }

}
