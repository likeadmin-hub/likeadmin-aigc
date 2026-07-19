<?php

namespace app\tenantapi\lists\brand;

use app\common\lists\ListsSearchInterface;
use app\common\model\brand\TenantBrandOrder;
use app\common\service\brand\TenantBrandService;
use app\tenantapi\lists\BaseAdminDataLists;

class TenantBrandOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '=' => ['order_sn', 'package_id', 'pay_status', 'open_status'],
        ];
    }

    public function queryWhere(): array
    {
        $where = [['tenant_id', '=', (int)$this->adminInfo['tenant_id']]];
        if (!empty($this->params['child_info'])) {
            $where[] = ['child_tenant_name|child_domain_alias', 'like', '%' . $this->params['child_info'] . '%'];
        }
        if (!empty($this->params['start_time']) && !empty($this->params['end_time'])) {
            $where[] = ['create_time', 'between', [strtotime($this->params['start_time']), strtotime($this->params['end_time'])]];
        }
        return $where;
    }

    public function lists(): array
    {
        $rows = TenantBrandOrder::where($this->searchWhere)
            ->where($this->queryWhere())
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        return array_map([TenantBrandService::class, 'formatBrandOrder'], $rows);
    }

    public function count(): int
    {
        return TenantBrandOrder::where($this->searchWhere)->where($this->queryWhere())->count();
    }
}

