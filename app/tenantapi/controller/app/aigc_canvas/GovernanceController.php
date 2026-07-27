<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasAgentOrchestrationPolicyService;
use app\common\service\app\aigc_canvas\AigcCanvasSkillEvaluationService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class GovernanceController extends BaseAdminController
{
    public function policies()
    {
        return $this->success('success', AigcCanvasAgentOrchestrationPolicyService::lists($this->tenantId));
    }

    public function savePolicy()
    {
        try {
            return $this->success('updated', AigcCanvasAgentOrchestrationPolicyService::save($this->tenantId, $this->adminId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function evaluationCases()
    {
        return $this->success('success', AigcCanvasSkillEvaluationService::lists($this->tenantId, $this->request->get()));
    }

    public function saveEvaluationCase()
    {
        try {
            return $this->success('updated', AigcCanvasSkillEvaluationService::save($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function runEvaluation()
    {
        return $this->success('success', AigcCanvasSkillEvaluationService::run($this->tenantId, $this->request->post()));
    }
}
