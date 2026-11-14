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

namespace app\api\lists\recharge;

use app\api\lists\BaseApiDataLists;
use app\common\enum\PayEnum;
use app\common\model\recharge\RechargeOrder;
use app\common\service\PointUnitService;
use app\common\service\recharge\RechargePackageService;


/**
 * 充值记录列表
 * Class RechargeLists
 * @package app\api\lists\recharge
 */
class RechargeLists extends BaseApiDataLists
{
    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2023/2/23 18:43
     */
    public function lists(): array
    {
        $lists = $this->paidOrders()
            ->field('id,sn,order_amount,recharge_points,package_id,package_name,pay_time,create_time')
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        foreach($lists as &$item) {
            $points = (float)($item['recharge_points'] ?? 0) > 0 ? $item['recharge_points'] : $item['order_amount'];
            $item['credited_points'] = RechargePackageService::formatAmount($points);
            $item['tips'] = '充值' . format_amount($points) . PointUnitService::unit();
        }

        return $lists;
    }


    /**
     * @notes  获取数量
     * @return int
     * @author 段誉
     * @date 2023/2/23 18:43
     */
    public function count(): int
    {
        return $this->paidOrders()->count();
    }

    private function paidOrders()
    {
        $tenantId = (int)($this->request->tenantId ?? 0);
        $query = RechargeOrder::where(['tenant_id'=>$tenantId, 'user_id'=>$this->userId, 'pay_status'=>PayEnum::ISPAID]);
        if ($tenantId <= 0 || $this->userId <= 0) $query->whereRaw('1=0');
        return $query;
    }

}
