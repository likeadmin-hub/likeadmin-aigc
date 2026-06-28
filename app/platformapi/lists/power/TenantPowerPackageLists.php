<?php

namespace app\platformapi\lists\power;

use app\common\lists\ListsSearchInterface;
use app\common\model\power\TenantPowerPackage;
use app\common\service\power\TenantPowerMallService;
use app\platformapi\lists\BaseAdminDataLists;

class TenantPowerPackageLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['name'],
            '=' => ['type', 'status'],
        ];
    }

    public function lists(): array
    {
        $lists = TenantPowerPackage::where($this->searchWhere)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        return array_map([TenantPowerMallService::class, 'formatPackage'], $lists);
    }

    public function count(): int
    {
        return TenantPowerPackage::where($this->searchWhere)->count();
    }
}
