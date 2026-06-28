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

namespace app\common\logic;

use app\common\logic\BaseLogic;
use app\common\service\membership\MembershipService;
use app\common\service\power\TenantPowerMallService;
use app\common\service\recharge\RechargeCreditService;
use think\facade\Db;
use think\facade\Log;

/**
 * 支付成功后处理订单状态
 * Class PayNotifyLogic
 * @package app\api\logic
 */
class PayNotifyLogic extends BaseLogic
{

    public static function handle($action, $orderSn, $extra = [])
    {
        Db::startTrans();
        try {
            self::$action($orderSn, $extra);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::write(implode('-', [
                __CLASS__,
                __FUNCTION__,
                $e->getFile(),
                $e->getLine(),
                $e->getMessage()
            ]));
            self::setError($e->getMessage());
            return $e->getMessage();
        }
    }


    /**
     * @notes 充值回调
     * @param $orderSn
     * @param array $extra
     * @author 段誉
     * @date 2023/2/27 15:28
     */
    public static function recharge($orderSn, array $extra = [])
    {
        RechargeCreditService::complete($orderSn, $extra);
    }

    /**
     * @notes 会员订单回调
     */
    public static function membership($orderSn, array $extra = [])
    {
        MembershipService::handlePaid($orderSn, $extra);
    }

    public static function tenant_power($orderSn, array $extra = [])
    {
        TenantPowerMallService::handlePaid($orderSn, $extra);
    }


}
