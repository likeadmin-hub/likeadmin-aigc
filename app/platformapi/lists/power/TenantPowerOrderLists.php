<?php

namespace app\platformapi\lists\power;

use app\common\lists\ListsSearchInterface;
use app\common\model\power\TenantPowerOrder;
use app\common\service\power\TenantPowerMallService;
use app\platformapi\lists\BaseAdminDataLists;

class TenantPowerOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '=' => ['o.order_sn', 'o.package_type', 'o.pay_status', 'o.tenant_id'],
        ];
    }

    public function queryWhere(): array
    {
        $where = [];
        if (!empty($this->params['tenant_info'])) {
            $where[] = ['t.name|t.sn|t.tel', 'like', '%' . $this->params['tenant_info'] . '%'];
        }
        if (!empty($this->params['start_time']) && !empty($this->params['end_time'])) {
            $where[] = ['o.create_time', 'between', [strtotime($this->params['start_time']), strtotime($this->params['end_time'])]];
        }
        return $where;
    }

    public function lists(): array
    {
        $lists = TenantPowerOrder::alias('o')
            ->leftJoin('tenant t', 't.id = o.tenant_id')
            ->field('o.*,t.name as tenant_name,t.sn as tenant_sn,t.tel as tenant_tel')
            ->where($this->searchWhere)
            ->where($this->queryWhere())
            ->order('o.id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        return array_map([TenantPowerMallService::class, 'formatOrder'], $lists);
    }

    public function count(): int
    {
        return TenantPowerOrder::alias('o')
            ->leftJoin('tenant t', 't.id = o.tenant_id')
            ->where($this->searchWhere)
            ->where($this->queryWhere())
            ->count();
    }
}
