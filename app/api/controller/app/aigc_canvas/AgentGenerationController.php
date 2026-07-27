<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService;
use Exception;

class AgentGenerationController extends BaseApiController
{
    public function schema()
    {
        return $this->respond(fn() => CanvasGenerationTaskCenterService::schema((int)$this->request->tenantId));
    }

    public function create()
    {
        return $this->respond(fn() => CanvasGenerationTaskCenterService::create((int)$this->request->tenantId, $this->userId, $this->request->post()));
    }

    public function query()
    {
        return $this->respond(fn() => CanvasGenerationTaskCenterService::query((int)$this->request->tenantId, $this->userId, $this->request->get()));
    }

    public function cancel()
    {
        return $this->respond(fn() => CanvasGenerationTaskCenterService::cancel((int)$this->request->tenantId, $this->userId, $this->request->post()));
    }

    public function retry()
    {
        return $this->respond(fn() => CanvasGenerationTaskCenterService::retry((int)$this->request->tenantId, $this->userId, $this->request->post()));
    }

    public function recover()
    {
        return $this->respond(fn() => CanvasGenerationTaskCenterService::recover((int)$this->request->tenantId, $this->userId, $this->request->post()));
    }

    private function respond(callable $handler)
    {
        try {
            return $this->success('success', $handler());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
