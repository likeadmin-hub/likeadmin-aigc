<?php

namespace app\platformapi\controller;

use app\common\service\ai\AiTaskRecordService;
use app\common\service\ai\AiTaskOperationService;

class AiTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->data(AiTaskRecordService::lists($this->request->get()));
    }

    public function detail()
    {
        $id = (int)$this->request->get('id', 0);
        $baseAppCode = (string)$this->request->get('base_app_code', 'aigc_image');
        $detail = AiTaskRecordService::detail($id, 0, $baseAppCode);
        if (!$detail) {
            return $this->fail('任务不存在');
        }
        $detail['operation_tracks'] = AiTaskOperationService::operationListsForTask(
            $baseAppCode === 'ai_app_task' ? $id : 0,
            0
        );
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
