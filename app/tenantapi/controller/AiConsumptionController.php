<?php

namespace app\tenantapi\controller;

use app\common\service\ai\AiUsageService;
use app\common\service\ai\AiTaskOperationService;

class AiConsumptionController extends BaseAdminController
{
    /** @notes 任务日志数据统计 */
    public function statistics()
    {
        $uris = (int)($this->adminInfo['root'] ?? 0) === 1 ? null
            : (new \app\common\cache\TenantAdminAuthCache($this->adminId))->getAdminUri() ?? [];
        if ($uris !== null && !in_array('ai_consumption/statistics', array_map('strtolower', $uris), true)) {
            return $this->fail('没有数据统计权限');
        }
        try {
            return $this->data(\app\common\service\workbench\TenantTaskStatistics::report($this->tenantId, $this->request->get()));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

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
        if (!$detail) return $this->fail('消耗日志不存在');
        $detail['operation_tracks'] = AiTaskOperationService::operationListsForTask((int)($detail['app_task_id'] ?? 0), (int)$detail['id'], $this->tenantId);
        return $this->success('获取成功', $detail);
    }

    public function batchAction()
    {
        try {
            $data = AiTaskOperationService::submit((array)$this->request->post('record_keys/a', []), (string)$this->request->post('action', ''), (string)$this->request->post('reason', ''), $this->tenantId, $this->adminId, 'tenant');
            return $this->success('批量操作已进入队列', $data);
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function batchDetail()
    {
        $data = AiTaskOperationService::detail((int)$this->request->get('id', 0), $this->tenantId);
        return $data ? $this->success('获取成功', $data) : $this->fail('操作单不存在');
    }

    public function operationLists()
    {
        return $this->data(AiTaskOperationService::lists($this->request->get(), $this->tenantId));
    }
}
