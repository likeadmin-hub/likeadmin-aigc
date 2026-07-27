<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\common\service\app\aigc_canvas\agent\router\CanvasAgentRouterService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class SkillController extends BaseAdminController
{
    public function lists()
    {
        try {
            AigcCanvasSkillService::seedBuiltinSkills($this->tenantId);
            return $this->success('success', AigcCanvasSkillService::lists($this->tenantId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('success', AigcCanvasSkillService::detail($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function create()
    {
        try {
            return $this->success('created', AigcCanvasSkillService::create($this->tenantId, $this->adminId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function update()
    {
        try {
            return $this->success('updated', AigcCanvasSkillService::update($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            return $this->success('updated', AigcCanvasSkillService::status($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcCanvasSkillService::delete($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('deleted', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function release()
    {
        try {
            return $this->success('updated', AigcCanvasSkillService::release($this->tenantId, $this->adminId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function versions()
    {
        try {
            return $this->success('success', AigcCanvasSkillService::versions($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function rollback()
    {
        try {
            return $this->success('rolled back', AigcCanvasSkillService::rollback($this->tenantId, $this->adminId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function debugRoute()
    {
        try {
            $params = $this->request->post();
            $content = trim((string)($params['content'] ?? ''));
            if ($content === '') {
                throw new Exception('请输入测试问题');
            }
            $routeParams = ['skill' => 'agent_auto'];
            if (!empty($params['skill_key'])) {
                $routeParams['skill_key'] = (string)$params['skill_key'];
            }
            if (!empty($params['skill_id'])) {
                $routeParams['skill_id'] = (int)$params['skill_id'];
            }
            $route = CanvasAgentRouterService::route($this->tenantId, $this->adminId, $routeParams, $content, [
                'project' => [],
                'canvas_summary' => '',
                'selected_elements' => is_array($params['selected_elements'] ?? null) ? $params['selected_elements'] : [],
                'recent_assets' => [],
                'uploaded_references' => is_array($params['uploaded_references'] ?? null) ? $params['uploaded_references'] : [],
                'context_used' => !empty($params['selected_elements']) || !empty($params['uploaded_references']),
            ]);
            $selectedSkill = !empty($params['skill_key']) || !empty($params['skill_id'])
                ? AigcCanvasSkillService::selectedForAgent($this->tenantId, $routeParams)
                : [];
            $selectedContract = $selectedSkill === []
                ? []
                : AigcCanvasSkillService::compileForAgent($selectedSkill, $content, [
                    'selected_elements' => is_array($params['selected_elements'] ?? null) ? $params['selected_elements'] : [],
                    'uploaded_references' => is_array($params['uploaded_references'] ?? null) ? $params['uploaded_references'] : [],
                ], true);
            return $this->success('success', [
                'matched' => !empty($route['matched']),
                'skill_key' => (string)($route['skill_key'] ?? $route['skill_code'] ?? ''),
                'skill_name' => (string)($route['db_skill']['name'] ?? ''),
                'intent' => (string)($route['intent'] ?? ''),
                'confidence' => (float)($route['confidence'] ?? 0),
                'reason' => (string)($route['reason'] ?? ''),
                'slots' => is_array($route['slots'] ?? null) ? $route['slots'] : [],
                'missing_slots' => is_array($route['missing_slots'] ?? null) ? $route['missing_slots'] : [],
                'next_action' => (string)($route['next_action'] ?? ''),
                'clarify_question' => (string)($route['clarify_question'] ?? ''),
                'tool_policy' => is_array($route['db_skill']['tool_policy_json'] ?? null) ? $route['db_skill']['tool_policy_json'] : [],
                'selected_skill_contract' => $selectedContract,
            ]);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
