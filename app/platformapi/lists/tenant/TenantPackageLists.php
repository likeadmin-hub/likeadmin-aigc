<?php

namespace app\platformapi\lists\tenant;

use app\common\lists\ListsSearchInterface;
use app\common\model\tenant\TenantPackage;
use app\common\service\tenant\TenantPackageService;
use app\platformapi\lists\BaseAdminDataLists;

class TenantPackageLists extends BaseAdminDataLists implements ListsSearchInterface
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
        $rows = TenantPackage::where($this->searchWhere)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        return array_map([TenantPackageService::class, 'formatPackage'], TenantPackageService::attachAppPlans($rows));
    }

    public function count(): int
    {
        return TenantPackage::where($this->searchWhere)->count();
    }
}

