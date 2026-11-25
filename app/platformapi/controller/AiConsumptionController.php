<?php

namespace app\platformapi\controller;

use app\common\service\ai\AiUsageService;
use app\common\service\ai\AiTaskOperationService;
use app\common\service\workbench\PlatformSupplierCostReview;
use app\common\service\workbench\PlatformTaskStatistics;

class AiConsumptionController extends BaseAdminController
{
    /**
     * @notes 平台任务数据统计
     */
    public function statistics()
    {
        $root = (int)($this->adminInfo['root'] ?? 0) === 1;
        if (!$root) {
            $uris = (new \app\common\cache\AdminAuthCache($this->adminId))->getAdminUri();
            if (!in_array('ai_consumption/statistics', array_map('strtolower', $uris), true)) {
                return $this->fail('没有数据统计权限');
            }
        }
        try {
            return $this->data(PlatformTaskStatistics::report($this->request->get(), $root));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function confirmSupplierCost()
    {
        if (!$this->request->isPost() || (int)($this->adminInfo['root'] ?? 0) !== 1) {
            return $this->fail('仅平台超级管理员可以确认上游成本');
        }
        try {
            PlatformSupplierCostReview::confirm(
                (int)$this->request->post('id/d', 0),
                $this->adminId,
                (string)$this->request->post('supplier_points/s', ''),
                (string)$this->request->post('reference/s', '')
            );
            return $this->success('上游成本已确认', [], 1, 0);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

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
        $markup = $detail['price_snapshot']['supply_markup'] ?? null;
        if (is_array($markup)) {
            $detail['supply_markup'] = array_intersect_key($markup, array_flip(['rate_percent', 'revision']));
        }
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
