<?php

namespace app\tenantapi\lists\finance;

use app\common\lists\ListsSearchInterface;
use app\common\model\recharge\RechargePackage;
use app\tenantapi\lists\BaseAdminDataLists;

class RechargePackageLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['name'],
            '=' => ['status'],
        ];
    }

    public function lists(): array
    {
        return RechargePackage::where('tenant_id', (int)$this->adminInfo['tenant_id'])
            ->where($this->searchWhere)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
    }

    public function count(): int
    {
        return RechargePackage::where('tenant_id', (int)$this->adminInfo['tenant_id'])
            ->where($this->searchWhere)
            ->count();
    }
}
