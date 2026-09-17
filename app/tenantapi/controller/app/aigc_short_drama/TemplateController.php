<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\ShortDramaTemplateService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class TemplateController extends BaseAdminController
{
    public function lists() { return $this->success('获取成功', ShortDramaTemplateService::adminLists($this->tenantId, $this->request->get())); }
    public function detail() { try { return $this->success('获取成功', ShortDramaTemplateService::detail($this->tenantId, (int)$this->request->get('id', 0))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function save() { try { return $this->success('保存成功', ShortDramaTemplateService::save($this->tenantId, $this->request->post()), 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function status() { try { ShortDramaTemplateService::status($this->tenantId, (int)$this->request->post('id', 0), (bool)$this->request->post('status', true)); return $this->success('设置成功', [], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function delete() { try { ShortDramaTemplateService::delete($this->tenantId, (int)$this->request->post('id', 0)); return $this->success('删除成功', [], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
