<?php

namespace app\tenantapi\lists\brand;

use app\common\lists\ListsSearchInterface;
use app\common\model\brand\TenantBrandQuotaOrder;
use app\common\service\brand\TenantBrandService;
use app\tenantapi\lists\BaseAdminDataLists;

class TenantBrandQuotaOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '=' => ['order_sn', 'package_id', 'pay_status'],
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
        $rows = TenantBrandQuotaOrder::where($this->searchWhere)
            ->where($this->queryWhere())
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        return array_map([TenantBrandService::class, 'formatQuotaOrder'], $rows);
    }

    public function count(): int
    {
        return TenantBrandQuotaOrder::where($this->searchWhere)->where($this->queryWhere())->count();
    }
}

