<?php

namespace app\platformapi\lists\tenant;

use app\common\lists\ListsSearchInterface;
use app\common\model\brand\TenantBrandOrder;
use app\common\service\brand\TenantBrandService;
use app\platformapi\lists\BaseAdminDataLists;

class TenantBrandOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '=' => ['o.order_sn', 'o.package_id', 'o.pay_status', 'o.open_status', 'o.tenant_id'],
        ];
    }

    public function queryWhere(): array
    {
        $where = [];
        if (!empty($this->params['tenant_info'])) {
            $where[] = ['t.name|t.sn|t.tel', 'like', '%' . $this->params['tenant_info'] . '%'];
        }
        if (!empty($this->params['child_info'])) {
            $where[] = ['o.child_tenant_name|o.child_domain_alias', 'like', '%' . $this->params['child_info'] . '%'];
        }
        if (!empty($this->params['start_time']) && !empty($this->params['end_time'])) {
            $where[] = ['o.create_time', 'between', [strtotime($this->params['start_time']), strtotime($this->params['end_time'])]];
        }
        return $where;
    }

    public function lists(): array
    {
        $rows = TenantBrandOrder::alias('o')
            ->leftJoin('tenant t', 't.id = o.tenant_id')
            ->field('o.*,t.name as tenant_name,t.sn as tenant_sn')
            ->where($this->searchWhere)
            ->where($this->queryWhere())
            ->order('o.id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        return array_map([TenantBrandService::class, 'formatBrandOrder'], $rows);
    }

    public function count(): int
    {
        return TenantBrandOrder::alias('o')
            ->leftJoin('tenant t', 't.id = o.tenant_id')
            ->where($this->searchWhere)
            ->where($this->queryWhere())
            ->count();
    }
}

