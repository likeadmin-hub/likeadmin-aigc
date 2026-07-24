<?php

namespace app\platformapi\controller;

use app\common\service\ai\AiUsageService;
use app\common\service\ai\AiTaskOperationService;

class AiConsumptionController extends BaseAdminController
{
    public function lists()
    {
        $data = AiUsageService::consumptionLists($this->request->get());
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
        $detail = AiUsageService::consumptionDetail((int)$this->request->get('id', 0));
        if (!$detail) return $this->fail('消耗日志不存在');
        $detail['operation_tracks'] = AiTaskOperationService::operationListsForTask((int)($detail['app_task_id'] ?? 0), (int)$detail['id']);
        return $this->success('获取成功', $detail);
    }

    public function batchAction()
    {
        try {
            $data = AiTaskOperationService::submit((array)$this->request->post('record_keys/a', []), (string)$this->request->post('action', ''), (string)$this->request->post('reason', ''), 0, $this->adminId, 'platform');
            return $this->success('批量操作已进入队列', $data);
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function batchDetail()
    {
        $data = AiTaskOperationService::detail((int)$this->request->get('id', 0));
        return $data ? $this->success('获取成功', $data) : $this->fail('操作单不存在');
    }

    public function operationLists()
    {
        return $this->data(AiTaskOperationService::lists($this->request->get()));
    }
}
