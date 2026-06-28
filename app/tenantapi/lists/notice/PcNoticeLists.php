<?php

namespace app\tenantapi\lists\notice;

use app\common\lists\ListsSearchInterface;
use app\common\model\notice\TenantPcNotice;
use app\tenantapi\lists\BaseAdminDataLists;

class PcNoticeLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['title'],
            '=' => ['status'],
        ];
    }

    public function lists(): array
    {
        return TenantPcNotice::where('tenant_id', (int)$this->adminInfo['tenant_id'])
            ->where($this->searchWhere)
            ->field('id,title,summary,image,is_popup,status,sort,publish_time,create_time,update_time')
            ->order(['sort' => 'desc', 'publish_time' => 'desc', 'id' => 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
    }

    public function count(): int
    {
        return TenantPcNotice::where('tenant_id', (int)$this->adminInfo['tenant_id'])
            ->where($this->searchWhere)
            ->count();
    }
}
