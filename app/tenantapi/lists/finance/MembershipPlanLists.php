<?php

namespace app\tenantapi\lists\finance;

use app\common\lists\ListsSearchInterface;
use app\common\model\app\App;
use app\common\model\membership\MembershipPlan;
use app\common\model\membership\MembershipPlanApp;
use app\tenantapi\lists\BaseAdminDataLists;

class MembershipPlanLists extends BaseAdminDataLists implements ListsSearchInterface
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
        $lists = MembershipPlan::where('tenant_id', (int)$this->adminInfo['tenant_id'])
            ->where($this->searchWhere)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        $apps = MembershipPlanApp::whereIn('plan_id', array_column($lists, 'id') ?: [''])->select()->toArray();
        $appNames = App::whereIn('code', array_column($apps, 'app_code') ?: [''])->column('name', 'code');
        $grouped = [];
        foreach ($apps as $app) {
            $grouped[$app['plan_id']][] = [
                'app_code' => $app['app_code'],
                'name' => $appNames[$app['app_code']] ?? $app['app_code'],
            ];
        }
        foreach ($lists as &$item) {
            $item['apps'] = $grouped[$item['id']] ?? [];
            $item['app_codes'] = array_column($item['apps'], 'app_code');
        }
        return $lists;
    }

    public function count(): int
    {
        return MembershipPlan::where('tenant_id', (int)$this->adminInfo['tenant_id'])
            ->where($this->searchWhere)
            ->count();
    }
}
