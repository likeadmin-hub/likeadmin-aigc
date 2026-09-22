<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\ShortDramaSkillRuntime;
use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class SkillController extends BaseAdminController
{
    public function lists() { try { return $this->success('获取成功', ShortDramaSkillService::lists($this->tenantId, $this->request->get())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function workflowEligible() { try { return $this->success('获取成功', ['lists' => ShortDramaSkillService::workflowEligible($this->tenantId)], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function detail() { try { return $this->success('获取成功', ShortDramaSkillService::detail($this->tenantId, (int)$this->request->get('id', 0))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function create() { try { return $this->success('创建成功', ShortDramaSkillService::create($this->tenantId, $this->adminId, $this->request->post()), 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function update() { try { return $this->success('保存成功', ShortDramaSkillService::update($this->tenantId, $this->adminId, $this->request->post()), 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function status() { try { ShortDramaSkillService::status($this->tenantId, (int)$this->request->post('id', 0), (bool)$this->request->post('status', true)); return $this->success('设置成功', [], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function release() { try { return $this->success('发布成功', ShortDramaSkillService::release($this->tenantId, $this->adminId, $this->request->post()), 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function versions() { try { return $this->success('获取成功', ShortDramaSkillService::versions($this->tenantId, (int)$this->request->get('id', 0))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function rollback() { try { return $this->success('回滚成功', ShortDramaSkillService::rollback($this->tenantId, $this->adminId, $this->request->post()), 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function delete() { try { ShortDramaSkillService::delete($this->tenantId, (int)$this->request->post('id', 0)); return $this->success('删除成功', [], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function preview()
    {
        try {
            $params = $this->request->post(); $definition = ShortDramaSkillRuntime::normalizeDefinition((array)($params['definition'] ?? []));
            $missing = ShortDramaSkillRuntime::missingSlots(['definition' => $definition], $params);
            $compiled = [];
            foreach (ShortDramaSkillRuntime::STAGES as $key => $label) $compiled[] = ['key' => $key, 'label' => $label, 'instruction' => ShortDramaSkillRuntime::instruction(['definition' => $definition], $key)];
            $matched = false;
            foreach (array_merge([(string)($params['invocation_rule'] ?? '')], $definition['keywords'], $definition['positive_examples']) as $term) {
                if ($term !== '' && mb_stripos((string)($params['prompt'] ?? ''), $term) !== false) $matched = true;
            }
            return $this->success('预览成功', ['missing_slots' => $missing, 'matched' => $matched, 'stages' => ShortDramaSkillRuntime::STAGES, 'compiled' => $compiled,
                'confirmations' => ShortDramaSkillRuntime::confirmations(['execution_policy' => (array)($params['execution_policy'] ?? [])], [])], 1, 1);
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Text-only draft suggestions. The administrator applies them explicitly. */
    public function optimize()
    {
        try { return $this->success('优化建议已生成', \app\common\service\app\aigc_short_drama\AigcShortDramaService::optimizeSkillDraft($this->tenantId, $this->adminId, $this->request->post()), 1, 1); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }
}
