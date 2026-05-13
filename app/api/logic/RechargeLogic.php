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

namespace app\api\logic;

use app\common\enum\PayEnum;
use app\common\logic\BaseLogic;
use app\common\model\recharge\RechargePackage;
use app\common\model\recharge\RechargeOrder;
use app\common\model\user\User;
use app\common\service\ConfigService;
use app\common\service\recharge\RechargePackageService;


/**
 * 充值逻辑层
 * Class RechargeLogic
 * @package app\shopapi\logic
 */
class RechargeLogic extends BaseLogic
{

    /**
     * @notes 充值
     * @param array $params
     * @return array|false
     * @author 段誉
     * @date 2023/2/24 10:43
     */
    public static function recharge(array $params)
    {
        try {
            $package = [];
            $money = (float)($params['money'] ?? 0);
            $points = $money;
            if (!empty($params['package_id'])) {
                $package = RechargePackage::where([
                    'tenant_id' => (int)$params['tenant_id'],
                    'id' => (int)$params['package_id'],
                    'status' => RechargePackageService::STATUS_ENABLED,
                ])->findOrEmpty()->toArray();
                if (empty($package)) {
                    throw new \Exception('算力套餐不存在或已下架');
                }
                $money = (float)$package['amount'];
                $points = (float)$package['points'];
            }
            if ($money <= 0 && empty($package)) {
                throw new \Exception('请选择充值套餐或填写充值点数');
            }

            $data = [
                'sn' => generate_sn(RechargeOrder::class, 'sn'),
                'order_terminal' => $params['terminal'],
                'user_id' => $params['user_id'],
                'tenant_id' => $params['tenant_id'],
                'pay_status' => PayEnum::UNPAID,
                'order_amount' => RechargePackageService::formatAmount($money),
                'recharge_points' => RechargePackageService::formatAmount($points),
                'package_id' => (int)($package['id'] ?? 0),
                'package_name' => (string)($package['name'] ?? ''),
            ];
            $order = RechargeOrder::create($data);

            return [
                'order_id' => (int)$order['id'],
                'from' => 'recharge'
            ];
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 充值配置
     * @param $userId
     * @return array
     * @author 段誉
     * @date 2023/2/24 16:56
     */
    public static function config($userId)
    {
        $userMoney = User::where(['id' => $userId])->value('user_money');
        $minAmount = ConfigService::get('recharge', 'min_amount', 0);
        $status = ConfigService::get('recharge', 'status', 0);
        $packages = RechargePackageService::enabledPackages((int)request()->tenantId, (float)$minAmount);

        return [
            'status' => $status,
            'min_amount' => $minAmount,
            'user_money' => $userMoney,
            'packages' => $packages,
        ];
    }




}
