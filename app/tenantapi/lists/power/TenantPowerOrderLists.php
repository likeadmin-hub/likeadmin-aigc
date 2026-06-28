<?php

namespace app\tenantapi\lists\power;

use app\common\lists\ListsSearchInterface;
use app\common\model\power\TenantPowerOrder;
use app\common\service\power\TenantPowerMallService;
use app\tenantapi\lists\BaseAdminDataLists;

class TenantPowerOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '=' => ['order_sn', 'package_type', 'pay_status'],
        ];
    }

    public function queryWhere(): array
    {
        $where = [['tenant_id', '=', (int)$this->adminInfo['tenant_id']]];
        if (!empty($this->params['start_time']) && !empty($this->params['end_time'])) {
            $where[] = ['create_time', 'between', [strtotime($this->params['start_time']), strtotime($this->params['end_time'])]];
        }
        return $where;
    }

    public function lists(): array
    {
        $lists = TenantPowerOrder::where($this->searchWhere)
            ->where($this->queryWhere())
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        return array_map([TenantPowerMallService::class, 'formatOrder'], $lists);
    }

    public function count(): int
    {
        return TenantPowerOrder::where($this->searchWhere)
            ->where($this->queryWhere())
            ->count();
    }
}
