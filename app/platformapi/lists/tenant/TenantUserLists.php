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
namespace app\platformapi\lists\tenant;

use app\common\enum\user\AccountLogEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\lists\ListsExcelInterface;
use app\common\model\user\User;
use app\common\model\user\UserAccountLog;
use app\platformapi\lists\BaseAdminDataLists;

/**
 * 租户用户列表
 * Class TenantUserLists
 * @package app\platformapi\lists\tenant
 */
class TenantUserLists extends BaseAdminDataLists implements ListsExcelInterface
{

    /**
     * @notes 搜索条件
     * @return array
     * @author 段誉
     * @date 2022/9/22 15:50
     */
    public function setSearch(): array
    {
        $allowSearch = ['keyword', 'channel', 'create_time_start', 'create_time_end', 'tenant_id'];
        return array_intersect(array_keys($this->params), $allowSearch);
    }


    /**
     * @notes 获取用户列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/22 15:50
     */
    public function lists(): array
    {
        $field = 'id,sn,nickname,sex,avatar,account,mobile,channel,create_time,is_disable,user_money,total_recharge_amount';
        $lists = User::withSearch($this->setSearch(), $this->params)
            ->limit($this->limitOffset, $this->limitLength)
            ->field($field)
            ->order('id desc')
            ->select()
            ->toArray();

        $usedAmountMap = $this->getUsedAmountMap(array_column($lists, 'id'));
        foreach ($lists as &$item) {
            $item['channel'] = UserTerminalEnum::getTermInalDesc($item['channel']);
            $item['user_money'] = $this->formatPoints($item['user_money'] ?? 0);
            $item['total_recharge_amount'] = $this->formatPoints($item['total_recharge_amount'] ?? 0);
            $item['total_used_amount'] = $this->formatPoints($usedAmountMap[$item['id']] ?? 0);
        }

        return $lists;
    }


    /**
     * @notes 获取数量
     * @return int
     * @author 段誉
     * @date 2022/9/22 15:51
     */
    public function count(): int
    {
        return User::withSearch($this->setSearch(), $this->params)->count();
    }


    /**
     * @notes 导出文件名
     * @return string
     * @author 段誉
     * @date 2022/11/24 16:17
     */
    public function setFileName(): string
    {
        return '用户列表';
    }


    /**
     * @notes 导出字段
     * @return string[]
     * @author 段誉
     * @date 2022/11/24 16:17
     */
    public function setExcelFields(): array
    {
        return [
            'sn' => '用户编号',
            'nickname' => '用户昵称',
            'account' => '账号',
            'mobile' => '手机号码',
            'user_money' => '剩余点数',
            'total_used_amount' => '已使用点数',
            'total_recharge_amount' => '累计充值点数',
            'channel' => '注册来源',
            'create_time' => '注册时间',
        ];
    }


    /**
     * @notes 获取用户累计消费点数
     * @param array $userIds
     * @return array
     */
    private function getUsedAmountMap(array $userIds): array
    {
        $tenantId = (int)($this->params['tenant_id'] ?? 0);
        $userIds = array_filter(array_map('intval', $userIds));
        if (empty($userIds) || $tenantId <= 0) {
            return [];
        }

        $rows = UserAccountLog::whereIn('user_id', $userIds)
            ->where('tenant_id', $tenantId)
            ->where('change_type', AccountLogEnum::UM_DEC_APP_CONSUME)
            ->where('action', AccountLogEnum::DEC)
            ->field('user_id,SUM(change_amount) as total_used_amount')
            ->group('user_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['user_id']] = (float)$row['total_used_amount'];
        }
        return $map;
    }


    /**
     * @notes 格式化点数
     * @param mixed $value
     * @return string
     */
    private function formatPoints(mixed $value): string
    {
        $number = round((float)$value, 2);
        return number_format($number, floor($number) == $number ? 0 : 2, '.', '');
    }

}
