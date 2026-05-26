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

namespace app\common\enum\user;

/**
 * 用户账户流水变动表枚举
 * Class AccountLogEnum
 * @package app\common\enum
 */
class AccountLogEnum
{
    /**
     * 变动类型命名规则：对象_动作_简洁描述
     * 动作 DEC-减少 INC-增加
     * 对象 UM-用户点数(user_money)
     */

    /**
     * 变动对象
     * UM 用户点数(user_money)
     */
    const UM = 1;

    /**
     * 动作
     * INC 增加
     * DEC 减少
     */
    const INC = 1;
    const DEC = 2;


    /**
     * 用户点数减少类型
     */
    const UM_DEC_ADMIN = 100;
    const UM_DEC_RECHARGE_REFUND = 101;
    const UM_DEC_APP_CONSUME = 102;

    /**
     * 用户点数增加类型
     */
    const UM_INC_ADMIN = 200;
    const UM_INC_RECHARGE = 201;
    const UM_INC_MEMBERSHIP_BONUS = 202;
    const UM_INC_REGISTER_BONUS = 203;
    const UM_INC_APP_REFUND = 204;


    /**
     * 用户点数（减少类型汇总）
     */
    const UM_DEC = [
        self::UM_DEC_ADMIN,
        self::UM_DEC_RECHARGE_REFUND,
        self::UM_DEC_APP_CONSUME,
    ];


    /**
     * 用户点数（增加类型汇总）
     */
    const UM_INC = [
        self::UM_INC_ADMIN,
        self::UM_INC_RECHARGE,
        self::UM_INC_MEMBERSHIP_BONUS,
        self::UM_INC_REGISTER_BONUS,
        self::UM_INC_APP_REFUND,
    ];


    /**
     * @notes 动作描述
     * @param $action
     * @param false $flag
     * @return string|string[]
     * @author 段誉
     * @date 2023/2/23 10:07
     */
    public static function getActionDesc($action, $flag = false)
    {
        $desc = [
            self::DEC => '减少',
            self::INC => '增加',
        ];
        if ($flag) {
            return $desc;
        }
        return $desc[$action] ?? '';
    }


    /**
     * @notes 变动类型描述
     * @param $changeType
     * @param false $flag
     * @return string|string[]
     * @author 段誉
     * @date 2023/2/23 10:07
     */
    public static function getChangeTypeDesc($changeType, $flag = false)
    {
        $desc = [
            self::UM_DEC_ADMIN => '后台减少点数',
            self::UM_INC_ADMIN => '后台增加点数',
            self::UM_INC_RECHARGE => '充值增加点数',
            self::UM_INC_MEMBERSHIP_BONUS => '会员套餐赠送积分',
            self::UM_INC_REGISTER_BONUS => '新用户注册赠送积分',
            self::UM_INC_APP_REFUND => '应用消费退回点数',
            self::UM_DEC_RECHARGE_REFUND => '充值订单退款减少点数',
            self::UM_DEC_APP_CONSUME => '应用消费扣减点数',
        ];
        if ($flag) {
            return $desc;
        }
        return $desc[$changeType] ?? '';
    }


    /**
     * @notes 获取用户点数类型描述
     * @return string|string[]
     * @author 段誉
     * @date 2023/2/23 10:08
     */
    public static function getUserMoneyChangeTypeDesc()
    {
        $UMChangeType = self::getUserMoneyChangeType();
        $changeTypeDesc = self::getChangeTypeDesc('', true);
        return array_filter($changeTypeDesc, function ($key) use ($UMChangeType) {
            return in_array($key, $UMChangeType);
        }, ARRAY_FILTER_USE_KEY);
    }


    /**
     * @notes 获取用户点数变动类型
     * @return int[]
     * @author 段誉
     * @date 2023/2/23 10:08
     */
    public static function getUserMoneyChangeType() : array
    {
        return array_merge(self::UM_DEC, self::UM_INC);
    }


    /**
     * @notes 获取变动对象
     * @param $changeType
     * @return false
     * @author 段誉
     * @date 2023/2/23 10:10
     */
    public static function getChangeObject($changeType)
    {
        // 用户点数
        $um = self::getUserMoneyChangeType();
        if (in_array($changeType, $um)) {
            return self::UM;
        }

        // 其他...

        return false;
    }
}
