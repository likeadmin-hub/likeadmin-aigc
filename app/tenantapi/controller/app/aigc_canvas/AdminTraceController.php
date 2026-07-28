<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\batch\EcommerceAgentBatchService;
use app\tenantapi\controller\BaseAdminController;
use think\facade\Db;

class AdminTraceController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('success', AigcCanvasService::agentTraceLists($this->tenantId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('success', AigcCanvasService::agentTraceDetail(
                $this->tenantId,
                (int)$this->request->get('id', 0)
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function promptTrace()
    {
        try {
            $batchId = (int)$this->request->get('batch_id', 0);
            if ($batchId <= 0) {
                throw new \Exception('batch_id is required');
            }

            $userId = (int)Db::name('aigc_canvas_agent_batch')
                ->where([
                    'id' => $batchId,
                    'tenant_id' => $this->tenantId,
                    'delete_time' => 0,
                ])
                ->value('user_id');
            if ($userId <= 0) {
                throw new \Exception('Agent batch not found');
            }

            return $this->success('success', EcommerceAgentBatchService::promptTrace(
                $this->tenantId,
                $userId,
                $this->request->get()
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
