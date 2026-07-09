<?php

namespace app\tenantapi\controller;

use app\common\service\ai\AiTaskRecordService;
use think\facade\Log;

class AiTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->data(AiTaskRecordService::lists($this->request->get(), $this->tenantId));
    }

    public function detail()
    {
        $id = (int)$this->request->get('id', 0);
        $baseAppCode = (string)$this->request->get('base_app_code', 'aigc_image');
        $detail = AiTaskRecordService::detail($id, $this->tenantId, $baseAppCode);
        if (!$detail) {
            return $this->fail('任务不存在');
        }
        return $this->success('获取成功', $detail);
    }

    public function query()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $baseAppCode = (string)$this->request->post('base_app_code', 'aigc_image');
            $detail = AiTaskRecordService::queryResult($id, $this->tenantId, $baseAppCode);
            if (!$detail) {
                return $this->fail('任务不存在');
            }
            return $this->success('查询成功', $detail, 1, 1);
        } catch (\Throwable $e) {
            Log::write('租户后台任务查询失败: ' . json_encode([
                'tenant_id' => $this->tenantId,
                'task_id' => $this->request->post('id', 0),
                'base_app_code' => $this->request->post('base_app_code', ''),
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
            return $this->fail('查询失败，请稍后重试');
        }
    }
}
