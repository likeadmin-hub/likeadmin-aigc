<?php

namespace app\tenantapi\controller;

use app\common\service\ai\AiUsageService;

class AiConsumptionController extends BaseAdminController
{
    public function lists()
    {
        $data = AiUsageService::consumptionLists($this->request->get(), $this->tenantId);
        return $this->data([
            'lists' => $data['rows'],
            'count' => $data['count'],
            'page_no' => $data['pageNo'],
            'page_size' => $data['pageSize'],
            'extend' => [],
        ]);
    }

    public function detail()
    {
        $detail = AiUsageService::consumptionDetail((int)$this->request->get('id', 0), $this->tenantId);
        return $detail ? $this->success('获取成功', $detail) : $this->fail('消耗日志不存在');
    }
}
