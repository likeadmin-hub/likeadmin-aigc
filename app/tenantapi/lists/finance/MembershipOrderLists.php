<?php

namespace app\tenantapi\lists\finance;

use app\common\enum\PayEnum;
use app\common\lists\ListsSearchInterface;
use app\common\model\membership\MembershipOrder;
use app\common\service\FileService;
use app\common\service\membership\MembershipService;
use app\tenantapi\lists\BaseAdminDataLists;

class MembershipOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '=' => ['mo.order_sn', 'mo.pay_status', 'mo.plan_id', 'mo.cycle'],
        ];
    }

    public function queryWhere(): array
    {
        $where = [['mo.tenant_id', '=', (int)$this->adminInfo['tenant_id']]];
        if (!empty($this->params['user_info'])) {
            $where[] = ['u.sn|u.nickname|u.mobile|u.account', 'like', '%' . $this->params['user_info'] . '%'];
        }
        if (!empty($this->params['start_time']) && !empty($this->params['end_time'])) {
            $where[] = ['mo.create_time', 'between', [strtotime($this->params['start_time']), strtotime($this->params['end_time'])]];
        }
        return $where;
    }

    public function lists(): array
    {
        $lists = MembershipOrder::alias('mo')
            ->join('user u', 'u.id = mo.user_id')
            ->field('mo.*,u.nickname,u.account,u.avatar')
            ->where($this->queryWhere())
            ->where($this->searchWhere)
            ->order('mo.id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->append(['pay_status_text', 'pay_way_text'])
            ->select()
            ->toArray();

        foreach ($lists as &$item) {
            $item = MembershipService::formatOrder($item);
            $item['avatar'] = FileService::getFileUrl($item['avatar'] ?? '');
            $item['pay_status_text'] = PayEnum::getPayStatusDesc($item['pay_status']);
            $item['pay_way_text'] = PayEnum::getPayDesc($item['pay_way']);
        }
        return $lists;
    }

    public function count(): int
    {
        return MembershipOrder::alias('mo')
            ->join('user u', 'u.id = mo.user_id')
            ->where($this->queryWhere())
            ->where($this->searchWhere)
            ->count();
    }
}
