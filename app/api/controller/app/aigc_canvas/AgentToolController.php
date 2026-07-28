<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\agent\tools\CanvasAgentToolRegistryService;
use Exception;

class AgentToolController extends BaseApiController
{
    public function lists()
    {
        return $this->respond(fn() => CanvasAgentToolRegistryService::toolList((int)$this->request->tenantId, $this->request->get()));
    }

    public function schemas()
    {
        return $this->respond(fn() => CanvasAgentToolRegistryService::schemas((int)$this->request->tenantId, $this->request->get()));
    }

    public function pricing()
    {
        return $this->respond(fn() => CanvasAgentToolRegistryService::pricing((int)$this->request->tenantId, $this->request->get()));
    }

    public function taskStatus()
    {
        return $this->respond(fn() => CanvasAgentToolRegistryService::taskStatus((int)$this->request->tenantId, $this->request->get()));
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
